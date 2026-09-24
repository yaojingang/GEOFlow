<?php

namespace Tests\PostgreSQL;

use App\Contracts\ArticleAiOptimizationRefiner;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiOptimizationStep;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Models\DistributionLog;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Services\GeoFlow\ArticleAiOptimizationCoordinator;
use App\Services\GeoFlow\ArticleAiOptimizationException;
use App\Services\GeoFlow\ArticleAiOptimizationReconciliationService;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticlePublicationDeliveryService;
use App\Services\GeoFlow\ArticlePublicationEligibilityService;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPublisherInterface;
use App\Services\GeoFlow\DistributionPublisherManager;
use App\Services\GeoFlow\DistributionRetryPolicy;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ArticleWorkflowConcurrencyTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_task_deletion_waits_for_task_lock_before_locking_articles(): void
    {
        [$article, $task] = $this->article();
        $barrier = tempnam(sys_get_temp_dir(), 'workflow-delete-barrier-');
        $peer = tempnam(sys_get_temp_dir(), 'workflow-delete-pid-');
        try {
            $results = $this->concurrent([
                fn () => DB::transaction(function () use ($article, $task, $barrier, $peer): string {
                    Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
                    file_put_contents($barrier, 'locked');
                    $this->waitForBlockedConnection($peer);
                    app(ArticleWorkflowTransitionService::class)->humanAction($article, 'hold');

                    return 'held';
                }),
                function () use ($task, $barrier, $peer): string {
                    $this->waitForBarrier($barrier);
                    file_put_contents($peer, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    app(TaskLifecycleService::class)->deleteTask($task->id);

                    return 'deleted';
                },
            ]);
            $this->assertSame(['held', 'deleted'], $results);
            $this->assertTrue(Task::withTrashed()->findOrFail($task->id)->trashed());
            $this->assertTrue(Article::withTrashed()->findOrFail($article->id)->trashed());
        } finally {
            DB::table('task_trash_entries')->where('task_id', $task->id)->delete();
            unlink($barrier);
            unlink($peer);
        }
    }

    public function test_recovery_json_deadlines_and_limits_use_due_receipts_on_postgresql(): void
    {
        [$article] = $this->article();
        $article->update(['status' => 'published', 'publication_intent' => 'none']);
        $fence = app(ArticlePublicationEligibilityService::class)->fence($article->fresh());
        $receipts = [];
        foreach ([now()->addHour(), now()->subMinute(), now()->subMinute()] as $next) {
            $receipts[] = DistributionLog::query()->create([
                'article_id' => $article->id, 'event' => ArticlePublicationDeliveryService::EVENT, 'level' => 'info',
                'message' => 'Pending test receipt', 'created_at' => now(),
                'context' => ['fence' => $fence, 'status' => 'pending', 'attempts' => 0, 'next_at' => $next->toIso8601String()],
            ]);
        }
        $this->assertSame(1, app(ArticlePublicationDeliveryService::class)->recoverPending(1));
        $this->assertSame('pending', data_get($receipts[0]->fresh()->context, 'status'));
        $this->assertSame('completed', data_get($receipts[1]->fresh()->context, 'status'));
        $this->assertSame('pending', data_get($receipts[2]->fresh()->context, 'status'));
    }

    public function test_undispatched_queue_recovery_uses_postgresql_json_retry_budget(): void
    {
        [$article, $task] = $this->article();
        $task->update(['publish_scope' => 'local_and_distribution']);
        $article->update(['status' => 'published', 'publication_intent' => 'none']);
        $channel = DistributionChannel::query()->create(['name' => 'Queue recovery', 'domain' => 'queue.example.test', 'endpoint_url' => 'https://queue.example.test', 'status' => 'active']);
        $service = app(DistributionOrchestrator::class);
        $service->syncTaskChannels($task, [$channel->id]);
        $id = $service->enqueueForArticle($article->fresh(), throwOnFailure: true)[0];
        $delivery = ArticleDistribution::query()->findOrFail($id);
        $meta = (array) $delivery->remote_meta;
        unset($meta['queue_dispatched_at']);
        $meta['queue_submit_attempts'] = 3;
        $meta['queue_submit_retry_at'] = now()->subMinute()->toIso8601String();
        $delivery->update(['remote_meta' => $meta]);
        $this->assertSame(0, $service->recoverUndispatched(1));
        $meta['queue_submit_attempts'] = 2;
        $delivery->update(['remote_meta' => $meta]);
        $this->assertSame(1, $service->recoverUndispatched(1));
        $this->assertNotNull(data_get($delivery->fresh()->remote_meta, 'queue_dispatched_at'));
        $this->assertSame(0, $service->recoverUndispatched(1));
        $this->assertSame(0, $delivery->fresh()->attempt_count);
    }

    public function test_optimization_reconciliation_uses_task_then_article_locks(): void
    {
        [$article, $task, $source, $model] = $this->qualityArticle(optimization: true);
        $source->update(['status' => 'completed', 'decision' => 'blocked', 'score' => 60, 'active_dedupe_key' => null, 'finished_at' => now()]);
        $run = app(ArticleAiOptimizationCoordinator::class)->start($article->fresh(), 'excellent_90', $model, ArticleAiOptimizationRun::TRIGGER_TASK_AUTO, dispatch: false);
        $run->forceFill(['updated_at' => now()->subMinutes(10), 'deadline_at' => now()->addHour()])->save();
        $this->assertTrue($run->fresh()->updated_at->lt(now()->subMinutes(5)));
        $barrier = tempnam(sys_get_temp_dir(), 'optimization-reconcile-lock-');
        $peer = tempnam(sys_get_temp_dir(), 'optimization-reconcile-pid-');
        try {
            $results = $this->concurrent([
                fn () => DB::transaction(function () use ($article, $task, $barrier, $peer): string {
                    Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
                    file_put_contents($barrier, 'locked');
                    $this->waitForBlockedConnection($peer);
                    app(ArticleWorkflowTransitionService::class)->humanAction($article, 'hold');

                    return 'held';
                }),
                function () use ($run, $barrier, $peer): string {
                    $this->waitForBarrier($barrier);
                    file_put_contents($peer, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    app(ArticleAiOptimizationReconciliationService::class)->reconcile(10);

                    return (string) $run->fresh()->status;
                },
            ]);
            $this->assertSame(['held', ArticleAiOptimizationRun::STATUS_STALE], $results);
            $this->assertSame('hold', $article->fresh()->publication_intent);
            $this->assertSame('workflow_intent_changed', $run->fresh()->stop_reason);
            $this->assertNoPublicationEffects($article);
        } finally {
            unlink($barrier);
            unlink($peer);
        }
    }

    public function test_acknowledged_agent_response_survives_an_aborted_postgresql_transaction(): void
    {
        [$article, $task] = $this->article();
        $task->update(['publish_scope' => 'local_and_distribution']);
        $article->update(['status' => 'published', 'publication_intent' => 'none']);
        $channel = DistributionChannel::query()->create(['name' => 'ACK test', 'domain' => 'example.com', 'endpoint_url' => 'https://example.com', 'status' => 'active']);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id, 'key_id' => 'pg-ack-secret', 'status' => 'active',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('test-only-secret'), 'scopes' => ['article.publish'],
        ]);
        Http::fake(['*' => Http::response(['ok' => true, 'remote_id' => 'pg-remote-1', 'remote_url' => 'https://example.com/article'])]);
        $service = app(DistributionOrchestrator::class);
        $service->syncTaskChannels($task, [$channel->id]);
        $id = $service->enqueueForArticle($article->fresh(), throwOnFailure: true)[0];
        $failed = false;
        DistributionChannelSecret::updating(function ($secret) use (&$failed): void {
            if (! $failed && $secret->isDirty('last_used_at')) {
                $failed = true;
                DB::statement('SELECT geoflow_intentionally_missing_column');
            }
        });
        (new ProcessArticleDistributionJob($id))->handle($service, app(DistributionRetryPolicy::class));
        $delivery = ArticleDistribution::query()->findOrFail($id);
        $this->assertTrue($failed);
        $this->assertSame('outcome_unknown', $delivery->status);
        $this->assertSame('pg-remote-1', $delivery->remote_id);
        $this->assertSame('distribution_local_commit_failed', data_get($delivery->remote_meta, 'acknowledged_response.error_code'));
        $this->assertSame([], $service->enqueueForArticle($article->fresh(), throwOnFailure: true));
        Http::assertSentCount(1);
    }

    public function test_locked_rejection_wins_over_a_concurrent_old_publish_request(): void
    {
        [$article, $task] = $this->article();
        $barrier = tempnam(sys_get_temp_dir(), 'workflow-barrier-');
        try {
            $results = $this->concurrent([
                fn () => DB::transaction(function () use ($article, $task, $barrier): string {
                    Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
                    file_put_contents($barrier, 'locked');
                    usleep(250000);
                    app(ArticleWorkflowTransitionService::class)->humanAction($article, 'reject', expectedVersion: 1);

                    return 'rejected';
                }),
                function () use ($article, $barrier): string {
                    $deadline = microtime(true) + 5;
                    while (file_get_contents($barrier) !== 'locked' && microtime(true) < $deadline) {
                        usleep(10000);
                    }
                    try {
                        app(ArticleWorkflowTransitionService::class)->humanAction($article, 'publish', expectedVersion: 1);

                        return 'unexpected_publish';
                    } catch (\RuntimeException $exception) {
                        return $exception->getMessage();
                    }
                },
            ]);
            $this->assertSame(['rejected', 'workflow_version_conflict'], $results);
            $article->refresh();
            $this->assertSame('draft', $article->status);
            $this->assertSame('rejected', $article->review_status);
            $this->assertSame('hold', $article->publication_intent);
            $this->assertSame(2, $article->workflow_version);
            $this->assertSame(1, $article->reviews()->count());
        } finally {
            unlink($barrier);
        }
    }

    public function test_two_database_workers_consume_duplicate_jobs_with_one_external_effect(): void
    {
        [$article, $task] = $this->article();
        $task->update(['publish_scope' => 'local_and_distribution']);
        $article->update(['status' => 'published', 'publication_intent' => 'none', 'published_at' => now()]);
        $channel = DistributionChannel::query()->create(['name' => 'Workflow channel', 'domain' => 'workflow.example.test', 'endpoint_url' => 'https://workflow.example.test', 'status' => 'active']);
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->syncTaskChannels($task, [$channel->id]);
        $ids = $orchestrator->enqueueForArticle($article->fresh(), throwOnFailure: true);
        $this->assertCount(1, $ids);
        $effects = tempnam(sys_get_temp_dir(), 'workflow-effects-');
        $publisher = \Mockery::mock(DistributionPublisherInterface::class);
        $publisher->shouldReceive('publish')->andReturnUsing(function () use ($effects): array {
            file_put_contents($effects, "sent\n", FILE_APPEND | LOCK_EX);
            usleep(150000);

            return ['remote_id' => 'workflow-remote-1', 'remote_url' => 'https://workflow.example.test/article'];
        });
        $manager = \Mockery::mock(DistributionPublisherManager::class);
        $manager->shouldReceive('forChannel')->andReturn($publisher);
        $this->app->instance(DistributionPublisherManager::class, $manager);
        $this->app->forgetInstance(DistributionOrchestrator::class);
        foreach ([1, 2] as $copy) {
            Bus::dispatch((new ProcessArticleDistributionJob($ids[0]))->onConnection('database')->onQueue('workflow-test'));
        }
        $this->assertSame(2, DB::table('jobs')->where('queue', 'workflow-test')->count());
        try {
            $work = fn () => Artisan::call('queue:work', ['connection' => 'database', '--queue' => 'workflow-test', '--once' => true, '--tries' => 1]);
            $this->assertSame([0, 0], $this->concurrent([$work, $work]));
            $this->assertSame("sent\n", file_get_contents($effects), json_encode(ArticleDistribution::query()->find($ids[0])->toArray()));
            $this->assertSame('synced', ArticleDistribution::query()->findOrFail($ids[0])->status);
            $this->assertSame(1, ArticleDistribution::query()->findOrFail($ids[0])->attempt_count);
            $this->assertSame(0, DB::table('jobs')->where('queue', 'workflow-test')->count());
            $this->assertSame(0, DB::table('failed_jobs')->count());
        } finally {
            unlink($effects);
        }
    }

    public function test_locked_task_review_requirement_wins_over_a_concurrent_completed_quality_callback(): void
    {
        [$article, $task, $check] = $this->qualityArticle();
        $check->update(['status' => 'completed', 'decision' => 'passed', 'score' => 100, 'issues' => [], 'gate_reasons' => [], 'active_dedupe_key' => null, 'finished_at' => now()]);

        // Prove the same completed report can publish before changing the task gate.
        DB::beginTransaction();
        try {
            app(ArticleAiQualityInspectionService::class)->applyCompletedWorkflow($check->id);
            $this->assertSame('published', $article->fresh()->status);
            $this->assertSame('succeeded', data_get($check->fresh()->execution_meta, 'workflow_apply.status'));
        } finally {
            DB::rollBack();
        }
        $barrier = tempnam(sys_get_temp_dir(), 'quality-config-race-');
        $peer = tempnam(sys_get_temp_dir(), 'quality-callback-pid-');
        try {
            $results = $this->concurrent([
                fn () => DB::transaction(function () use ($article, $task, $barrier, $peer): string {
                    Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
                    file_put_contents($barrier, 'locked');
                    $this->waitForBlockedConnection($peer);
                    app(TaskLifecycleService::class)->updateTask($task->id, ['need_review' => 1], auditAdminId: $task->model_access_admin_id);

                    return $article->fresh()->review_status;
                }),
                function () use ($check, $barrier, $peer): string {
                    $this->waitForBarrier($barrier);
                    file_put_contents($peer, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    app(ArticleAiQualityInspectionService::class)->applyCompletedWorkflow($check->id);

                    return (string) data_get($check->fresh()->execution_meta, 'workflow_apply.status');
                },
            ]);
            $this->assertSame('pending', $results[0]);
            $this->assertSame('succeeded', $results[1]);
            $this->assertSame(1, (int) $task->fresh()->need_review);
            $this->assertSame(2, (int) $task->fresh()->ai_quality_config_version);
            $this->assertSame('draft', $article->fresh()->status);
            $this->assertSame('pending', $article->fresh()->review_status);
            $this->assertSame('immediate', $article->fresh()->publication_intent);
            $this->assertNull($article->fresh()->published_at);
            $this->assertSame('completed', $check->fresh()->status);
            $this->assertSame('passed', $check->fresh()->decision);
            $this->assertNoPublicationEffects($article);
        } finally {
            unlink($barrier);
            unlink($peer);
        }
    }

    public function test_locked_human_hold_wins_over_a_concurrent_automatic_optimization_apply(): void
    {
        [$article, $task, $source, $model] = $this->qualityArticle(optimization: true);
        $source->update([
            'status' => 'completed', 'decision' => 'blocked', 'score' => 62, 'active_dedupe_key' => null, 'finished_at' => now(),
            'issues' => [[
                'code' => 'ad_absolute_claim', 'severity' => 'high', 'field' => 'content', 'quote' => '保证100%有效',
                'location_status' => 'resolved', 'start_offset' => 5, 'end_offset' => 13,
                'root_cause_key' => 'ad_absolute_claim:content:5', 'reason' => '绝对化承诺', 'suggestion' => '收敛表达', 'evidence_keys' => [],
            ]],
        ]);
        $this->app->instance(ArticleAiOptimizationRefiner::class, new class implements ArticleAiOptimizationRefiner
        {
            public function refine(AiModel $model, string $instructions, int $timeoutSeconds, int $quotaReserve = 0): array
            {
                preg_match('/"base_article_hash":"([a-f0-9]{64})"/', $instructions, $matches);

                return [
                    'result' => ['base_article_hash' => $matches[1], 'strategy' => 'excellent_90', 'operations' => [[
                        'field' => 'content', 'anchor_start' => 5, 'anchor_end' => 13, 'replace_start' => 5, 'replace_end' => 13,
                        'old_text_hash' => hash('sha256', '保证100%有效'), 'replacement' => '有助于改善体验',
                        'issue_codes' => ['ad_absolute_claim'], 'root_cause_keys' => ['ad_absolute_claim:content:5'],
                        'evidence_keys' => [], 'reason' => '收敛绝对化承诺',
                    ]]],
                    'usage' => [], 'model' => ['id' => (int) $model->id], 'mode' => 'structured',
                ];
            }
        });
        $coordinator = app(ArticleAiOptimizationCoordinator::class);
        $run = $coordinator->start($article->fresh(), 'excellent_90', $model, ArticleAiOptimizationRun::TRIGGER_TASK_AUTO, dispatch: false);
        $this->assertSame(ArticleAiOptimizationRun::STATUS_QUEUED, $run->status);
        $coordinator->process($run->id);
        $step = ArticleAiOptimizationStep::query()->where('run_id', $run->id)->sole();
        $candidate = ArticleAiQualityCheck::query()->findOrFail($step->output_check_id);
        $candidate->update(['status' => 'completed', 'decision' => 'passed', 'score' => 95, 'issues' => [], 'gate_reasons' => [], 'active_dedupe_key' => null, 'finished_at' => now()]);
        // Leave an accepted candidate pending so the two connections race at apply.
        config()->set('geoflow.ai_quality_optimization_auto_apply_enabled', false);
        try {
            $coordinator->candidateCompleted($candidate->id);
        } finally {
            config()->set('geoflow.ai_quality_optimization_auto_apply_enabled', true);
        }
        $run->refresh();
        $this->assertSame(ArticleAiOptimizationRun::STATUS_CANDIDATE_READY, $run->status);
        $originalContent = $article->fresh()->content;
        $originalVersion = $article->fresh()->workflow_version;
        DB::beginTransaction();
        try {
            $coordinator->apply($run->id, $run->candidate_hash);
            $this->assertSame(ArticleAiOptimizationRun::STATUS_COMPLETED, $run->fresh()->status);
            $this->assertStringContainsString('有助于改善体验', $article->fresh()->content);
        } finally {
            DB::rollBack();
        }
        $barrier = tempnam(sys_get_temp_dir(), 'optimization-hold-race-');
        $peer = tempnam(sys_get_temp_dir(), 'optimization-apply-pid-');
        try {
            $results = $this->concurrent([
                fn () => DB::transaction(function () use ($article, $task, $originalVersion, $barrier, $peer): string {
                    Task::query()->whereKey($task->id)->lockForUpdate()->firstOrFail();
                    file_put_contents($barrier, 'locked');
                    $this->waitForBlockedConnection($peer);
                    app(ArticleWorkflowTransitionService::class)->humanAction($article, 'hold', expectedVersion: $originalVersion);

                    return 'held';
                }),
                function () use ($run, $barrier, $peer): string {
                    $this->waitForBarrier($barrier);
                    file_put_contents($peer, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    try {
                        app(ArticleAiOptimizationCoordinator::class)->apply($run->id, $run->candidate_hash);

                        return 'unexpected_apply';
                    } catch (ArticleAiOptimizationException $exception) {
                        return $exception->errorCode();
                    }
                },
            ]);
            $this->assertSame(['held', 'article_ai_optimization_stale'], $results);
            $this->assertSame('draft', $article->fresh()->status);
            $this->assertSame('hold', $article->fresh()->publication_intent);
            $this->assertSame($originalVersion + 1, $article->fresh()->workflow_version);
            $this->assertSame($originalContent, $article->fresh()->content);
            $this->assertSame(ArticleAiOptimizationRun::STATUS_STALE, $run->fresh()->status);
            $this->assertSame('workflow_intent_changed', $run->fresh()->stop_reason);
            $this->assertNull($run->fresh()->final_check_id);
            $this->assertFalse($candidate->fresh()->gate_applied);
            $this->assertSame('optimization_candidate', $candidate->fresh()->evaluation_mode);
            $this->assertNoPublicationEffects($article);
        } finally {
            unlink($barrier);
            unlink($peer);
        }
    }

    private function qualityArticle(bool $optimization = false): array
    {
        Http::preventStrayRequests();
        config()->set('queue.connections.redis', ['driver' => 'null']);
        foreach (['ai_quality_optimization', 'ai_quality_optimization_auto_apply'] as $capability) {
            config()->set('geoflow.'.$capability.'_enabled', $optimization);
            config()->set('geoflow.'.$capability.'_percent', 100);
        }
        [$article, $task] = $this->article();
        $admin = Admin::query()->create(['username' => 'workflow-quality-executor', 'password' => 'password', 'role' => 'admin', 'status' => 'active']);
        $model = AiModel::query()->findOrFail($task->ai_model_id);
        $model->forceFill(['version' => '1', 'status' => 'active', 'api_url' => 'https://quality.example.test', 'owner_admin_id' => $admin->id, 'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT])->save();
        $knowledge = KnowledgeBase::query()->create(['name' => 'Workflow evidence', 'content' => '本产品可以帮助改善使用体验。', 'review_status' => 'approved']);
        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        Title::query()->create(['library_id' => $task->title_library_id, 'title' => 'Available workflow title']);
        $task->forceFill([
            'article_limit' => 1, 'ai_quality_enabled' => true, 'ai_quality_retrieval_mode' => 'knowledge_broad',
            'ai_quality_prompt_id' => $prompt->id, 'ai_quality_model_id' => $model->id, 'ai_quality_pass_score' => 85,
            'knowledge_base_id' => $knowledge->id, 'ai_quality_auto_optimize_enabled' => $optimization, 'ai_quality_optimization_level' => 'excellent_90',
            'model_access_admin_id' => $admin->id, 'model_access_admin_role' => 'admin', 'model_access_policy_version' => 1,
            'publish_scope' => 'local_and_distribution',
        ])->save();
        $task->knowledgeBases()->sync([$knowledge->id => ['sort_order' => 0]]);
        $channel = DistributionChannel::query()->create(['name' => 'Quality workflow channel', 'domain' => 'quality.example.test', 'endpoint_url' => 'https://quality.example.test', 'status' => 'active']);
        app(DistributionOrchestrator::class)->syncTaskChannels($task, [$channel->id]);
        $article->update([
            'title' => '产品说明', 'excerpt' => '产品摘要', 'keywords' => '产品,说明', 'meta_description' => '产品说明摘要',
            'content' => $optimization ? '开场说明。保证100%有效，请结合实际情况使用。' : '本产品可以帮助改善使用体验。',
            'ai_quality_required_at_creation' => true,
        ]);
        $check = app(ArticleAiQualityInspectionService::class)->requestManualInspection(
            $article->fresh(), dispatch: false, auditAdminId: $admin->id,
            requestedWorkflowState: ['status' => 'published', 'review_status' => 'auto_approved'],
        );

        return [$article->fresh(), $task->fresh(), $check, $model];
    }

    private function assertNoPublicationEffects(Article $article): void
    {
        $this->assertSame(0, ArticleDistribution::query()->where('article_id', $article->id)->count());
        $this->assertSame(0, DistributionLog::query()->where('article_id', $article->id)->where('event', ArticlePublicationDeliveryService::EVENT)->count());
        $this->assertSame(0, DB::table('jobs')->count());
        Http::assertNothingSent();
    }

    private function waitForBarrier(string $path): void
    {
        $deadline = microtime(true) + 4;
        while (file_get_contents($path) !== 'locked' && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertSame('locked', file_get_contents($path), 'The competing transaction must acquire its lock first.');
    }

    private function waitForBlockedConnection(string $path): void
    {
        $deadline = microtime(true) + 4;
        do {
            $pid = (int) file_get_contents($path);
            if ($pid > 0 && DB::selectOne('SELECT pg_backend_pid() = ANY(pg_blocking_pids(?)) AS blocked', [$pid])?->blocked === true) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('The competing service did not wait on the real PostgreSQL row lock.');
    }

    private function article(): array
    {
        $library = DB::table('title_libraries')->insertGetId(['name' => 'Workflow titles', 'created_at' => now(), 'updated_at' => now()]);
        $prompt = DB::table('prompts')->insertGetId(['name' => 'Workflow prompt', 'type' => 'content', 'content' => 'Write content.', 'created_at' => now(), 'updated_at' => now()]);
        $model = DB::table('ai_models')->insertGetId(['name' => 'Workflow model', 'api_key' => 'test-key', 'model_id' => 'test-model', 'created_at' => now(), 'updated_at' => now()]);
        $task = Task::query()->create(['title_library_id' => $library, 'prompt_id' => $prompt, 'ai_model_id' => $model, 'name' => 'Workflow task', 'status' => 'active', 'schedule_enabled' => 1, 'need_review' => 0, 'publish_scope' => 'local_only']);
        $category = Category::query()->create(['name' => 'Workflow', 'slug' => 'workflow']);
        $author = Author::query()->create(['name' => 'Workflow author']);
        $article = Article::query()->create(['title' => 'Factual workflow test', 'slug' => 'workflow-test', 'content' => 'Verified descriptive content.', 'task_id' => $task->id, 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'draft', 'review_status' => 'auto_approved']);

        return [$article, $task];
    }

    private function concurrent(array $actions): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'PostgreSQL acceptance requires pcntl.');
        DB::disconnect('pgsql');
        $children = [];
        foreach ($actions as $action) {
            $path = tempnam(sys_get_temp_dir(), 'workflow-child-');
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    DB::purge('pgsql');
                    DB::reconnect('pgsql');
                    DB::statement("SET lock_timeout TO '5s'");
                    file_put_contents($path, json_encode(['result' => $action()], JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($path, $exception::class.': '.$exception->getMessage());
                    exit(1);
                }
            }
            $children[] = [$pid, $path];
        }
        $results = [];
        foreach ($children as [$pid, $path]) {
            pcntl_waitpid($pid, $status);
            $output = file_get_contents($path);
            unlink($path);
            $this->assertSame(0, pcntl_wexitstatus($status), $output);
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result'];
        }
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        return $results;
    }
}
