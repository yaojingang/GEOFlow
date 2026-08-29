<?php

namespace Tests\Feature;

use App\Jobs\ArticleAiQualityProbeJob;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityHealthService;
use App\Services\GeoFlow\ArticleAiQualityVersionPolicy;
use App\Services\GeoFlow\ArticleAiQualityWorkerLiveness;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStarting;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ArticleAiQualityHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('worker_heartbeats')) {
            Schema::create('worker_heartbeats', function (Blueprint $table): void {
                $table->string('worker_id', 100)->primary();
                $table->string('status', 20)->default('idle');
                $table->timestamp('last_seen_at');
                $table->text('meta')->nullable();
                $table->timestamps();
            });
        }
    }

    public function test_worker_heartbeats_are_counted_by_exact_quality_queue(): void
    {
        config()->set('geoflow.ai_quality_front_workers', 1);
        Event::dispatch(new WorkerStarting('database', 'ai-quality', new WorkerOptions));
        Event::dispatch(new WorkerStarting('redis', 'default,ai-quality', new WorkerOptions));
        Event::dispatch(new Looping('redis', 'ai-quality-backfill'));

        $this->assertDatabaseCount('worker_heartbeats', 2);

        $counts = app(ArticleAiQualityWorkerLiveness::class)->freshCounts();

        $this->assertSame(['front' => 1, 'backfill' => 1], $counts);
        $this->assertSame('healthy', app(ArticleAiQualityWorkerLiveness::class)->serviceStatus());
    }

    public function test_a_front_probe_acknowledges_the_exact_queue_without_business_data(): void
    {
        Cache::forget(ArticleAiQualityProbeJob::cacheKey('probe-token'));
        $job = new ArticleAiQualityProbeJob('probe-token', 'ai-quality');

        $job->handle();

        $this->assertSame('ai-quality', Cache::get(ArticleAiQualityProbeJob::cacheKey('probe-token'))['queue']);
    }

    public function test_a_stopping_worker_removes_its_registered_quality_heartbeats(): void
    {
        Event::dispatch(new WorkerStarting('redis', 'ai-quality', new WorkerOptions));
        $this->assertDatabaseCount('worker_heartbeats', 1);

        Event::dispatch(new WorkerStopping);

        $this->assertDatabaseCount('worker_heartbeats', 0);
    }

    public function test_health_snapshot_is_unavailable_when_the_front_consumer_is_missing(): void
    {
        $service = $this->healthyRedisService();

        $snapshot = $service->snapshot();

        $this->assertSame('unavailable', $snapshot['status']);
        $this->assertContains('front_consumer_missing', $snapshot['issues']);
    }

    public function test_health_command_returns_nonzero_when_the_front_consumer_is_missing(): void
    {
        $this->artisan('geoflow:ai-quality-health', ['--json' => true])
            ->expectsOutputToContain('"status":"unavailable"')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_health_snapshot_is_healthy_with_all_required_consumers_and_a_valid_timeout_chain(): void
    {
        config()->set('geoflow.ai_quality_front_workers', 1);
        app(ArticleAiQualityWorkerLiveness::class)->record('redis', 'ai-quality,ai-quality-backfill');
        $service = $this->healthyRedisService();

        $snapshot = $service->snapshot();

        $this->assertSame('healthy', $snapshot['status']);
        $this->assertSame([], $snapshot['issues']);
        $this->assertSame(['business' => 235, 'job' => 245, 'worker' => 250, 'retry_after' => 960], $snapshot['timeouts']);
        $this->assertTrue($snapshot['quality_metrics_24h']['sampled_auto_release_enabled']);
        $this->assertSame(0, $snapshot['quality_metrics_24h']['fallback_sampled']['total']);
        $this->assertSame(1.0, $snapshot['quality_metrics_24h']['fallback_sampled']['terminal_convergence_rate']);
    }

    public function test_health_snapshot_surfaces_an_exhausted_post_quality_workflow(): void
    {
        config()->set('geoflow.ai_quality_front_workers', 1);
        app(ArticleAiQualityWorkerLiveness::class)->record('redis', 'ai-quality,ai-quality-backfill');
        $task = Task::query()->create(['name' => 'Quality workflow health task']);
        $category = Category::query()->create(['name' => 'Quality health', 'slug' => 'quality-health']);
        $author = Author::query()->create(['name' => 'Quality health author']);
        $article = Article::query()->create([
            'title' => 'Quality workflow health article',
            'slug' => 'quality-workflow-health-article',
            'content' => 'Quality workflow health content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
        ArticleAiQualityCheck::query()->create([
            'article_id' => $article->id,
            'task_id' => $task->id,
            'request_key' => 'workflow-health-check',
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 95,
            'pass_score' => 85,
            'manual_override_min_score' => 70,
            'input_fingerprint' => hash('sha256', 'workflow-health-check'),
            'algorithm_version' => '1.0.0',
            'execution_meta' => [
                'workflow_apply' => [
                    'status' => 'exhausted',
                    'attempts' => 3,
                    'error_code' => 'workflow_apply_exhausted',
                ],
            ],
            'finished_at' => now(),
        ]);

        $snapshot = $this->healthyRedisService()->snapshot();

        $this->assertSame('degraded', $snapshot['status']);
        $this->assertContains('workflow_apply_exhausted', $snapshot['issues']);
        $this->assertSame(1, $snapshot['quality_metrics_24h']['all']['workflow_exhausted']);
        $this->assertSame(0, $snapshot['quality_metrics_24h']['all']['passed']);
    }

    private function healthyRedisService(): ArticleAiQualityHealthService
    {
        return new class(app(ArticleAiQualityWorkerLiveness::class), app(ArticleAiQualityVersionPolicy::class)) extends ArticleAiQualityHealthService
        {
            protected function redisAvailable(): bool
            {
                return true;
            }
        };
    }
}
