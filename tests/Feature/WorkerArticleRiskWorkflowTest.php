<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SensitiveWord;
use App\Models\Task;
use App\Services\GeoFlow\ArticlePublicationEligibilityService;
use App\Services\GeoFlow\ArticleRiskGate;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

class WorkerArticleRiskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_worker_publishes_clean_approved_draft_after_recording_a_fresh_scan(): void
    {
        SensitiveWord::query()->create(['word' => 'manual review']);
        [$task, $article] = $this->createTaskArticle();

        $result = $this->publishDueDraft($task);

        $this->assertSame((int) $article->id, $result['article_id'] ?? null);
        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertSame('clean', $article->latestRiskScan?->status);
        $this->assertSame('worker_publish', $article->latestRiskScan?->trigger);
        $this->assertSame(1, (int) $task->fresh()->published_count);
    }

    public function test_worker_reads_task_before_reloading_the_article_for_transition(): void
    {
        [$task] = $this->createTaskArticle();
        $lockedTables = [];
        $fixtureTransactionLevel = DB::transactionLevel();
        DB::listen(function ($query) use (&$lockedTables, $fixtureTransactionLevel): void {
            if (DB::transactionLevel() <= $fixtureTransactionLevel || ! str_starts_with(ltrim(strtolower((string) $query->sql)), 'select')) {
                return;
            }
            preg_match('/\bfrom\s+"([^"]+)"/', strtolower((string) $query->sql), $firstTable);
            if (($firstTable[1] ?? null) === 'articles' && ! str_starts_with(strtolower((string) $query->sql), 'select *')) {
                return;
            }
            if (in_array($firstTable[1] ?? null, ['articles', 'tasks'], true)) {
                $lockedTables[] = $firstTable[1];
            }
        });

        $this->publishDueDraft($task);

        $articleIndex = array_search('articles', $lockedTables, true);
        $taskIndex = array_search('tasks', $lockedTables, true);
        $this->assertIsInt($articleIndex);
        $this->assertIsInt($taskIndex);
        $this->assertLessThan($articleIndex, $taskIndex);
    }

    public function test_worker_risk_warning_preserves_approval_without_counting_a_publish(): void
    {
        SensitiveWord::query()->create(['word' => 'manual review']);
        [$task, $article] = $this->createTaskArticle(['content' => 'This needs manual review.']);

        $result = $this->publishDueDraft($task);

        $this->assertNull($result);
        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertSame('warning', $article->latestRiskScan?->status);
        $this->assertSame('worker_publish', $article->latestRiskScan?->trigger);
        $this->assertSame(0, (int) $task->fresh()->published_count);
    }

    public function test_worker_non_publication_verdict_preserves_human_approval(): void
    {
        [$task, $article] = $this->createTaskArticle([
            'content' => "【待人工复核，禁止发布】\n原因：资料不足。",
        ]);

        $result = $this->publishDueDraft($task);

        $this->assertNull($result);
        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertSame('blocked', $article->latestRiskScan?->status);
        $this->assertSame('publication_verdict', $article->latestRiskScan?->matches[0]['category']);
        $this->assertSame(0, (int) $task->fresh()->published_count);
    }

    public function test_worker_publishes_manually_approved_warning_with_a_fresh_override(): void
    {
        SensitiveWord::query()->create(['word' => 'manual review']);
        [$task, $article] = $this->createTaskArticle(['content' => 'This needs manual review.']);
        $admin = Admin::query()->create([
            'username' => 'risk-reviewer',
            'password' => 'secret-password',
            'role' => 'admin',
            'status' => 1,
        ]);
        app(ArticleRiskGate::class)->check($article, 'admin_review', (int) $admin->id, 'Context verified.');

        $result = $this->publishDueDraft($task);

        $this->assertSame((int) $article->id, $result['article_id'] ?? null);
        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertTrue((bool) $article->latestRiskScan?->is_overridden);
        $this->assertSame(1, (int) $task->fresh()->published_count);
    }

    public function test_worker_exempt_article_can_use_a_current_risk_override_without_fabricating_human_approval(): void
    {
        SensitiveWord::query()->create(['word' => 'manual review']);
        [$task, $article] = $this->createTaskArticle([
            'content' => 'This needs manual review.',
            'review_status' => 'auto_approved',
        ], ['need_review' => 0]);
        $admin = Admin::query()->create([
            'username' => 'risk-reviewer',
            'password' => 'secret-password',
            'role' => 'admin',
            'status' => 1,
        ]);
        app(ArticleRiskGate::class)->check($article, 'admin_review', (int) $admin->id, 'Context verified.');

        $result = $this->publishDueDraft($task);

        $this->assertSame((int) $article->id, $result['article_id']);
        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertSame('auto_approved', $article->review_status);
        $this->assertTrue($article->latestRiskScan->is_overridden);
        $this->assertSame(0, $article->reviews()->count());
        $this->assertSame(1, (int) $task->fresh()->published_count);
    }

    public function test_worker_keeps_approved_distribution_only_article_private_and_enqueues_it(): void
    {
        [$task, $article] = $this->createTaskArticle([], [
            'publish_scope' => 'distribution_only',
        ]);
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('enqueueForArticle')
            ->once()
            ->with((int) $article->id, 'publish', [], true, \Mockery::on(fn (array $fence): bool => $fence === app(ArticlePublicationEligibilityService::class)->fence($article->fresh(), 'automatic')))
            ->andReturn([]);
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $this->assertSame((int) $article->id, $result['article_id']);
        $this->assertSame('private', $article->fresh()->status);
        $this->assertSame('approved', $article->fresh()->review_status);
        $this->assertNull($article->fresh()->published_at);
    }

    public function test_worker_keeps_auto_approved_distribution_only_article_private_and_enqueues_it(): void
    {
        [$task, $article] = $this->createTaskArticle([
            'review_status' => 'auto_approved',
        ], [
            'publish_scope' => 'distribution_only',
            'need_review' => 0,
        ]);
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('enqueueForArticle')
            ->once()
            ->with((int) $article->id, 'publish', [], true, \Mockery::on(fn (array $fence): bool => $fence === app(ArticlePublicationEligibilityService::class)->fence($article->fresh(), 'automatic')))
            ->andReturn([]);
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);

        $result = app(WorkerExecutionService::class)->executeTask((int) $task->id);

        $this->assertSame((int) $article->id, $result['article_id']);
        $this->assertSame('private', $article->fresh()->status);
        $this->assertSame('auto_approved', $article->fresh()->review_status);
        $this->assertNull($article->fresh()->published_at);
    }

    public function test_worker_skips_a_blocked_fifo_head_and_publishes_the_next_eligible_article_once(): void
    {
        Queue::fake();
        [$task, $blocked] = $this->createTaskArticle([
            'content' => 'UNSAFE_FIFO_CLAIM', 'review_status' => 'auto_approved', 'publication_intent' => 'scheduled',
        ], ['need_review' => 0, 'ai_quality_enabled' => false]);
        $clean = Article::query()->create([
            'title' => 'Second FIFO article', 'slug' => 'second-fifo', 'content' => 'Safe factual content.',
            'category_id' => $blocked->category_id, 'author_id' => $blocked->author_id, 'task_id' => $task->id,
            'status' => 'draft', 'review_status' => 'auto_approved', 'publication_intent' => 'scheduled',
        ]);
        SensitiveWord::query()->create(['word' => 'UNSAFE_FIFO_CLAIM', 'severity' => 'blocked']);

        $result = $this->publishDueDraft($task);

        $this->assertSame('publish_draft', $result['meta']['action']);
        $this->assertSame($clean->id, $result['article_id']);
        $this->assertSame('published', $clean->fresh()->status);
        $this->assertSame('draft', $blocked->fresh()->status);
        $this->assertSame('scheduled', $blocked->fresh()->publication_intent);
        $this->assertSame('auto_approved', $blocked->fresh()->review_status);
        $this->assertSame('blocked', $blocked->fresh()->latestRiskScan->status);
        $this->assertNull($this->publishDueDraft($task->fresh()));
        $this->assertSame(1, (int) $task->fresh()->published_count);
    }

    /**
     * @param  array<string, mixed>  $articleOverrides
     * @param  array<string, mixed>  $taskOverrides
     * @return array{Task, Article}
     */
    private function createTaskArticle(array $articleOverrides = [], array $taskOverrides = []): array
    {
        $task = Task::query()->create(array_merge([
            'name' => 'Risk worker task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'publish_scope' => 'local_and_distribution',
            'next_publish_at' => now()->subMinute(),
        ], $taskOverrides));
        $category = Category::query()->create([
            'name' => 'Worker risk',
            'slug' => 'worker-risk-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'Worker risk author',
            'email' => uniqid().'@example.com',
        ]);
        $article = Article::query()->create(array_merge([
            'title' => 'Worker risk article',
            'slug' => 'worker-risk-article-'.uniqid(),
            'excerpt' => 'Safe excerpt.',
            'content' => 'Safe article content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'draft',
            'review_status' => 'approved',
            'published_at' => null,
        ], $articleOverrides));

        return [$task, $article];
    }

    /** @return array<string, mixed>|null */
    private function publishDueDraft(Task $task): ?array
    {
        $service = app(WorkerExecutionService::class);
        $method = new ReflectionMethod($service, 'publishDueDraftArticle');

        return $method->invoke($service, $task);
    }
}
