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
use App\Models\DistributionChannelSecret;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\SensitiveWord;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleAiQualitySampleBuilder;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPayloadBuilder;
use App\Services\GeoFlow\DistributionRetryPolicy;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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

    public function test_distribution_execution_rejects_a_guard_whose_current_quality_policy_disappeared(): void
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

        $task->forceFill(['ai_quality_enabled' => false])->save();
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
        $this->assertIsArray(data_get($distribution->remote_meta, 'ai_quality_guard'));

        $task->forceFill(['ai_quality_enabled' => false])->save();
        $orchestrator->enqueueForArticle($article->fresh());

        $this->assertNull(data_get($distribution->fresh()->remote_meta, 'ai_quality_guard'));
    }

    public function test_ai_workspace_enqueue_surfaces_an_approved_payload_mismatch(): void
    {
        [$article] = $this->createDistributionArticle('Safe content.');

        try {
            app(DistributionOrchestrator::class)->enqueueForArticle($article, 'publish', [
                'expected_payload_digest' => str_repeat('f', 64),
            ]);
            $this->fail('Expected the AI workspace payload mismatch to be surfaced.');
        } catch (\RuntimeException $exception) {
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
        } catch (\RuntimeException $exception) {
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
        } catch (\RuntimeException $exception) {
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
        } catch (\RuntimeException) {
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

    public function test_legacy_published_distribution_without_a_task_remains_sendable(): void
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

        $this->assertSame('synced', $distribution->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_distribution_send_holds_a_channel_operation_lease_until_the_result_is_saved(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Lease protected content.');
        $distribution = ArticleDistribution::query()->create([
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
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'task-delete-during-retry-decision',
        ]);
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('process')
            ->once()
            ->andReturnUsing(function (ArticleDistribution $candidate): never {
                $candidate->forceFill(['status' => 'sending', 'attempt_count' => 1])->save();

                throw new \RuntimeException('500 remote failure');
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
        $payloadBuilder = \Mockery::mock(DistributionPayloadBuilder::class);
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
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => 'remote-before-update-delete',
            'idempotency_key' => 'immediate-update-after-task-delete',
        ]);
        $payloadBuilder = \Mockery::mock(DistributionPayloadBuilder::class);
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
        } catch (\RuntimeException) {
            $this->assertSame('synced', (string) $distribution->fresh()->status);
        }

        $this->assertNull(Task::query()->find($task->id));
        Http::assertSentCount(0);
    }

    public function test_immediate_delete_result_cannot_overwrite_task_deletion_outcome(): void
    {
        [$article, $task, $channel] = $this->createDistributionArticle('Delete during immediate remote delete.');
        $distribution = ArticleDistribution::query()->create([
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

        app(DistributionOrchestrator::class)->deleteRemoteArticle($distribution);

        $this->assertSame('outcome_unknown', (string) $distribution->fresh()->status);
        $this->assertNull($distribution->fresh()->next_retry_at);
        $this->assertNull(Task::query()->find($task->id));
        Http::assertSentCount(1);
    }

    /** @return array{Article, Task, DistributionChannel} */
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
