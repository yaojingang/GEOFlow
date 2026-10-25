<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiQualityAuditEvent;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReconcileArticleWorkflowCommandTest extends TestCase
{
    use RefreshDatabase;

    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_preview_is_read_only_and_apply_requires_a_manifest(): void
    {
        $article = $this->article();
        $before = $article->fresh()->getAttributes();
        Artisan::call('geoflow:reconcile-article-workflow', ['--article' => [$article->id]]);
        $preview = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($preview['dry_run']);
        $this->assertSame('auto_approved', $preview['items'][0]['proposed_review_status']);
        $this->assertSame($before, $article->fresh()->getAttributes());
        $this->assertSame(1, Artisan::call('geoflow:reconcile-article-workflow', ['--apply' => true]));
    }

    public function test_reviewed_apply_and_repeated_apply_are_idempotent(): void
    {
        $article = $this->article();
        $path = $this->manifest($article);
        $this->assertSame(0, Artisan::call('geoflow:reconcile-article-workflow', ['--apply' => true, '--manifest' => $path]));
        $this->assertSame('auto_approved', $article->fresh()->review_status);
        $version = $article->fresh()->workflow_version;
        $this->assertSame(0, Artisan::call('geoflow:reconcile-article-workflow', ['--apply' => true, '--manifest' => $path]));
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('unchanged', $result['results'][0]['status']);
        $this->assertSame($version, $article->fresh()->workflow_version);
        $this->assertSame('draft', $article->fresh()->status);
    }

    public function test_changes_after_preview_are_skipped(): void
    {
        $article = $this->article();
        $path = $this->manifest($article);
        $article->update(['workflow_version' => 2, 'review_status' => 'rejected', 'publication_intent' => 'hold']);
        Artisan::call('geoflow:reconcile-article-workflow', ['--apply' => true, '--manifest' => $path]);
        $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('conflict', $result['results'][0]['status']);
        $this->assertSame('rejected', $article->fresh()->review_status);
    }

    public function test_ambiguous_hold_requires_explicit_per_article_release_and_never_approves_content(): void
    {
        $article = $this->article(['publication_intent' => 'hold']);
        $article->task()->update(['need_review' => 1]);
        $path = $this->manifest($article);
        Artisan::call('geoflow:reconcile-article-workflow', ['--apply' => true, '--manifest' => $path]);
        $this->assertSame('hold', $article->fresh()->publication_intent);
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $manifest['items'][0]['release_hold'] = true;
        file_put_contents($path, json_encode($manifest));
        Artisan::call('geoflow:reconcile-article-workflow', ['--apply' => true, '--manifest' => $path]);
        $this->assertSame('scheduled', $article->fresh()->publication_intent);
        $this->assertSame('pending', $article->fresh()->review_status);
        $this->assertSame('draft', $article->fresh()->status);
    }

    public function test_partial_failure_rolls_back_one_item_and_rerun_resumes_without_reapplying_completed_items(): void
    {
        $articles = [$this->article(), $this->article(), $this->article()];
        $path = sys_get_temp_dir().'/workflow-reentry-'.uniqid().'.json';
        $this->paths[] = $path;
        Artisan::call('geoflow:reconcile-article-workflow', ['--article' => array_map(fn ($article) => $article->id, $articles), '--manifest' => $path]);
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        foreach ($manifest['items'] as &$item) {
            $item['reviewed'] = true;
        }
        unset($item);
        file_put_contents($path, json_encode($manifest));
        $failedOnce = false;
        Event::listen('eloquent.creating: '.AiQualityAuditEvent::class,
            function ($event) use ($articles, &$failedOnce): void {
                if (! $failedOnce && $event->event_type === 'article_workflow_reconciled' && (int) $event->article_id === $articles[1]->id) {
                    $failedOnce = true;
                    throw new \RuntimeException('Injected item failure');
                }
            });
        Artisan::call('geoflow:reconcile-article-workflow', ['--apply' => true, '--manifest' => $path]);
        $first = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['success', 'failed', 'success'], array_column($first['results'], 'status'));
        $this->assertSame('pending', $articles[1]->fresh()->review_status);
        $versions = [$articles[0]->fresh()->workflow_version, $articles[2]->fresh()->workflow_version];
        Artisan::call('geoflow:reconcile-article-workflow', ['--apply' => true, '--manifest' => $path]);
        $second = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['unchanged', 'success', 'unchanged'], array_column($second['results'], 'status'));
        $this->assertSame($versions, [$articles[0]->fresh()->workflow_version, $articles[2]->fresh()->workflow_version]);
        $this->assertSame('auto_approved', $articles[1]->fresh()->review_status);
        $this->assertSame(3, AiQualityAuditEvent::query()->where('correlation_id', $manifest['manifest_id'])->count());
    }

    public function test_changed_article_quality_policy_conflicts_with_old_recovery_manifest(): void
    {
        Queue::fake();
        $admin = Admin::query()->create(['username' => 'recovery-policy-probe', 'password' => 'secret', 'role' => 'admin', 'status' => 'active']);
        $token = $admin->createToken('probe', ['articles:read', 'articles:write', 'articles:publish'])->plainTextToken;
        $task = Task::query()->create(['name' => 'Recovery policy task', 'need_review' => 0, 'ai_quality_enabled' => false, 'status' => 'paused', 'publish_scope' => 'local_only']);
        $article = Article::query()->create(['title' => 'Recovery policy article', 'slug' => 'recovery-policy-probe', 'content' => 'Factual text.', 'task_id' => $task->id,
            'category_id' => Category::query()->create(['name' => 'Probe', 'slug' => 'probe'])->id, 'author_id' => Author::query()->create(['name' => 'Probe'])->id,
            'status' => 'draft', 'review_status' => 'pending', 'publication_intent' => 'hold', 'ai_quality_policy_version' => 1, 'ai_quality_retrieval_mode_override' => 'knowledge_broad']);
        $path = sys_get_temp_dir().'/recovery-policy-manifest-'.uniqid().'.json';
        try {
            Artisan::call('geoflow:reconcile-article-workflow', ['--article' => [$article->id], '--manifest' => $path]);
            $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            $manifest['items'][0]['reviewed'] = true;
            $manifest['items'][0]['release_hold'] = true;
            file_put_contents($path, json_encode($manifest));
            $this->withToken($token)->patchJson('/api/v1/articles/'.$article->id, [
                'ai_quality_retrieval_mode_override' => null, 'config_version' => 1, 'workflow_version' => (int) $article->workflow_version,
            ])->assertOk();
            $this->assertSame(2, (int) $article->fresh()->ai_quality_policy_version);
            $this->assertSame((int) $article->workflow_version, (int) $article->fresh()->workflow_version);
            Artisan::call('geoflow:reconcile-article-workflow', ['--apply' => true, '--manifest' => $path]);
            $result = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('conflict', $result['results'][0]['status']);
            $this->assertSame('preview_changed', $result['results'][0]['reason']);
            $this->assertSame('hold', $article->fresh()->publication_intent);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    private function manifest(Article $article): string
    {
        $path = sys_get_temp_dir().'/geoflow-workflow-'.uniqid().'.json';
        $this->paths[] = $path;
        Artisan::call('geoflow:reconcile-article-workflow', ['--article' => [$article->id], '--manifest' => $path]);
        $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $manifest['items'][0]['reviewed'] = true;
        file_put_contents($path, json_encode($manifest));

        return $path;
    }

    private function article(array $attributes = []): Article
    {
        $task = Task::query()->create(['name' => 'Recovery', 'need_review' => 0, 'status' => 'paused', 'publish_scope' => 'local_only']);

        return Article::query()->create($attributes + [
            'task_id' => $task->id, 'title' => 'Recovery article', 'slug' => 'recovery-'.uniqid(), 'content' => 'Plain content.',
            'category_id' => Category::query()->create(['name' => 'Recovery', 'slug' => 'category-'.uniqid()])->id,
            'author_id' => Author::query()->create(['name' => 'Recovery author'])->id,
            'status' => 'draft', 'review_status' => 'pending',
        ]);
    }
}
