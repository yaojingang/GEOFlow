<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReconcileDistributionOnlyArticlesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_dry_by_default_and_apply_repairs_only_invalid_articles(): void
    {
        $distributionTask = $this->createTask('distribution_only');
        $localTask = $this->createTask('local_and_distribution');
        $invalidArticle = $this->createArticle('invalid', $distributionTask, 'published');
        $validPrivateArticle = $this->createArticle('valid-private', $distributionTask, 'private');
        $localArticle = $this->createArticle('local', $localTask, 'published');
        $tasklessArticle = $this->createArticle('taskless', null, 'published');

        $this->artisan('geoflow:reconcile-distribution-only-articles')
            ->assertSuccessful()
            ->expectsOutputToContain('Matched distribution-only published articles: 1 (dry run)');

        $this->assertSame('published', $invalidArticle->fresh()->status);
        $this->assertNotNull($invalidArticle->fresh()->published_at);

        $this->artisan('geoflow:reconcile-distribution-only-articles', ['--apply' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Reconciled distribution-only published articles: 1');

        $this->assertSame('private', $invalidArticle->fresh()->status);
        $this->assertNull($invalidArticle->fresh()->published_at);
        $this->assertSame('private', $validPrivateArticle->fresh()->status);
        $this->assertSame('published', $localArticle->fresh()->status);
        $this->assertSame('published', $tasklessArticle->fresh()->status);

        $this->artisan('geoflow:reconcile-distribution-only-articles', ['--apply' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Reconciled distribution-only published articles: 0');
    }

    private function createTask(string $publishScope): Task
    {
        return Task::query()->create([
            'name' => 'Reconcile task '.uniqid(),
            'status' => 'paused',
            'schedule_enabled' => 0,
            'publish_scope' => $publishScope,
        ]);
    }

    private function createArticle(string $suffix, ?Task $task, string $status): Article
    {
        $category = Category::query()->create([
            'name' => 'Reconcile category '.$suffix,
            'slug' => 'reconcile-category-'.$suffix,
        ]);
        $author = Author::query()->create([
            'name' => 'Reconcile author '.$suffix,
            'email' => 'reconcile-'.$suffix.'@example.test',
        ]);

        return Article::query()->create([
            'title' => 'Reconcile article '.$suffix,
            'slug' => 'reconcile-article-'.$suffix,
            'content' => 'Reconcile article content.',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task?->id,
            'status' => $status,
            'review_status' => 'approved',
            'published_at' => $status === 'published' ? now() : null,
        ]);
    }
}
