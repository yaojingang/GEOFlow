<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionLog;
use App\Models\Task;
use App\Services\GeoFlow\ArticlePublicationDeliveryService;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticlePublicationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixtureForHandoff(): Article
    {
        $task = Task::query()->create(['name' => 'handoff fixture', 'need_review' => 0, 'ai_quality_enabled' => false, 'status' => 'active', 'schedule_enabled' => 1]);

        return Article::query()->create(['task_id' => $task->id, 'title' => 'Delivery fixture', 'slug' => 'delivery-'.uniqid(), 'content' => 'A factual article.', 'status' => 'draft', 'review_status' => 'pending', 'category_id' => Category::query()->create(['name' => 'Delivery', 'slug' => uniqid('delivery-')])->id, 'author_id' => Author::query()->create(['name' => 'Delivery author'])->id]);
    }

    private function failedHandoff(bool $automatic = false): array
    {
        $article = $this->fixtureForHandoff();
        $failing = \Mockery::mock(DistributionOrchestrator::class);
        $failing->shouldReceive('enqueueForArticle')->once()->andThrow(new \RuntimeException('temporary_delivery_failure'));
        $this->app->instance(DistributionOrchestrator::class, $failing);
        if ($automatic) {
            app(ArticleWorkflowTransitionService::class)->transition($article, ['status' => 'published', 'review_status' => 'auto_approved', 'published_at' => now()], 'worker_publish');
        } else {
            app(ArticleWorkflowTransitionService::class)->humanAction($article, 'publish');
        }
        $receipt = DistributionLog::query()->where('event', ArticlePublicationDeliveryService::EVENT)->where('article_id', $article->id)->firstOrFail();

        return [$article->fresh(), $receipt];
    }

    public function test_publication_rollback_leaves_no_handoff_or_after_commit_enqueue(): void
    {
        Queue::fake();
        $article = $this->fixtureForHandoff();
        $orch = \Mockery::mock(DistributionOrchestrator::class);
        $orch->shouldNotReceive('enqueueForArticle');
        $this->app->instance(DistributionOrchestrator::class, $orch);
        try {
            DB::transaction(function () use ($article) {
                app(ArticleWorkflowTransitionService::class)->humanAction($article, 'publish');
                $this->assertSame(1, DistributionLog::query()->where('event', ArticlePublicationDeliveryService::EVENT)->count());
                throw new \RuntimeException('rollback_probe');
            });
            $this->fail('expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback_probe', $e->getMessage());
        }
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertSame(0, DistributionLog::query()->where('event', ArticlePublicationDeliveryService::EVENT)->count());
    }

    public function test_sending_lease_expires_and_recovers_once(): void
    {
        Queue::fake();
        [$article,$receipt] = $this->failedHandoff();
        $ctx = $receipt->context;
        $ctx['status'] = 'sending';
        $ctx['next_at'] = now()->addMinutes(5)->toIso8601String();
        $receipt->update(['context' => $ctx]);
        $orch = \Mockery::mock(DistributionOrchestrator::class);
        $orch->shouldReceive('enqueueForArticle')->once()->andReturn([]);
        $this->app->instance(DistributionOrchestrator::class, $orch);
        $service = app(ArticlePublicationDeliveryService::class);
        $this->assertSame(0, $service->recoverPending());
        $this->travel(301)->seconds();
        $this->assertSame(1, $service->recoverPending());
        $this->assertSame(0, $service->recoverPending());
        $this->assertSame('completed', data_get($receipt->fresh()->context, 'status'));
        $this->assertSame(2, data_get($receipt->fresh()->context, 'attempts'));
    }

    public function test_ai_disabled_article_is_recovered_by_periodic_convergence(): void
    {
        Queue::fake();
        [$article,$receipt] = $this->failedHandoff();
        $this->assertFalse($article->task->ai_quality_enabled);
        $this->assertSame(0, $article->aiQualityChecks()->count());
        $orch = \Mockery::mock(DistributionOrchestrator::class);
        $orch->shouldReceive('enqueueForArticle')->once()->andReturn([]);
        $orch->shouldReceive('recoverUndispatched')->once()->andReturn(0);
        $this->app->instance(DistributionOrchestrator::class, $orch);
        $this->travel(61)->seconds();
        $this->artisan('geoflow:converge-ai-quality', ['--json' => true])->assertSuccessful();
        $this->assertSame('completed', data_get($receipt->fresh()->context, 'status'));
    }

    public function test_hold_cancels_handoff_and_task_resume_does_not_resurrect(): void
    {
        Queue::fake();
        $realOrch = app(DistributionOrchestrator::class);
        [$article,$receipt] = $this->failedHandoff(true);
        app(ArticleWorkflowTransitionService::class)->humanAction($article, 'hold');
        $orch = \Mockery::mock(DistributionOrchestrator::class);
        $orch->shouldReceive('enqueueForArticle')->once()->andReturnUsing(function ($id, ...$args) use ($realOrch) {
            $a = Article::query()->findOrFail($id);
            $fence = $args['workflowFence'] ?? end($args);
            if (! $realOrch->workflowFenceMatches($a, $fence)) {
                throw new \RuntimeException('distribution_workflow_superseded');
            }

            return [];
        });
        $this->app->instance(DistributionOrchestrator::class, $orch);
        $this->travel(61)->seconds();
        $service = app(ArticlePublicationDeliveryService::class);
        $this->assertSame(0, $service->recoverPending());
        $this->assertSame('superseded', data_get($receipt->fresh()->context, 'status'));
        $article->task()->increment('automation_version');
        $service->resumeForTask($article->task_id);
        $this->assertSame('superseded', data_get($receipt->fresh()->context, 'status'));
        $this->assertSame('hold', $article->fresh()->publication_intent);
    }

    public function test_automatic_pending_handoff_rebinds_after_task_resume(): void
    {
        Queue::fake();
        $realOrch = app(DistributionOrchestrator::class);
        [$article,$receipt] = $this->failedHandoff(true);
        $orch = \Mockery::mock(DistributionOrchestrator::class);
        $orch->shouldReceive('enqueueForArticle')->twice()->andReturnUsing(function ($id, ...$args) use ($realOrch) {
            $a = Article::query()->findOrFail($id);
            $fence = $args['workflowFence'] ?? end($args);
            if (! $realOrch->workflowFenceMatches($a, $fence)) {
                throw new \RuntimeException('distribution_workflow_superseded');
            }

            return [];
        });
        $this->app->instance(DistributionOrchestrator::class, $orch);
        $article->task()->update(['status' => 'paused', 'automation_version' => 2]);
        $this->travel(61)->seconds();
        $service = app(ArticlePublicationDeliveryService::class);
        $this->assertSame(0, $service->recoverPending());
        $this->assertSame('superseded', data_get($receipt->fresh()->context, 'status'));
        $article->task()->update(['status' => 'active', 'automation_version' => 3]);
        $service->resumeForTask($article->task_id);
        $this->assertSame('completed', data_get($receipt->fresh()->context, 'status'));
        $this->assertSame(3, data_get($receipt->fresh()->context, 'fence.automation_version'));
    }

    public function test_expired_old_claim_cannot_overwrite_newer_completed_receipt(): void
    {
        Queue::fake();
        [$article,$receipt] = $this->failedHandoff();
        $this->travel(61)->seconds();
        $service = app(ArticlePublicationDeliveryService::class);
        $calls = 0;
        $orch = \Mockery::mock(DistributionOrchestrator::class);
        $orch->shouldReceive('enqueueForArticle')->twice()->andReturnUsing(function () use (&$calls, $service, $receipt) {
            if (++$calls === 1) {
                $this->travel(301)->seconds();
                $this->assertTrue($service->attempt($receipt->id));
                throw new \RuntimeException('old_claim_failed_late');
            }

            return [];
        });
        $this->app->instance(DistributionOrchestrator::class, $orch);
        $this->assertFalse($service->attempt($receipt->id));
        $this->assertSame('completed', data_get($receipt->fresh()->context, 'status'));
        $this->assertSame(3, data_get($receipt->fresh()->context, 'attempts'));
        $this->assertSame(0, $service->recoverPending());
    }

    public function test_recovery_limit_counts_failed_attempts_and_skips_future_receipts(): void
    {
        Queue::fake();
        [, $future] = $this->failedHandoff();
        [, $firstDue] = $this->failedHandoff();
        [, $secondDue] = $this->failedHandoff();
        $future->update(['context' => array_replace($future->context, ['next_at' => now()->addHour()->toIso8601String()])]);
        $this->travel(61)->seconds();
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('enqueueForArticle')->once()->andThrow(new \RuntimeException('temporary_delivery_failure'));
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);

        $this->assertSame(0, app(ArticlePublicationDeliveryService::class)->recoverPending(1));

        $this->assertSame(1, data_get($future->fresh()->context, 'attempts'));
        $this->assertSame(2, data_get($firstDue->fresh()->context, 'attempts'));
        $this->assertSame(1, data_get($secondDue->fresh()->context, 'attempts'));
    }

    public function test_expired_third_lease_ends_automatic_handoff_attempts(): void
    {
        Queue::fake();
        [, $receipt] = $this->failedHandoff();
        $receipt->update(['context' => array_replace($receipt->context, [
            'status' => 'sending', 'attempts' => 3, 'lease' => 'interrupted-third-attempt',
            'next_at' => now()->subMinute()->toIso8601String(),
        ])]);
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldNotReceive('enqueueForArticle');
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);

        $this->assertFalse(app(ArticlePublicationDeliveryService::class)->attempt($receipt->id));
        $this->assertSame('exhausted', data_get($receipt->fresh()->context, 'status'));
        $this->assertSame(3, data_get($receipt->fresh()->context, 'attempts'));
        $this->assertNull(data_get($receipt->fresh()->context, 'next_at'));
    }

    public function test_transient_delivery_failure_is_bounded_and_explicit_republish_can_retry(): void
    {
        Queue::fake();
        [$article,$receipt] = $this->failedHandoff();
        $orch = \Mockery::mock(DistributionOrchestrator::class);
        $orch->shouldReceive('enqueueForArticle')->twice()->andThrow(new \RuntimeException('still_failing'));
        $this->app->instance(DistributionOrchestrator::class, $orch);
        $service = app(ArticlePublicationDeliveryService::class);
        $this->travel(61)->seconds();
        $this->assertSame(0, $service->recoverPending());
        $this->travel(301)->seconds();
        $this->assertSame(0, $service->recoverPending());
        $this->assertSame('exhausted', data_get($receipt->fresh()->context, 'status'));
        $this->assertSame(3, data_get($receipt->fresh()->context, 'attempts'));
        $this->travel(601)->seconds();
        $this->assertSame(0, $service->recoverPending());
        $recovered = \Mockery::mock(DistributionOrchestrator::class);
        $recovered->shouldReceive('enqueueForArticle')->once()->andReturn([]);
        $this->app->instance(DistributionOrchestrator::class, $recovered);
        app(ArticleWorkflowTransitionService::class)->humanAction($article->fresh(), 'publish');
        $this->assertSame('completed', data_get($receipt->fresh()->context, 'status'));
        $this->assertSame(1, data_get($receipt->fresh()->context, 'attempts'));
    }
}
