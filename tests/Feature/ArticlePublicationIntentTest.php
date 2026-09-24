<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityPolicyResolver;
use App\Services\GeoFlow\ArticlePublicationEligibilityService;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ArticlePublicationIntentTest extends TestCase
{
    use RefreshDatabase;

    public function test_auto_approval_does_not_publish_a_draft_or_private_article(): void
    {
        foreach (['draft', 'private'] as $status) {
            $this->assertSame($status, ArticleWorkflow::normalizeState($status, 'auto_approved')['status']);
        }
    }

    public function test_human_approval_records_current_content_and_preserves_a_hold(): void
    {
        $article = $this->article();
        $approved = app(ArticleWorkflowTransitionService::class)->humanAction($article, 'approve');
        $this->assertSame('draft', $approved->status);
        $this->assertSame('hold', $approved->publication_intent);
        $this->assertSame('approved', $approved->review_status);
        $this->assertSame($approved->reviewContentHash(), $approved->reviews()->sole()->content_hash);
        $this->assertSame(2, $approved->workflow_version);
        $again = app(ArticleWorkflowTransitionService::class)->humanAction($approved, 'approve');
        $this->assertSame(2, $again->workflow_version);
        $this->assertSame(1, $again->reviews()->count());
    }

    public function test_content_changes_invalidate_approval_without_deleting_its_audit(): void
    {
        $service = app(ArticleWorkflowTransitionService::class);
        $approved = $service->humanAction($this->article(), 'approve');
        $approved->update(['content' => 'New factual content.']);
        $changed = $service->contentChanged($approved);
        $this->assertSame('pending', $changed->review_status);
        $this->assertSame(3, $changed->workflow_version);
        $this->assertSame(1, $changed->reviews()->count());
    }

    public function test_stale_human_action_cannot_overwrite_a_new_rejection(): void
    {
        $service = app(ArticleWorkflowTransitionService::class);
        $article = $this->article();
        $service->humanAction($article, 'reject', expectedVersion: 1);
        try {
            $service->humanAction($article, 'approve', expectedVersion: 1);
            $this->fail('Expected version conflict.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('workflow_version_conflict', $exception->getMessage());
        }
        $this->assertSame('rejected', $article->fresh()->review_status);
    }

    public function test_latest_task_policy_removes_historical_ai_requirement_and_preserves_zero_floor(): void
    {
        $task = $this->task();
        $article = $this->article(['task_id' => $task->id, 'ai_quality_required_at_creation' => true]);
        $this->assertFalse(app(ArticleAiQualityPolicyResolver::class)->resolve($article)['required']);
        $task->update(['ai_quality_enabled' => true, 'ai_quality_manual_override_min_score' => 0]);
        $policy = app(ArticleAiQualityPolicyResolver::class)->resolve($article->fresh());
        $this->assertTrue($policy['required']);
        $this->assertSame(0, $policy['manual_override_min_score']);
    }

    public function test_review_switch_recomputes_without_releasing_holds_or_rejections(): void
    {
        $task = $this->task(['need_review' => 1]);
        $scheduled = $this->article(['task_id' => $task->id]);
        $held = $this->article(['task_id' => $task->id, 'publication_intent' => 'hold']);
        $rejected = $this->article(['task_id' => $task->id, 'review_status' => 'rejected']);
        app(TaskLifecycleService::class)->updateTask($task->id, ['need_review' => 0]);
        $this->assertSame('auto_approved', $scheduled->fresh()->review_status);
        $this->assertSame('scheduled', $scheduled->fresh()->publication_intent);
        $this->assertSame('hold', $held->fresh()->publication_intent);
        $this->assertSame('rejected', $rejected->fresh()->review_status);
        app(TaskLifecycleService::class)->updateTask($task->id, ['need_review' => 1]);
        $this->assertSame('pending', $scheduled->fresh()->review_status);
    }

    public function test_hold_and_task_pause_invalidate_older_automatic_fences(): void
    {
        $task = $this->task(['status' => 'active', 'schedule_enabled' => 1]);
        $article = $this->article(['task_id' => $task->id]);
        $eligibility = app(ArticlePublicationEligibilityService::class);
        $fence = $eligibility->fence($article);
        $this->assertTrue($eligibility->fenceAllows($article, $fence));
        app(ArticleWorkflowTransitionService::class)->humanAction($article, 'hold');
        $this->assertFalse($eligibility->fenceAllows($article->fresh(), $fence));
        $scheduled = $this->article(['task_id' => $task->id]);
        $oldCycle = $eligibility->fence($scheduled);
        app(TaskLifecycleService::class)->stopTask($task->id);
        $this->assertGreaterThan($oldCycle['automation_version'], $task->fresh()->automation_version);
        $this->assertSame('scheduled', $scheduled->fresh()->publication_intent);
        $this->assertFalse($eligibility->fenceAllows($scheduled->fresh(), $oldCycle));
        $this->assertFalse($eligibility->fenceAllows($scheduled->fresh(), null));
    }

    public function test_repeated_immediate_publish_preserves_intent_version_and_retries_idempotent_delivery(): void
    {
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('enqueueForArticle')->twice()->andReturn([]);
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);
        $task = $this->task(['next_publish_at' => now()->addHour()]);
        $article = $this->article(['task_id' => $task->id, 'review_status' => 'auto_approved']);
        $service = app(ArticleWorkflowTransitionService::class);
        $published = $service->humanAction($article, 'publish');
        $this->assertSame('published', $published->status);
        $this->assertSame('none', $published->publication_intent);
        $version = $published->workflow_version;
        $this->assertSame($version, $service->humanAction($published, 'publish')->workflow_version);
    }

    private function task(array $attributes = []): Task
    {
        return Task::query()->create($attributes + [
            'name' => 'Workflow contract', 'status' => 'paused', 'schedule_enabled' => 0,
            'need_review' => 0, 'ai_quality_enabled' => false, 'publish_scope' => 'local_only',
        ]);
    }

    private function article(array $attributes = []): Article
    {
        return Article::query()->create($attributes + [
            'category_id' => Category::query()->create(['name' => 'Workflow', 'slug' => 'category-'.uniqid()])->id,
            'author_id' => Author::query()->create(['name' => 'Workflow author'])->id,
            'title' => 'Workflow article', 'slug' => 'workflow-'.uniqid(),
            'content' => 'Plain factual content.', 'excerpt' => '', 'keywords' => '', 'meta_description' => '',
            'status' => 'draft', 'review_status' => 'pending',
        ]);
    }
}
