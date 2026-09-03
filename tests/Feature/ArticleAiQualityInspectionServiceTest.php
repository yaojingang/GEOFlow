<?php

namespace Tests\Feature;

use App\Ai\Agents\ArticleQualityJsonReviewerAgent;
use App\Ai\Agents\ArticleQualityReviewerAgent;
use App\Ai\Agents\LegacyArticleQualityReviewerAgent;
use App\Contracts\ArticleAiQualityReviewer;
use App\Contracts\DeadlineAwareArticleAiQualityReviewer;
use App\Contracts\VersionAwareArticleAiQualityReviewer;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Jobs\ProcessArticleAiQualityJob;
use App\Jobs\ReconcileArticleAiQualityJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleAiQualityRollout;
use App\Models\ArticleAiQualitySegment;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityExecutionBoundaryHook;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleAiQualityPolicyResolver;
use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use App\Services\GeoFlow\ArticleAiQualityRetrievalCoordinator;
use App\Services\GeoFlow\ArticleAiQualityRolloutPolicy;
use App\Services\GeoFlow\ArticleFactCandidateExtractor;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\KnowledgeFacts\ArticleAtomicFactInspector;
use App\Services\GeoFlow\KnowledgeRetrievalService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Carbon\Carbon;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use UnexpectedValueException;

class ArticleAiQualityInspectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_quality_structured_fallback_records_each_provider_invocation_after_segment_commit(): void
    {
        $this->setQualityRollout(execution: 100);
        $executor = $this->qualityAdmin('quality-ledger-structured', 'super_admin');
        $article = $this->createQualityFixture('quality-ledger-structured', needReview: true);
        $model = $article->task->aiModel;
        $model->forceFill([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quality-ledger-secret'),
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 1,
        ])->save();
        LegacyArticleQualityReviewerAgent::fake(static function (): never {
            throw new ArticleAiQualityRuntimeException('structured_output_unsupported');
        })->preventStrayPrompts();
        ArticleQualityReviewerAgent::fake(static function (): never {
            throw new ArticleAiQualityRuntimeException('structured_output_unsupported');
        })->preventStrayPrompts();
        ArticleQualityJsonReviewerAgent::fake([[
            'summary' => '质检通过。',
            'promotion_context' => 'informational',
            'knowledge_coverage' => 'sufficient',
            'issues' => [],
            'uncertainties' => [],
        ]])->preventStrayPrompts();
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article->fresh(), dispatch: false));

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame('completed', $completed->status);
        $this->assertCount(2, $events);
        $this->assertSame($completed->request_key, $events[0]->request_id);
        $this->assertSame($events[0]->request_id, $events[1]->request_id);
        $this->assertSame(
            [AiModelUsageEvent::STATUS_FAILED, AiModelUsageEvent::STATUS_SUCCEEDED],
            $events->pluck('status')->all(),
        );
        $this->assertSame(['personal', 'personal'], $events->pluck('model_source')->all());
        $this->assertSame([$executor->id, $executor->id], $events->pluck('execution_admin_id')->all());
        $this->assertSame([1, 1], $events->pluck('ai_config_access_version')->all());
        $this->assertSame(['article_ai_quality', 'article_ai_quality'], $events->pluck('business_source')->all());
        $this->assertSame(
            [ArticleAiQualitySegment::class, ArticleAiQualitySegment::class],
            $events->pluck('source_type')->all(),
        );
        $this->assertSame(
            [$completed->segments->first()->id, $completed->segments->first()->id],
            $events->pluck('source_id')->map(static fn (string $id): int => (int) $id)->all(),
        );
        $this->assertStringContainsString('provider-1-structured', $events[0]->call_key);
        $this->assertStringContainsString('provider-2-json_fallback', $events[1]->call_key);
        $serializedEvents = $events->toJson();
        $this->assertStringNotContainsString('quality-ledger-secret', $serializedEvents);
        $this->assertStringNotContainsString('https://example.test', $serializedEvents);
        $this->assertStringNotContainsString('质检通过。', $serializedEvents);
    }

    public function test_production_quality_records_personal_failure_and_shared_candidate_success_separately(): void
    {
        $this->setQualityRollout(execution: 100);
        $provider = $this->qualityAdmin('quality-ledger-provider', 'super_admin');
        $executor = $this->qualityAdmin('quality-ledger-executor', 'admin', $provider);
        $article = $this->createQualityFixture('quality-ledger-candidates', needReview: true);
        $personal = $article->task->aiModel;
        $personal->forceFill([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quality-personal-secret'),
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
            'failover_priority' => 0,
        ])->save();
        $shared = $this->qualityModel($provider, 'quality-ledger-shared', 1);
        $shared->forceFill(['api_key' => app(ApiKeyCrypto::class)->encrypt('quality-shared-secret')])->save();
        $article->task->forceFill([
            'model_selection_mode' => 'smart_failover',
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => 1,
        ])->save();
        $providerCalls = 0;
        ArticleQualityReviewerAgent::fake(function () use (&$providerCalls): array {
            $providerCalls++;
            if ($providerCalls === 1) {
                throw new ArticleAiQualityRuntimeException('provider_gateway_error', true);
            }

            return $this->passingV2QualityResult();
        })->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake(function () use (&$providerCalls): array {
            $providerCalls++;
            if ($providerCalls === 1) {
                throw new ArticleAiQualityRuntimeException('provider_gateway_error', true);
            }

            return $this->passingV2QualityResult();
        })->preventStrayPrompts();
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article->fresh(), dispatch: false));

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame('completed', $completed->status);
        $this->assertSame(2, $providerCalls);
        $this->assertCount(2, $events);
        $this->assertSame([$personal->id, $shared->id], $events->pluck('ai_model_id')->all());
        $this->assertSame(['personal', 'shared'], $events->pluck('model_source')->all());
        $this->assertSame([$executor->id, $provider->id], $events->pluck('config_owner_admin_id')->all());
        $this->assertSame([$executor->id, $executor->id], $events->pluck('execution_admin_id')->all());
        $this->assertSame(['article_ai_quality.inspect', 'article_ai_quality.inspect'], $events->pluck('operation')->all());
        $this->assertSame(
            [AiModelUsageEvent::STATUS_FAILED, AiModelUsageEvent::STATUS_SUCCEEDED],
            $events->pluck('status')->all(),
        );
        $this->assertNotSame($events[0]->call_key, $events[1]->call_key);
    }

    public function test_production_quality_marks_provider_result_revoked_when_access_changes_before_segment_commit(): void
    {
        $this->setQualityRollout(execution: 100);
        $executor = $this->qualityAdmin('quality-ledger-revoked', 'super_admin');
        $article = $this->createQualityFixture('quality-ledger-revoked', needReview: true);
        $model = $article->task->aiModel;
        $model->forceFill([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quality-revoked-secret'),
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 1,
        ])->save();
        ArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        $this->app->instance(
            ArticleAiQualityExecutionBoundaryHook::class,
            new class((int) $executor->id) extends ArticleAiQualityExecutionBoundaryHook
            {
                public function __construct(private readonly int $adminId) {}

                public function beforeFullSegmentCommit(
                    ArticleAiQualityCheck $check,
                    ArticleAiQualitySegment $segment,
                    AiModel $model,
                ): void {
                    Admin::query()->whereKey($this->adminId)->increment('ai_config_access_version');
                }
            },
        );
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);

        try {
            $service->process($check);
            $this->fail('Expected the frozen administrator access to be revoked.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $exception->getErrorCode());
        }

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $event->error_code);
        $this->assertSame(0, (int) $check->fresh()->completed_segment_count);
    }

    public function test_production_quality_discards_provider_result_when_segment_commit_loses_its_claim(): void
    {
        $this->setQualityRollout(execution: 100);
        $executor = $this->qualityAdmin('quality-ledger-cancelled', 'super_admin');
        $article = $this->createQualityFixture('quality-ledger-cancelled', needReview: true);
        $model = $article->task->aiModel;
        $model->forceFill([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quality-cancelled-secret'),
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 1,
        ])->save();
        $checkId = 0;
        $cancel = function () use (&$checkId): array {
            ArticleAiQualitySegment::query()
                ->where('article_ai_quality_check_id', $checkId)
                ->update(['status' => 'cancelled']);

            return $this->passingV2QualityResult();
        };
        ArticleQualityReviewerAgent::fake($cancel)->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake($cancel)->preventStrayPrompts();
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $checkId = (int) $check->id;

        $processed = $service->process($check);

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame('running', $processed->status);
        $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, $event->status);
        $this->assertSame('ai_result_not_committed', $event->error_code);
        $this->assertSame(0, (int) $processed->completed_segment_count);
    }

    public function test_production_quality_records_invalid_output_retry_as_a_new_provider_attempt(): void
    {
        $this->setQualityRollout(execution: 100);
        $executor = $this->qualityAdmin('quality-ledger-invalid-retry', 'super_admin');
        $article = $this->createQualityFixture('quality-ledger-invalid-retry', needReview: true);
        $model = $article->task->aiModel;
        $model->forceFill([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quality-invalid-retry-secret'),
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 1,
        ])->save();
        $providerCalls = 0;
        $respond = function () use (&$providerCalls): array {
            $providerCalls++;
            $result = $this->passingV2QualityResult();
            if ($providerCalls === 1) {
                $result['unexpected'] = 'invalid';
            }

            return $result;
        };
        ArticleQualityReviewerAgent::fake($respond)->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake($respond)->preventStrayPrompts();
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article->fresh(), dispatch: false));

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame('completed', $completed->status);
        $this->assertSame(2, $providerCalls);
        $this->assertSame(
            [AiModelUsageEvent::STATUS_DISCARDED, AiModelUsageEvent::STATUS_SUCCEEDED],
            $events->pluck('status')->all(),
        );
        $this->assertSame('invalid_model_output', $events[0]->error_code);
        $this->assertStringContainsString('occurrence-1', $events[0]->call_key);
        $this->assertStringContainsString('occurrence-2', $events[1]->call_key);
    }

    public function test_production_sampled_quality_records_success_only_after_the_check_transaction_commits(): void
    {
        $this->setQualityRollout(execution: 100);
        $executor = $this->qualityAdmin('quality-ledger-sampled', 'super_admin');
        $article = $this->createQualityFixture('quality-ledger-sampled', needReview: true);
        $article->task->forceFill(['ai_quality_timeout_sampling_enabled' => true])->save();
        $model = $article->task->aiModel;
        $model->forceFill([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quality-sampled-secret'),
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 1,
        ])->save();
        ArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $this->assertTrue($service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
            dispatch: false,
        ));

        $completed = $service->process($check->fresh());

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame('completed', $completed->status);
        $this->assertSame('fallback_sampled', $completed->inspection_scope);
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $event->status);
        $this->assertSame(ArticleAiQualityCheck::class, $event->source_type);
        $this->assertSame((string) $check->id, $event->source_id);
        $this->assertStringStartsWith('sampled.segment-0.attempt-', $event->call_key);
    }

    public function test_production_sampled_quality_marks_returned_provider_result_revoked_before_check_commit(): void
    {
        $this->setQualityRollout(execution: 100);
        $executor = $this->qualityAdmin('quality-ledger-sampled-revoked', 'super_admin');
        $article = $this->createQualityFixture('quality-ledger-sampled-revoked', needReview: true);
        $article->task->forceFill(['ai_quality_timeout_sampling_enabled' => true])->save();
        $model = $article->task->aiModel;
        $model->forceFill([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quality-sampled-revoked-secret'),
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 1,
        ])->save();
        ArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        $this->app->instance(
            ArticleAiQualityExecutionBoundaryHook::class,
            new class((int) $executor->id) extends ArticleAiQualityExecutionBoundaryHook
            {
                public function __construct(private readonly int $adminId) {}

                public function beforeSampledCommit(ArticleAiQualityCheck $check, AiModel $model): void
                {
                    Admin::query()->whereKey($this->adminId)->increment('ai_config_access_version');
                }
            },
        );
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $this->assertTrue($service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
            dispatch: false,
        ));

        try {
            $service->process($check->fresh());
            $this->fail('Expected sampled execution access to be revoked before commit.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, $exception->getErrorCode());
        }

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $event->status);
        $this->assertSame(ArticleAiQualityCheck::class, $event->source_type);
        $this->assertNull($check->fresh()->decision);
    }

    public function test_production_sampled_quality_discards_returned_result_when_completion_claim_is_cancelled(): void
    {
        $this->setQualityRollout(execution: 100);
        $executor = $this->qualityAdmin('quality-ledger-sampled-cancelled', 'super_admin');
        $article = $this->createQualityFixture('quality-ledger-sampled-cancelled', needReview: true);
        $article->task->forceFill(['ai_quality_timeout_sampling_enabled' => true])->save();
        $model = $article->task->aiModel;
        $model->forceFill([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quality-sampled-cancelled-secret'),
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 1,
        ])->save();
        ArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        $this->app->instance(
            ArticleAiQualityExecutionBoundaryHook::class,
            new class extends ArticleAiQualityExecutionBoundaryHook
            {
                public function beforeSampledCommit(ArticleAiQualityCheck $check, AiModel $model): void
                {
                    ArticleAiQualityCheck::query()->whereKey($check->id)->update([
                        'status' => 'cancelled',
                        'active_dedupe_key' => null,
                    ]);
                }
            },
        );
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $this->assertTrue($service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
            dispatch: false,
        ));

        try {
            $service->process($check->fresh());
            $this->fail('Expected the sampled completion claim to reject the provider result.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('inspection_deadline_exceeded', $exception->safeCode());
        }

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_DISCARDED, $event->status);
        $this->assertSame('ai_result_not_committed', $event->error_code);
        $this->assertSame('cancelled', $check->fresh()->status);
    }

    public function test_production_quality_worker_retry_allocates_new_call_identity_without_ledger_conflict(): void
    {
        $this->setQualityRollout(execution: 100);
        $executor = $this->qualityAdmin('quality-ledger-worker-retry', 'super_admin');
        $article = $this->createQualityFixture('quality-ledger-worker-retry', needReview: true);
        $article->task->forceFill(['ai_quality_timeout_sampling_enabled' => false])->save();
        $model = $article->task->aiModel;
        $model->forceFill([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quality-worker-retry-secret'),
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 1,
        ])->save();
        $providerCalls = 0;
        $respond = function () use (&$providerCalls): array {
            $providerCalls++;
            if ($providerCalls === 1) {
                throw new ArticleAiQualityRuntimeException('provider_gateway_error', true);
            }

            return $this->passingV2QualityResult();
        };
        ArticleQualityReviewerAgent::fake($respond)->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake($respond)->preventStrayPrompts();
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);

        try {
            $service->process($check);
            $this->fail('Expected the first provider invocation to fail.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('provider_gateway_error', $exception->safeCode());
            $service->markRetryPending($check, $exception);
            $this->assertSame('queued', $check->fresh()->status);
        }
        $completed = $service->process($check->id);

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame('completed', $completed->status);
        $this->assertSame(2, $providerCalls);
        $this->assertCount(2, $events);
        $this->assertSame($events[0]->request_id, $events[1]->request_id);
        $this->assertNotSame($events[0]->call_key, $events[1]->call_key);
        $this->assertStringContainsString('attempt-1', $events[0]->call_key);
        $this->assertStringContainsString('attempt-2', $events[1]->call_key);
        $this->assertSame(
            [AiModelUsageEvent::STATUS_FAILED, AiModelUsageEvent::STATUS_SUCCEEDED],
            $events->pluck('status')->all(),
        );
    }

    public function test_production_quality_records_one_delivered_provider_attempt_for_each_completed_segment(): void
    {
        Queue::fake();
        $this->setQualityRollout(execution: 100);
        $executor = $this->qualityAdmin('quality-ledger-segments', 'super_admin');
        $article = $this->createQualityFixture('quality-ledger-segments', needReview: true);
        Article::withoutEvents(function () use ($article): void {
            $article->forceFill(['content' => str_repeat('逐段核验产品能力边界。', 2200)])->save();
        });
        $model = $article->task->aiModel;
        $model->forceFill([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quality-segments-secret'),
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 1,
        ])->save();
        ArticleQualityReviewerAgent::fake(fn (): array => $this->passingV2QualityResult())->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake(fn (): array => $this->passingV2QualityResult())->preventStrayPrompts();
        $service = app(ArticleAiQualityInspectionService::class);
        $result = $service->createOrReuse($article->fresh(), dispatch: false);

        for ($attempt = 0; $attempt < 10 && $result->status !== 'completed'; $attempt++) {
            $result = $service->process((int) $result->id);
        }

        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertSame('completed', $result->status);
        $this->assertGreaterThan(1, $result->segment_count);
        $this->assertCount((int) $result->segment_count, $events);
        $this->assertSame(
            array_fill(0, (int) $result->segment_count, AiModelUsageEvent::STATUS_SUCCEEDED),
            $events->pluck('status')->all(),
        );
        $this->assertSame((int) $result->segment_count, $events->pluck('source_id')->unique()->count());
        foreach (range(0, (int) $result->segment_count - 1) as $segmentIndex) {
            $this->assertTrue($events->contains(
                static fn (AiModelUsageEvent $event): bool => str_contains($event->call_key, 'segment-'.$segmentIndex.'.'),
            ));
        }
    }

    public function test_sampling_enabled_checks_persist_primary_and_final_deadlines_with_an_immutable_policy_snapshot(): void
    {
        config()->set('geoflow.ai_quality_deadline_seconds', 180);
        config()->set('geoflow.ai_quality_sampled_fallback_seconds', 45);
        config()->set('geoflow.ai_quality_persistence_reserve_seconds', 10);
        $article = $this->createQualityFixture('sampled-deadlines', needReview: false);
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);

        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article->fresh(), dispatch: false);

        $this->assertSame('full', $check->inspection_scope);
        $this->assertSame('chunk', $check->requested_retrieval_mode);
        $this->assertNull($check->effective_retrieval_mode);
        $this->assertSame('chunk-evidence-1.1.0', $check->retrieval_strategy_version);
        $this->assertSame(64, strlen((string) $check->retrieval_basis_hash));
        $this->assertSame($check->retrieval_basis_hash, data_get($check->execution_meta, 'retrieval_basis.hash'));
        $this->assertEquals(180, $check->created_at->diffInSeconds($check->primary_deadline_at));
        $this->assertEquals(235, $check->created_at->diffInSeconds($check->deadline_at));
        $this->assertTrue((bool) data_get($check->execution_meta, 'policy_snapshot.timeout_sampling_enabled'));
        $this->assertFalse((bool) data_get($check->execution_meta, 'policy_snapshot.manual_review_required'));
        $this->assertSame(
            'article-quality-sampling-1.1.0',
            data_get($check->execution_meta, 'policy_snapshot.sampling_algorithm_version'),
        );
        $this->assertSame(
            'article-quality-principles-2.1.0',
            data_get($check->execution_meta, 'principle_snapshot.version'),
        );
        $this->assertNotEmpty(data_get($check->execution_meta, 'principle_snapshot.advertising_rules_hash'));
        $this->assertNotEmpty(data_get($check->execution_meta, 'principle_snapshot.selected_rule_ids'));

        $article->task()->update([
            'ai_quality_timeout_sampling_enabled' => false,
            'need_review' => true,
        ]);
        $this->assertTrue((bool) data_get($check->fresh()->execution_meta, 'policy_snapshot.timeout_sampling_enabled'));
        $this->assertFalse((bool) data_get($check->fresh()->execution_meta, 'policy_snapshot.manual_review_required'));
    }

    public function test_a_performance_failure_enters_sampled_fallback_once_and_cancels_only_unfinished_segments(): void
    {
        Queue::fake();
        $article = $this->createQualityFixture('sampled-cas', needReview: false);
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);
        Article::withoutEvents(function () use ($article): void {
            $article->forceFill(['content' => str_repeat('用于抽样状态机的正文。', 2200)])->save();
        });
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $firstSegment = $check->segments()->orderBy('segment_index')->firstOrFail();
        $firstSegment->forceFill([
            'status' => 'completed',
            'validated_result' => [
                'summary' => '已完成分段。',
                'promotion_context' => 'informational',
                'knowledge_coverage' => 'sufficient',
                'issues' => [],
                'uncertainties' => [],
            ],
            'finished_at' => now(),
        ])->save();
        $check->forceFill(['completed_segment_count' => 1])->save();

        $transitioned = $service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
            dispatch: false,
        );
        $secondTransition = $service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
            dispatch: false,
        );

        $sampled = $check->fresh();
        $this->assertTrue($transitioned);
        $this->assertFalse($secondTransition);
        $this->assertSame('fallback_sampled', $sampled->inspection_scope);
        $this->assertSame('queued', $sampled->status);
        $this->assertNull($sampled->decision);
        $this->assertSame('provider_timeout', $sampled->fallback_trigger_code);
        $this->assertNotNull($sampled->sampled_deadline_at);
        $this->assertLessThanOrEqual(55, now()->diffInSeconds($sampled->sampled_deadline_at, false));
        $this->assertSame('sampling_queued', data_get($sampled->execution_meta, 'current_phase'));
        $this->assertNotNull(data_get($sampled->execution_meta, 'fallback.started_at'));
        $this->assertSame('completed', $firstSegment->fresh()->status);
        $this->assertSame(
            $sampled->segment_count - 1,
            $sampled->segments()->where('status', 'cancelled')->count(),
        );
        Queue::assertNothingPushed();
    }

    public function test_worker_marks_a_check_stale_when_the_frozen_retrieval_source_changes(): void
    {
        $article = $this->createQualityFixture('retrieval-source-stale', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $knowledgeBase = $article->task->knowledgeBases()->firstOrFail();
        $knowledgeBase->forceFill(['content' => '服务客户已经更新为 1200 家。'])->save();

        $stale = $service->process($check);

        $this->assertSame('stale', $stale->status);
        $this->assertSame('ai_quality_retrieval_source_stale', $stale->error_code);
        $this->assertSame('ai_quality_retrieval_source_stale', $stale->retrieval_failure_code);
        $this->assertNull($stale->effective_retrieval_mode);
        $this->assertSame('pending', $article->fresh()->review_status);
    }

    public function test_configuration_and_capacity_failures_never_enter_sampled_fallback(): void
    {
        $article = $this->createQualityFixture('sampled-denylist', needReview: false);
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);

        $this->assertFalse($service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('provider_quota_exhausted', false),
            dispatch: false,
        ));
        $this->assertSame('full', $check->fresh()->inspection_scope);
        $this->assertSame('queued', $check->fresh()->status);
    }

    public function test_current_task_setting_can_stop_an_existing_full_check_from_entering_sampling(): void
    {
        $article = $this->createQualityFixture('sampled-current-policy-fence', needReview: false);
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);

        $article->task()->update(['ai_quality_timeout_sampling_enabled' => false]);

        $this->assertFalse($service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
            dispatch: false,
        ));
        $this->assertSame('full', $check->fresh()->inspection_scope);
        $this->assertSame('queued', $check->fresh()->status);
    }

    public function test_backend_result_validation_failures_are_reported_as_invalid_model_output(): void
    {
        $article = $this->createQualityFixture('invalid-result-contract', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);

        $service->markFailed($check, new UnexpectedValueException('ai_quality_issue_value_invalid'));

        $failed = $check->fresh();
        $this->assertSame('failed', $failed->status);
        $this->assertSame('invalid_model_output', $failed->error_code);
        $this->assertSame('invalid_model_output', data_get($failed->execution_meta, 'failure.code'));
        $this->assertFalse((bool) data_get($failed->execution_meta, 'failure.retryable'));
        $this->assertNull($failed->score);
    }

    public function test_single_model_invalid_output_is_retried_once_within_the_same_check(): void
    {
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            public int $calls = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls++;

                return [
                    'result' => $this->calls === 1
                        ? [
                            'summary' => '首次结果包含无效问题枚举。',
                            'promotion_context' => 'informational',
                            'knowledge_coverage' => 'sufficient',
                            'issues' => [[
                                'code' => 'unsupported_fact_type',
                                'severity' => 'medium',
                                'field' => 'content',
                                'quote' => '测试正文。',
                                'paragraph_index' => 0,
                                'heading' => '',
                                'fact_candidate_id' => '',
                                'article_claim' => '',
                                'evidence_value' => '',
                                'knowledge_refs' => [],
                                'legal_refs' => [],
                                'reason' => '枚举无效。',
                                'suggestion' => '重试。',
                            ]],
                            'uncertainties' => [],
                        ]
                        : [
                            'summary' => '重试后质检通过。',
                            'promotion_context' => 'informational',
                            'knowledge_coverage' => 'sufficient',
                            'issues' => [],
                            'uncertainties' => [],
                        ],
                    'usage' => ['totalTokens' => 20],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $article = $this->createQualityFixture('same-model-invalid-output-retry', needReview: true);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);

        $completed = $service->process((int) $check->id);

        $this->assertSame(2, $reviewer->calls);
        $this->assertSame('completed', $completed->status);
        $this->assertSame('chunk', $completed->effective_retrieval_mode);
        $this->assertSame('passed', $completed->decision);
    }

    public function test_a_late_full_job_failure_cannot_overwrite_the_new_sampled_phase(): void
    {
        $article = $this->createQualityFixture('sampled-phase-fence', needReview: false);
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $this->assertTrue($service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
            dispatch: false,
        ));

        (new ProcessArticleAiQualityJob((int) $check->id, 'full'))->failed(
            new \RuntimeException('late_full_worker_failure'),
        );

        $sampled = $check->fresh();
        $this->assertSame('fallback_sampled', $sampled->inspection_scope);
        $this->assertSame('queued', $sampled->status);
        $this->assertNull($sampled->error_code);
    }

    public function test_sampled_fallback_completes_with_coverage_and_a_distinct_passed_scope(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('sampled-completion', needReview: true);
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);
        Article::withoutEvents(function () use ($article): void {
            $article->forceFill([
                'content' => implode("\n\n", [
                    '开头说明服务客户为 800 家。',
                    str_repeat('前部背景说明。', 300),
                    '中部说明产品适用边界与数据来源。',
                    str_repeat('中部背景说明。', 300),
                    '结论提示读者结合实际场景判断。',
                ]),
            ])->save();
        });
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $completedSegment = $check->segments()->orderBy('segment_index')->firstOrFail();
        $completedSegment->forceFill([
            'status' => 'completed',
            'validated_result' => [
                'summary' => '文章缺少 AI 生成内容标识，需要人工确认。',
                'promotion_context' => 'informational',
                'knowledge_coverage' => 'sufficient',
                'issues' => [[
                    'code' => 'ai_generated_disclosure',
                    'severity' => 'high',
                    'field' => 'content',
                    'quote' => '开头说明',
                ]],
                'uncertainties' => [[
                    'claim' => 'AI 生成内容标识状态',
                    'materiality' => 'high',
                    'reason' => '无法确认是否已声明 AI 生成',
                    'needed_evidence' => '提供发布元数据标识',
                ]],
                'truncated_issue_count' => 0,
            ],
            'finished_at' => now(),
        ])->save();
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        $executionMeta['segment_runs'] = [
            '0' => [
                'usage' => ['prompt_tokens' => 11, 'completion_tokens' => 7, 'total_tokens' => 18],
            ],
        ];
        $check->forceFill([
            'completed_segment_count' => 1,
            'execution_meta' => $executionMeta,
        ])->save();
        $this->assertTrue($service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('inspection_primary_deadline_exceeded', false),
            dispatch: false,
        ));

        $completed = $service->process($check->id);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('passed', $completed->decision);
        $this->assertSame('fallback_sampled', $completed->inspection_scope);
        $this->assertNotNull($completed->score);
        $this->assertGreaterThan(0, (int) data_get($completed->coverage_meta, 'checked_chars'));
        $this->assertSame(
            ['front', 'middle', 'back'],
            data_get($completed->coverage_meta, 'regions_covered'),
        );
        $this->assertSame(
            data_get($completed->coverage_meta, 'mandatory_claims_total'),
            data_get($completed->coverage_meta, 'mandatory_claims_covered'),
        );
        $this->assertSame(18, data_get($completed->usage_meta, 'primary_review.total_tokens'));
        $this->assertSame(0, data_get($completed->usage_meta, 'atomic_verification.total_tokens'));
        $this->assertArrayNotHasKey('knowledge_fallback', $completed->usage_meta);
    }

    public function test_sampled_fallback_reextracts_all_high_risk_claims_before_deciding_coverage(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('sampled-all-claims', needReview: true);
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);
        $parts = [];
        for ($index = 1; $index <= 13; $index++) {
            $parts[] = "第 {$index} 项审计数据显示，客户增长率达到 {$index}%。";
            $parts[] = str_repeat('普通背景说明。', 90);
        }
        Article::withoutEvents(function () use ($article, $parts): void {
            $article->forceFill(['content' => implode("\n\n", $parts)])->save();
        });

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $limitedFacts = app(ArticleFactCandidateExtractor::class)->extract(
            is_array($check->article_snapshot) ? $check->article_snapshot : [],
        );
        $this->assertCount(12, $limitedFacts);
        $check->forceFill([
            'fact_candidates_snapshot' => $limitedFacts,
            'evidence_snapshot' => [],
            'knowledge_coverage' => 'sufficient',
        ])->save();
        $this->assertTrue($service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('inspection_primary_deadline_exceeded', false),
            dispatch: false,
        ));

        $completed = $service->process((int) $check->id);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('needs_review', $completed->decision);
        $this->assertGreaterThan(12, (int) data_get($completed->coverage_meta, 'mandatory_claims_total'));
        $this->assertTrue((bool) data_get($completed->coverage_meta, 'mandatory_overflow'));
    }

    public function test_new_checks_persist_an_immutable_end_to_end_deadline(): void
    {
        config()->set('geoflow.ai_quality_deadline_seconds', 60);
        $article = $this->createQualityFixture('immutable-deadline', needReview: false);

        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $originalDeadline = $check->deadline_at?->toISOString();

        config()->set('geoflow.ai_quality_deadline_seconds', 45);

        $this->assertNotNull($originalDeadline);
        $this->assertSame($originalDeadline, $check->fresh()->deadline_at?->toISOString());
        $this->assertEquals(60, $check->created_at->diffInSeconds($check->deadline_at));
    }

    public function test_expired_queued_checks_converge_without_a_queue_consumer_or_model_call(): void
    {
        Queue::fake();
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            public int $calls = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls++;

                return [];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $article = $this->createQualityFixture('queued-convergence', needReview: false);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill(['deadline_at' => now()->subSecond()])->save();

        $first = app(ArticleAiQualityReconciliationService::class)->convergeExpired();
        $second = app(ArticleAiQualityReconciliationService::class)->convergeExpired();

        $this->assertSame(1, $first['expired']);
        $this->assertSame(0, $second['expired']);
        $this->assertSame(0, $reviewer->calls);
        $this->assertSame('failed', $check->fresh()->status);
        $this->assertSame('queue_worker_unavailable', $check->fresh()->error_code);
        $this->assertNull($check->fresh()->active_dedupe_key);
        Queue::assertNothingPushed();
    }

    public function test_reconciliation_switches_an_authorized_primary_deadline_to_sampling_before_the_final_deadline(): void
    {
        Queue::fake();
        $article = $this->createQualityFixture('primary-convergence', needReview: false);
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article->fresh(), dispatch: false);
        $check->forceFill([
            'primary_deadline_at' => now()->subSecond(),
            'deadline_at' => now()->addSeconds(45),
        ])->save();

        $result = app(ArticleAiQualityReconciliationService::class)->convergeExpired();

        $this->assertSame(1, $result['degraded']);
        $this->assertSame(0, $result['expired']);
        $this->assertSame('fallback_sampled', $check->fresh()->inspection_scope);
        $this->assertSame('queued', $check->fresh()->status);
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_expired_running_checks_converge_as_interrupted_when_the_worker_is_missing(): void
    {
        $article = $this->createQualityFixture('running-convergence', needReview: false);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'running',
            'started_at' => now()->subSeconds(30),
            'deadline_at' => now()->subSecond(),
        ])->save();

        app(ArticleAiQualityReconciliationService::class)->convergeExpired();

        $this->assertSame('failed', $check->fresh()->status);
        $this->assertSame('worker_interrupted', $check->fresh()->error_code);
    }

    public function test_reconciliation_does_not_recover_a_running_provider_call_before_its_request_budget(): void
    {
        Queue::fake();
        config()->set('geoflow.ai_quality_request_timeout_seconds', 160);
        $article = $this->createQualityFixture('running-request-budget', needReview: false);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'running',
            'started_at' => now()->subSeconds(90),
            'primary_deadline_at' => now()->addSeconds(100),
            'deadline_at' => now()->addSeconds(100),
        ])->save();
        $check->newQuery()->whereKey($check->id)->update(['updated_at' => now()->subSeconds(90)]);

        $first = app(ArticleAiQualityReconciliationService::class)->convergeExpired();

        $this->assertSame(0, $first['recovered']);
        $this->assertSame('running', $check->fresh()->status);
        Queue::assertNothingPushed();

        $check->newQuery()->whereKey($check->id)->update(['updated_at' => now()->subSeconds(166)]);
        $second = app(ArticleAiQualityReconciliationService::class)->convergeExpired();

        $this->assertSame(1, $second['recovered']);
        $this->assertSame('queued', $check->fresh()->status);
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_a_passing_async_check_preserves_an_explicit_manual_rejection(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('manual-rejection', needReview: false);
        $article->forceFill(['review_status' => 'rejected'])->save();

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $completed = $service->process($check);

        $this->assertSame('passed', $completed->decision);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame('rejected', $article->fresh()->review_status);
    }

    public function test_long_articles_continue_one_segment_per_job_until_complete(): void
    {
        Queue::fake();
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('segmented-continuation', needReview: false);
        Article::withoutEvents(function () use ($article): void {
            $article->forceFill(['content' => str_repeat('分段质检内容。', 2200)])->save();
        });

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);

        $firstPass = $service->process($check);

        $this->assertSame('queued', $firstPass->status);
        $this->assertGreaterThan(1, $firstPass->segment_count);
        $this->assertSame(1, $firstPass->segments()->where('status', 'completed')->count());
        Queue::assertPushed(ProcessArticleAiQualityJob::class, fn (ProcessArticleAiQualityJob $job): bool => $job->delay !== null);

        $result = $firstPass;
        for ($attempt = 0; $attempt < 10 && $result->status !== 'completed'; $attempt++) {
            $result = $service->process((int) $result->id);
        }

        $this->assertSame('completed', $result->status);
        $this->assertSame($result->segment_count, $result->segments()->where('status', 'completed')->count());
        $this->assertSame(245, (new ProcessArticleAiQualityJob((int) $result->id))->timeout);
        $this->assertTrue((new ProcessArticleAiQualityJob((int) $result->id))->failOnTimeout);
    }

    public function test_resumed_atomic_check_recovers_atomic_results_from_persisted_execution_metadata(): void
    {
        $check = new ArticleAiQualityCheck([
            'requested_retrieval_mode' => 'atomic_first',
            'execution_meta' => [
                'retrieval' => [
                    'atomic_facts' => [
                        'issues' => [['code' => 'knowledge_contradiction', 'severity' => 'critical']],
                        'contradicted_count' => 1,
                    ],
                ],
            ],
        ]);
        $service = app(ArticleAiQualityInspectionService::class);
        $recover = \Closure::bind(
            fn (): array => $service->atomicFactsFromRetrievalResult($check, [], [], []),
            $service,
            ArticleAiQualityInspectionService::class,
        );

        $result = $recover();

        $this->assertTrue($result['formal']);
        $this->assertSame('atomic_first', $result['mode']);
        $this->assertSame('knowledge_contradiction', data_get($result, 'inspection.issues.0.code'));
    }

    public function test_chunk_checks_ignore_the_formal_atomic_rollout_track(): void
    {
        $this->setQualityRollout(atomicFact: 100);
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('chunk-with-formal-atomic-rollout', needReview: true);
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article, dispatch: false));

        $this->assertSame('completed', $completed->status);
        $this->assertSame('chunk', $completed->effective_retrieval_mode);
        $this->assertSame('disabled', data_get($completed->execution_meta, 'atomic_facts.mode'));
        $this->assertFalse((bool) data_get($completed->execution_meta, 'atomic_facts.formal'));
        $this->assertSame(['chunk'], $completed->sources()->pluck('dependency_kind')->unique()->values()->all());
        $this->assertSame(['chunk'], $completed->sources()->whereNotNull('used_at')->pluck('used_provider')->unique()->values()->all());
    }

    public function test_knowledge_broad_checks_ignore_the_formal_atomic_rollout_track(): void
    {
        $this->setQualityRollout(atomicFact: 100);
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('broad-with-formal-atomic-rollout', needReview: true);
        $article->task()->update(['ai_quality_retrieval_mode' => 'knowledge_broad']);
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article->fresh(), dispatch: false));

        $this->assertSame('completed', $completed->status);
        $this->assertSame('knowledge_broad', $completed->effective_retrieval_mode);
        $this->assertSame('disabled', data_get($completed->execution_meta, 'atomic_facts.mode'));
        $this->assertFalse((bool) data_get($completed->execution_meta, 'atomic_facts.formal'));
        $this->assertSame(['raw_content'], $completed->sources()->pluck('dependency_kind')->unique()->values()->all());
        $this->assertSame(['knowledge_broad'], $completed->sources()->whereNotNull('used_at')->pluck('used_provider')->unique()->values()->all());
    }

    public function test_knowledge_broad_marks_only_the_knowledge_bases_it_actually_reads(): void
    {
        config()->set('geoflow.ai_quality_max_evidence', 1);
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('broad-source-ledger', needReview: true);
        $secondBase = KnowledgeBase::query()->create([
            'name' => '未读取的第二知识库',
            'content' => '第二知识库中的事实。',
            'review_status' => 'reviewed',
            'chunk_sync_status' => 'completed',
            'chunk_source_hash' => 'second-source-ledger',
        ]);
        $article->task->knowledgeBases()->attach($secondBase->id, ['sort_order' => 1]);
        $article->task()->update(['ai_quality_retrieval_mode' => 'knowledge_broad']);
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article->fresh(), dispatch: false));
        $usedSources = $completed->sources()->whereNotNull('used_at')->get();

        $this->assertSame('completed', $completed->status);
        $this->assertCount(2, $completed->sources);
        $this->assertCount(1, $usedSources);
        $this->assertSame(
            (int) data_get($completed->evidence_snapshot, '0.knowledge_base_id'),
            (int) $usedSources->first()->knowledge_base_id,
        );
    }

    public function test_chunk_shadow_atomic_inspection_runs_once(): void
    {
        $this->setQualityRollout(atomicShadow: 100);
        $inspector = Mockery::mock(ArticleAtomicFactInspector::class);
        $inspector->shouldReceive('inspect')->once()->andReturn([
            'mode' => 'knowledge_fallback',
            'algorithm_version' => ArticleAtomicFactInspector::ALGORITHM_VERSION,
            'results' => [],
            'issues' => [],
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
        ]);
        $this->app->instance(ArticleAtomicFactInspector::class, $inspector);
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('chunk-single-shadow-inspection', needReview: true);
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article, dispatch: false));

        $this->assertSame('completed', $completed->status);
        $this->assertSame('shadow', data_get($completed->execution_meta, 'atomic_facts.mode'));
    }

    public function test_effective_mode_remains_empty_when_retrieval_never_completes(): void
    {
        $retrieval = Mockery::mock(KnowledgeRetrievalService::class);
        $retrieval->shouldReceive('retrieveEvidenceFromMany')
            ->andThrow(new \RuntimeException('retrieval unavailable'));
        $this->app->instance(KnowledgeRetrievalService::class, $retrieval);
        $article = $this->createQualityFixture('retrieval-never-completes', needReview: true);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);

        try {
            $service->process($check);
            $this->fail('Expected evidence retrieval to fail.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('evidence_retrieval_failed', $exception->safeCode());
        }

        $this->assertNull($check->fresh()->effective_retrieval_mode);
    }

    public function test_invalid_retrieval_contract_is_not_retryable(): void
    {
        $article = $this->createQualityFixture('invalid-retrieval-contract', needReview: true);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $retrieval = Mockery::mock(ArticleAiQualityRetrievalCoordinator::class);
        $retrieval->shouldReceive('strategyVersion')->andReturn('chunk-evidence-1.1.0');
        $retrieval->shouldReceive('retrieve')->once()->andThrow(
            new InvalidArgumentException('ai_quality_retrieval_evidence_contract_invalid'),
        );
        $this->app->instance(ArticleAiQualityRetrievalCoordinator::class, $retrieval);

        try {
            app(ArticleAiQualityInspectionService::class)->process($check);
            $this->fail('Expected the invalid retrieval contract to fail.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('evidence_retrieval_failed', $exception->safeCode());
            $this->assertFalse($exception->retryable());
        }
    }

    public function test_a_stale_check_terminalizes_its_segments_and_holds_the_article_for_review(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('stale-segment', needReview: false);
        $article->forceFill(['review_status' => 'approved'])->save();

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        Article::withoutEvents(function () use ($article): void {
            $article->forceFill(['content' => '质检排队后发生变化的正文。'])->save();
        });

        $stale = $service->process($check);

        $this->assertSame('stale', $stale->status);
        $this->assertSame('stale', $stale->segments->first()->status);
        $this->assertSame('input_changed', $stale->segments->first()->error_code);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame('pending', $article->fresh()->review_status);
    }

    public function test_smart_failover_uses_the_next_active_model_and_records_a_sanitized_attempt_trace(): void
    {
        $reviewer = new class implements DeadlineAwareArticleAiQualityReviewer
        {
            /** @var list<int> */
            public array $modelIds = [];

            public int $failingModelId = 0;

            /** @var list<int> */
            public array $timeouts = [];

            public function review(AiModel $model, string $instructions): array
            {
                return $this->reviewWithin($model, $instructions, 999);
            }

            public function reviewWithin(AiModel $model, string $instructions, int $timeoutSeconds): array
            {
                $this->modelIds[] = (int) $model->id;
                $this->timeouts[] = $timeoutSeconds;
                if ((int) $model->id === $this->failingModelId) {
                    throw new \RuntimeException('temporary primary model timeout');
                }

                return [
                    'result' => [
                        'summary' => '备用模型完成质检。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => ['totalTokens' => 30],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $article = $this->createQualityFixture('smart-failover', needReview: true);
        $primaryModelId = (int) $article->task()->value('ai_model_id');
        $reviewer->failingModelId = $primaryModelId;
        AiModel::query()->create([
            'name' => '其他数据边界模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'untrusted-fallback-model',
            'api_url' => 'https://external-provider.example.test',
            'status' => 'active',
            'model_type' => 'chat',
            'failover_priority' => 0,
        ]);
        $fallback = AiModel::query()->create([
            'name' => '质检备用模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-fallback-model',
            'api_url' => 'https://example.test/v1',
            'status' => 'active',
            'model_type' => 'chat',
            'failover_priority' => 1,
        ]);
        $article->task()->update(['model_selection_mode' => 'smart_failover']);

        $service = app(ArticleAiQualityInspectionService::class);
        $completed = $service->process($service->createOrReuse($article->fresh(), dispatch: false));

        $this->assertSame('passed', $completed->decision);
        $this->assertSame([$primaryModelId, (int) $fallback->id], $reviewer->modelIds);
        $this->assertSame((int) $fallback->id, (int) $completed->ai_model_id);
        $this->assertSame((int) $fallback->id, (int) $completed->model_snapshot['id']);
        $this->assertSame([$primaryModelId, (int) $fallback->id], $completed->execution_meta['model_candidate_ids']);
        $this->assertSame('failed', $completed->execution_meta['model_attempts'][0]['outcome']);
        $this->assertSame('succeeded', $completed->execution_meta['model_attempts'][1]['outcome']);
        $this->assertArrayHasKey('provider', $completed->execution_meta['model_attempts'][1]);
        $this->assertArrayHasKey('duration_ms', $completed->execution_meta['model_attempts'][1]);
        $this->assertArrayNotHasKey('api_key', $completed->execution_meta['model_attempts'][0]);
        $this->assertCount(2, $reviewer->timeouts);
        $this->assertGreaterThanOrEqual(100, $reviewer->timeouts[0]);
        $this->assertLessThanOrEqual(110, $reviewer->timeouts[0]);
        $this->assertGreaterThanOrEqual(150, $reviewer->timeouts[1]);
        $this->assertLessThanOrEqual(160, $reviewer->timeouts[1]);
    }

    public function test_execution_bound_quality_check_uses_requested_then_personal_then_shared_candidates_only(): void
    {
        config()->set('geoflow.ai_quality_max_model_candidates', 3);
        $provider = $this->qualityAdmin('quality-provider', 'super_admin');
        $executor = $this->qualityAdmin('quality-executor', 'admin', $provider);
        $peer = $this->qualityAdmin('quality-peer', 'admin');
        $article = $this->createQualityFixture('execution-candidates', needReview: true);
        $primary = $article->task->aiModel;
        $primary->forceFill([
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
            'failover_priority' => 50,
        ])->save();
        $personal = $this->qualityModel($executor, 'quality-personal-fallback', 1);
        $shared = $this->qualityModel($provider, 'quality-shared-fallback', 0);
        $this->qualityModel($peer, 'quality-peer-model', 0);
        $this->qualityModel($provider, 'quality-system-model', 0, AiModel::ACCESS_SCOPE_SYSTEM_ONLY);
        $archived = $this->qualityModel($executor, 'quality-archived-model', 0);
        $archived->forceFill(['archived_at' => now()])->save();
        $article->task->forceFill([
            'model_selection_mode' => 'smart_failover',
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => 1,
        ])->save();

        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse(
            $article->fresh(),
            dispatch: false,
        );

        $this->assertSame(
            [$primary->id, $personal->id, $shared->id],
            data_get($check->execution_meta, 'model_candidate_ids'),
        );
        $this->assertSame($executor->id, data_get($check->execution_meta, 'ai_execution.model_access_admin_id'));
        $this->assertSame(
            [$primary->id, $personal->id, $shared->id],
            data_get($check->execution_meta, 'ai_execution.model_candidate_ids'),
        );
    }

    public function test_full_quality_fails_closed_without_execution_identity_when_enforcement_flags_are_disabled(): void
    {
        $article = $this->createQualityFixture('historical-missing-execution-identity', needReview: true);
        ArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        config()->set('geoflow.admin_ai_access.shadow_enabled', true);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', false);
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', false);

        $this->assertNull(data_get($check->execution_meta, 'ai_execution'));

        $processed = $service->process($check);

        $this->assertSame('stale', $processed->status);
        $this->assertSame('ai_config_access_revoked', $processed->error_code);
        ArticleQualityReviewerAgent::assertNeverPrompted();
        LegacyArticleQualityReviewerAgent::assertNeverPrompted();
        $this->assertDatabaseCount('ai_model_usage_events', 0);
    }

    public function test_enforced_worker_never_rebuilds_global_candidates_when_the_frozen_candidate_list_is_empty(): void
    {
        $executor = $this->qualityAdmin('quality-empty-candidates', 'admin');
        $article = $this->createQualityFixture('empty-frozen-candidates', needReview: true);
        $model = $article->task->aiModel;
        $model->forceFill([
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => 1,
        ])->save();
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            public int $calls = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls++;

                throw new UnexpectedValueException('Frozen candidates must be required.');
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $executionMeta = (array) $check->execution_meta;
        $executionMeta['model_candidate_ids'] = [];
        $executionMeta['ai_execution'] = array_replace((array) $executionMeta['ai_execution'], [
            'model_candidate_ids' => [],
        ]);
        $check->forceFill(['execution_meta' => $executionMeta])->save();
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', true);

        $processed = $service->process($check->fresh());

        $this->assertSame('stale', $processed->status);
        $this->assertSame('ai_config_access_revoked', $processed->error_code);
        $this->assertSame(0, $reviewer->calls);
    }

    public function test_execution_access_revoked_after_provider_response_discards_quality_result(): void
    {
        $executor = $this->qualityAdmin('quality-late-revocation', 'admin');
        $article = $this->createQualityFixture('late-access-revocation', needReview: true);
        $model = $article->task->aiModel;
        $model->forceFill([
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => 1,
        ])->save();
        $reviewer = new class((int) $executor->id) implements ArticleAiQualityReviewer
        {
            public int $calls = 0;

            public function __construct(private readonly int $adminId) {}

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls++;
                Admin::query()->whereKey($this->adminId)->increment('ai_config_access_version');

                return [
                    'result' => [
                        'summary' => '该结果应因撤权而丢弃。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);

        try {
            $service->process($check);
            $this->fail('Expected the quality execution access snapshot to be revoked.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getErrorCode());
        }

        $this->assertSame(1, $reviewer->calls);
        $this->assertSame(0, (int) $check->fresh()->completed_segment_count);
        $this->assertNull($check->fresh()->raw_model_output);
        $this->assertNull($check->segments()->firstOrFail()->validated_result);
    }

    public function test_sampled_fallback_rechecks_the_frozen_execution_identity_after_provider_response(): void
    {
        $executor = $this->qualityAdmin('quality-sampled-revocation', 'admin');
        $article = $this->createQualityFixture('sampled-access-revocation', needReview: true);
        $model = $article->task->aiModel;
        $model->forceFill([
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => 1,
            'ai_quality_timeout_sampling_enabled' => true,
        ])->save();
        $reviewer = new class((int) $executor->id) implements ArticleAiQualityReviewer
        {
            public int $calls = 0;

            public function __construct(private readonly int $adminId) {}

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls++;
                Admin::query()->whereKey($this->adminId)->increment('ai_config_access_version');

                return [
                    'result' => [
                        'summary' => '撤权后的抽样结果必须丢弃。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $this->assertTrue($service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
            dispatch: false,
        ));

        try {
            $service->process($check->fresh());
            $this->fail('Expected sampled execution access to be revoked.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame('ai_config_access_revoked', $exception->getErrorCode());
        }

        $sampled = $check->fresh();
        $this->assertSame(1, $reviewer->calls);
        $this->assertSame('running', $sampled->status);
        $this->assertNull($sampled->raw_model_output);
        $this->assertNull($sampled->decision);
    }

    #[DataProvider('sampledOutboundRevocationProvider')]
    public function test_sampled_fallback_reloads_execution_access_immediately_before_outbound(
        string $mutation,
        string $expectedCode,
    ): void {
        $provider = $this->qualityAdmin('sampled-boundary-provider-'.$mutation, 'super_admin');
        $executor = $this->qualityAdmin('sampled-boundary-executor-'.$mutation, 'admin', $provider);
        $article = $this->createQualityFixture('sampled-boundary-'.$mutation, needReview: true);
        $model = $article->task->aiModel;
        $model->forceFill([
            'owner_admin_id' => $provider->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $article->task->forceFill([
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => 1,
            'ai_quality_timeout_sampling_enabled' => true,
        ])->save();
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            public int $calls = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls++;

                throw new UnexpectedValueException('Revoked sampled execution must not reach the provider.');
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $this->app->instance(
            ArticleAiQualityExecutionBoundaryHook::class,
            new class($mutation, (int) $executor->id) extends ArticleAiQualityExecutionBoundaryHook
            {
                public function __construct(
                    private readonly string $mutation,
                    private readonly int $adminId,
                ) {}

                public function beforeSampledOutbound(ArticleAiQualityCheck $check, AiModel $model): void
                {
                    $admin = Admin::query()->findOrFail($this->adminId);
                    match ($this->mutation) {
                        'access_version' => $admin->forceFill([
                            'ai_config_access_version' => (int) $admin->ai_config_access_version + 1,
                        ])->save(),
                        'inactive' => $admin->forceFill(['status' => 'inactive'])->save(),
                        'role_changed' => $admin->forceFill(['role' => 'super_admin'])->save(),
                        'shared_revoked' => $admin->forceFill(['shared_ai_config_owner_id' => null])->save(),
                        default => throw new UnexpectedValueException('Unknown revocation mutation.'),
                    };
                }
            },
        );
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $this->assertTrue($service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
            dispatch: false,
        ));

        try {
            $service->process($check->fresh());
            $this->fail('Expected sampled access to be revoked before provider execution.');
        } catch (AiModelAccessException $exception) {
            $this->assertSame($expectedCode, $exception->getErrorCode());
        }

        $this->assertSame(0, $reviewer->calls);
        $this->assertNull($check->fresh()->raw_model_output);
        $this->assertNull($check->fresh()->decision);
    }

    /** @return iterable<string,array{string,string}> */
    public static function sampledOutboundRevocationProvider(): iterable
    {
        yield 'access version changed' => ['access_version', 'ai_config_access_revoked'];
        yield 'administrator inactive' => ['inactive', 'ai_execution_admin_inactive'];
        yield 'administrator role changed' => ['role_changed', 'ai_config_access_revoked'];
        yield 'shared provider revoked' => ['shared_revoked', 'ai_model_not_accessible'];
    }

    public function test_sampled_quality_fails_closed_without_execution_identity_when_enforcement_flags_are_disabled(): void
    {
        $executionAdmin = $this->qualityAdmin('sampled-missing-execution-identity', 'super_admin');
        $article = $this->createQualityFixture('sampled-missing-execution-identity', needReview: true);
        $article->task->aiModel->forceFill([
            'owner_admin_id' => $executionAdmin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
        ])->save();
        $article->task->forceFill([
            'ai_quality_timeout_sampling_enabled' => true,
            'model_access_admin_id' => $executionAdmin->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 1,
        ])->save();
        ArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $this->assertTrue($service->tryStartSampledFallback(
            $check,
            new ArticleAiQualityRuntimeException('provider_timeout', true),
            dispatch: false,
        ));
        $executionMeta = (array) $check->fresh()->execution_meta;
        unset($executionMeta['ai_execution']);
        $check->forceFill(['execution_meta' => $executionMeta])->save();
        config()->set('geoflow.admin_ai_access.shadow_enabled', true);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', false);
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', false);

        $this->assertNull(data_get($check->execution_meta, 'ai_execution'));

        $processed = $service->process($check->fresh());

        $this->assertSame('stale', $processed->status);
        $this->assertSame('ai_config_access_revoked', $processed->error_code);
        ArticleQualityReviewerAgent::assertNeverPrompted();
        LegacyArticleQualityReviewerAgent::assertNeverPrompted();
        $this->assertDatabaseCount('ai_model_usage_events', 0);
    }

    public function test_historical_quality_check_with_a_backfilled_legacy_identity_executes_and_records_usage(): void
    {
        $this->setQualityRollout(execution: 100);
        config()->set('geoflow.admin_ai_access.shadow_enabled', true);
        config()->set('geoflow.admin_ai_access.access_enforce_enabled', false);
        config()->set('geoflow.admin_ai_access.revocation_enforce_enabled', false);
        $legacyAdmin = $this->qualityAdmin('quality-backfilled-legacy', 'super_admin');
        $article = $this->createQualityFixture('backfilled-legacy-identity', needReview: true);
        $task = $article->task;
        $model = $task->aiModel;
        $model->forceFill([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('quality-backfilled-secret'),
            'owner_admin_id' => $legacyAdmin->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'model_type' => 'chat',
        ])->save();
        ArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        LegacyArticleQualityReviewerAgent::fake([$this->passingV2QualityResult()])->preventStrayPrompts();
        $service = app(ArticleAiQualityInspectionService::class);
        $task->forceFill([
            'model_access_admin_id' => null,
            'model_access_admin_role' => null,
            'model_access_policy_version' => null,
        ])->save();
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $this->assertNull(data_get($check->execution_meta, 'ai_execution'));

        $task->forceFill([
            'model_access_admin_id' => $legacyAdmin->id,
            'model_access_admin_role' => 'super_admin',
            'model_access_policy_version' => 1,
        ])->save();
        $executionMeta = (array) $check->execution_meta;
        $executionMeta['model_candidate_ids'] = [(int) $model->id];
        $executionMeta['ai_execution'] = [
            'model_access_admin_id' => (int) $legacyAdmin->id,
            'model_access_admin_role' => 'super_admin',
            'ai_config_access_version' => (int) $legacyAdmin->ai_config_access_version,
            'resolver_policy_version' => 1,
            'requested_model_id' => (int) $model->id,
            'source_type' => 'task',
            'source_id' => (int) $task->id,
            'model_candidate_ids' => [(int) $model->id],
        ];
        $check->forceFill(['execution_meta' => $executionMeta])->save();

        $completed = $service->process($check->fresh());

        $event = AiModelUsageEvent::query()->sole();
        $this->assertSame('completed', $completed->status);
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $event->status);
        $this->assertSame($legacyAdmin->id, $event->execution_admin_id);
        $this->assertSame($legacyAdmin->id, $event->config_owner_admin_id);
        $this->assertSame((int) $legacyAdmin->ai_config_access_version, $event->ai_config_access_version);
        $this->assertSame((int) $model->id, $event->ai_model_id);
    }

    public function test_permanent_quality_provider_rejection_does_not_switch_candidates(): void
    {
        config()->set('geoflow.ai_quality_max_model_candidates', 2);
        $executor = $this->qualityAdmin('quality-permanent-rejection', 'admin');
        $article = $this->createQualityFixture('permanent-rejection', needReview: true);
        $primary = $article->task->aiModel;
        $primary->forceFill([
            'owner_admin_id' => $executor->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
        ])->save();
        $fallback = $this->qualityModel($executor, 'quality-unused-permanent-fallback', 1);
        $article->task->forceFill([
            'model_selection_mode' => 'smart_failover',
            'model_access_admin_id' => $executor->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => 1,
        ])->save();
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            /** @var list<int> */
            public array $calls = [];

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls[] = (int) $model->id;

                throw new RequestException(new Response(new PsrResponse(401, [], '{}')));
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $job = new ProcessArticleAiQualityJob((int) $check->id);

        try {
            $job->handle($service);
            $this->fail('Expected the permanent provider rejection to fail the job.');
        } catch (RequestException) {
            // The persisted terminal state is asserted below.
        }

        $this->assertSame([$primary->id], $reviewer->calls);
        $this->assertNotContains($fallback->id, $reviewer->calls);
        $this->assertSame('failed', $check->fresh()->status);
        $this->assertFalse((bool) data_get($check->fresh()->execution_meta, 'retryable_failure'));
    }

    public function test_it_runs_a_check_persists_evidence_and_uses_backend_scoring(): void
    {
        $this->app->bind(ArticleAiQualityReviewer::class, fn () => new class implements ArticleAiQualityReviewer
        {
            public function review(AiModel $model, string $instructions): array
            {
                return [
                    'result' => [
                        'summary' => '数据与知识依据一致。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => ['promptTokens' => 100, 'completionTokens' => 20],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        });

        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $model = AiModel::query()->create([
            'name' => '质检模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '企业资料',
            'content' => '标准价格为 980 元。',
            'review_status' => 'reviewed',
            'chunk_sync_status' => 'completed',
            'chunk_source_hash' => 'kb-source-v1',
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '标准价格为 980 元。',
            'content_hash' => hash('sha256', '标准价格为 980 元。'),
            'source_hash' => 'kb-source-v1',
            'chunk_title' => '价格说明',
            'section_path' => '产品价格',
            'metadata_json' => json_encode(['review_status' => 'reviewed'], JSON_UNESCAPED_UNICODE),
        ]);
        $task = Task::query()->create([
            'name' => '质检任务',
            'ai_model_id' => $model->id,
            'need_review' => 1,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_pass_score' => 85,
            'ai_quality_manual_override_min_score' => 70,
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $category = Category::query()->create(['name' => '质检', 'slug' => 'quality-inspection']);
        $author = Author::query()->create(['name' => '作者']);
        $article = Article::query()->create([
            'title' => '产品价格说明',
            'slug' => 'quality-service-test',
            'content' => '标准价格为 980 元。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $completed = $service->process($check);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('passed', $completed->decision);
        $this->assertSame(100, $completed->score);
        $this->assertSame('sufficient', $completed->knowledge_coverage);
        $this->assertSame('K1', $completed->evidence_snapshot[0]['id']);
        $this->assertSame('sufficient', $completed->fact_candidates_snapshot[0]['coverage_status']);
        $this->assertSame(1, $completed->completed_segment_count);
        $this->assertSame(120, $completed->usage_meta['total_tokens']);
        $this->assertSame(120, data_get($completed->usage_meta, 'primary_review.total_tokens'));
        $this->assertSame(0, data_get($completed->usage_meta, 'atomic_verification.total_tokens'));
        $this->assertArrayNotHasKey('knowledge_fallback', $completed->usage_meta);
        foreach (['queue_wait', 'claim_extraction', 'evidence_retrieval', 'prompt_render', 'model_total', 'validation', 'scoring', 'persistence', 'total'] as $timing) {
            $this->assertArrayHasKey($timing, $completed->execution_meta['timings_ms']);
            $this->assertGreaterThanOrEqual(0, $completed->execution_meta['timings_ms'][$timing]);
        }
        $this->assertNull($completed->active_dedupe_key);
        $this->assertSame('completed', $completed->segments->first()->status);
    }

    public function test_a_job_error_terminalizes_the_check_for_an_explicit_recheck(): void
    {
        $this->app->bind(ArticleAiQualityReviewer::class, fn () => new class implements ArticleAiQualityReviewer
        {
            public function review(AiModel $model, string $instructions): array
            {
                throw new ArticleAiQualityRuntimeException('provider_timeout', true);
            }
        });

        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $model = AiModel::query()->create([
            'name' => '重试质检模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-retry-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '重试质检任务',
            'ai_model_id' => $model->id,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '重试质检知识库',
            'content' => '等待模型质检的正文依据。',
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $category = Category::query()->create(['name' => '重试质检', 'slug' => 'quality-retry']);
        $author = Author::query()->create(['name' => '重试作者']);
        $article = Article::query()->create([
            'title' => '重试质检文章',
            'slug' => 'quality-retry-article',
            'content' => '等待模型质检的正文。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $job = new ProcessArticleAiQualityJob((int) $check->id);

        try {
            $job->handle($service);
            $this->fail('Expected the temporary reviewer failure to be rethrown.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('provider_timeout', $exception->getMessage());
        }

        $check->refresh();
        $this->assertSame('failed', $check->status);
        $this->assertSame('error', $check->decision);
        $this->assertNull($check->active_dedupe_key);
        $this->assertNotNull($check->finished_at);
        $this->assertSame('failed', $check->segments()->firstOrFail()->status);
        $this->assertSame('provider_timeout', $check->segments()->firstOrFail()->error_code);
    }

    public function test_an_interrupted_running_check_can_resume_when_the_queue_redelivers_it(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('resume-running', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'running',
            'started_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ])->save();

        $completed = $service->process($check->id, allowRunningRecovery: true);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('passed', $completed->decision);
    }

    public function test_resumed_check_normalizes_removed_disclosure_artifacts_from_a_completed_segment(): void
    {
        Queue::fake();
        $article = $this->createQualityFixture('resume-removed-disclosure-segment', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $segment = $check->segments()->firstOrFail();
        $segment->forceFill([
            'status' => 'completed',
            'validated_result' => [
                'summary' => '文章缺少 AI 生成内容标识，需要人工确认。',
                'promotion_context' => 'informational',
                'knowledge_coverage' => 'sufficient',
                'issues' => [[
                    'code' => 'ai_generated_disclosure',
                    'severity' => 'high',
                    'field' => 'content',
                    'quote' => '产品说明',
                ]],
                'uncertainties' => [[
                    'claim' => 'AI 生成内容标识状态',
                    'materiality' => 'high',
                    'reason' => '无法确认是否已声明 AI 生成',
                    'needed_evidence' => '提供发布元数据标识',
                ]],
                'truncated_issue_count' => 0,
            ],
            'finished_at' => now(),
        ])->save();
        $check->forceFill(['completed_segment_count' => 1])->save();

        $completed = $service->process($check);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('passed', $completed->decision);
        $this->assertSame([], $completed->issues);
        $this->assertSame([], $completed->uncertainties);
    }

    public function test_queue_time_consumes_the_primary_article_deadline_before_model_execution(): void
    {
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            public int $calls = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls++;

                return [];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $article = $this->createQualityFixture('queue-deadline', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $check->newQuery()->whereKey($check->id)->update([
            'created_at' => now()->subSeconds(61),
            'updated_at' => now()->subSeconds(61),
            'primary_deadline_at' => now()->subSecond(),
            'deadline_at' => now()->subSecond(),
        ]);

        try {
            (new ProcessArticleAiQualityJob((int) $check->id))->handle($service);
            $this->fail('Expected the end-to-end deadline to fail closed.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('inspection_primary_deadline_exceeded', $exception->safeCode());
        }

        $this->assertSame(0, $reviewer->calls);
        $this->assertSame('failed', $check->fresh()->status);
        $this->assertSame('inspection_primary_deadline_exceeded', $check->fresh()->error_code);
    }

    public function test_insufficient_budget_for_the_next_full_segment_enters_sampled_fallback(): void
    {
        Queue::fake();
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            public int $calls = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls++;

                return [];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $article = $this->createQualityFixture('remaining-budget-fallback', needReview: false);
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article->fresh(), dispatch: false);
        $check->forceFill([
            'primary_deadline_at' => now()->addSeconds(14),
            'deadline_at' => now()->addSeconds(69),
        ])->save();

        $result = $service->process((int) $check->id);

        $this->assertSame(0, $reviewer->calls);
        $this->assertSame('fallback_sampled', $result->inspection_scope);
        $this->assertSame('remaining_budget_insufficient', $result->fallback_trigger_code);
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_a_model_result_returned_after_the_deadline_cannot_overwrite_the_terminal_failure(): void
    {
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            public int $calls = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls++;
                Carbon::setTestNow(Carbon::now()->addSeconds(181));

                return [
                    'result' => [
                        'summary' => '迟到结果。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $article = $this->createQualityFixture('late-model-result', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);

        try {
            (new ProcessArticleAiQualityJob((int) $check->id))->handle($service);
            $this->fail('Expected the deadline guard to reject the late result.');
        } catch (ArticleAiQualityRuntimeException $exception) {
            $this->assertSame('inspection_deadline_exceeded', $exception->safeCode());
        } finally {
            Carbon::setTestNow();
        }

        $this->assertSame(1, $reviewer->calls);
        $this->assertSame('failed', $check->fresh()->status);
        $this->assertSame('inspection_deadline_exceeded', $check->fresh()->error_code);
    }

    public function test_a_fresh_running_check_is_not_claimed_by_a_second_worker(): void
    {
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            public int $calls = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $this->calls++;

                return [
                    'result' => [
                        'summary' => '质检通过。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $article = $this->createQualityFixture('fresh-running', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'running',
            'started_at' => now(),
            'updated_at' => now(),
        ])->save();

        $returned = $service->process($check->id, allowRunningRecovery: true);

        $this->assertSame('running', $returned->status);
        $this->assertSame(0, $reviewer->calls);
        $this->assertSame(0, $returned->attempt_count);
    }

    public function test_completion_preserves_manual_audit_entries_appended_while_the_check_is_running(): void
    {
        $reviewer = new class implements ArticleAiQualityReviewer
        {
            public int $checkId = 0;

            public function review(AiModel $model, string $instructions): array
            {
                $check = ArticleAiQualityCheck::query()->findOrFail($this->checkId);
                $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
                $executionMeta['manual_requests'][] = [
                    'trigger' => 'api_manual',
                    'admin_id' => 7,
                    'api_token_id' => 11,
                    'requested_at' => now()->toIso8601String(),
                ];
                $check->forceFill(['execution_meta' => $executionMeta])->save();

                return [
                    'result' => [
                        'summary' => '质检通过。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);
        $article = $this->createQualityFixture('audit-merge', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $reviewer->checkId = (int) $check->id;

        $completed = $service->process($check->id);

        $this->assertSame('completed', $completed->status);
        $this->assertSame('api_manual', $completed->execution_meta['manual_requests'][0]['trigger']);
        $this->assertSame(7, $completed->execution_meta['manual_requests'][0]['admin_id']);
        $this->assertSame(11, $completed->execution_meta['manual_requests'][0]['api_token_id']);
    }

    public function test_manual_inspection_preserves_private_distribution_state(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('private-manual', needReview: false);
        $article->forceFill([
            'status' => 'private',
            'review_status' => 'approved',
        ])->save();
        $service = app(ArticleAiQualityInspectionService::class);

        $check = $service->requestManualInspection($article, dispatch: false);
        $this->assertSame('private', $article->fresh()->status);
        $this->assertSame('approved', $article->fresh()->review_status);

        $completed = $service->process($check);
        $this->assertSame('passed', $completed->decision);
        $this->assertSame('private', $article->fresh()->status);
        $this->assertSame('approved', $article->fresh()->review_status);
    }

    public function test_passing_manual_inspection_restores_a_requested_private_target(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('restore-private-target', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->requestManualInspection(
            $article,
            dispatch: false,
            requestedWorkflowState: [
                'status' => 'private',
                'review_status' => 'approved',
                'published_at' => null,
            ],
        );

        $completed = $service->process($check);

        $this->assertSame('passed', $completed->decision);
        $this->assertSame('private', $article->fresh()->status);
        $this->assertSame('approved', $article->fresh()->review_status);
    }

    public function test_failed_post_quality_workflow_is_persisted_and_reconciled(): void
    {
        Queue::fake();
        $this->bindPassingReviewer();
        $transition = new class extends ArticleWorkflowTransitionService
        {
            public int $calls = 0;

            public function __construct() {}

            public function transition(
                Article $article,
                array $workflowState,
                string $trigger,
                ?int $adminId = null,
                ?string $overrideReason = null,
                bool $allowExistingOverride = true,
                ?array $rejectedWorkflowState = null,
                ?callable $lockedGuard = null,
            ): Article {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \RuntimeException('temporary workflow failure');
                }

                $article->forceFill($workflowState)->save();

                return $article->fresh();
            }
        };
        $this->app->instance(ArticleWorkflowTransitionService::class, $transition);
        $article = $this->createQualityFixture('workflow-reconcile', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article, dispatch: false));

        $this->assertSame('completed', $completed->status);
        $this->assertSame('failed', data_get($completed->fresh()->execution_meta, 'workflow_apply.status'));
        $this->assertSame('pending', $article->fresh()->review_status);

        (new ReconcileArticleAiQualityJob((int) $article->id, (int) $article->id, 1))->handle($service);

        $this->assertSame('succeeded', data_get($completed->fresh()->execution_meta, 'workflow_apply.status'));
        $this->assertSame('approved', $article->fresh()->review_status);
        $this->assertSame(2, $transition->calls);
    }

    public function test_stale_processing_post_quality_workflow_is_reconciled(): void
    {
        Queue::fake();
        $this->bindPassingReviewer();
        $transition = new class extends ArticleWorkflowTransitionService
        {
            public int $calls = 0;

            public function __construct() {}

            public function transition(
                Article $article,
                array $workflowState,
                string $trigger,
                ?int $adminId = null,
                ?string $overrideReason = null,
                bool $allowExistingOverride = true,
                ?array $rejectedWorkflowState = null,
                ?callable $lockedGuard = null,
            ): Article {
                $this->calls++;
                if ($this->calls === 1) {
                    throw new \RuntimeException('temporary workflow failure');
                }

                $article->forceFill($workflowState)->save();

                return $article->fresh();
            }
        };
        $this->app->instance(ArticleWorkflowTransitionService::class, $transition);
        $article = $this->createQualityFixture('workflow-stale-processing', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $completed = $service->process($service->createOrReuse($article, dispatch: false));
        $executionMeta = $completed->fresh()->execution_meta;
        $executionMeta['workflow_apply']['status'] = 'processing';
        $executionMeta['workflow_apply']['updated_at'] = now()->subMinutes(2)->toIso8601String();
        $completed->newQuery()->whereKey((int) $completed->id)->update([
            'execution_meta' => json_encode($executionMeta, JSON_THROW_ON_ERROR),
            'updated_at' => now()->subMinutes(2),
        ]);

        (new ReconcileArticleAiQualityJob((int) $article->id, (int) $article->id, 1))->handle($service);

        $this->assertSame('succeeded', data_get($completed->fresh()->execution_meta, 'workflow_apply.status'));
        $this->assertSame('approved', $article->fresh()->review_status);
        $this->assertSame(2, $transition->calls);
    }

    public function test_completed_check_is_superseded_before_workflow_apply_when_quality_basis_changed_in_queue(): void
    {
        Queue::fake();
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('workflow-quality-basis-changed', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $article->task()->update(['ai_quality_pass_score' => 90]);

        $service->process($check);

        $check->refresh();
        $latest = $article->aiQualityChecks()->latest('id')->firstOrFail();
        $this->assertSame('stale', $check->status);
        $this->assertSame('quality_basis_changed', $check->error_code);
        $this->assertSame('pending', $article->fresh()->review_status);
        $this->assertNotSame((int) $check->id, (int) $latest->id);
        $this->assertSame('queued', $latest->status);
        Queue::assertPushed(ProcessArticleAiQualityJob::class, fn (ProcessArticleAiQualityJob $job): bool => $job->checkId === (int) $latest->id);
    }

    public function test_exact_reconciliation_ids_do_not_touch_unrelated_stale_articles(): void
    {
        Queue::fake();
        $selected = $this->createQualityFixture('exact-reconcile-selected', needReview: false);
        $unrelated = $this->createQualityFixture('exact-reconcile-unrelated', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $selectedCheck = $service->createOrReuse($selected, dispatch: false);
        $unrelatedCheck = $service->createOrReuse($unrelated, dispatch: false);
        $selectedCheck->forceFill(['status' => 'stale', 'active_dedupe_key' => null])->save();
        $unrelatedCheck->forceFill(['status' => 'stale', 'active_dedupe_key' => null])->save();

        (new ReconcileArticleAiQualityJob(0, 0, 100, [(int) $selected->id]))->handle($service);

        $this->assertSame(2, $selected->aiQualityChecks()->count());
        $this->assertSame('queued', $selected->fresh()->latestAiQualityCheck?->status);
        $this->assertSame(1, $unrelated->aiQualityChecks()->count());
        $this->assertSame('stale', $unrelated->fresh()->latestAiQualityCheck?->status);
    }

    public function test_post_quality_workflow_stops_after_three_failed_attempts(): void
    {
        Queue::fake();
        $this->bindPassingReviewer();
        $transition = new class extends ArticleWorkflowTransitionService
        {
            public int $calls = 0;

            public function __construct() {}

            public function transition(
                Article $article,
                array $workflowState,
                string $trigger,
                ?int $adminId = null,
                ?string $overrideReason = null,
                bool $allowExistingOverride = true,
                ?array $rejectedWorkflowState = null,
                ?callable $lockedGuard = null,
            ): Article {
                $this->calls++;

                throw new \RuntimeException('permanent workflow failure');
            }
        };
        $this->app->instance(ArticleWorkflowTransitionService::class, $transition);
        $article = $this->createQualityFixture('workflow-retry-limit', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article, dispatch: false));
        $service->applyCompletedWorkflow($completed);
        $service->applyCompletedWorkflow($completed);
        $service->applyCompletedWorkflow($completed);

        $completed->refresh();
        $this->assertSame('exhausted', data_get($completed->execution_meta, 'workflow_apply.status'));
        $this->assertSame(3, data_get($completed->execution_meta, 'workflow_apply.attempts'));
        $this->assertSame(3, $transition->calls);
        $this->assertSame('pending', $article->fresh()->review_status);

        $this->assertTrue($service->retryCompletedWorkflow($completed));
        $completed->refresh();
        $this->assertSame('failed', data_get($completed->execution_meta, 'workflow_apply.status'));
        $this->assertSame(1, data_get($completed->execution_meta, 'workflow_apply.attempts'));
        $this->assertSame(4, $transition->calls);
    }

    public function test_manual_inspection_persists_an_article_policy_even_when_the_task_is_currently_enabled(): void
    {
        $article = $this->createQualityFixture('manual-snapshot-enabled-task', needReview: false);
        $article->forceFill([
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();

        app(ArticleAiQualityInspectionService::class)->requestManualInspection($article, dispatch: false);
        $article->task()->update(['ai_quality_enabled' => false]);

        $article->refresh();
        $this->assertTrue($article->ai_quality_required_at_creation);
        $this->assertSame('manual_article', data_get($article->ai_quality_policy_snapshot, 'source'));
        $this->assertTrue((bool) app(ArticleAiQualityPolicyResolver::class)
            ->resolve($article)['required']);
    }

    public function test_manual_recheck_refreshes_the_article_snapshot_from_the_current_task_configuration(): void
    {
        $article = $this->createQualityFixture('manual-refresh', needReview: false);
        $article->task()->update(['ai_quality_enabled' => false]);
        $service = app(ArticleAiQualityInspectionService::class);
        $service->requestManualInspection($article, dispatch: false);
        $replacement = KnowledgeBase::query()->create([
            'name' => '替换后的单篇质检知识库',
            'content' => '最新核查依据。',
        ]);
        $article->task->knowledgeBases()->sync([$replacement->id => ['sort_order' => 0]]);

        $service->requestManualInspection($article->fresh(), dispatch: false);

        $this->assertSame(
            [(int) $replacement->id],
            data_get($article->fresh()->ai_quality_policy_snapshot, 'knowledge_base_ids'),
        );
    }

    public function test_manual_inspection_uses_the_active_task_model_when_the_dedicated_quality_model_is_disabled(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('disabled-quality-model', needReview: false);
        $activeTaskModelId = (int) $article->task()->value('ai_model_id');
        $disabledQualityModel = AiModel::query()->create([
            'name' => '已停用专用质检模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'disabled-quality-model',
            'api_url' => 'https://example.test',
            'status' => 'inactive',
        ]);
        $article->task()->update(['ai_quality_model_id' => $disabledQualityModel->id]);
        $service = app(ArticleAiQualityInspectionService::class);

        $check = $service->requestManualInspection($article->fresh(), dispatch: false);
        $completed = $service->process($check->id);

        $this->assertSame($activeTaskModelId, (int) $check->ai_model_id);
        $this->assertSame($activeTaskModelId, (int) data_get($article->fresh()->ai_quality_policy_snapshot, 'model_id'));
        $this->assertSame('completed', $completed->status);
        $this->assertSame('passed', $completed->decision);
    }

    public function test_detached_manual_inspection_rebinds_an_unavailable_snapshot_model(): void
    {
        $article = $this->createQualityFixture('detached-model-rebind', needReview: false);
        $resolver = app(ArticleAiQualityPolicyResolver::class);
        $snapshot = $resolver->snapshot($resolver->resolveForManualInspection($article));
        $oldModelId = (int) ($snapshot['model_id'] ?? 0);
        $replacement = AiModel::query()->create([
            'name' => 'Detached replacement quality model',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'detached-replacement-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $article->forceFill([
            'task_id' => null,
            'ai_quality_required_at_creation' => true,
            'ai_quality_policy_snapshot' => $snapshot,
        ])->save();
        AiModel::query()->whereKey($oldModelId)->update(['status' => 'inactive']);

        $check = app(ArticleAiQualityInspectionService::class)
            ->requestManualInspection($article->fresh(), dispatch: false);

        $this->assertSame((int) $replacement->id, (int) $check->ai_model_id);
        $this->assertSame(
            (int) $replacement->id,
            (int) data_get($article->fresh()->ai_quality_policy_snapshot, 'model_id'),
        );
    }

    public function test_a_queued_manual_check_uses_its_immutable_evidence_policy_when_the_task_changes(): void
    {
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('manual-stale-policy', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->requestManualInspection($article, dispatch: false);
        $replacement = KnowledgeBase::query()->create([
            'name' => '排队后替换的知识库',
            'content' => '服务客户为 900 家。',
            'review_status' => 'reviewed',
            'chunk_sync_status' => 'completed',
            'chunk_source_hash' => 'manual-stale-policy-source',
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $replacement->id,
            'chunk_index' => 0,
            'content' => '服务客户为 900 家。',
            'content_hash' => hash('sha256', '服务客户为 900 家。'),
            'source_hash' => 'manual-stale-policy-source',
            'metadata_json' => json_encode(['review_status' => 'reviewed'], JSON_UNESCAPED_UNICODE),
        ]);
        $article->task->knowledgeBases()->sync([$replacement->id => ['sort_order' => 0]]);

        $stale = $service->process($check->id);

        $this->assertSame('completed', $stale->status);
        $this->assertSame('passed', $stale->decision);
        $this->assertSame(
            data_get($check->execution_meta, 'policy_snapshot.knowledge_base_ids'),
            data_get($stale->execution_meta, 'policy_snapshot.knowledge_base_ids'),
        );
    }

    public function test_queue_dispatch_failure_marks_the_committed_check_retryable_instead_of_leaving_it_stuck(): void
    {
        $article = $this->createQualityFixture('dispatch-failure', needReview: false);
        $queue = Mockery::mock(QueueContract::class);
        $queue->shouldReceive('push')->once()->andThrow(new \RuntimeException('queue connection unavailable'));
        Queue::shouldReceive('connection')->andReturn($queue);

        try {
            app(ArticleAiQualityInspectionService::class)->requestManualInspection($article);
            $this->fail('Expected queue dispatch to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }

        $check = $article->aiQualityChecks()->latest('id')->firstOrFail();
        $this->assertSame('failed', $check->status);
        $this->assertNull($check->active_dedupe_key);
        $this->assertSame('queue_dispatch_failed', $check->error_code);
        $this->assertTrue($check->execution_meta['retryable_failure']);
    }

    public function test_scoring_v2_can_take_over_the_primary_gate_for_a_stable_article_group(): void
    {
        $this->setQualityRollout(scoring: 100);
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('scoring-v2-primary', needReview: true);

        $completed = app(ArticleAiQualityInspectionService::class)->process(
            app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false),
        );

        $this->assertSame('v2', $completed->scoring_version);
        $this->assertTrue($completed->gate_applied);
        $this->assertSame('primary', $completed->evaluation_mode);
        $this->assertSame([], $completed->gate_reasons);
        $this->assertStringContainsString('score=2', $completed->algorithm_version);
    }

    public function test_execution_rollout_selects_versioned_prompt_snapshots(): void
    {
        $this->setQualityRollout();
        $legacyArticle = $this->createQualityFixture('legacy-execution-prompt', needReview: true);
        $legacy = app(ArticleAiQualityInspectionService::class)->createOrReuse($legacyArticle, dispatch: false);

        $this->assertStringContainsString('knowledge_coverage', $legacy->prompt_template_snapshot);
        $this->assertStringNotContainsString('truncated_issue_count', $legacy->prompt_template_snapshot);

        $this->setQualityRollout(execution: 100);
        $fastArticle = $this->createQualityFixture('fast-execution-prompt', needReview: true);
        $fast = app(ArticleAiQualityInspectionService::class)->createOrReuse($fastArticle, dispatch: false);

        $this->assertStringContainsString('truncated_issue_count', $fast->prompt_template_snapshot);
        $this->assertNotSame($legacy->prompt_hash, $fast->prompt_hash);
    }

    public function test_tampered_v2_principle_snapshot_is_rejected_before_model_review(): void
    {
        $this->setQualityRollout(principles: 100);
        $article = $this->createQualityFixture('tampered-principle-snapshot', needReview: true);
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $executionMeta = $check->execution_meta;
        $executionMeta['principle_snapshot']['advertising_rules']['rules'][0]['summary'] = 'tampered';
        $check->forceFill(['execution_meta' => $executionMeta])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('principle_snapshot_invalid');

        $service->process((int) $check->id);
    }

    public function test_execution_version_is_passed_explicitly_to_the_reviewer_contract(): void
    {
        $reviewer = new class implements VersionAwareArticleAiQualityReviewer
        {
            /** @var list<string> */
            public array $versions = [];

            public function review(AiModel $model, string $instructions): array
            {
                throw new \RuntimeException('versioned reviewer entry point required');
            }

            public function reviewWithin(AiModel $model, string $instructions, int $timeoutSeconds): array
            {
                throw new \RuntimeException('versioned reviewer entry point required');
            }

            public function reviewWithinVersion(
                AiModel $model,
                string $instructions,
                int $timeoutSeconds,
                string $executionVersion,
            ): array {
                $this->versions[] = $executionVersion;
                preg_match_all('/"claim_hash"\s*:\s*"([a-f0-9]{64})"/i', $instructions, $claimMatches);

                return [
                    'result' => array_filter([
                        'summary' => '质检通过。',
                        'promotion_context' => 'informational',
                        'reviewed_claim_hashes' => $executionVersion === 'fast_v2'
                            ? array_values(array_unique($claimMatches[1] ?? []))
                            : null,
                        'knowledge_coverage' => $executionVersion === 'legacy' ? 'sufficient' : null,
                        'issues' => [],
                        'uncertainties' => [],
                        'truncated_issue_count' => $executionVersion === 'fast_v2' ? 0 : null,
                    ], static fn (mixed $value): bool => $value !== null),
                    'usage' => [],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        };
        $this->app->instance(ArticleAiQualityReviewer::class, $reviewer);

        $this->setQualityRollout();
        $legacyArticle = $this->createQualityFixture('explicit-legacy-schema', needReview: true);
        app(ArticleAiQualityInspectionService::class)->process(
            app(ArticleAiQualityInspectionService::class)->createOrReuse($legacyArticle, dispatch: false),
        );

        $this->setQualityRollout(execution: 100);
        $fastArticle = $this->createQualityFixture('explicit-fast-schema', needReview: true);
        app(ArticleAiQualityInspectionService::class)->process(
            app(ArticleAiQualityInspectionService::class)->createOrReuse($fastArticle, dispatch: false),
        );

        $this->assertSame(['legacy', 'fast_v2'], $reviewer->versions);
    }

    public function test_fast_v2_execution_validates_and_scores_the_v2_model_contract(): void
    {
        $this->setQualityRollout(execution: 100, scoring: 100);
        $this->app->bind(ArticleAiQualityReviewer::class, fn () => new class implements ArticleAiQualityReviewer
        {
            public function review(AiModel $model, string $instructions): array
            {
                preg_match_all('/"claim_hash"\s*:\s*"([a-f0-9]{64})"/i', $instructions, $claimMatches);

                return [
                    'result' => [
                        'summary' => '快速质检通过。',
                        'promotion_context' => 'informational',
                        'reviewed_claim_hashes' => array_values(array_unique($claimMatches[1] ?? [])),
                        'issues' => [],
                        'uncertainties' => [],
                        'truncated_issue_count' => 0,
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        });
        $article = $this->createQualityFixture('fast-v2-contract', needReview: true);
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article, dispatch: false));

        $this->assertSame('completed', $completed->status);
        $this->assertSame('v2', $completed->scoring_version);
        $this->assertSame('passed', $completed->decision);
        $this->assertSame(0, $completed->truncated_issue_count);
    }

    public function test_shadow_scoring_reuses_the_primary_output_without_becoming_the_latest_gate_result(): void
    {
        $this->setQualityRollout(shadow: 100);
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('scoring-v2-shadow', needReview: true);
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article, dispatch: false));
        $shadow = $article->aiQualityChecks()->where('gate_applied', false)->firstOrFail();

        $this->assertSame('v1', $completed->scoring_version);
        $this->assertSame('v2', $shadow->scoring_version);
        $this->assertSame('shadow', $shadow->evaluation_mode);
        $this->assertSame($completed->id, $shadow->baseline_check_id);
        $this->assertSame($completed->id, $article->fresh()->latestAiQualityCheck->id);
        $this->assertNull($shadow->raw_model_output);
        $this->assertNull($shadow->article_snapshot);
        $this->assertNull($shadow->evidence_snapshot);
    }

    public function test_manual_rechecks_reuse_only_the_exact_evidence_cache_and_still_create_a_new_model_audit(): void
    {
        config()->set('geoflow.ai_quality_evidence_cache_enabled', true);
        $this->bindPassingReviewer();
        $article = $this->createQualityFixture('evidence-cache', needReview: true);
        $service = app(ArticleAiQualityInspectionService::class);

        $first = $service->process($service->createOrReuse($article, dispatch: false));
        $second = $service->process($service->createOrReuse($article, dispatch: false, force: true));

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('miss', data_get($first->execution_meta, 'evidence_cache.status'));
        $this->assertSame('hit', data_get($second->execution_meta, 'evidence_cache.status'));
    }

    public function test_raw_model_payloads_are_bounded_without_truncating_validated_results(): void
    {
        $this->app->bind(ArticleAiQualityReviewer::class, fn () => new class implements ArticleAiQualityReviewer
        {
            public function review(AiModel $model, string $instructions): array
            {
                return [
                    'result' => [
                        'summary' => str_repeat('超长模型原始说明', 12000),
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        });
        $article = $this->createQualityFixture('bounded-raw-output', needReview: false);
        $service = app(ArticleAiQualityInspectionService::class);

        $completed = $service->process($service->createOrReuse($article, dispatch: false));
        $segment = $completed->segments()->firstOrFail();

        $this->assertLessThanOrEqual(65536, strlen(json_encode($completed->raw_model_output, JSON_UNESCAPED_UNICODE)));
        $this->assertLessThanOrEqual(65536, strlen(json_encode($segment->model_result, JSON_UNESCAPED_UNICODE)));
        $this->assertTrue((bool) data_get($completed->raw_model_output, '_truncated'));
        $this->assertTrue((bool) data_get($segment->model_result, '_truncated'));
        $this->assertSame('completed', $completed->status);
        $this->assertNotSame('', $completed->summary);
    }

    private function bindPassingReviewer(): void
    {
        $this->app->bind(ArticleAiQualityReviewer::class, fn () => new class implements ArticleAiQualityReviewer
        {
            public function review(AiModel $model, string $instructions): array
            {
                return [
                    'result' => [
                        'summary' => '质检通过。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => [],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        });
    }

    /** @return array<string,mixed> */
    private function passingV2QualityResult(): array
    {
        return [
            'summary' => '质检通过。',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => [],
            'issues' => [],
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ];
    }

    private function setQualityRollout(
        int $execution = 0,
        int $scoring = 0,
        int $shadow = 0,
        int $principles = 0,
        int $atomicShadow = 0,
        int $atomicFact = 0,
    ): void {
        ArticleAiQualityRollout::query()->updateOrCreate(['id' => 1], [
            'execution_percent' => $execution,
            'scoring_percent' => $scoring,
            'shadow_percent' => $shadow,
            'principle_percent' => $principles,
            'atomic_shadow_percent' => $atomicShadow,
            'atomic_fact_percent' => $atomicFact,
            'atomic_fact_frozen' => false,
            'sampled_auto_release_enabled' => true,
            'frozen' => false,
        ]);
        app(ArticleAiQualityRolloutPolicy::class)->forget();
    }

    private function createQualityFixture(string $suffix, bool $needReview): Article
    {
        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $model = AiModel::query()->create([
            'name' => '质检模型 '.$suffix,
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-'.$suffix,
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '质检知识库 '.$suffix,
            'content' => '服务客户为 800 家。',
            'review_status' => 'reviewed',
            'chunk_sync_status' => 'completed',
            'chunk_source_hash' => 'source-'.$suffix,
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => '服务客户为 800 家。',
            'content_hash' => hash('sha256', '服务客户为 800 家。'),
            'source_hash' => 'source-'.$suffix,
            'chunk_title' => '客户数据',
            'section_path' => '企业资料',
            'metadata_json' => json_encode(['review_status' => 'reviewed'], JSON_UNESCAPED_UNICODE),
        ]);
        $task = Task::query()->create([
            'name' => '质检任务 '.$suffix,
            'ai_model_id' => $model->id,
            'need_review' => $needReview ? 1 : 0,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_pass_score' => 85,
            'ai_quality_manual_override_min_score' => 70,
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $category = Category::query()->create([
            'name' => '质检分类 '.$suffix,
            'slug' => 'quality-'.$suffix,
        ]);
        $author = Author::query()->create(['name' => '质检作者 '.$suffix]);

        return Article::query()->create([
            'title' => '质检文章 '.$suffix,
            'slug' => 'quality-article-'.$suffix,
            'content' => '服务客户为 800 家。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
    }

    private function qualityAdmin(string $username, string $role, ?Admin $provider = null): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'password',
            'role' => $role,
            'status' => 'active',
        ]);
        $admin->forceFill([
            'shared_ai_config_owner_id' => $provider?->id,
            'ai_config_access_version' => 1,
        ])->save();

        return $admin;
    }

    private function qualityModel(
        Admin $owner,
        string $modelId,
        int $priority,
        string $scope = AiModel::ACCESS_SCOPE_USER_CONTENT,
    ): AiModel {
        $model = AiModel::query()->create([
            'name' => $modelId,
            'version' => '1',
            'api_key' => 'test',
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://example.test',
            'status' => 'active',
            'failover_priority' => $priority,
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => $scope,
        ])->save();

        return $model;
    }
}
