<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SensitiveWord;
use App\Models\Task;
use App\Services\GeoFlow\ArticleRiskGate;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_worker_downgrades_unoverridden_warning_to_pending_without_counting_a_publish(): void
    {
        SensitiveWord::query()->create(['word' => 'manual review']);
        [$task, $article] = $this->createTaskArticle(['content' => 'This needs manual review.']);

        $result = $this->publishDueDraft($task);

        $this->assertNull($result);
        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertNull($article->published_at);
        $this->assertSame('warning', $article->latestRiskScan?->status);
        $this->assertSame('worker_publish', $article->latestRiskScan?->trigger);
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

    public function test_worker_auto_approval_cannot_reuse_a_manual_warning_override(): void
    {
        SensitiveWord::query()->create(['word' => 'manual review']);
        [$task, $article] = $this->createTaskArticle([
            'content' => 'This needs manual review.',
            'review_status' => 'auto_approved',
        ]);
        $admin = Admin::query()->create([
            'username' => 'risk-reviewer',
            'password' => 'secret-password',
            'role' => 'admin',
            'status' => 1,
        ]);
        app(ArticleRiskGate::class)->check($article, 'admin_review', (int) $admin->id, 'Context verified.');

        $result = $this->publishDueDraft($task);

        $this->assertNull($result);
        $article->refresh();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame(0, (int) $task->fresh()->published_count);
    }

    /**
     * @param  array<string, mixed>  $articleOverrides
     * @return array{Task, Article}
     */
    private function createTaskArticle(array $articleOverrides = []): array
    {
        $task = Task::query()->create([
            'name' => 'Risk worker task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_interval' => 3600,
            'publish_scope' => 'local_and_distribution',
            'next_publish_at' => now()->subMinute(),
        ]);
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
