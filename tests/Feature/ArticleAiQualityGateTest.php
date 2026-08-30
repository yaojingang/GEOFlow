<?php

namespace Tests\Feature;

use App\Contracts\ArticleAiQualityReviewer;
use App\Exceptions\ArticleAiQualityGateException;
use App\Jobs\ProcessArticleAiQualityJob;
use App\Jobs\ReconcileArticleAiQualityJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityBackfillGuard;
use App\Services\GeoFlow\ArticleAiQualityGate;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Services\GeoFlow\ArticleAiQualityPolicyResolver;
use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use App\Services\GeoFlow\ArticleAiQualitySampleBuilder;
use App\Services\GeoFlow\ArticlePublicationQualityGate;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArticleAiQualityGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_result_is_queued_and_publication_is_closed(): void
    {
        Queue::fake();
        $article = $this->qualityArticle();

        try {
            app(ArticleAiQualityGate::class)->check($article, 'test_publish');
            $this->fail('Expected pending quality gate rejection.');
        } catch (ArticleAiQualityGateException $exception) {
            $this->assertSame('article_ai_quality_pending', $exception->getErrorCode());
            $this->assertSame('queued', $exception->getCheck()?->status);
        }

        Queue::assertPushed(ProcessArticleAiQualityJob::class);
    }

    public function test_reconciliation_does_not_recheck_a_completed_article_for_an_algorithm_version_change_alone(): void
    {
        Queue::fake();
        $article = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
            'algorithm_version' => 'exec=legacy;ret=1;prompt=1;score=1',
            'advertising_rules_snapshot' => app(ArticleAiQualityInspectionService::class)->rules(),
        ])->save();

        (new ReconcileArticleAiQualityJob((int) $article->id, (int) $article->id))
            ->handle(app(ArticleAiQualityInspectionService::class));

        $this->assertSame(1, $article->aiQualityChecks()->count());
        $this->assertSame('completed', $check->fresh()->status);
        Queue::assertNotPushed(ProcessArticleAiQualityJob::class);
    }

    public function test_reconciliation_pauses_while_a_front_queue_check_exceeds_the_wait_budget(): void
    {
        Queue::fake();
        config()->set('geoflow.ai_quality_front_queue_wait_seconds', 10);
        $frontArticle = $this->qualityArticle();
        $waitingCheck = app(ArticleAiQualityInspectionService::class)->createOrReuse(
            $frontArticle,
            trigger: 'admin_manual',
            dispatch: false,
        );
        $waitingCheck->newQuery()->whereKey($waitingCheck->id)->update([
            'created_at' => now()->subSeconds(11),
            'updated_at' => now()->subSeconds(11),
        ]);
        $backfillArticle = $this->qualityArticle();

        (new ReconcileArticleAiQualityJob((int) $backfillArticle->id, (int) $backfillArticle->id))
            ->handle(app(ArticleAiQualityInspectionService::class));

        $this->assertSame(0, $backfillArticle->aiQualityChecks()->count());
        Queue::assertNotPushed(ProcessArticleAiQualityJob::class);
    }

    public function test_targeted_reconciliation_does_not_replace_the_full_backfill_cursor(): void
    {
        $guard = app(ArticleAiQualityBackfillGuard::class);
        $guard->preserveCursor(321);

        (new ReconcileArticleAiQualityJob(999_998, 999_999))
            ->handle(app(ArticleAiQualityInspectionService::class));

        $this->assertSame(321, $guard->resumeCursor());
    }

    public function test_reconciliation_leaves_failed_checks_terminal_until_an_explicit_retry(): void
    {
        Queue::fake();
        $nonRetryableArticle = $this->qualityArticle();
        $retryableArticle = $this->qualityArticle();
        $service = app(ArticleAiQualityInspectionService::class);

        $nonRetryable = $service->createOrReuse($nonRetryableArticle, dispatch: false);
        $nonRetryable->forceFill([
            'status' => 'failed',
            'decision' => 'error',
            'active_dedupe_key' => null,
            'error_code' => 'provider_authentication_failed',
            'execution_meta' => array_replace($nonRetryable->execution_meta, ['retryable_failure' => false]),
            'finished_at' => now(),
        ])->save();

        $retryable = $service->createOrReuse($retryableArticle, dispatch: false);
        $retryable->forceFill([
            'status' => 'failed',
            'decision' => 'error',
            'active_dedupe_key' => null,
            'error_code' => 'provider_timeout',
            'execution_meta' => array_replace($retryable->execution_meta, ['retryable_failure' => true]),
            'finished_at' => now(),
        ])->save();

        (new ReconcileArticleAiQualityJob(
            (int) $nonRetryableArticle->id,
            (int) $retryableArticle->id,
        ))->handle($service);

        $this->assertSame(1, $nonRetryableArticle->aiQualityChecks()->count());
        $this->assertSame(1, $retryableArticle->aiQualityChecks()->count());
        Queue::assertNotPushed(ProcessArticleAiQualityJob::class);
    }

    public function test_missing_knowledge_configuration_fails_closed_with_a_stable_gate_error(): void
    {
        Queue::fake();
        $article = $this->qualityArticle();
        $article->task()->firstOrFail()->knowledgeBases()->sync([]);

        try {
            app(ArticleAiQualityGate::class)->check($article, 'test_publish');
            $this->fail('Expected unavailable knowledge configuration to close the gate.');
        } catch (ArticleAiQualityGateException $exception) {
            $this->assertSame('article_ai_quality_failed', $exception->getErrorCode());
            $this->assertNull($exception->getCheck());
        }

        Queue::assertNothingPushed();
    }

    public function test_fresh_passed_result_allows_publication(): void
    {
        $article = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 96,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $allowed = app(ArticleAiQualityGate::class)->check($article, 'test_publish');

        $this->assertTrue($allowed?->is($check));
    }

    public function test_a_new_full_recheck_supersedes_an_older_stale_optimization_run(): void
    {
        $article = $this->qualityArticle();
        $inspection = app(ArticleAiQualityInspectionService::class);
        $source = $inspection->createOrReuse($article, dispatch: false);
        $source->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 96,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $run = ArticleAiOptimizationRun::query()->create([
            'article_id' => $article->id,
            'task_id' => $article->task_id,
            'source_check_id' => $source->id,
            'request_key' => (string) Str::uuid(),
            'trigger' => ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            'strategy' => 'excellent_80',
            'target_score' => 85,
            'max_rounds' => 2,
            'status' => ArticleAiOptimizationRun::STATUS_STALE,
            'base_article_hash' => str_repeat('a', 64),
            'policy_hash' => str_repeat('b', 64),
            'stop_reason' => 'article_changed',
            'finished_at' => now(),
        ]);
        $run->newQuery()->whereKey($run->id)->update(['updated_at' => now()->subMinute()]);
        $replacement = $inspection->createOrReuse($article->fresh(), trigger: 'admin_manual', dispatch: false, force: true);
        $replacement->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 97,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $allowed = app(ArticleAiQualityGate::class)->check($article->fresh(), 'test_publish');

        $this->assertTrue($allowed?->is($replacement));
    }

    public function test_a_failed_optimization_blocks_publication_even_when_the_source_check_passed(): void
    {
        $article = $this->qualityArticle();
        $inspection = app(ArticleAiQualityInspectionService::class);
        $source = $inspection->createOrReuse($article, dispatch: false);
        $source->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 85,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        ArticleAiOptimizationRun::query()->create([
            'article_id' => $article->id,
            'task_id' => $article->task_id,
            'source_check_id' => $source->id,
            'request_key' => (string) Str::uuid(),
            'trigger' => ArticleAiOptimizationRun::TRIGGER_TASK_AUTO,
            'strategy' => 'excellent_90',
            'target_score' => 90,
            'max_rounds' => 3,
            'status' => ArticleAiOptimizationRun::STATUS_FAILED,
            'base_article_hash' => str_repeat('a', 64),
            'policy_hash' => str_repeat('b', 64),
            'error_code' => 'article_ai_optimization_provider_error',
            'finished_at' => now(),
        ]);

        try {
            app(ArticleAiQualityGate::class)->check($article->fresh(), 'test_publish');
            $this->fail('Expected failed optimization to close the publication gate.');
        } catch (ArticleAiQualityGateException $exception) {
            $this->assertSame('article_ai_optimization_failed', $exception->getErrorCode());
            $this->assertSame($source->id, $exception->getCheck()?->id);
        }
    }

    public function test_an_article_created_under_quality_control_keeps_its_snapshot_gate_after_the_task_switches_off(): void
    {
        $article = $this->qualityArticle();
        $policyResolver = app(ArticleAiQualityPolicyResolver::class);
        $policy = $policyResolver->resolve($article);
        $article->forceFill([
            'ai_quality_required_at_creation' => true,
            'ai_quality_policy_snapshot' => $policyResolver->snapshot($policy),
        ])->save();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 96,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $article->task()->update(['ai_quality_enabled' => false]);

        $allowed = app(ArticleAiQualityGate::class)->check($article, 'test_publish');

        $this->assertTrue($allowed?->is($check));
    }

    public function test_a_soft_deleted_task_uses_the_article_policy_snapshot_for_a_new_check(): void
    {
        $article = $this->qualityArticle();
        $resolver = app(ArticleAiQualityPolicyResolver::class);
        $policy = $resolver->resolve($article);
        $article->forceFill([
            'ai_quality_required_at_creation' => true,
            'ai_quality_policy_snapshot' => $resolver->snapshot($policy),
        ])->save();
        $article->task()->firstOrFail()->delete();

        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);

        $this->assertNotNull($check);
        $this->assertSame('article_snapshot', $check->execution_meta['policy_source']);
        $this->assertSame(85, $check->pass_score);
    }

    public function test_needs_review_can_be_overridden_with_audited_reason_above_the_floor(): void
    {
        $article = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 78,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $admin = Admin::query()->create([
            'username' => 'quality-admin',
            'password' => 'secret-password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $allowed = app(ArticleAiQualityGate::class)->check(
            $article,
            'admin_publish',
            (int) $admin->id,
            '已核对企业原始证明材料',
        );

        $this->assertTrue($allowed?->is_overridden);
        $this->assertSame($admin->id, $allowed?->overridden_by);
        $this->assertSame('已核对企业原始证明材料', $allowed?->override_reason);
    }

    public function test_publication_risk_reason_cannot_implicitly_override_ai_quality(): void
    {
        $article = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 78,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $admin = Admin::query()->create([
            'username' => 'quality-publication-admin',
            'password' => 'secret-password',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        try {
            app(ArticlePublicationQualityGate::class)->check(
                $article,
                'admin_publish',
                (int) $admin->id,
                '仅用于确定性风险扫描的放行原因',
            );
            $this->fail('Expected the AI quality gate to require its explicit override action.');
        } catch (ArticleAiQualityGateException $exception) {
            $this->assertSame('article_ai_quality_blocked', $exception->getErrorCode());
        }

        $this->assertFalse($check->fresh()->is_overridden);
        $this->assertNull($check->fresh()->override_reason);
    }

    public function test_ai_quality_rejection_does_not_downgrade_an_already_published_article(): void
    {
        $article = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'blocked',
            'score' => 55,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ])->save();

        try {
            app(ArticleWorkflowTransitionService::class)->transition(
                $article,
                ArticleWorkflow::normalizeState('private', 'approved'),
                'admin_batch_status',
                rejectedWorkflowState: ArticleWorkflow::normalizeState('draft', 'pending'),
            );
            $this->fail('Expected the AI quality gate to reject the transition.');
        } catch (ArticleAiQualityGateException $exception) {
            $this->assertSame('article_ai_quality_blocked', $exception->getErrorCode());
        }

        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertNotNull($article->published_at);
    }

    public function test_invalidation_holds_an_approved_unpublished_article_for_review(): void
    {
        $article = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 96,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill(['status' => 'draft', 'review_status' => 'approved'])->save();

        app(ArticleAiQualityInvalidationService::class)->invalidateArticle(
            $article,
            'article_content_changed',
            reconcile: false,
        );

        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame('stale', $check->fresh()->status);
    }

    public function test_deleting_a_task_cancels_in_flight_quality_checks_before_articles_are_detached(): void
    {
        $article = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);

        app(TaskLifecycleService::class)->deleteTask((int) $article->task_id, true);

        $this->assertSame('cancelled', $check->fresh()->status);
        $this->assertSame('article_unavailable', $check->fresh()->error_code);
        $this->assertNotNull(Article::withTrashed()->findOrFail($article->id)->deleted_at);
        $this->assertNull(Article::withTrashed()->findOrFail($article->id)->task_id);
    }

    public function test_deleted_article_snapshot_cannot_create_a_new_quality_check(): void
    {
        $article = $this->qualityArticle();
        $taskId = (int) $article->task_id;
        $service = app(ArticleAiQualityInspectionService::class);
        app(TaskLifecycleService::class)->deleteTask($taskId, true);

        $check = $service->createOrReuse($article, dispatch: false);

        $this->assertNull($check);
        $this->assertDatabaseCount('article_ai_quality_checks', 0);
    }

    public function test_reviewer_result_cannot_overwrite_cancellation_after_task_deletion(): void
    {
        $article = $this->qualityArticle();
        $taskId = (int) $article->task_id;
        $this->app->bind(ArticleAiQualityReviewer::class, fn () => new class($taskId) implements ArticleAiQualityReviewer
        {
            public function __construct(private readonly int $taskId) {}

            public function review(AiModel $model, string $instructions): array
            {
                app(TaskLifecycleService::class)->deleteTask($this->taskId, true);

                return [
                    'result' => [
                        'summary' => '已完成质检。',
                        'promotion_context' => 'informational',
                        'knowledge_coverage' => 'sufficient',
                        'issues' => [],
                        'uncertainties' => [],
                    ],
                    'usage' => ['promptTokens' => 10, 'completionTokens' => 5, 'totalTokens' => 15],
                    'model' => ['id' => (int) $model->id, 'model_id' => (string) $model->model_id],
                    'mode' => 'structured',
                ];
            }
        });

        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $result = $service->process($check);

        $this->assertSame('cancelled', $result->status);
        $this->assertSame('article_unavailable', $result->error_code);
        $this->assertSame('cancelled', $result->segments->firstOrFail()->status);
        $this->assertNull(Task::query()->find($taskId));
    }

    public function test_reconciliation_rechecks_published_articles_without_unpublishing_when_the_legal_rule_version_changes(): void
    {
        Queue::fake();
        $article = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
            'input_fingerprint' => str_repeat('a', 64),
            'advertising_rules_snapshot' => ['version' => 'cn-ads-content-labeling-0.9.0', 'rules' => []],
        ])->save();
        $article->forceFill([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ])->save();

        (new ReconcileArticleAiQualityJob((int) $article->id, (int) $article->id))
            ->handle(app(ArticleAiQualityInspectionService::class));

        $this->assertSame(2, $article->aiQualityChecks()->count());
        $this->assertSame('stale', $check->fresh()->status);
        $this->assertSame('queued', $article->aiQualityChecks()->latest('id')->firstOrFail()->status);
        $this->assertSame('published', $article->fresh()->status);
        Queue::assertPushed(ProcessArticleAiQualityJob::class);
        Queue::assertPushed(ProcessArticleAiQualityJob::class, fn (ProcessArticleAiQualityJob $job): bool => $job->queue === 'ai-quality-backfill');
    }

    public function test_independent_convergence_terminalizes_an_expired_running_check(): void
    {
        Queue::fake();
        $article = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'running',
            'started_at' => now()->subMinutes(20),
            'deadline_at' => now()->subSecond(),
        ])->save();
        $check->segments()->update(['status' => 'running']);
        $check->newQuery()->whereKey($check->id)->update(['updated_at' => now()->subMinutes(20)]);

        app(ArticleAiQualityReconciliationService::class)->convergeExpired();

        $this->assertSame('failed', $check->fresh()->status);
        $this->assertSame('failed', $check->segments()->firstOrFail()->status);
        $this->assertSame('worker_interrupted', $check->segments()->firstOrFail()->error_code);
        Queue::assertNothingPushed();
    }

    public function test_reconciliation_continues_after_one_article_has_an_invalid_model_configuration(): void
    {
        Queue::fake();
        $blockedArticle = $this->qualityArticle();
        $healthyArticle = $this->qualityArticle();
        $blockedArticle->task->aiModel->update(['status' => 'inactive']);

        (new ReconcileArticleAiQualityJob(
            (int) $blockedArticle->id,
            (int) $healthyArticle->id,
            100,
        ))->handle(app(ArticleAiQualityInspectionService::class));

        $this->assertDatabaseCount('article_ai_quality_checks', 1);
        $this->assertDatabaseHas('article_ai_quality_checks', [
            'article_id' => (int) $healthyArticle->id,
            'status' => 'queued',
        ]);
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_detached_snapshot_articles_are_invalidated_when_their_knowledge_base_changes(): void
    {
        Queue::fake();
        $article = $this->qualityArticle();
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '快照知识库',
            'content' => '原始依据',
        ]);
        $task = $article->task()->firstOrFail();
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $resolver = app(ArticleAiQualityPolicyResolver::class);
        $article->refresh()->forceFill([
            'ai_quality_required_at_creation' => true,
            'ai_quality_policy_snapshot' => $resolver->snapshot($resolver->resolve($article->fresh())),
        ])->save();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article->fresh(), dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
        ])->save();
        $article->forceFill(['task_id' => null])->save();

        app(ArticleAiQualityInvalidationService::class)->invalidateKnowledgeBase(
            (int) $knowledgeBase->id,
            '知识库内容已更新',
        );

        $this->assertSame('stale', $check->fresh()->status);
        Queue::assertPushed(ReconcileArticleAiQualityJob::class);
    }

    public function test_prompt_and_model_changes_invalidate_checks_even_after_an_article_is_detached(): void
    {
        Queue::fake();
        $article = $this->qualityArticle();
        $service = app(ArticleAiQualityInspectionService::class);
        $invalidation = app(ArticleAiQualityInvalidationService::class);
        $promptCheck = $service->createOrReuse($article, dispatch: false);
        $promptCheck->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
        ])->save();
        $resolver = app(ArticleAiQualityPolicyResolver::class);
        $article->forceFill([
            'ai_quality_policy_snapshot' => $resolver->snapshot($resolver->resolve($article)),
            'ai_quality_required_at_creation' => true,
            'task_id' => null,
        ])->save();

        $invalidation->invalidatePrompt((int) $promptCheck->prompt_id, '质检方案已更新');
        $this->assertSame('stale', $promptCheck->fresh()->status);

        $detachedArticle = $article->fresh();
        $this->assertTrue((bool) data_get($detachedArticle->ai_quality_policy_snapshot, 'required'));
        $this->assertTrue((bool) data_get($resolver->resolve($detachedArticle), 'required'));
        $modelCheck = $service->createOrReuse($detachedArticle, dispatch: false, force: true);
        $this->assertNotNull($modelCheck);
        $modelCheck->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
        ])->save();
        $invalidation->invalidateModel((int) $modelCheck->ai_model_id, '质检模型已更新');

        $this->assertSame('stale', $modelCheck->fresh()->status);
        Queue::assertPushed(ReconcileArticleAiQualityJob::class);
    }

    public function test_prompt_change_invalidates_active_optimization_runs_for_every_detached_article(): void
    {
        Queue::fake();
        $runs = collect();
        $promptId = null;

        foreach (range(1, 3) as $index) {
            $article = $this->qualityArticle();
            $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
            $check->forceFill([
                'status' => 'completed',
                'decision' => 'passed',
                'score' => 100,
                'active_dedupe_key' => null,
                'finished_at' => now(),
            ])->save();
            $article->forceFill(['task_id' => null])->save();
            $promptId ??= (int) $check->prompt_id;

            $runs->push(ArticleAiOptimizationRun::query()->create([
                'article_id' => $article->id,
                'task_id' => null,
                'source_check_id' => $check->id,
                'request_key' => (string) Str::uuid(),
                'trigger' => ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
                'strategy' => 'excellent_80',
                'target_score' => 85,
                'max_rounds' => 2,
                'status' => ArticleAiOptimizationRun::STATUS_CANDIDATE_READY,
                'base_article_hash' => hash('sha256', 'base-'.$index),
                'policy_hash' => hash('sha256', 'policy-'.$index),
            ]));
        }

        app(ArticleAiQualityInvalidationService::class)->invalidatePrompt(
            (int) $promptId,
            '质检方案已更新',
        );

        $runs->each(function (ArticleAiOptimizationRun $run): void {
            $this->assertSame(ArticleAiOptimizationRun::STATUS_STALE, $run->fresh()->status);
            $this->assertSame('article_changed', $run->fresh()->stop_reason);
        });
    }

    public function test_a_smart_failover_candidate_change_invalidates_quality_results(): void
    {
        Queue::fake();
        $article = $this->qualityArticle();
        $article->task()->update(['model_selection_mode' => 'smart_failover']);
        $fallback = AiModel::query()->create([
            'name' => '质检候选模型',
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-candidate-model',
            'api_url' => 'https://example.test/v1',
            'status' => 'active',
            'model_type' => 'chat',
            'failover_priority' => 1,
        ]);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article->fresh(), dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
        ])->save();

        app(ArticleAiQualityInvalidationService::class)->invalidateModel(
            (int) $fallback->id,
            '候选模型配置已更新',
        );

        $this->assertContains((int) $fallback->id, $check->execution_meta['model_candidate_ids']);
        $this->assertSame('stale', $check->fresh()->status);
        Queue::assertPushed(ReconcileArticleAiQualityJob::class);
    }

    public function test_sampled_pass_requires_snapshot_authorization_current_authorization_and_safe_coverage(): void
    {
        Queue::fake();
        $article = $this->qualityArticle();
        $article->task()->update(['ai_quality_timeout_sampling_enabled' => true]);
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article->fresh(), dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'inspection_scope' => 'fallback_sampled',
            'active_dedupe_key' => null,
            'coverage_meta' => [
                'algorithm_version' => ArticleAiQualitySampleBuilder::ALGORITHM_VERSION,
                'checked_chars' => 6,
                'total_chars' => 6,
                'mandatory_claims_total' => 0,
                'mandatory_claims_covered' => 0,
                'mandatory_overflow' => false,
                'regions_covered' => ['front', 'middle', 'back'],
                'safe_for_auto_release' => true,
            ],
        ])->save();

        $allowed = app(ArticleAiQualityGate::class)->check($article->fresh(), 'test_publish');
        $this->assertSame($check->id, $allowed?->id);

        $article->task()->update(['ai_quality_timeout_sampling_enabled' => false]);
        try {
            app(ArticleAiQualityGate::class)->check($article->fresh(), 'test_publish');
            $this->fail('Expected sampled result to stop authorizing publication.');
        } catch (ArticleAiQualityGateException $exception) {
            $this->assertSame('article_ai_quality_sampled_stale', $exception->getErrorCode());
        }
        $this->assertSame('stale', $check->fresh()->status);
    }

    public function test_sampling_toggle_changes_preserve_full_results_and_only_invalidate_sampled_results_when_disabled(): void
    {
        Queue::fake();
        $article = $this->qualityArticle();
        $article->task()->update(['status' => 'paused']);
        $service = app(ArticleAiQualityInspectionService::class);
        $lifecycle = app(TaskLifecycleService::class);
        $full = $service->createOrReuse($article, dispatch: false);
        $full->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
        ])->save();

        $lifecycle->updateTask((int) $article->task_id, [
            'ai_quality_timeout_sampling_enabled' => true,
        ]);
        $this->assertSame('completed', $full->fresh()->status);

        $sampled = $service->createOrReuse($article->fresh(), dispatch: false, force: true);
        $sampled->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'inspection_scope' => 'fallback_sampled',
            'active_dedupe_key' => null,
            'coverage_meta' => [
                'algorithm_version' => ArticleAiQualitySampleBuilder::ALGORITHM_VERSION,
                'safe_for_auto_release' => true,
                'mandatory_overflow' => false,
                'mandatory_claims_total' => 0,
                'mandatory_claims_covered' => 0,
                'regions_covered' => ['front', 'middle', 'back'],
            ],
        ])->save();

        $lifecycle->updateTask((int) $article->task_id, [
            'ai_quality_timeout_sampling_enabled' => false,
        ]);

        $this->assertSame('completed', $full->fresh()->status);
        $this->assertSame('stale', $sampled->fresh()->status);
        $this->assertSame('sampling_policy_disabled', $sampled->fresh()->error_code);
        Queue::assertPushed(ReconcileArticleAiQualityJob::class);
    }

    public function test_task_updates_compare_effective_quality_values_before_invalidating_results(): void
    {
        Queue::fake();
        $article = $this->qualityArticle();
        $article->task()->update(['status' => 'paused']);
        $task = $article->task()->firstOrFail();
        $service = app(ArticleAiQualityInspectionService::class);
        $check = $service->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
        ])->save();

        app(TaskLifecycleService::class)->updateTask((int) $task->id, [
            'ai_quality_enabled' => (bool) $task->ai_quality_enabled,
            'ai_quality_prompt_id' => $task->ai_quality_prompt_id,
            'ai_quality_model_id' => $task->ai_quality_model_id,
            'ai_quality_pass_score' => (int) $task->ai_quality_pass_score,
            'ai_quality_manual_override_min_score' => (int) $task->ai_quality_manual_override_min_score,
            'ai_model_id' => $task->ai_model_id,
            'model_selection_mode' => (string) $task->model_selection_mode,
            'publish_scope' => (string) $task->publish_scope,
            'distribution_strategy' => (string) $task->distribution_strategy,
            'need_review' => (bool) $task->need_review,
        ]);

        $this->assertSame('completed', $check->fresh()->status);

        app(TaskLifecycleService::class)->updateTask((int) $task->id, [
            'need_review' => ! (bool) $task->need_review,
        ]);

        $this->assertSame('stale', $check->fresh()->status);
        Queue::assertPushed(ReconcileArticleAiQualityJob::class);
    }

    private function qualityArticle(): Article
    {
        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $model = AiModel::query()->create([
            'name' => '质检模型 '.uniqid(),
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '质检任务 '.uniqid(),
            'ai_model_id' => $model->id,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_pass_score' => 85,
            'ai_quality_manual_override_min_score' => 70,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '质检知识库 '.uniqid(),
            'content' => '待检查正文的核验依据。',
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $category = Category::query()->create(['name' => '分类 '.uniqid(), 'slug' => 'category-'.uniqid()]);
        $author = Author::query()->create(['name' => '作者 '.uniqid()]);

        return Article::query()->create([
            'title' => '待发布文章',
            'slug' => 'quality-gate-'.uniqid(),
            'content' => '待检查正文。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
    }
}
