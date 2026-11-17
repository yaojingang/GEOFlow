<?php

namespace Tests\Feature;

use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Jobs\ConvergeArticleAiQualityDeadlineJob;
use App\Jobs\ProcessArticleAiQualityJob;
use App\Jobs\RetryArticleAiQualityCheckJob;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ArticleAiQualityDeadlineConvergenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_deadline_job_leaves_a_check_alone_until_its_deadline(): void
    {
        $check = $this->check([
            'status' => 'queued',
            'deadline_at' => now()->addMinutes(3),
        ]);

        $changed = app(ArticleAiQualityReconciliationService::class)->convergeCheck((int) $check->id, 'final');

        $this->assertFalse($changed);
        $this->assertSame('queued', $check->fresh()->status);
    }

    public function test_deadline_job_fails_one_check_after_its_final_deadline(): void
    {
        $check = $this->check([
            'status' => 'running',
            'deadline_at' => now()->subSecond(),
        ]);

        (new ConvergeArticleAiQualityDeadlineJob((int) $check->id, 'final'))
            ->handle(app(ArticleAiQualityReconciliationService::class));

        $this->assertSame('failed', $check->fresh()->status);
    }

    public function test_scheduled_watch_does_not_scan_future_technical_retries(): void
    {
        $check = $this->check([
            'status' => 'failed',
            'error_code' => 'provider_gateway_error',
            'deadline_at' => now()->subMinute(),
            'execution_meta' => [
                'technical_retry' => [
                    'attempt' => 0,
                    'next_at' => now()->addMinutes(5)->toIso8601String(),
                ],
            ],
        ]);

        $this->artisan('geoflow:converge-ai-quality', ['--deadlines-only' => true, '--json' => true])
            ->assertSuccessful();

        $this->assertSame(0, app(ArticleAiQualityReconciliationService::class)->retryFailedChecks());
        $this->assertNull(data_get($check->fresh()->execution_meta, 'technical_retry.replacement_id'));
    }

    public function test_marking_a_transient_failure_queues_one_delayed_retry(): void
    {
        Queue::fake();
        $check = $this->check([
            'status' => 'running',
            'inspection_scope' => 'full',
            'error_code' => null,
        ]);

        $this->freezeSecond();
        app(ArticleAiQualityInspectionService::class)->markFailed(
            $check, new ArticleAiQualityRuntimeException('provider_gateway_error', true, retryAfterSeconds: 90),
        );

        Queue::assertPushed(RetryArticleAiQualityCheckJob::class, function (RetryArticleAiQualityCheckJob $job) use ($check): bool {
            return $job->checkId === (int) $check->id && $job->queue === 'ai-quality-backfill'
                && $job->connection === 'redis' && $job->delay->equalTo(now()->addSeconds(90));
        });
    }

    public function test_primary_deadline_cannot_fail_a_check_concurrently_changed_to_sampled(): void
    {
        $this->freezeSecond();
        $check = $this->check([
            'primary_deadline_at' => now()->subSecond(),
            'deadline_at' => now()->addSeconds(50),
        ]);
        $sampledAt = now()->addSeconds(30);
        $changed = false;
        DB::listen(function (QueryExecuted $event) use ($check, $sampledAt, &$changed): void {
            if (! $changed && str_contains($event->sql, 'exists') && str_contains($event->sql, 'article_ai_quality_checks')) {
                $changed = true;
                $check->update(['inspection_scope' => 'fallback_sampled', 'sampled_deadline_at' => $sampledAt]);
            }
        });

        $job = new ConvergeArticleAiQualityDeadlineJob($check->id, 'primary', 'full', now()->subSecond()->timestamp);
        $job->handle(app(ArticleAiQualityReconciliationService::class));

        $this->assertTrue($changed);
        $this->assertSame('queued', $check->fresh()->status);
        $this->assertSame('fallback_sampled', $check->fresh()->inspection_scope);
    }

    public function test_auxiliary_deadline_failure_leaves_main_check_queued_for_processing(): void
    {
        Exceptions::fake();
        $deadline = now()->addMinute();
        $check = $this->check(['primary_deadline_at' => $deadline, 'deadline_at' => $deadline]);
        $connection = Mockery::mock(QueueContract::class);
        $connection->shouldReceive('push')->once()->withArgs(fn ($job, ...$args) => $job instanceof ProcessArticleAiQualityJob);
        $failure = new RuntimeException('Deadline queue unavailable');
        $connection->shouldReceive('later')->once()->withArgs(fn ($delay, $job, ...$args) => $job instanceof ConvergeArticleAiQualityDeadlineJob)
            ->andThrow($failure);
        Queue::shouldReceive('connection')->with('redis')->andReturn($connection);

        app(ArticleAiQualityInspectionService::class)->dispatchQueuedInspection($check);

        $this->assertSame('queued', $check->fresh()->status);
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception === $failure);
    }

    public function test_real_redis_consumes_earlier_sampled_deadline_while_online_job_is_reserved(): void
    {
        $socket = getenv('GEOFLOW_TEST_REDIS_SOCKET');
        if (! $socket || ! file_exists($socket) || filetype($socket) !== 'socket') {
            $this->markTestSkipped('Set GEOFLOW_TEST_REDIS_SOCKET to a disposable Redis server socket.');
        }

        $this->freezeSecond();
        foreach (['default', 'cache'] as $connection) {
            config([
                "database.redis.{$connection}.host" => $socket,
                "database.redis.{$connection}.port" => 0,
                "database.redis.{$connection}.url" => null,
                "database.redis.{$connection}.password" => null,
            ]);
        }
        config(['database.redis.options.prefix' => 'quality-test:'.Str::uuid().':', 'cache.default' => 'redis']);
        $check = $this->check([
            'primary_deadline_at' => now()->addSeconds(60),
            'deadline_at' => now()->addSeconds(115),
            'execution_meta' => ['policy_snapshot' => ['timeout_sampling_enabled' => true]],
        ]);

        $check->task()->update(['ai_quality_enabled' => true, 'ai_quality_timeout_sampling_enabled' => true]);
        $service = app(ArticleAiQualityInspectionService::class);
        $service->dispatchQueuedInspection($check);
        $queue = Queue::connection('redis');
        $reserved = $queue->pop('ai-quality');
        $this->assertNotNull($reserved);
        $this->assertSame(2, $queue->delayedSize('ai-quality-deadlines'));
        $this->assertSame(0, $queue->delayedSize('ai-quality'));

        $this->assertTrue($service->tryStartSampledFallback($check, new ArticleAiQualityRuntimeException('provider_timeout', true)));
        $check->refresh();
        $this->assertSame('fallback_sampled', $check->inspection_scope);
        $this->assertTrue($check->sampled_deadline_at->lt($check->primary_deadline_at));
        $this->assertSame(3, $queue->delayedSize('ai-quality-deadlines'));

        $this->travelTo($check->sampled_deadline_at->copy()->addSecond());
        $this->artisan('queue:work', ['connection' => 'redis', '--queue' => 'ai-quality-deadlines', '--once' => true, '--timeout' => 35])
            ->assertSuccessful();
        $this->assertSame('failed', $check->fresh()->status);
        $this->assertSame(1, $queue->reservedSize('ai-quality'));
        $this->assertSame(2, $queue->delayedSize('ai-quality-deadlines'));
        $reserved->delete();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function check(array $overrides): ArticleAiQualityCheck
    {
        $task = Task::query()->create(['name' => 'Deadline convergence task']);
        $category = Category::query()->create(['name' => 'Deadline', 'slug' => 'deadline-convergence']);
        $author = Author::query()->create(['name' => 'Deadline author']);
        $article = Article::query()->create([
            'title' => 'Deadline convergence article',
            'slug' => 'deadline-convergence-article-'.Str::lower(Str::random(8)),
            'content' => 'Deadline convergence content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        return ArticleAiQualityCheck::query()->create([
            'article_id' => $article->id,
            'task_id' => $task->id,
            'request_key' => (string) Str::uuid(),
            'status' => 'queued',
            'inspection_scope' => 'full',
            'pass_score' => 85,
            'manual_override_min_score' => 70,
            'input_fingerprint' => hash('sha256', (string) Str::uuid()),
            'algorithm_version' => '1.0.0',
            ...$overrides,
        ]);
    }
}
