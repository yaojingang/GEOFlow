<?php

namespace Tests\Feature;

use App\Contracts\ArticleAiQualityReviewer;
use App\Contracts\DeadlineAwareArticleAiQualityReviewer;
use App\Contracts\VersionAwareArticleAiQualityReviewer;
use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Jobs\ProcessArticleAiQualityJob;
use App\Jobs\ReconcileArticleAiQualityJob;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleAiQualityRollout;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleAiQualityPolicyResolver;
use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use App\Services\GeoFlow\ArticleAiQualityRolloutPolicy;
use App\Services\GeoFlow\ArticleFactCandidateExtractor;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;
use UnexpectedValueException;

class ArticleAiQualityInspectionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sampling_enabled_checks_persist_primary_and_final_deadlines_with_an_immutable_policy_snapshot(): void
    {
        config()->set('geoflow.ai_quality_deadline_seconds', 180);
        config()->set('geoflow.ai_quality_sampled_fallback_seconds', 45);
        config()->set('geoflow.ai_quality_persistence_reserve_seconds', 10);
        $article = $this->createQualityFixture('sampled-deadlines', needReview: false);
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);

        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article->fresh(), dispatch: false);

        $this->assertSame('full', $check->inspection_scope);
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
        $check->forceFill(['completed_segment_count' => 1])->save();
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

    private function setQualityRollout(
        int $execution = 0,
        int $scoring = 0,
        int $shadow = 0,
        int $principles = 0,
    ): void {
        ArticleAiQualityRollout::query()->updateOrCreate(['id' => 1], [
            'execution_percent' => $execution,
            'scoring_percent' => $scoring,
            'shadow_percent' => $shadow,
            'principle_percent' => $principles,
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
}
