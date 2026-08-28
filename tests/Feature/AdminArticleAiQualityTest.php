<?php

namespace Tests\Feature;

use App\Jobs\ProcessArticleAiQualityJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleGeoFlowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminArticleAiQualityTest extends TestCase
{
    use RefreshDatabase;

    public function test_article_list_and_edit_page_show_ai_quality_result_and_issue_location(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 78,
            'summary' => '数据与知识库记录不一致，需要人工核查。',
            'knowledge_coverage' => 'sufficient',
            'dimension_scores' => [
                'knowledge_consistency' => 23,
                'data_traceability' => 13,
                'advertising_compliance' => 24,
                'content_integrity' => 10,
            ],
            'issues' => [[
                'code' => 'data_mismatch',
                'severity' => 'high',
                'field' => 'content',
                'quote' => '服务客户超过 1000 家',
                'paragraph_index' => 1,
                'reason' => '知识库记录为 800 家。',
                'suggestion' => '改为经审核的 800 家。',
                'knowledge_refs' => ['K001'],
                'legal_refs' => [],
            ]],
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->task()->update(['ai_quality_enabled' => false]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.index', ['ai_quality_status' => 'needs_review']))
            ->assertOk()
            ->assertSee(__('admin.articles.column.ai_quality'))
            ->assertSee(__('admin.articles.ai_quality.needs_review'))
            ->assertSee('78');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee(__('admin.articles.ai_quality.title'))
            ->assertSee('数据与知识库记录不一致')
            ->assertSee('服务客户超过 1000 家')
            ->assertSee('K001')
            ->assertSee('23/35')
            ->assertSee('13/25')
            ->assertSee('24/30')
            ->assertSee('data-ai-quality-locate', false)
            ->assertSee('revealRange', false)
            ->assertSee('name="run_ai_quality_after_save"', false)
            ->assertSee(route('admin.articles.ai-quality.override', ['articleId' => $article->id]), false);
    }

    public function test_published_article_with_a_failed_latest_check_shows_a_published_content_risk_warning(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'blocked',
            'score' => 55,
            'summary' => '关键事实与知识库不一致。',
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee(__('admin.articles.ai_quality.published_risk_title'))
            ->assertSee(__('admin.articles.ai_quality.published_risk_desc'));

        $this->assertSame('published', $article->fresh()->status);
    }

    public function test_published_ai_quality_risk_blocks_admin_and_api_content_updates(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'blocked',
            'score' => 55,
            'summary' => '关键事实与知识库不一致。',
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ])->save();
        $originalContent = (string) $article->content;

        $this->actingAs($admin, 'admin')
            ->from(route('admin.articles.edit', ['articleId' => $article->id]))
            ->put(route('admin.articles.update', ['articleId' => $article->id]), [
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content' => '后台尝试修改后的正文。',
                'keywords' => $article->keywords,
                'meta_description' => $article->meta_description,
                'category_id' => $article->category_id,
                'author_id' => $article->author_id,
                'status' => 'published',
                'review_status' => 'approved',
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionHasErrors();

        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertSame($originalContent, $article->content);

        $token = $admin->createToken('quality-published-update', ['articles:write'])->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->patchJson("/api/v1/articles/{$article->id}", ['content' => 'API 尝试修改后的正文。'])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'article_ai_quality_blocked');

        $article->refresh();
        $this->assertSame('published', $article->status);
        $this->assertSame($originalContent, $article->content);
    }

    public function test_article_history_shows_historical_conclusions_and_original_quotes(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $service = app(ArticleAiQualityInspectionService::class);
        $historical = $service->createOrReuse($article, dispatch: false);
        $historical->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 76,
            'summary' => '历史质检发现客户数量不一致。',
            'issues' => [[
                'code' => 'data_mismatch',
                'severity' => 'high',
                'field' => 'content',
                'quote' => '服务客户超过 1000 家',
                'paragraph_index' => 1,
                'reason' => '历史知识记录为 800 家。',
                'suggestion' => '按历史证据修订。',
                'start_offset' => 0,
                'end_offset' => 13,
                'knowledge_refs' => ['K1'],
                'legal_refs' => [],
            ]],
            'active_dedupe_key' => null,
            'finished_at' => now()->subMinute(),
        ])->save();
        $current = $service->createOrReuse($article, trigger: 'admin_recheck', dispatch: false, force: true);
        $current->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 96,
            'summary' => '当前质检已通过。',
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee('data-ai-quality-history-check', false)
            ->assertSee('历史质检发现客户数量不一致。')
            ->assertSee('历史知识记录为 800 家。')
            ->assertSee('服务客户超过 1000 家')
            ->assertSee('findTextRangeByOccurrence', false);
    }

    public function test_admin_can_override_reviewable_result_with_audited_reason(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 78,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.ai-quality.override', ['articleId' => $article->id]), [
                'ai_quality_override_reason' => '已核对客户盖章的数据证明材料',
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));

        $this->assertDatabaseHas('article_ai_quality_checks', [
            'id' => $check->id,
            'is_overridden' => true,
            'overridden_by' => $admin->id,
            'override_reason' => '已核对客户盖章的数据证明材料',
        ]);
    }

    public function test_admin_recheck_preserves_the_old_result_and_queues_a_successor(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $oldCheck = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $oldCheck->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.ai-quality.recheck', ['articleId' => $article->id]))
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));

        $this->assertSame('completed', $oldCheck->fresh()->status);
        $this->assertDatabaseHas('article_ai_quality_checks', [
            'article_id' => $article->id,
            'status' => 'queued',
            'supersedes_check_id' => $oldCheck->id,
        ]);
        Queue::assertPushed(ProcessArticleAiQualityJob::class);
    }

    public function test_admin_can_enable_and_queue_ai_quality_for_one_article_when_the_task_setting_is_disabled(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $article->forceFill([
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();
        $article->task()->update(['ai_quality_enabled' => false]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee(__('admin.articles.ai_quality.start_manual'))
            ->assertSee(__('admin.articles.ai_quality.manual_help'))
            ->assertSee('form="article-edit-form"', false)
            ->assertSee('name="run_ai_quality_after_save"', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.ai-quality.recheck', ['articleId' => $article->id]))
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));

        $article->refresh();
        $this->assertTrue($article->ai_quality_required_at_creation);
        $this->assertTrue((bool) data_get($article->ai_quality_policy_snapshot, 'required'));
        $this->assertSame('manual_article', data_get($article->ai_quality_policy_snapshot, 'source'));
        $this->assertFalse((bool) $article->task()->value('ai_quality_enabled'));
        $this->assertDatabaseHas('article_ai_quality_checks', [
            'article_id' => $article->id,
            'status' => 'queued',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.ai-quality.recheck', ['articleId' => $article->id]))
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));

        $this->assertDatabaseCount('article_ai_quality_checks', 1);
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_one_click_ai_quality_saves_the_current_form_content_before_it_queues_the_check(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $article->forceFill([
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();
        $article->task()->update(['ai_quality_enabled' => false]);

        $response = $this->actingAs($admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), [
                'title' => '点击质检前刚修改的标题',
                'excerpt' => '刚修改的摘要',
                'content' => '点击一键质检前尚未单独保存的正文。',
                'keywords' => '质检,保存',
                'meta_description' => '保存当前表单后再质检',
                'category_id' => $article->category_id,
                'author_id' => $article->author_id,
                'status' => 'draft',
                'review_status' => 'pending',
                'run_ai_quality_after_save' => '1',
            ]);
        $response->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));

        $article->refresh();
        $check = $article->aiQualityChecks()->latest('id')->firstOrFail();
        $this->assertSame('点击质检前刚修改的标题', $article->title);
        $this->assertSame('点击一键质检前尚未单独保存的正文。', $article->content);
        $this->assertSame($article->content, data_get($check->article_snapshot, 'content'));
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_article_with_a_required_policy_and_no_result_can_start_the_check_from_the_edit_page(): void
    {
        [$admin, $article] = $this->qualityArticle();

        $html = $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee(__('admin.articles.ai_quality.not_started'))
            ->getContent();

        $this->assertMatchesRegularExpression(
            '/<button(?=[^>]*name="run_ai_quality_after_save")(?![^>]*\sdisabled(?:\s|=|>))[^>]*>/i',
            $html,
        );
    }

    public function test_historical_ai_quality_result_remains_visible_after_the_article_policy_is_disabled(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 80,
            'summary' => '历史单篇质检结论仍需保留。',
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill([
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();
        $article->task()->update(['ai_quality_enabled' => false]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee(__('admin.articles.ai_quality.disabled_short'))
            ->assertSee('历史单篇质检结论仍需保留。');
    }

    public function test_private_article_content_change_queues_manual_quality_and_remembers_the_private_target(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $oldCheck = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $oldCheck->forceFill([
            'status' => 'completed',
            'decision' => 'passed',
            'score' => 100,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill([
            'status' => 'private',
            'review_status' => 'approved',
        ])->save();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), [
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content' => '私有文章修改后提交单篇 AI 质检。',
                'keywords' => $article->keywords,
                'meta_description' => $article->meta_description,
                'category_id' => $article->category_id,
                'author_id' => $article->author_id,
                'status' => 'private',
                'review_status' => 'approved',
                'run_ai_quality_after_save' => '1',
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionHas('message', __('admin.articles.ai_quality.recheck_queued'));

        $article->refresh();
        $check = $article->aiQualityChecks()->latest('id')->firstOrFail();
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame('queued', $check->status);
        $this->assertSame('private', data_get($check->execution_meta, 'requested_workflow_state.status'));
        $this->assertSame('approved', data_get($check->execution_meta, 'requested_workflow_state.review_status'));
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_published_article_without_content_changes_stays_published_while_manual_quality_is_queued(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $oldCheck = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $oldCheck->forceFill([
            'status' => 'completed',
            'decision' => 'blocked',
            'score' => 20,
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();
        $article->forceFill([
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now()->subHour(),
        ])->save();
        $publishedAt = $article->published_at?->toDateTimeString();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), [
                'title' => $article->title,
                'excerpt' => $article->excerpt,
                'content' => $article->content,
                'keywords' => $article->keywords,
                'meta_description' => $article->meta_description,
                'category_id' => $article->category_id,
                'author_id' => $article->author_id,
                'status' => 'published',
                'review_status' => 'approved',
                'run_ai_quality_after_save' => '1',
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertSessionHas('message', __('admin.articles.ai_quality.recheck_queued'));

        $article->refresh();
        $check = $article->aiQualityChecks()->latest('id')->firstOrFail();
        $this->assertSame('published', $article->status);
        $this->assertSame('approved', $article->review_status);
        $this->assertSame($publishedAt, $article->published_at?->toDateTimeString());
        $this->assertSame('queued', $check->status);
        $this->assertSame('published', data_get($check->execution_meta, 'requested_workflow_state.status'));
        Queue::assertPushed(ProcessArticleAiQualityJob::class, 1);
    }

    public function test_manual_ai_quality_prerequisite_failure_keeps_the_article_and_history_unchanged(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $article->forceFill([
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();
        $article->task()->update(['ai_quality_enabled' => false]);
        $article->task->knowledgeBases()->detach();

        $response = $this->actingAs($admin, 'admin')
            ->from(route('admin.articles.edit', ['articleId' => $article->id]))
            ->post(route('admin.articles.ai-quality.recheck', ['articleId' => $article->id]));

        $response->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));
        $this->assertStringContainsString(
            __('admin.articles.ai_quality.manual_unavailable_knowledge'),
            implode(' ', session('errors')->all()),
        );
        $article->refresh();
        $this->assertFalse($article->ai_quality_required_at_creation);
        $this->assertNull($article->ai_quality_policy_snapshot);
        $this->assertDatabaseCount('article_ai_quality_checks', 0);
        Queue::assertNothingPushed();
    }

    public function test_recheck_failures_do_not_expose_internal_exception_details(): void
    {
        [$admin, $article] = $this->qualityArticle();
        $article->task()->update(['ai_quality_prompt_id' => null]);
        $internalMessage = 'database-host.internal.example secret-model-token';
        $this->mock(ArticleAiQualityInspectionService::class)
            ->shouldReceive('requestManualInspection')
            ->twice()
            ->andThrow(new \RuntimeException($internalMessage));

        $adminResponse = $this->actingAs($admin, 'admin')
            ->from(route('admin.articles.edit', ['articleId' => $article->id]))
            ->post(route('admin.articles.ai-quality.recheck', ['articleId' => $article->id]));

        $adminResponse->assertRedirect(route('admin.articles.edit', ['articleId' => $article->id]));
        $this->assertStringNotContainsString($internalMessage, implode(' ', session('errors')->all()));

        $token = $admin->createToken('quality-error-api', ['articles:publish'])->plainTextToken;
        $apiResponse = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck");

        $apiResponse->assertStatus(409)
            ->assertJsonPath('error.code', 'article_ai_quality_failed');
        $this->assertStringNotContainsString($internalMessage, (string) $apiResponse->getContent());
    }

    public function test_api_detail_filter_recheck_and_override_expose_quality_workflow(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $issuedToken = $admin->createToken('quality-api', [
            'articles:read',
            'articles:write',
            'articles:publish',
        ]);
        $token = $issuedToken->plainTextToken;
        $check = app(ArticleAiQualityInspectionService::class)->createOrReuse($article, dispatch: false);
        $check->forceFill([
            'status' => 'completed',
            'decision' => 'needs_review',
            'score' => 78,
            'summary' => '等待管理员核查。',
            'dimension_scores' => ['factual_consistency' => 23],
            'issues' => [['code' => 'data_mismatch', 'severity' => 'high']],
            'active_dedupe_key' => null,
            'finished_at' => now(),
        ])->save();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/articles?ai_quality_status=failed')
            ->assertOk()
            ->assertJsonPath('data.items.0.id', $article->id)
            ->assertJsonPath('data.items.0.ai_quality.decision', 'needs_review');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson("/api/v1/articles/{$article->id}")
            ->assertOk()
            ->assertJsonPath('data.ai_quality.check_id', $check->id)
            ->assertJsonPath('data.ai_quality.issues.0.code', 'data_mismatch');

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/override", [
                'reason' => '已通过企业原始证明材料核对',
            ])
            ->assertOk()
            ->assertJsonPath('data.ai_quality.is_overridden', true);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/v1/articles/{$article->id}/ai-quality/recheck")
            ->assertOk()
            ->assertJsonPath('data.ai_quality.status', 'queued');

        $manualRequest = $article->aiQualityChecks()->latest('id')->firstOrFail()->execution_meta['manual_requests'][0];
        $this->assertSame((int) $admin->id, (int) $manualRequest['admin_id']);
        $this->assertSame((int) $issuedToken->accessToken->id, (int) $manualRequest['api_token_id']);
        Queue::assertPushed(ProcessArticleAiQualityJob::class);
    }

    public function test_api_task_rebinding_snapshots_the_effective_quality_policy_on_the_article(): void
    {
        Queue::fake();
        [$admin, $article] = $this->qualityArticle();
        $taskId = (int) $article->task_id;
        $article->forceFill([
            'task_id' => null,
            'ai_quality_required_at_creation' => false,
            'ai_quality_policy_snapshot' => null,
        ])->save();

        app(ArticleGeoFlowService::class)->updateArticle($article->id, [
            'task_id' => $taskId,
        ], $admin->id);

        $article->refresh();
        $this->assertTrue($article->ai_quality_required_at_creation);
        $this->assertTrue((bool) data_get($article->ai_quality_policy_snapshot, 'required'));
        $this->assertSame($taskId, $article->task_id);
    }

    /** @return array{Admin, Article} */
    private function qualityArticle(): array
    {
        $admin = Admin::query()->create([
            'username' => 'article-quality-admin-'.uniqid(),
            'password' => 'secret-123',
            'display_name' => 'AI Quality Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $model = AiModel::query()->create([
            'name' => '质检模型 '.uniqid(),
            'version' => '1',
            'api_key' => 'test',
            'model_id' => 'quality-model',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $prompt = Prompt::query()->where('system_key', 'article_quality.cn_ads_knowledge.v1')->firstOrFail();
        $task = Task::query()->create([
            'name' => '质检任务 '.uniqid(),
            'ai_model_id' => $model->id,
            'ai_quality_enabled' => true,
            'ai_quality_prompt_id' => $prompt->id,
            'ai_quality_model_id' => $model->id,
            'ai_quality_pass_score' => 85,
            'ai_quality_manual_override_min_score' => 70,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '文章质检知识库 '.uniqid(),
            'content' => '服务客户为 800 家。',
        ]);
        $task->knowledgeBases()->sync([$knowledgeBase->id => ['sort_order' => 0]]);
        $category = Category::query()->create(['name' => '质检分类', 'slug' => 'quality-category-'.uniqid()]);
        $author = Author::query()->create(['name' => '质检作者']);
        $article = Article::query()->create([
            'title' => 'AI 质检文章',
            'slug' => 'ai-quality-article-'.uniqid(),
            'excerpt' => '摘要',
            'content' => '服务客户超过 1000 家。',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'ai_quality_required_at_creation' => true,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        return [$admin, $article];
    }
}
