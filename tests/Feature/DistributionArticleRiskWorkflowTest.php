<?php

namespace Tests\Feature;

use App\Ai\Workspace\AiPlanCompiler;
use App\Exceptions\ArticleRiskGateException;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelOperation;
use App\Models\DistributionChannelSecret;
use App\Models\DistributionLog;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\SensitiveWord;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use App\Services\GeoFlow\ArticleAiQualitySampleBuilder;
use App\Services\GeoFlow\ArticlePublicationDeliveryService;
use App\Services\GeoFlow\ArticlePublicationEligibilityService;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPayloadBuilder;
use App\Services\GeoFlow\DistributionPublisherInterface;
use App\Services\GeoFlow\DistributionPublisherManager;
use App\Services\GeoFlow\DistributionRetryPolicy;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Connectors\ConnectorInterface;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class DistributionArticleRiskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
    }

    public function test_risky_article_is_not_enqueued_for_distribution(): void
    {
        SensitiveWord::query()->create(['word' => 'restricted claim']);
        [$article] = $this->createDistributionArticle('Contains a restricted claim.');

        app(DistributionOrchestrator::class)->enqueueForArticle($article);

        $this->assertDatabaseCount('article_distributions', 0);
        $this->assertSame('warning', $article->fresh()->latestRiskScan?->status);
        $this->assertSame('distribution_enqueue', $article->fresh()->latestRiskScan?->trigger);
        Queue::assertNothingPushed();
    }

    public function test_clean_article_is_scanned_and_enqueued_for_distribution(): void
    {
        SensitiveWord::query()->create(['word' => 'restricted claim']);
        [$article] = $this->createDistributionArticle('Safe content.');

        app(DistributionOrchestrator::class)->enqueueForArticle($article);

        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $article->id,
            'action' => 'publish',
            'status' => 'queued',
        ]);
        $this->assertSame('clean', $article->fresh()->latestRiskScan?->status);
        $this->assertSame('distribution_enqueue', $article->fresh()->latestRiskScan?->trigger);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
    }

    public function test_enqueued_distribution_keeps_an_immutable_payload_and_binds_it_to_the_idempotency_key(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Original approved content.');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $payloadHash = (string) $distribution->payload_hash;

        $this->assertSame('Original approved content.', data_get($distribution->remote_meta, 'distribution_payload.article.content'));
        $this->assertStringEndsWith(substr($payloadHash, 0, 16), (string) $distribution->idempotency_key);

        $article->update(['content' => 'New safe content queued for a later update.']);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'gfk_immutable_payload',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_immutable_payload'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        Http::fake(['*' => Http::response([
            'ok' => true,
            'remote_id' => 'immutable-remote',
            'remote_url' => 'https://risk-target.example.com/articles/immutable-remote',
        ])]);

        $orchestrator->process($distribution);

        Http::assertSent(fn ($request): bool => data_get($request->data(), 'article.content') === 'Original approved content.');
    }

    public function test_distribution_job_waits_for_a_pending_ai_quality_result_without_consuming_an_attempt(): void
    {
        [$article, $task, $channel] = $this->createDistributionArticle('Pending quality content.');
        $this->enableQualityPolicy($task);
        $distribution = ArticleDistribution::query()->create([
            'remote_meta' => ['workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article->fresh())],
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'attempt_count' => 0,
            'idempotency_key' => 'pending-quality-distribution',
        ]);

        (new ProcessArticleDistributionJob((int) $distribution->id))->handle(
            app(DistributionOrchestrator::class),
            app(DistributionRetryPolicy::class),
        );

        $distribution->refresh();
        $this->assertSame('queued', $distribution->status);
        $this->assertSame(0, $distribution->attempt_count);
        $this->assertSame('article_ai_quality_pending', data_get($distribution->remote_meta, 'ai_quality_dispatch.error_code'));
        $this->assertNotNull($distribution->next_retry_at);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
    }

    public function test_distribution_job_terminally_blocks_a_rejected_ai_quality_result(): void
    {
        [$article, $task, $channel] = $this->createDistributionArticle('Rejected quality content.');
        $this->enableQualityPolicy($task);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article->fresh(), dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'blocked',
            'score' => 10,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $distribution = ArticleDistribution::query()->create([
            'remote_meta' => ['workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article->fresh())],
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'attempt_count' => 0,
            'idempotency_key' => 'blocked-quality-distribution',
        ]);

        (new ProcessArticleDistributionJob((int) $distribution->id))->handle(
            app(DistributionOrchestrator::class),
            app(DistributionRetryPolicy::class),
        );

        $distribution->refresh();
        $this->assertSame('failed', $distribution->status);
        $this->assertSame(0, $distribution->attempt_count);
        $this->assertSame('article_ai_quality_blocked', data_get($distribution->remote_meta, 'ai_quality_dispatch.error_code'));
        $this->assertNull($distribution->next_retry_at);
        Queue::assertNotPushed(ProcessArticleDistributionJob::class);
    }

    public function test_sampled_quality_release_is_preserved_in_distribution_audit_metadata(): void
    {
        [$article, $task] = $this->createDistributionArticle('Safe sampled quality content.');
        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $model = AiModel::query()->create([
            'name' => 'Distribution quality model',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Distribution quality knowledge',
            'content' => 'Safe sampled quality content.',
        ]);
        $task->forceFill([
            'ai_model_id' => $model->id,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_pass_score' => 85,
            'ai_quality_timeout_sampling_enabled' => true,
        ])->save();
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $article->unsetRelation('task');
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 92,
            'inspection_scope' => 'fallback_sampled',
            'fallback_trigger_code' => 'inspection_primary_deadline_exceeded',
            'active_dedupe_key' => null,
            'coverage_meta' => [
                'algorithm_version' => ArticleAiQualitySampleBuilder::ALGORITHM_VERSION,
                'safe_for_auto_release' => true,
                'mandatory_overflow' => false,
                'mandatory_claims_total' => 2,
                'mandatory_claims_covered' => 2,
                'regions_covered' => ['front', 'middle', 'back'],
                'deterministic_risk_status' => 'clean',
                'checked_chars' => 120,
                'total_chars' => 600,
                'sampled_content' => 'must remain private',
                'sampled_ranges' => [[
                    'start' => 0,
                    'end' => 120,
                    'content' => 'must remain private',
                ]],
            ],
            'finished_at' => now(),
        ])->save();

        app(DistributionOrchestrator::class)->enqueueForArticle($article->fresh());

        $distribution = ArticleDistribution::query()->firstOrFail();
        $this->assertSame('fallback_sampled', data_get($distribution->remote_meta, 'ai_quality_guard.inspection_scope'));
        $this->assertSame(
            (string) $check->retrieval_basis_hash,
            data_get($distribution->remote_meta, 'ai_quality_guard.retrieval_basis_hash'),
        );
        $this->assertSame('inspection_primary_deadline_exceeded', data_get($distribution->remote_meta, 'ai_quality_guard.fallback_trigger_code'));
        $this->assertSame(120, data_get($distribution->remote_meta, 'ai_quality_guard.coverage.checked_chars'));
        $this->assertNull(data_get($distribution->remote_meta, 'ai_quality_guard.coverage.sampled_content'));
        $this->assertNull(data_get($distribution->remote_meta, 'ai_quality_guard.coverage.sampled_ranges.0.content'));
    }

    public function test_distribution_execution_rejects_a_changed_quality_basis(): void
    {
        Queue::fake();
        [$article, $task] = $this->createDistributionArticle('Stable quality basis content.');
        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $model = AiModel::query()->create([
            'name' => 'Quality basis model',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-basis-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'Quality basis knowledge',
            'content' => 'Stable quality basis content.',
        ]);
        $task->forceFill([
            'ai_model_id' => $model->id,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_pass_score' => 85,
        ])->save();
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $article->unsetRelation('task');
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 95,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        app(DistributionOrchestrator::class)->enqueueForArticle($article->fresh());
        $distribution = ArticleDistribution::query()->firstOrFail();

        $check->forceFill(['retrieval_basis_hash' => str_repeat('f', 64)])->save();
        (new ProcessArticleDistributionJob((int) $distribution->id))->handle(
            app(DistributionOrchestrator::class),
            app(DistributionRetryPolicy::class),
        );

        $distribution->refresh();
        $this->assertSame('failed', $distribution->status);
        $this->assertSame(
            'article_ai_quality_basis_changed',
            data_get($distribution->remote_meta, 'ai_quality_dispatch.error_code'),
        );
    }

    public function test_distribution_execution_rejects_a_guard_when_enabled_quality_configuration_becomes_unavailable(): void
    {
        [$article, $task] = $this->createDistributionArticle('Guarded quality content.');
        $this->enableQualityPolicy($task);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article->fresh(), dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 95,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        app(DistributionOrchestrator::class)->enqueueForArticle($article->fresh());
        $distribution = ArticleDistribution::query()->firstOrFail();

        $task->forceFill(['ai_quality_prompt_id' => null])->save();
        (new ProcessArticleDistributionJob((int) $distribution->id))->handle(
            app(DistributionOrchestrator::class),
            app(DistributionRetryPolicy::class),
        );

        $distribution->refresh();
        $this->assertSame('failed', $distribution->status);
        $this->assertSame(
            'article_ai_quality_failed',
            data_get($distribution->remote_meta, 'ai_quality_dispatch.error_code'),
        );
    }

    public function test_reenqueue_clears_an_old_quality_guard_after_quality_is_disabled(): void
    {
        [$article, $task] = $this->createDistributionArticle('Quality guard reuse content.');
        $this->enableQualityPolicy($task);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article->fresh(), dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 95,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article->fresh());
        $distribution = ArticleDistribution::query()->firstOrFail();
        $this->assertSame('queued', $distribution->status);
        $this->assertIsArray(data_get($distribution->remote_meta, 'ai_quality_guard'));

        $task->forceFill(['ai_quality_enabled' => false])->save();
        $orchestrator->enqueueForArticle($article->fresh());

        $this->assertNull(data_get($distribution->fresh()->remote_meta, 'ai_quality_guard'));
    }

    public function test_disabling_task_quality_releases_an_existing_queued_delivery_without_reenqueue(): void
    {
        [$article, $task, $delivery] = $this->queuedDeliveryAfterDisablingQuality();
        $payload = data_get($delivery->remote_meta, 'distribution_payload');
        $idempotencyKey = $delivery->idempotency_key;

        (new ProcessArticleDistributionJob((int) $delivery->id))->handle(
            app(DistributionOrchestrator::class), app(DistributionRetryPolicy::class),
        );

        $this->assertSame('synced', $delivery->fresh()->status);
        $this->assertSame($idempotencyKey, $delivery->fresh()->idempotency_key);
        $this->assertSame($payload, data_get($delivery->fresh()->remote_meta, 'distribution_payload'));
        $this->assertSame('published', $article->fresh()->status);
        $this->assertFalse($task->fresh()->ai_quality_enabled);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => data_get($request->data(), 'article.content') === 'Approved content before quality was disabled.');
    }

    public function test_reenabling_quality_before_transport_rejects_the_old_queued_report(): void
    {
        [, $task, $delivery] = $this->queuedDeliveryAfterDisablingQuality();
        DistributionChannelOperation::created(function ($operation) use ($task): void {
            if ($operation->operation === 'article_publish') {
                app(TaskLifecycleService::class)->updateTask($task->id, ['ai_quality_enabled' => true]);
            }
        });

        (new ProcessArticleDistributionJob((int) $delivery->id))->handle(
            app(DistributionOrchestrator::class), app(DistributionRetryPolicy::class),
        );

        $this->assertTrue($task->fresh()->ai_quality_enabled, (string) $delivery->fresh()->last_error_message);
        $this->assertNotSame('synced', $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_quality_disabled_delivery_still_honors_a_hold_before_transport(): void
    {
        [$article, , $delivery] = $this->queuedDeliveryAfterDisablingQuality();
        DistributionChannelOperation::created(function ($operation) use ($article): void {
            if ($operation->operation === 'article_publish') {
                app(ArticleWorkflowTransitionService::class)->humanAction($article->fresh(), 'hold');
            }
        });

        (new ProcessArticleDistributionJob((int) $delivery->id))->handle(
            app(DistributionOrchestrator::class), app(DistributionRetryPolicy::class),
        );

        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertSame('hold', $article->fresh()->publication_intent);
        Http::assertNothingSent();
    }

    public function test_quality_disabled_delivery_still_honors_pause_before_transport(): void
    {
        [, $task, $delivery] = $this->queuedDeliveryAfterDisablingQuality();
        DistributionChannelOperation::created(function ($operation) use ($task): void {
            if ($operation->operation === 'article_publish') {
                app(TaskLifecycleService::class)->stopTask($task->id);
            }
        });

        (new ProcessArticleDistributionJob((int) $delivery->id))->handle(
            app(DistributionOrchestrator::class), app(DistributionRetryPolicy::class),
        );

        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertSame('paused', $task->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_enabling_manual_review_blocks_queued_delivery_until_current_content_is_approved(): void
    {
        [$article, $task, $delivery] = $this->queuedDeliveryAfterDisablingQuality();
        app(TaskLifecycleService::class)->updateTask($task->id, ['need_review' => false]);
        $article->update(['review_status' => 'auto_approved']);
        app(TaskLifecycleService::class)->updateTask($task->id, ['need_review' => true]);

        (new ProcessArticleDistributionJob((int) $delivery->id))->handle(
            app(DistributionOrchestrator::class), app(DistributionRetryPolicy::class),
        );

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame('auto_approved', $article->fresh()->review_status);
        Http::assertNothingSent();

        $workflow = app(ArticleWorkflowTransitionService::class);
        $workflow->humanAction($article->fresh(), 'approve');
        $workflow->humanAction($article->fresh(), 'publish');
        (new ProcessArticleDistributionJob((int) $delivery->id))->handle(
            app(DistributionOrchestrator::class), app(DistributionRetryPolicy::class),
        );

        $this->assertSame('synced', $delivery->fresh()->status);
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame('approved', $article->fresh()->review_status);
        Http::assertSentCount(1);
    }

    public function test_enabling_manual_review_before_immediate_update_transport_blocks_the_update(): void
    {
        [$article, $task, $delivery] = $this->queuedDeliveryAfterDisablingQuality();
        app(TaskLifecycleService::class)->updateTask($task->id, ['need_review' => false]);
        $article->update(['review_status' => 'auto_approved']);
        $delivery->update(['status' => 'synced', 'remote_id' => 'previous-publication']);
        DistributionChannelOperation::created(function ($operation) use ($task): void {
            if ($operation->operation === 'article_update') {
                app(TaskLifecycleService::class)->updateTask($task->id, ['need_review' => true]);
            }
        });

        try {
            app(DistributionOrchestrator::class)->updateRemoteArticle($delivery);
            $this->fail('The current manual review requirement must block the pending update.');
        } catch (RuntimeException $exception) {
            $this->assertSame('distribution_workflow_superseded', $exception->getMessage());
        }

        $this->assertSame('published', $article->fresh()->status);
        $this->assertTrue((bool) $task->fresh()->need_review);
        $this->assertDatabaseHas('article_distributions', [
            'article_id' => $article->id, 'action' => 'update', 'status' => 'cancelled',
        ]);
        Http::assertNothingSent();
    }

    /** @return array{Article, Task, ArticleDistribution} */
    private function queuedDeliveryAfterDisablingQuality(): array
    {
        Http::preventStrayRequests();
        [$article, $task, $channel] = $this->createDistributionArticle('Approved content before quality was disabled.');
        $library = TitleLibrary::query()->create(['name' => 'Quality switch titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'A future quality switch article', 'used_count' => 0]);
        $task->update(['title_library_id' => $library->id, 'article_limit' => 1, 'created_count' => 0, 'ai_quality_retrieval_mode' => 'knowledge_broad']);
        $this->enableQualityPolicy($task);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article->fresh(), dispatch: false);
        $check->forceFill([
            'status' => 'completed', 'decision' => 'passed', 'score' => 95,
            'active_dedupe_key' => null, 'finished_at' => now(),
        ])->save();
        app(DistributionOrchestrator::class)->enqueueForArticle($article->fresh(), throwOnFailure: true);
        $delivery = ArticleDistribution::query()->sole();
        $this->assertIsArray(data_get($delivery->remote_meta, 'ai_quality_guard'));
        app(TaskLifecycleService::class)->updateTask($task->id, ['ai_quality_enabled' => false]);
        $this->assertSame('stale', $check->fresh()->status);
        $this->assertSame('queued', $delivery->fresh()->status);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'gfk_quality_switch',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_quality_switch'),
            'status' => 'active', 'scopes' => ['article.publish'],
        ]);
        Http::fake(['https://risk-target.example.com/*' => Http::response([
            'ok' => true, 'remote_id' => 'quality-switch-remote',
            'remote_url' => 'https://risk-target.example.com/articles/quality-switch-remote',
        ])]);

        return [$article, $task, $delivery];
    }

    public function test_ai_workspace_enqueue_surfaces_an_approved_payload_mismatch(): void
    {
        [$article] = $this->createDistributionArticle('Safe content.');

        try {
            app(DistributionOrchestrator::class)->enqueueForArticle($article, 'publish', [
                'expected_payload_digest' => str_repeat('f', 64),
            ]);
            $this->fail('Expected the AI workspace payload mismatch to be surfaced.');
        } catch (RuntimeException $exception) {
            $this->assertSame('AI 工作台分发载荷在审批后已变化。', $exception->getMessage());
            $this->assertDatabaseCount('article_distributions', 0);
            Queue::assertNothingPushed();
        }
    }

    public function test_ai_workspace_distribution_rejects_a_channel_changed_after_enqueue(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Immutable target content.');
        $summary = app(AiPlanCompiler::class)->targetSummaryFor('distribution.publish', [
            'article_ids' => [$article->id],
            'channel_ids' => [$channel->id],
        ]);
        $channelRevision = (string) data_get($summary, 'channel_snapshots.0.revision');
        $distributionIds = app(DistributionOrchestrator::class)->enqueueForArticle($article, 'publish', [
            'expected_payload_digest' => (string) data_get($summary, 'article_snapshots.0.outbound_payload_digest'),
            'approved_channel_revisions' => [$channel->id => $channelRevision],
        ]);
        $this->assertCount(1, $distributionIds);
        $channel->forceFill(['endpoint_url' => 'https://changed-target.example.com/api'])->save();
        Http::fake();

        try {
            app(DistributionOrchestrator::class)->process(ArticleDistribution::query()->findOrFail($distributionIds[0]));
            $this->fail('Expected the changed AI workspace target to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('AI 工作台分发目标在审批后已变化。', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_ai_workspace_distribution_rejects_a_secret_rotated_after_approval(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Immutable credential target.');
        $oldSecret = DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'approved-secret',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('approved-secret-value'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        $summary = app(AiPlanCompiler::class)->targetSummaryFor('distribution.publish', [
            'article_ids' => [$article->id],
            'channel_ids' => [$channel->id],
        ]);
        $approvedRevision = (string) data_get($summary, 'channel_snapshots.0.revision');
        $oldSecret->forceFill(['last_used_at' => now()])->save();
        $afterUseSummary = app(AiPlanCompiler::class)->targetSummaryFor('distribution.publish', [
            'article_ids' => [$article->id],
            'channel_ids' => [$channel->id],
        ]);
        $this->assertSame($approvedRevision, (string) data_get($afterUseSummary, 'channel_snapshots.0.revision'));
        $distributionIds = app(DistributionOrchestrator::class)->enqueueForArticle($article, 'publish', [
            'expected_payload_digest' => (string) data_get($summary, 'article_snapshots.0.outbound_payload_digest'),
            'approved_channel_revisions' => [
                $channel->id => $approvedRevision,
            ],
        ]);
        $oldSecret->forceFill(['status' => 'revoked'])->save();
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'rotated-secret',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('rotated-secret-value'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        Http::fake();

        try {
            app(DistributionOrchestrator::class)->process(ArticleDistribution::query()->findOrFail($distributionIds[0]));
            $this->fail('Expected the changed AI workspace credential target to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertSame('AI 工作台分发目标在审批后已变化。', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_ai_workspace_enqueue_does_not_claim_an_existing_sending_distribution(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Already sending content.');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $distribution->forceFill(['status' => 'sending'])->save();
        $summary = app(AiPlanCompiler::class)->targetSummaryFor('distribution.publish', [
            'article_ids' => [$article->id],
            'channel_ids' => [$channel->id],
        ]);

        $distributionIds = $orchestrator->enqueueForArticle($article, 'publish', [
            'expected_payload_digest' => (string) data_get($summary, 'article_snapshots.0.outbound_payload_digest'),
            'approved_channel_revisions' => [
                $channel->id => (string) data_get($summary, 'channel_snapshots.0.revision'),
            ],
        ]);

        $this->assertSame([], $distributionIds);
        $this->assertNull(data_get($distribution->fresh()->remote_meta, 'ai_workspace_guard'));
    }

    public function test_distribution_send_rechecks_content_changed_after_enqueue(): void
    {
        SensitiveWord::query()->create(['word' => 'restricted claim']);
        [$article] = $this->createDistributionArticle('Safe content.');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $article->update(['content' => 'Now contains a restricted claim.']);
        Http::fake();

        try {
            $orchestrator->process($distribution);
            $this->fail('Expected the distribution risk gate to reject the stale queued article.');
        } catch (ArticleRiskGateException) {
            $this->assertSame('queued', $distribution->fresh()->status);
            $this->assertSame('warning', $article->fresh()->latestRiskScan?->status);
            $this->assertSame('distribution_send', $article->fresh()->latestRiskScan?->trigger);
            Http::assertNothingSent();
        }
    }

    public function test_distribution_send_rejects_an_article_that_was_downgraded_after_enqueue(): void
    {
        [$article] = $this->createDistributionArticle('Safe content.');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $article->update([
            'status' => 'draft',
            'review_status' => 'pending',
            'published_at' => null,
        ]);
        Http::fake();

        try {
            $orchestrator->process($distribution);
            $this->fail('Expected distribution to reject an article that is no longer publishable.');
        } catch (RuntimeException) {
            $this->assertSame('queued', $distribution->fresh()->status);
            Http::assertNothingSent();
        }
    }

    public function test_distribution_builds_the_payload_from_the_same_fresh_article_snapshot_that_passed_the_gate(): void
    {
        SensitiveWord::query()->create([
            'word' => 'blocked stale content',
            'severity' => 'blocked',
        ]);
        [$article, , $channel] = $this->createDistributionArticle('blocked stale content');
        $staleArticle = Article::query()->findOrFail($article->id);
        $article->update(['content' => 'Fresh safe content.']);
        $distribution = ArticleDistribution::query()->create([
            'remote_meta' => ['workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article->fresh())],
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'attempt_count' => 0,
            'idempotency_key' => 'fresh-payload-snapshot',
        ]);
        $distribution->setRelation('article', $staleArticle);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'gfk_fresh_payload',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_fresh_payload_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'remote_id' => 'remote-1',
                'remote_url' => 'https://risk-target.example.com/articles/remote-1',
            ]),
        ]);

        app(DistributionOrchestrator::class)->process($distribution);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return data_get($payload, 'article.content') === 'Fresh safe content.'
                && data_get($payload, 'article.content') !== 'blocked stale content';
        });
    }

    public function test_legacy_distribution_without_a_workflow_fence_is_cancelled_before_sending(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Legacy safe content.');
        $article->update([
            'task_id' => null,
            'review_status' => 'pending',
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'legacy-published-distribution',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'gfk_legacy_distribution',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_legacy_distribution_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'remote_id' => 'legacy-remote-1',
                'remote_url' => 'https://risk-target.example.com/articles/legacy-remote-1',
            ]),
        ]);

        app(DistributionOrchestrator::class)->process($distribution);

        $this->assertSame('cancelled', $distribution->fresh()->status);
        $this->assertSame(0, $distribution->fresh()->attempt_count);
        Http::assertNothingSent();
    }

    public function test_distribution_send_holds_a_channel_operation_lease_until_the_result_is_saved(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Lease protected content.');
        $distribution = ArticleDistribution::query()->create([
            'remote_meta' => ['workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article->fresh())],
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'lease-protected-distribution',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'gfk_lease_protected',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_lease_protected_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        Http::fake(function () use ($channel, $distribution) {
            $this->assertDatabaseHas('distribution_channel_operations', [
                'distribution_channel_id' => (int) $channel->id,
                'operation' => 'article_publish',
            ]);
            $this->assertDatabaseHas('article_distributions', [
                'id' => (int) $distribution->id,
                'status' => 'sending',
            ]);

            return Http::response([
                'ok' => true,
                'remote_id' => 'lease-remote-1',
                'remote_url' => 'https://risk-target.example.com/articles/lease-remote-1',
            ]);
        });

        app(DistributionOrchestrator::class)->process($distribution);

        $this->assertSame('synced', $distribution->fresh()->status);
        $this->assertDatabaseCount('distribution_channel_operations', 0);
    }

    public function test_in_flight_distribution_cannot_overwrite_task_deletion_outcome(): void
    {
        [$article, $task, $channel] = $this->createDistributionArticle('Delete during external delivery.');
        $distribution = ArticleDistribution::query()->create([
            'remote_meta' => ['workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article->fresh())],
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'task-delete-during-distribution',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'gfk_delete_during_distribution',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_delete_during_distribution'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        Http::fake(function () use ($task) {
            $this->assertDatabaseHas('article_distributions', [
                'status' => 'sending',
            ]);
            app(TaskLifecycleService::class)->deleteTask((int) $task->id);

            return Http::response([
                'ok' => true,
                'remote_id' => 'remote-after-task-delete',
                'remote_url' => 'https://risk-target.example.com/articles/remote-after-task-delete',
            ]);
        });

        $processed = app(DistributionOrchestrator::class)->process($distribution);

        $this->assertFalse($processed);
        $this->assertSame('outcome_unknown', (string) $distribution->fresh()->status);
        $this->assertNull($distribution->fresh()->next_retry_at);
        $this->assertNull(Task::query()->find($task->id));
        Http::assertSentCount(1);
    }

    public function test_distribution_failure_after_task_deletion_preserves_outcome_unknown(): void
    {
        [$article, $task, $channel] = $this->createDistributionArticle('Fail after task deletion.');
        $distribution = ArticleDistribution::query()->create([
            'remote_meta' => ['workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article->fresh())],
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'task-delete-during-failed-distribution',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'gfk_delete_during_failed_distribution',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_delete_during_failed_distribution'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        Http::fake(function () use ($task) {
            app(TaskLifecycleService::class)->deleteTask((int) $task->id);

            return Http::response(['message' => 'remote failure'], 500);
        });

        (new ProcessArticleDistributionJob((int) $distribution->id))->handle(
            app(DistributionOrchestrator::class),
            app(DistributionRetryPolicy::class),
        );

        $this->assertSame('outcome_unknown', (string) $distribution->fresh()->status);
        $this->assertNull($distribution->fresh()->next_retry_at);
        Http::assertSentCount(1);
    }

    public function test_retry_decision_cannot_overwrite_task_deletion_outcome(): void
    {
        [$article, $task, $channel] = $this->createDistributionArticle('Delete during retry decision.');
        $distribution = ArticleDistribution::query()->create([
            'remote_meta' => ['workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article->fresh())],
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'task-delete-during-retry-decision',
        ]);
        $orchestrator = Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('process')
            ->once()
            ->andReturnUsing(function (ArticleDistribution $candidate): never {
                $candidate->forceFill(['status' => 'sending', 'attempt_count' => 1])->save();

                throw new RuntimeException('500 remote failure');
            });
        $retryPolicy = new class((int) $task->id) extends DistributionRetryPolicy
        {
            public function __construct(private readonly int $taskId) {}

            public function shouldRetry(\Throwable $exception, int $attemptCount, int $maxAttempts): bool
            {
                app(TaskLifecycleService::class)->deleteTask($this->taskId);

                return true;
            }
        };

        (new ProcessArticleDistributionJob((int) $distribution->id))->handle($orchestrator, $retryPolicy);

        $this->assertSame('outcome_unknown', (string) $distribution->fresh()->status);
        $this->assertNull($distribution->fresh()->next_retry_at);
        Queue::assertNothingPushed();
    }

    public function test_stale_enqueue_snapshot_cannot_queue_after_task_deletion(): void
    {
        [$article, $task] = $this->createDistributionArticle('Delete after payload snapshot.');
        $payloadBuilder = Mockery::mock(DistributionPayloadBuilder::class);
        $payloadBuilder->shouldReceive('build')
            ->once()
            ->andReturnUsing(function () use ($task): array {
                app(TaskLifecycleService::class)->deleteTask((int) $task->id);

                return ['title' => 'Stale payload'];
            });
        $this->app->instance(DistributionPayloadBuilder::class, $payloadBuilder);

        $queued = app(DistributionOrchestrator::class)->enqueueForArticle($article);

        $this->assertSame([], $queued);
        $this->assertDatabaseCount('article_distributions', 0);
        $this->assertNull(Task::query()->find($task->id));
        Queue::assertNothingPushed();
    }

    public function test_immediate_update_cannot_claim_after_task_deletion(): void
    {
        Http::fake();
        [$article, $task, $channel] = $this->createDistributionArticle('Delete after immediate update payload.');
        $distribution = ArticleDistribution::query()->create([
            'remote_meta' => ['workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article->fresh())],
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => 'remote-before-update-delete',
            'idempotency_key' => 'immediate-update-after-task-delete',
        ]);
        $payloadBuilder = Mockery::mock(DistributionPayloadBuilder::class);
        $payloadBuilder->shouldReceive('build')
            ->once()
            ->andReturnUsing(function () use ($task): array {
                app(TaskLifecycleService::class)->deleteTask((int) $task->id);

                return ['title' => 'Stale immediate update'];
            });
        $this->app->instance(DistributionPayloadBuilder::class, $payloadBuilder);

        try {
            app(DistributionOrchestrator::class)->updateRemoteArticle($distribution);
            $this->fail('Expected immediate update claim to reject a deleted task.');
        } catch (RuntimeException) {
            $this->assertSame('synced', (string) $distribution->fresh()->status);
        }

        $this->assertNull(Task::query()->find($task->id));
        Http::assertSentCount(0);
    }

    public function test_immediate_delete_result_cannot_overwrite_task_deletion_outcome(): void
    {
        [$article, $task, $channel] = $this->createDistributionArticle('Delete during immediate remote delete.');
        $distribution = ArticleDistribution::query()->create([
            'remote_meta' => ['workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article->fresh())],
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => 'remote-delete-race',
            'idempotency_key' => 'immediate-delete-task-race',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'gfk_immediate_delete_task_race',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_immediate_delete_task_race'),
            'status' => 'active',
            'scopes' => ['article.delete'],
        ]);
        Http::fake(function () use ($task) {
            $this->assertDatabaseHas('article_distributions', [
                'status' => 'sending',
                'action' => 'delete',
            ]);
            app(TaskLifecycleService::class)->deleteTask((int) $task->id);

            return Http::response(['ok' => true, 'remote_id' => 'remote-delete-race']);
        });

        $deleteDistribution = app(DistributionOrchestrator::class)->deleteRemoteArticle($distribution);

        $this->assertSame('synced', (string) $distribution->fresh()->status);
        $this->assertSame('publish', (string) $distribution->fresh()->action);
        $this->assertSame('outcome_unknown', (string) $deleteDistribution->fresh()->status);
        $this->assertNull($deleteDistribution->fresh()->next_retry_at);
        $this->assertNull(Task::query()->find($task->id));
        Http::assertSentCount(1);
    }

    /** @return array{Article, Task, DistributionChannel} */
    public function test_pause_after_claim_before_transport_can_resume(): void
    {
        Http::preventStrayRequests();
        [$article, $task] = $this->createDistributionArticle('Safe content for pre-send pause.');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article, throwOnFailure: true);
        $delivery = ArticleDistribution::query()->firstOrFail();
        DistributionChannelOperation::created(function ($operation) use ($task): void {
            if ($operation->operation === 'article_publish') {
                app(TaskLifecycleService::class)->stopTask($task->id);
            }
        });

        $this->assertFalse($orchestrator->process($delivery));
        $delivery->refresh();
        $this->assertSame('cancelled', $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertNotNull($delivery->last_attempt_at);
        Http::assertNothingSent();
        $this->resumeDistributionTask($task);
        $this->assertSame('active', $task->fresh()->status);
        $this->assertSame(0, $orchestrator->resumeUnsentForTask($task->id));
        $this->assertSame('queued', $delivery->fresh()->status);
    }

    public function test_failed_after_commit_push_is_requeued_on_retry(): void
    {
        Http::preventStrayRequests();
        [$article] = $this->createDistributionArticle('Safe content for queue outage.');
        $adapter = $this->installFailingDistributionQueue();
        $orchestrator = app(DistributionOrchestrator::class);
        try {
            $orchestrator->enqueueForArticle($article, throwOnFailure: true);
            $this->fail('Queue push should throw after committing the delivery row.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Simulated queue connection', $e->getMessage());
        }
        $delivery = ArticleDistribution::query()->firstOrFail();
        $this->assertSame('queued', $delivery->status);
        $this->assertSame(1, $adapter->pushAttempts);
        $this->assertSame(0, $delivery->attempt_count);
        $adapter->failPush = false;
        $this->assertSame([], $orchestrator->enqueueForArticle($article->fresh(), throwOnFailure: true));
        $this->assertSame(2, $adapter->pushAttempts, 'Retry must re-dispatch the committed queued delivery.');
        $this->assertNotNull(data_get($delivery->fresh()->remote_meta, 'queue_dispatched_at'));
        $this->assertSame([], $orchestrator->enqueueForArticle($article->fresh(), throwOnFailure: true));
        $this->assertSame(2, $adapter->pushAttempts, 'Successful queue submission must suppress duplicate submission.');
        $this->assertSame('queued', $delivery->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_queue_failure_during_resume_retries_current_cycle(): void
    {
        Http::preventStrayRequests();
        [$article, $task] = $this->createDistributionArticle('Safe content for resume queue outage.');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article, throwOnFailure: true);
        $delivery = ArticleDistribution::query()->firstOrFail();
        $this->assertNotNull(data_get($delivery->remote_meta, 'queue_dispatched_at'));
        app(TaskLifecycleService::class)->stopTask($task->id);
        $this->assertFalse($orchestrator->process($delivery));
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $adapter = $this->installFailingDistributionQueue();

        $this->resumeDistributionTask($task);
        $this->assertSame(1, $adapter->pushAttempts);
        $this->assertSame('queued', $delivery->fresh()->status);
        $this->assertSame(1, data_get($delivery->fresh()->remote_meta, 'queue_submit_attempts'));
        $this->assertNull(data_get($delivery->fresh()->remote_meta, 'queue_dispatched_at'));
        $adapter->failPush = false;
        $this->assertSame(0, $orchestrator->recoverUndispatched(), 'Queue recovery observes the retry backoff.');
        $this->travel(61)->seconds();
        $this->artisan('geoflow:converge-ai-quality', ['--json' => true])->assertSuccessful();
        $this->assertNotNull(data_get($delivery->fresh()->remote_meta, 'queue_dispatched_at'));
        $this->assertSame(0, $orchestrator->resumeUnsentForTask($task->id));
        $this->assertSame([], $orchestrator->enqueueForArticle($article->fresh(), throwOnFailure: true));
        $this->assertSame(2, $adapter->pushAttempts, 'Current-cycle retry must submit exactly once after recovering from the queue failure.');
        Http::assertNothingSent();
    }

    public function test_channel_refresh_binds_the_current_workflow_after_article_edit(): void
    {
        Http::preventStrayRequests();
        [$article, $task, $channel] = $this->createDistributionArticle('Safe content before channel refresh.');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article, throwOnFailure: true);
        $delivery = ArticleDistribution::query()->firstOrFail();
        $delivery->update(['status' => 'synced', 'remote_id' => 'existing-remote']);
        $oldVersion = (int) data_get($delivery->remote_meta, 'workflow_fence.workflow_version');
        $task->update(['need_review' => false]);
        $article->update(['content' => 'Safe edited content to synchronize.']);
        app(ArticleWorkflowTransitionService::class)->contentChanged($article);
        $this->assertGreaterThan($oldVersion, (int) $article->fresh()->workflow_version);
        $this->assertSame(1, $orchestrator->enqueueChannelContentRefresh($channel));
        $this->assertSame('queued', $delivery->fresh()->status);
        $this->assertSame('update', $delivery->fresh()->action);
        $this->assertSame((int) $article->fresh()->workflow_version, (int) data_get($delivery->fresh()->remote_meta, 'workflow_fence.workflow_version'));
        $this->assertSame('manual', data_get($delivery->fresh()->remote_meta, 'workflow_fence.origin'));
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id, 'key_id' => 'refresh-fence-test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('local-test-secret'),
            'status' => 'active', 'scopes' => ['article.publish', 'article.update'],
        ]);
        Http::fake(['https://risk-target.example.com/*' => Http::response(['ok' => true, 'remote_id' => 'existing-remote'])]);
        $this->assertTrue($orchestrator->process($delivery->fresh()));
        $this->assertSame('synced', $delivery->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_completed_local_receipt_does_not_suppress_explicit_distribution_publish(): void
    {
        [$article, $task] = $this->createDistributionArticle('Safe factual article.');
        $task->update(['publish_scope' => 'local_only', 'need_review' => 0]);
        $article->update(['status' => 'draft', 'publication_intent' => 'scheduled']);
        $workflow = app(ArticleWorkflowTransitionService::class);
        $workflow->humanAction($article->fresh(), 'publish');
        $this->assertSame('published', $article->fresh()->status);
        $receipt = DistributionLog::where('event', ArticlePublicationDeliveryService::EVENT)->sole();
        $this->assertSame('completed', data_get($receipt->context, 'status'));
        $this->assertSame(0, ArticleDistribution::count());
        $task->update(['publish_scope' => 'local_and_distribution']);
        $workflow->humanAction($article->fresh(), 'publish');
        $this->assertSame(1, ArticleDistribution::count(), 'A new explicit publish must deliver to the currently configured channels.');
    }

    public function test_retryable_distribution_resumes_after_pause(): void
    {
        [$article, $task] = $this->createDistributionArticle('Safe factual article.');
        $publisher = Mockery::mock(DistributionPublisherInterface::class);
        $publisher->shouldReceive('publish')->once()->ordered()->andThrow(new RuntimeException('connection refused'));
        $publisher->shouldReceive('publish')->once()->ordered()->andReturn(['remote_id' => 'retry-success']);
        $manager = Mockery::mock(DistributionPublisherManager::class);
        $manager->shouldReceive('forChannel')->andReturn($publisher);
        $this->app->instance(DistributionPublisherManager::class, $manager);
        $orch = app(DistributionOrchestrator::class);
        $orch->enqueueForArticle($article, throwOnFailure: true);
        $delivery = ArticleDistribution::sole();
        (new ProcessArticleDistributionJob($delivery->id))->handle($orch, app(DistributionRetryPolicy::class));
        $this->assertSame('queued', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempt_count);
        $retryAt = $delivery->fresh()->next_retry_at;
        $this->assertNotNull($retryAt);
        app(TaskLifecycleService::class)->stopTask($task->id);
        (new ProcessArticleDistributionJob($delivery->id))->handle($orch, app(DistributionRetryPolicy::class));
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->resumeDistributionTask($task);
        $this->assertSame('active', $task->fresh()->status);
        $this->assertSame('queued', $delivery->fresh()->status, 'A previously scheduled safe retry should continue after resume.');
        $this->assertSame(1, $delivery->fresh()->attempt_count);
        $this->assertTrue($retryAt->equalTo($delivery->fresh()->next_retry_at));
        (new ProcessArticleDistributionJob($delivery->id))->handle($orch, app(DistributionRetryPolicy::class));
        $this->assertSame(1, $delivery->fresh()->attempt_count, 'An early duplicate job must preserve backoff.');
        $this->travelTo($retryAt->addSecond());
        (new ProcessArticleDistributionJob($delivery->id))->handle($orch, app(DistributionRetryPolicy::class));
        $this->assertSame('synced', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempt_count);
        $this->assertNull(data_get($delivery->fresh()->remote_meta, 'safe_retry_at'));
    }

    public function test_immediate_remote_update_respects_a_newer_hold_before_transport(): void
    {
        [$article] = $this->createDistributionArticle('Safe factual article.');
        $sent = 0;
        $publisher = Mockery::mock(DistributionPublisherInterface::class);
        $publisher->shouldReceive('update')->andReturnUsing(function () use (&$sent): array {
            $sent++;

            return ['remote_id' => 'known-id', 'remote_url' => 'https://risk-target.example.com/known'];
        });
        $manager = Mockery::mock(DistributionPublisherManager::class);
        $manager->shouldReceive('forChannel')->andReturn($publisher);
        $this->app->instance(DistributionPublisherManager::class, $manager);
        $orch = app(DistributionOrchestrator::class);
        $orch->enqueueForArticle($article, throwOnFailure: true);
        $delivery = ArticleDistribution::sole();
        $delivery->update(['status' => 'synced', 'remote_id' => 'known-id']);
        DistributionChannelOperation::created(function ($operation) use ($article): void {
            if ($operation->operation === 'article_update') {
                app(ArticleWorkflowTransitionService::class)->humanAction($article->fresh(), 'hold');
            }
        });
        try {
            $orch->updateRemoteArticle($delivery);
            $this->fail('The superseded update must report a conflict.');
        } catch (RuntimeException $exception) {
            $this->assertSame('distribution_workflow_superseded', $exception->getMessage());
        }
        $this->assertSame('hold', $article->fresh()->publication_intent);
        $this->assertSame(0, $sent, 'A hold committed before publisher entry must prevent the old update.');
    }

    public function test_queue_submission_recovery_stops_after_three_failures(): void
    {
        [$article] = $this->createDistributionArticle('Safe content with an unavailable queue.');
        $adapter = $this->installFailingDistributionQueue();
        $orchestrator = app(DistributionOrchestrator::class);
        try {
            $orchestrator->enqueueForArticle($article, throwOnFailure: true);
            $this->fail('The unavailable queue must report failure.');
        } catch (RuntimeException) {
            $this->assertSame(1, $adapter->pushAttempts);
        }
        $delivery = ArticleDistribution::query()->sole();
        $this->travel(61)->seconds();
        $this->assertSame(0, $orchestrator->recoverUndispatched());
        $this->assertSame(2, $adapter->pushAttempts);
        $this->travel(301)->seconds();
        $this->assertSame(0, $orchestrator->recoverUndispatched());
        $this->assertSame(3, $adapter->pushAttempts);
        $this->travel(301)->seconds();
        $this->assertSame(0, $orchestrator->recoverUndispatched());
        $this->assertSame(3, $adapter->pushAttempts);
        $this->assertSame('distribution_queue_retry_exhausted', $delivery->fresh()->last_error_message);
        $this->assertSame(0, $delivery->fresh()->attempt_count);
    }

    public function test_task_resume_attempts_other_recoveries_when_one_service_fails(): void
    {
        [, $task] = $this->createDistributionArticle('Independent task recovery services.');
        app(TaskLifecycleService::class)->stopTask($task->id);
        $orchestrator = Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('resumeUnsentForTask')->once()->with($task->id)
            ->andThrow(new RuntimeException('Simulated recovery failure'));
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);
        foreach ([ArticlePublicationDeliveryService::class,
            ArticleAiQualityReconciliationService::class,
        ] as $service) {
            $mock = Mockery::mock($service)->makePartial();
            $mock->shouldReceive('resumeForTask')->once()->with($task->id);
            $this->app->instance($service, $mock);
        }
        $this->resumeDistributionTask($task);
        $this->assertSame('active', $task->fresh()->status);
    }

    public function test_superseded_unsubmitted_delivery_does_not_starve_later_recovery_batches(): void
    {
        [$first, $task] = $this->createDistributionArticle('Old paused publication.');
        [$second] = $this->createDistributionArticle('Current active publication.');
        $service = app(DistributionOrchestrator::class);
        foreach ([$first, $second] as $article) {
            $service->enqueueForArticle($article, throwOnFailure: true);
            $delivery = ArticleDistribution::query()->where('article_id', $article->id)->sole();
            $meta = (array) $delivery->remote_meta;
            unset($meta['queue_dispatched_at']);
            $delivery->update(['remote_meta' => $meta]);
        }
        $task->update(['status' => 'paused', 'schedule_enabled' => false]);
        $this->assertSame(0, $service->recoverUndispatched(1));
        $this->assertSame('cancelled', ArticleDistribution::query()->where('article_id', $first->id)->sole()->status);
        $this->assertSame(1, $service->recoverUndispatched(1));
        $this->assertNotNull(data_get(ArticleDistribution::query()->where('article_id', $second->id)->sole()->remote_meta, 'queue_dispatched_at'));
    }

    private function resumeDistributionTask(Task $task): void
    {
        $library = TitleLibrary::query()->create(['name' => 'Distribution resume titles']);
        Title::query()->create(['library_id' => $library->id, 'title' => 'A future article title', 'used_count' => 0]);
        $task->update(['title_library_id' => $library->id, 'article_limit' => 1, 'created_count' => 0]);
        app(TaskLifecycleService::class)->startTask($task->id);
    }

    private function installFailingDistributionQueue(): SyncQueue
    {
        $adapter = new class extends SyncQueue
        {
            public int $pushAttempts = 0;

            public bool $failPush = true;

            protected function executeJob($job, $data = '', $queue = null)
            {
                $this->pushAttempts++;
                if ($this->failPush) {
                    throw new RuntimeException('Simulated queue connection unavailable after database commit');
                }

                return 0;
            }
        };
        $connector = new class($adapter) implements ConnectorInterface
        {
            public function __construct(private $adapter) {}

            public function connect(array $config)
            {
                return $this->adapter;
            }
        };
        $manager = new QueueManager(app());
        $manager->addConnector('distribution-failure-test', fn () => $connector);
        config(['queue.default' => 'distribution-failure-test', 'queue.connections.distribution-failure-test' => ['driver' => 'distribution-failure-test']]);
        Queue::swap($manager);

        return $adapter;
    }

    /** @return array{Article,Task,DistributionChannel} */
    private function createDistributionArticle(string $content): array
    {
        $task = Task::query()->create([
            'name' => 'Risk distribution task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'local_and_distribution',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => 'Risk distribution target',
            'domain' => 'risk-target.example.com',
            'endpoint_url' => 'https://risk-target.example.com',
            'status' => 'active',
        ]);
        app(DistributionOrchestrator::class)->syncTaskChannels($task, [(int) $channel->id]);
        $category = Category::query()->create([
            'name' => 'Distribution risk',
            'slug' => 'distribution-risk-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'Distribution risk author',
            'email' => uniqid().'@example.com',
        ]);
        $article = Article::query()->create([
            'title' => 'Distribution risk article',
            'slug' => 'distribution-risk-article-'.uniqid(),
            'excerpt' => 'Distribution excerpt.',
            'content' => $content,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        return [$article, $task, $channel];
    }

    private function enableQualityPolicy(Task $task): void
    {
        $model = AiModel::query()->create([
            'name' => 'Distribution quality gate model '.uniqid(),
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'distribution-quality-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $knowledge = KnowledgeBase::query()->create([
            'name' => 'Distribution quality gate knowledge '.uniqid(),
            'content' => 'Distribution quality content.',
        ]);
        $task->forceFill([
            'ai_model_id' => $model->id,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_model_id' => $model->id,
            'ai_quality_pass_score' => 85,
            'ai_quality_manual_override_min_score' => 70,
        ])->save();
        $task->knowledgeBases()->sync([$knowledge->id => ['sort_order' => 0]]);
    }
}
