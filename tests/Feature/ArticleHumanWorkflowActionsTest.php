<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleReview;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionLog;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\SensitiveWord;
use App\Models\Task;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\ArticlePublicationDeliveryService;
use App\Services\GeoFlow\ArticlePublicationEligibilityService;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ArticleHumanWorkflowActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_review_records_approval_without_publishing_a_task_article(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $task = Task::query()->create(['name' => 'Scheduled review', 'need_review' => 0, 'ai_quality_enabled' => false]);
        $article = $this->article(['task_id' => $task->id]);

        $this->actingAs($admin, 'admin')->postJson(route('admin.articles.batch.update-review'), [
            'article_ids' => [$article->id], 'review_status' => 'approved',
        ])->assertOk()->assertJsonPath('results.0.status', 'success')
            ->assertJsonPath('results.0.actual_state.status', 'draft');

        $this->assertSame('approved', $article->fresh()->review_status);
        $this->assertSame('draft', $article->fresh()->status);
        $this->assertDatabaseHas('article_reviews', ['article_id' => $article->id, 'admin_id' => $admin->id, 'review_status' => 'approved']);
    }

    public function test_batch_result_accounts_for_unique_missing_blocked_and_unchanged_articles(): void
    {
        Queue::fake();
        $approved = $this->article(['review_status' => 'approved', 'status' => 'published', 'published_at' => now()]);
        $pending = $this->article();
        $response = $this->actingAs($this->admin(), 'admin')->postJson(route('admin.articles.batch.update-status'), [
            'article_ids' => [$approved->id, $pending->id, $pending->id, 999999], 'new_status' => 'published',
        ])->assertOk()->assertJsonCount(3, 'results')->assertJsonPath('totals.total', 3);
        $this->assertSame(3, array_sum(array_intersect_key($response->json('totals'), array_flip(['success', 'unchanged', 'blocked', 'conflict', 'failed']))));
        $this->assertSame('blocked', $response->json('results.1.status'));
        $this->assertSame('failed', $response->json('results.2.status'));
        $this->assertSame('pending', $pending->fresh()->review_status);
        $this->assertSame('draft', $pending->fresh()->status);
    }

    public function test_batch_rejects_a_stale_workflow_version_and_keeps_the_newer_rejection(): void
    {
        Queue::fake();
        $article = $this->article(['review_status' => 'rejected', 'workflow_version' => 3]);
        $this->actingAs($this->admin(), 'admin')->postJson(route('admin.articles.batch.update-review'), [
            'article_ids' => [$article->id], 'review_status' => 'approved', 'workflow_versions' => [$article->id => 2],
        ])->assertOk()->assertJsonPath('results.0.status', 'conflict');
        $this->assertSame('rejected', $article->fresh()->review_status);
    }

    public function test_batch_draft_and_private_preserve_manual_hold(): void
    {
        Queue::fake();
        foreach (['draft', 'private'] as $status) {
            $article = $this->article(['review_status' => 'approved']);
            $this->actingAs($this->admin(), 'admin')->postJson(route('admin.articles.batch.update-status'), [
                'article_ids' => [$article->id], 'new_status' => $status,
            ])->assertOk()->assertJsonPath('results.0.actual_state.status', $status)
                ->assertJsonPath('results.0.actual_state.publication_intent', 'hold');
        }
    }

    public function test_legacy_auto_approval_is_a_human_review_without_an_implicit_publish(): void
    {
        Queue::fake();
        $article = $this->article();
        app(ArticleGeoFlowService::class)->reviewArticle($article->id, 'auto_approved', 'Compatibility review', '', $this->admin()->id);
        $this->assertSame('approved', $article->fresh()->review_status);
        $this->assertSame('draft', $article->fresh()->status);
    }

    public function test_approval_note_never_overrides_risk_when_resuming_an_existing_publish_request(): void
    {
        Queue::fake();
        $article = $this->article(['content' => 'Warning phrase', 'publication_intent' => 'immediate']);
        SensitiveWord::query()->create(['word' => 'Warning phrase', 'severity' => 'warning']);
        $result = app(ArticleWorkflowTransitionService::class)
            ->humanAction($article, 'approve', $this->admin()->id, 'Approved editorial content');
        $this->assertSame('approved', $result->review_status);
        $this->assertSame('draft', $result->status);
        $this->assertSame('immediate', $result->publication_intent);
        $this->assertFalse((bool) $result->latestRiskScan->is_overridden);
    }

    public function test_empty_batch_returns_validation_feedback(): void
    {
        $this->actingAs($this->admin(), 'admin')->postJson(route('admin.articles.batch.update-status'), [
            'article_ids' => [], 'new_status' => 'published',
        ])->assertUnprocessable()->assertJsonValidationErrors('article_ids');
    }

    public function test_api_schedule_and_hold_obey_versions_and_publication_scope(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $token = $admin->createToken('workflow-actions', ['articles:read', 'articles:publish'])->plainTextToken;
        $task = Task::query()->create(['name' => 'Scheduled', 'need_review' => 0, 'ai_quality_enabled' => false]);
        $article = $this->article(['task_id' => $task->id, 'publication_intent' => 'hold']);

        $scheduled = $this->withToken($token)->postJson('/api/v1/articles/'.$article->id.'/schedule', [
            'workflow_version' => (int) $article->workflow_version,
        ])->assertOk()->assertJsonPath('data.publication_intent', 'scheduled')->assertJsonPath('data.status', 'draft');
        $version = $scheduled->json('data.workflow_version');
        $this->withToken($token)->postJson('/api/v1/articles/'.$article->id.'/hold', [
            'status' => 'private', 'workflow_version' => $version - 1,
        ])->assertConflict();
        $this->assertSame('scheduled', $article->fresh()->publication_intent);
        $this->withToken($token)->postJson('/api/v1/articles/'.$article->id.'/hold', [
            'status' => 'private', 'workflow_version' => $version,
        ])->assertOk()->assertJsonPath('data.publication_intent', 'hold')->assertJsonPath('data.status', 'private');

        $readToken = $admin->createToken('workflow-read', ['articles:read'])->plainTextToken;
        $this->withToken($readToken)->postJson('/api/v1/articles/'.$article->id.'/schedule')->assertForbidden();
    }

    public function test_edit_keep_preserves_schedule_while_invalidating_the_old_content_approval(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $task = Task::query()->create(['name' => 'Keep schedule', 'need_review' => 1, 'ai_quality_enabled' => false]);
        $article = $this->article(['task_id' => $task->id, 'publication_intent' => 'scheduled']);
        app(ArticleGeoFlowService::class)->reviewArticle($article->id, 'approved', '', '', $admin->id);
        $article->refresh();
        $version = (int) $article->workflow_version;
        $this->actingAs($admin, 'admin')->put(route('admin.articles.update', ['articleId' => $article->id]), [
            'title' => 'Updated factual article', 'excerpt' => '', 'content' => 'Updated facts.',
            'keywords' => '', 'meta_description' => '', 'category_id' => $article->category_id,
            'author_id' => $article->author_id, 'status' => 'keep', 'review_status' => 'keep',
            'workflow_version' => $version,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();
        $article->refresh();
        $this->assertSame('Updated factual article', $article->title);
        $this->assertSame('scheduled', $article->publication_intent);
        $this->assertSame('pending', $article->review_status);
        $this->assertGreaterThan($version, $article->workflow_version);
        $this->assertSame(1, ArticleReview::query()->where('article_id', $article->id)->count());
    }

    public function test_risk_recheck_alert_preserves_publication_and_human_approval(): void
    {
        Queue::fake();
        $article = $this->article(['status' => 'published', 'review_status' => 'approved', 'published_at' => now()]);
        SensitiveWord::query()->create(['word' => 'factual article', 'severity' => 'blocked']);
        $this->actingAs($this->admin(), 'admin')->post(route('admin.articles.risk-scan', ['articleId' => $article->id]))
            ->assertRedirect()->assertSessionHasErrors();
        $article->refresh();
        $this->assertSame('blocked', $article->latestRiskScan->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertSame('published', $article->status);
        $this->assertNotNull($article->published_at);
    }

    public function test_published_keep_rejects_blocked_new_content_and_rolls_back_the_edit(): void
    {
        Queue::fake();
        $article = $this->article(['status' => 'published', 'review_status' => 'approved', 'published_at' => now()]);
        $before = $article->getAttributes();
        SensitiveWord::query()->create(['word' => 'UNSAFE_NEW_CLAIM', 'severity' => 'blocked']);

        $this->actingAs($this->admin(), 'admin')->put(route('admin.articles.update', ['articleId' => $article->id]), [
            'title' => 'Edited title', 'content' => 'UNSAFE_NEW_CLAIM', 'excerpt' => '', 'keywords' => '', 'meta_description' => '',
            'category_id' => $article->category_id, 'author_id' => $article->author_id,
            'status' => 'keep', 'review_status' => 'keep', 'workflow_version' => (int) $article->workflow_version,
        ])->assertRedirect()->assertSessionHasErrors();

        $article->refresh();
        foreach (['title', 'content', 'status', 'review_status', 'published_at', 'workflow_version'] as $field) {
            $this->assertSame($before[$field] ?? null, $article->getRawOriginal($field), $field);
        }
        $this->assertSame(0, $article->riskScans()->count());
        $this->assertSame(0, $article->reviews()->count());
    }

    public function test_api_quality_only_patch_accepts_a_workflow_version_without_content_fields(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $article = $this->article(['task_id' => $this->qualityTask()->id]);
        $token = $admin->createToken('quality-only-version', ['articles:read', 'articles:write', 'articles:publish'])->plainTextToken;
        $this->withToken($token)->patchJson('/api/v1/articles/'.$article->id, [
            'ai_quality_retrieval_mode_override' => null,
            'config_version' => max(1, (int) $article->ai_quality_policy_version),
            'workflow_version' => (int) $article->workflow_version,
        ])->assertOk()->assertJsonPath('data.content', $article->content);
        $this->assertSame($article->content, $article->fresh()->content);
        $this->assertSame((int) $article->workflow_version, (int) $article->fresh()->workflow_version);
    }

    public function test_api_noop_content_update_still_rejects_a_stale_workflow_version(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $article = $this->article(['workflow_version' => 5]);
        $token = $admin->createToken('noop-version', ['articles:write'])->plainTextToken;
        $this->withToken($token)->patchJson('/api/v1/articles/'.$article->id, [
            'content' => $article->content, 'workflow_version' => 4,
        ])->assertConflict()->assertJsonPath('error.code', 'workflow_version_conflict');
        $this->assertSame(5, (int) $article->fresh()->workflow_version);
        $this->assertSame($article->content, $article->fresh()->content);
        $this->assertSame(0, $article->riskScans()->count());
    }

    public function test_private_article_waiting_for_quality_reports_queued_publication_without_becoming_public(): void
    {
        Queue::fake();
        $task = $this->qualityTask();
        $article = $this->article(['task_id' => $task->id, 'status' => 'private', 'review_status' => 'auto_approved', 'publication_intent' => 'hold']);
        $this->actingAs($this->admin(), 'admin')->postJson(route('admin.articles.batch.update-status'), [
            'article_ids' => [$article->id], 'new_status' => 'published', 'workflow_versions' => [$article->id => (int) $article->workflow_version],
        ])->assertOk()->assertJsonPath('results.0.status', 'success')->assertJsonPath('results.0.reason', 'waiting_checks')
            ->assertJsonPath('results.0.actual_state.status', 'private')->assertJsonPath('results.0.actual_state.publication_intent', 'immediate');
        $article->refresh();
        $check = $article->latestAiQualityCheck()->firstOrFail();
        $this->assertSame('queued', $check->status);
        $this->assertSame('published', data_get($check->execution_meta, 'requested_workflow_state.status'));
        $this->assertSame((int) $article->workflow_version, data_get($check->execution_meta, 'workflow_fence.workflow_version'));
        $this->assertNull($article->published_at);
    }

    public function test_api_task_rebind_increments_workflow_version_and_invalidates_the_old_callback_fence(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $source = Task::query()->create(['name' => 'Source task', 'need_review' => 0, 'ai_quality_enabled' => false]);
        $target = Task::query()->create(['name' => 'Target task', 'need_review' => 0, 'ai_quality_enabled' => false]);
        $article = $this->article(['task_id' => $source->id, 'publication_intent' => 'scheduled']);
        $version = (int) $article->workflow_version;
        $eligibility = app(ArticlePublicationEligibilityService::class);
        $fence = $eligibility->fence($article);
        $token = $admin->createToken('task-rebind-version', ['articles:read', 'articles:write', 'articles:publish'])->plainTextToken;
        $this->withToken($token)->patchJson('/api/v1/articles/'.$article->id, [
            'task_id' => $target->id, 'config_version' => max(1, (int) $article->ai_quality_policy_version), 'workflow_version' => $version,
        ])->assertOk()->assertJsonPath('data.task_id', $target->id);
        $this->assertGreaterThan($version, (int) $article->fresh()->workflow_version);
        $this->assertFalse($eligibility->fenceAllows($article->fresh(), $fence));
    }

    private function qualityTask(): Task
    {
        $model = AiModel::query()->create([
            'name' => 'Entrypoint quality model', 'version' => '1', 'api_key' => 'test', 'model_id' => 'entrypoint-quality',
            'api_url' => 'https://example.test', 'model_type' => 'chat', 'status' => 'active',
        ]);
        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $knowledge = KnowledgeBase::query()->create(['name' => 'Entrypoint knowledge', 'content' => 'A factual article.']);
        $task = Task::query()->create([
            'name' => 'Entrypoint quality task', 'status' => 'active', 'schedule_enabled' => 1, 'need_review' => 0,
            'ai_model_id' => $model->id, 'ai_quality_enabled' => true, 'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_pass_score' => 85, 'ai_quality_manual_override_min_score' => 70,
        ]);
        $task->knowledgeBases()->sync([$knowledge->id => ['sort_order' => 0]]);

        return $task;
    }

    private function admin(): Admin
    {
        return Admin::query()->create(['username' => 'workflow-'.uniqid(), 'password' => 'secret', 'role' => 'admin', 'status' => 'active']);
    }

    private function article(array $attributes = []): Article
    {
        return Article::query()->create(array_merge(['title' => 'Workflow article', 'slug' => 'workflow-'.uniqid(), 'content' => 'A factual article.', 'category_id' => Category::query()->create(['name' => 'Workflow', 'slug' => uniqid('workflow-')])->id, 'author_id' => Author::query()->create(['name' => 'Workflow author'])->id, 'status' => 'draft', 'review_status' => 'pending'], $attributes));
    }

    public function test_admin_edit_enqueues_after_outer_transaction_commits(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $task = Task::query()->create(['name' => 'publish edit', 'need_review' => 0, 'ai_quality_enabled' => false, 'status' => 'active', 'schedule_enabled' => 1]);
        $article = $this->article(['task_id' => $task->id]);
        $baseline = DB::transactionLevel();
        $enqueueLevel = null;
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('enqueueForArticle')->once()->andReturnUsing(function () use (&$enqueueLevel) {
            $enqueueLevel = DB::transactionLevel();

            return [];
        });
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);
        $this->actingAs($admin, 'admin')->put(route('admin.articles.update', ['articleId' => $article->id]), [
            'title' => $article->title, 'content' => $article->content, 'excerpt' => $article->excerpt ?? '', 'keywords' => '', 'meta_description' => '', 'category_id' => $article->category_id, 'author_id' => $article->author_id,
            'status' => 'published', 'review_status' => 'keep', 'workflow_version' => (int) $article->workflow_version,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();
        $this->assertSame('published', $article->fresh()->status);
        $this->assertSame($baseline, $enqueueLevel);
    }

    public function test_api_create_enqueues_after_outer_transaction_commits(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $task = Task::query()->create(['name' => 'publish api create', 'need_review' => 0, 'ai_quality_enabled' => false, 'status' => 'active', 'schedule_enabled' => 1]);
        $article = $this->article();
        $baseline = DB::transactionLevel();
        $enqueueLevel = null;
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('enqueueForArticle')->once()->andReturnUsing(function () use (&$enqueueLevel) {
            $enqueueLevel = DB::transactionLevel();

            return [];
        });
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);
        $result = app(ArticleGeoFlowService::class)->createArticle([
            'title' => 'API new publish', 'content' => $article->content, 'category_id' => $article->category_id, 'author_id' => $article->author_id, 'task_id' => $task->id,
            'status' => 'published', 'review_status' => 'approved',
        ], $admin->id);
        $this->assertSame('published', $result['status']);
        $this->assertSame($baseline, $enqueueLevel);
    }

    public function test_api_create_enqueue_failure_is_durable_and_recovers_after_idempotent_replay(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $token = $admin->createToken('review-create', ['articles:write', 'articles:read', 'articles:publish'])->plainTextToken;
        $task = Task::query()->create(['name' => 'publish api failure', 'need_review' => 0, 'ai_quality_enabled' => false, 'status' => 'active', 'schedule_enabled' => 1]);
        $article = $this->article();
        $orchestrator = \Mockery::mock(DistributionOrchestrator::class);
        $orchestrator->shouldReceive('enqueueForArticle')->once()->andThrow(new \RuntimeException('transient_enqueue_failure'));
        $this->app->instance(DistributionOrchestrator::class, $orchestrator);
        $body = ['title' => 'API enqueue failure', 'content' => $article->content, 'category_id' => $article->category_id, 'author_id' => $article->author_id, 'task_id' => $task->id, 'status' => 'published', 'review_status' => 'approved'];
        $this->withToken($token)->withHeader('X-Idempotency-Key', 'review-enqueue-after-commit')->postJson('/api/v1/articles', $body)->assertCreated();
        $created = Article::query()->where('title', 'API enqueue failure')->firstOrFail();
        $this->assertSame('published', $created->status);
        $this->assertSame('none', $created->publication_intent);
        $this->assertSame(0, $created->distributions()->count());
        $again = $this->withToken($token)->withHeader('X-Idempotency-Key', 'review-enqueue-after-commit')->postJson('/api/v1/articles', $body);
        $again->assertCreated();
        $receipt = DistributionLog::query()->where('event', ArticlePublicationDeliveryService::EVENT)->firstOrFail();
        $this->assertSame('pending', data_get($receipt->context, 'status'));
        $recovered = \Mockery::mock(DistributionOrchestrator::class);
        $recovered->shouldReceive('enqueueForArticle')->once()->andReturn([]);
        $this->app->instance(DistributionOrchestrator::class, $recovered);
        $this->travel(61)->seconds();
        $this->assertSame(1, app(ArticlePublicationDeliveryService::class)->recoverPending());
        $this->assertSame('completed', data_get($receipt->fresh()->context, 'status'));
        $this->assertSame(0, app(ArticlePublicationDeliveryService::class)->recoverPending());
    }
}
