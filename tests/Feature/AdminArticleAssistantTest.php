<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Data\Ai\DirectAdminAiExecutionContext;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiModelMutationService;
use App\Services\GeoFlow\ArticleContentGenerationService;
use App\Services\GeoFlow\DirectAdminAiInvocationBoundaryHook;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminArticleAssistantTest extends TestCase
{
    use RefreshDatabase;

    private ?Admin $modelOwner = null;

    public function test_create_page_renders_title_picker_and_ai_generation_options(): void
    {
        $admin = $this->createAdmin('assistant_page');
        $library = TitleLibrary::query()->create(['name' => 'GEO 标题库']);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => 'GEO 标题示例',
            'keyword' => 'GEO',
        ]);
        $knowledgeBase = $this->createKnowledgeBase();
        $prompt = $this->createPrompt();
        $model = $this->createModel();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.create'))
            ->assertOk()
            ->assertSee('id="article-title-picker-open"', false)
            ->assertSee('id="article-title-picker-modal"', false)
            ->assertSee('id="article-create-assistant"', false)
            ->assertSee(AdminWeb::routePath('admin.articles.editor.titles'), false)
            ->assertSee(AdminWeb::routePath('admin.articles.editor.generate'), false)
            ->assertSee('id="article-ai-knowledge-base"', false)
            ->assertSee('value="'.$knowledgeBase->id.'"', false)
            ->assertSee('value="'.$prompt->id.'"', false)
            ->assertSee('value="'.$model->id.'"', false)
            ->assertSee('GEO 标题库 · 1');
    }

    public function test_title_picker_searches_and_filters_titles_by_usage(): void
    {
        $admin = $this->createAdmin('assistant_titles');
        $library = TitleLibrary::query()->create(['name' => '产品标题库']);
        $otherLibrary = TitleLibrary::query()->create(['name' => '行业标题库']);
        $unused = Title::query()->create([
            'library_id' => $library->id,
            'title' => 'GEO 产品怎么选',
            'keyword' => 'GEO 产品',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        Title::query()->create([
            'library_id' => $library->id,
            'title' => 'GEO 产品使用指南',
            'keyword' => 'GEO 产品',
            'used_count' => 2,
            'usage_count' => 2,
        ]);
        Title::query()->create([
            'library_id' => $otherLibrary->id,
            'title' => 'GEO 行业观察',
            'keyword' => 'GEO 行业',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.editor.titles', [
                'library_id' => $library->id,
                'search' => 'geo',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $unused->id)
            ->assertJsonPath('items.0.library_name', '产品标题库')
            ->assertJsonPath('pagination.total', 1);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.articles.editor.titles', [
                'library_id' => $library->id,
                'usage' => 'used',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.used_count', 2);
    }

    public function test_edit_page_keeps_category_options_without_loading_create_assistant(): void
    {
        $admin = $this->createAdmin('assistant_edit');
        $category = Category::query()->create([
            'name' => '编辑页分类',
            'slug' => 'assistant-edit-category',
        ]);
        $author = Author::query()->create(['name' => '编辑页作者']);
        $article = Article::query()->create([
            'title' => '已有文章',
            'slug' => 'existing-assistant-article',
            'content' => '已有正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.articles.edit', ['articleId' => $article->id]))
            ->assertOk()
            ->assertSee('编辑页分类')
            ->assertSee('编辑页作者')
            ->assertDontSee('id="article-create-assistant"', false)
            ->assertDontSee('id="article-title-picker-modal"', false);
    }

    public function test_creating_article_tracks_selected_title_and_ai_generation_flag(): void
    {
        $admin = $this->createAdmin('assistant_store');
        $category = Category::query()->create([
            'name' => '内容分类',
            'slug' => 'assistant-content',
        ]);
        $author = Author::query()->create(['name' => 'GEOFlow']);
        $library = TitleLibrary::query()->create(['name' => '创建标题库']);
        $title = Title::query()->create([
            'library_id' => $library->id,
            'title' => 'AI CRM 实施指南',
            'keyword' => 'AI CRM',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.store'), [
                'title' => $title->title,
                'content' => "## 正文\n\nAI 生成的文章内容。[K1] Vitamin K2 保留。",
                'excerpt' => '',
                'keywords' => 'AI CRM',
                'meta_description' => '',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'draft',
                'review_status' => 'pending',
                'source_title_id' => $title->id,
                'is_ai_generated' => '1',
            ])
            ->assertRedirect();

        $article = Article::query()->where('title', $title->title)->firstOrFail();
        $this->assertTrue((bool) $article->is_ai_generated);
        $this->assertSame("## 正文\n\nAI 生成的文章内容。Vitamin K2 保留。", $article->content);
        $this->assertSame((int) $title->id, (int) $article->source_title_id);
        $this->assertTrue($article->sourceTitle->is($title));
        $this->assertSame(1, (int) $title->fresh()->used_count);
        $this->assertSame(1, (int) $title->fresh()->usage_count);
    }

    public function test_manual_title_edit_does_not_consume_mismatched_source_title(): void
    {
        $admin = $this->createAdmin('assistant_mismatch');
        $category = Category::query()->create([
            'name' => '内容分类',
            'slug' => 'assistant-mismatch',
        ]);
        $author = Author::query()->create(['name' => 'GEOFlow']);
        $library = TitleLibrary::query()->create(['name' => '来源标题库']);
        $title = Title::query()->create([
            'library_id' => $library->id,
            'title' => '原始标题',
            'used_count' => 0,
            'usage_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.articles.store'), [
                'title' => '用户修改后的标题',
                'content' => '手动正文',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'draft',
                'review_status' => 'pending',
                'source_title_id' => $title->id,
                'is_ai_generated' => '0',
            ])
            ->assertRedirect();

        $this->assertSame(0, (int) $title->fresh()->used_count);
        $this->assertSame(0, (int) $title->fresh()->usage_count);
    }

    public function test_ai_article_update_cleans_markers_even_when_the_hidden_flag_is_tampered(): void
    {
        $admin = $this->createAdmin('assistant_update_clean');
        $category = Category::query()->create(['name' => '更新清理', 'slug' => 'assistant-update-clean']);
        $author = Author::query()->create(['name' => '更新清理作者']);
        $article = Article::query()->create([
            'title' => 'AI 文章更新清理',
            'slug' => 'ai-article-update-clean',
            'content' => '旧正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => true,
        ]);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => $article->id]), [
                'title' => $article->title,
                'content' => '更新正文 [K1]，Vitamin K2 保留。',
                'excerpt' => '',
                'keywords' => '',
                'meta_description' => '',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'draft',
                'review_status' => 'pending',
                'is_ai_generated' => '0',
            ])
            ->assertRedirect();

        $this->assertSame('更新正文，Vitamin K2 保留。', $article->fresh()->content);
    }

    public function test_ai_generation_streams_content_and_counts_model_usage(): void
    {
        MarkdownContentWriterAgent::fake(['## 生成正文 [K1] Vitamin K2 内容完整。'])->preventStrayPrompts();

        $admin = $this->createAdmin('assistant_stream');
        $prompt = $this->createPrompt([
            'content' => '请围绕“{{title}}”撰写文章，关键词是“{{keyword}}”。',
        ]);
        $knowledgeBase = $this->createKnowledgeBase('GEO 内容工程需要结合检索证据与结构化写作流程。');
        $model = $this->createModel();

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => 'GEO 内容工程',
                'keyword' => 'GEO',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
            ]);

        $response->assertOk();
        $streamedContent = $response->streamedContent();
        $this->assertStringContainsString('"type":"text_delta"', $streamedContent);
        $this->assertStringContainsString('##', $streamedContent);
        $this->assertStringContainsString('"type":"article_content_replacement"', $streamedContent);
        $this->assertStringContainsString('## 生成正文 Vitamin K2 内容完整。', $streamedContent);
        $this->assertStringContainsString('data: [DONE]', $streamedContent);
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
        $this->assertSame(1, (int) $knowledgeBase->fresh()->usage_count);
        $usageEvent = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_SUCCEEDED, $usageEvent->status);
        $this->assertSame(AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN, $usageEvent->execution_scope);
        $this->assertSame($admin->id, $usageEvent->execution_admin_id);
        $this->assertSame($admin->id, $usageEvent->config_owner_admin_id);

        MarkdownContentWriterAgent::assertPrompted(
            fn ($prompt): bool => str_contains($prompt->prompt, 'GEO 内容工程')
                && str_contains($prompt->prompt, 'GEO 内容工程需要结合检索证据')
                && str_contains($prompt->prompt, '【知识库证据】')
                && str_contains($prompt->prompt, '最终文章中不得出现任何内部证据编号')
                && ! str_contains($prompt->prompt, '并在相关句子后标注证据编号'),
        );
    }

    public function test_ai_generation_automatically_prefers_personal_then_uses_shared_fallback(): void
    {
        MarkdownContentWriterAgent::fake([
            '## 个人模型生成',
            '## 共享模型生成',
        ])->preventStrayPrompts();
        $provider = $this->namedAdmin('assistant_shared_provider', 'super_admin');
        $admin = $this->createAdmin('assistant_personal_first');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $invalidPersonal = $this->createModel([
            'name' => '无密钥个人模型',
            'model_id' => 'invalid-personal-chat',
            'api_key' => '',
            'failover_priority' => 1,
        ]);
        $blockedPersonal = $this->createModel([
            'name' => '健康门禁个人模型',
            'model_id' => 'health-blocked-personal-chat',
            'failover_priority' => 2,
            'ai_workspace_readiness_status' => 'failed',
            'ai_workspace_readiness_expires_at' => now()->addMinute(),
        ]);
        $personal = $this->createModel(['name' => '个人模型', 'failover_priority' => 100]);
        $this->modelOwner = $provider;
        $shared = $this->createModel(['name' => '共享模型', 'model_id' => 'shared-chat', 'failover_priority' => 1]);
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();
        $payload = [
            'title' => '自动模型选择',
            'knowledge_base_id' => $knowledgeBase->id,
            'prompt_id' => $prompt->id,
        ];

        $personalResponse = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), $payload);
        $personalResponse->assertOk();
        $this->assertStringContainsString('个人模型生成', $personalResponse->streamedContent());
        $this->assertSame(0, (int) $invalidPersonal->fresh()->total_used);
        $this->assertSame(0, (int) $blockedPersonal->fresh()->total_used);
        $this->assertSame(1, (int) $personal->fresh()->total_used);
        $this->assertSame(0, (int) $shared->fresh()->total_used);

        $personal->forceFill(['daily_limit' => 1])->save();
        $sharedResponse = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), $payload);
        $sharedResponse->assertOk();
        $this->assertStringContainsString('共享模型生成', $sharedResponse->streamedContent());
        $this->assertSame(1, (int) $shared->fresh()->total_used);
        $events = AiModelUsageEvent::query()->orderBy('id')->get();
        $this->assertCount(2, $events);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_PERSONAL, $events[0]->model_source);
        $this->assertSame(AiModelUsageEvent::MODEL_SOURCE_SHARED, $events[1]->model_source);
        $this->assertSame($provider->id, $events[1]->config_owner_admin_id);
        $this->assertSame($admin->id, $events[1]->execution_admin_id);
    }

    public function test_ai_generation_skips_a_personal_candidate_whose_last_quota_is_consumed_before_lock(): void
    {
        MarkdownContentWriterAgent::fake(['## 竞态后共享模型生成'])->preventStrayPrompts();
        $provider = $this->namedAdmin('assistant_race_shared_provider', 'super_admin');
        $admin = $this->createAdmin('assistant_quota_race');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $personal = $this->createModel(['name' => '仅余一次个人模型', 'daily_limit' => 1]);
        $this->modelOwner = $provider;
        $shared = $this->createModel(['name' => '竞态共享模型', 'model_id' => 'race-shared-chat']);
        $state = (object) ['consumed' => false];
        $this->app->instance(
            DirectAdminAiInvocationBoundaryHook::class,
            new class((int) $personal->id, $state) extends DirectAdminAiInvocationBoundaryHook
            {
                public function __construct(
                    private readonly int $personalModelId,
                    private readonly object $state,
                ) {}

                public function beforeCandidateLock(DirectAdminAiExecutionContext $context, AiModel $candidate): void
                {
                    if ($this->state->consumed || (int) $candidate->id !== $this->personalModelId) {
                        return;
                    }
                    $this->state->consumed = true;
                    DB::table('ai_models')->where('id', $this->personalModelId)->update([
                        'used_today' => 1,
                        'total_used' => 1,
                        'usage_date' => now()->toDateString(),
                    ]);
                }
            },
        );
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();

        $response = $this->actingAs($admin, 'admin')->postJson(route('admin.articles.editor.generate'), [
            'title' => '额度竞态测试',
            'knowledge_base_id' => $knowledgeBase->id,
            'prompt_id' => $prompt->id,
        ]);

        $response->assertOk();
        $this->assertStringContainsString('竞态后共享模型生成', $response->streamedContent());
        $this->assertTrue($state->consumed);
        $this->assertSame(1, (int) $personal->fresh()->total_used);
        $this->assertSame(1, (int) $shared->fresh()->used_today);
        $this->assertSame(1, (int) $shared->fresh()->total_used);
    }

    public function test_ai_generation_rejects_system_and_ordinary_admin_peer_models_before_streaming(): void
    {
        MarkdownContentWriterAgent::fake()->preventStrayPrompts();
        $ordinary = $this->createAdmin('assistant_isolated_ordinary');
        $system = $this->createModel();
        $system->forceFill(['access_scope' => AiModel::ACCESS_SCOPE_SYSTEM_ONLY])->save();
        $embedding = $this->createModel(['name' => '个人向量模型', 'model_type' => 'embedding']);
        $superAdmin = $this->namedAdmin('assistant_isolated_super', 'super_admin');
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();
        $payload = [
            'title' => '隔离模型测试',
            'knowledge_base_id' => $knowledgeBase->id,
            'prompt_id' => $prompt->id,
        ];

        foreach ([
            [$ordinary, $system],
            [$ordinary, $embedding],
            [$superAdmin, $this->createModel(['name' => '普通管理员私有模型'])],
        ] as [$actor, $forbiddenModel]) {
            $this->actingAs($actor, 'admin')
                ->postJson(route('admin.articles.editor.generate'), $payload + [
                    'ai_model_id' => $forbiddenModel->id,
                ])
                ->assertNotFound()
                ->assertJsonPath('error_code', 'ai_model_not_accessible');
        }

        MarkdownContentWriterAgent::assertNeverPrompted();
        $this->assertDatabaseCount('ai_model_usage_events', 0);
    }

    public function test_ai_generation_stops_before_the_first_event_when_access_is_revoked_during_lazy_stream_start(): void
    {
        $admin = $this->createAdmin('assistant_stream_revoked');
        $model = $this->createModel([
            'api_key' => app(ApiKeyCrypto::class)->encrypt('stream-secret-key'),
            'api_url' => 'https://stream-secret.example.test/v1',
        ]);
        MarkdownContentWriterAgent::fake(function () use ($admin): string {
            Admin::query()->whereKey($admin->id)->increment('ai_config_access_version');

            return '此内容不得输出';
        })->preventStrayPrompts();
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => '流式撤权',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
            ]);

        $response->assertOk();
        $streamed = $response->streamedContent();
        $this->assertStringContainsString('"type":"error"', $streamed);
        $this->assertStringContainsString('ai_config_access_revoked', $streamed);
        $this->assertStringNotContainsString('此内容不得输出', $streamed);
        $this->assertStringNotContainsString('article_content_replacement', $streamed);
        $this->assertStringNotContainsString('[DONE]', $streamed);
        $this->assertStringNotContainsString('stream-secret-key', $streamed);
        $this->assertStringNotContainsString('stream-secret.example.test', $streamed);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
        $this->assertSame(0, (int) $knowledgeBase->fresh()->usage_count);
    }

    public function test_ai_generation_stops_subsequent_deltas_and_finalization_when_access_is_revoked_midstream(): void
    {
        $admin = $this->createAdmin('assistant_midstream_revoked');
        $model = $this->createModel();
        MarkdownContentWriterAgent::fake(['第一段 第二段 第三段'])->preventStrayPrompts();
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();
        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => '流式中途撤权',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
            ]);

        $adminReads = 0;
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use ($admin, &$adminReads, &$revoked): void {
            $sql = strtolower($query->sql);
            if ($revoked || (! str_contains($sql, 'from "admins"') && ! str_contains($sql, 'from `admins`'))) {
                return;
            }
            $adminReads++;
            if ($adminReads === 7) {
                $revoked = true;
                DB::table('admins')->where('id', $admin->id)->increment('ai_config_access_version');
            }
        });

        $response->assertOk();
        $streamed = $response->streamedContent();
        $this->assertTrue($revoked);
        $this->assertStringContainsString(trim((string) json_encode('第一段'), '"'), $streamed);
        $this->assertStringNotContainsString(trim((string) json_encode('第二段'), '"'), $streamed);
        $this->assertStringContainsString('ai_config_access_revoked', $streamed);
        $this->assertStringNotContainsString('article_content_replacement', $streamed);
        $this->assertStringNotContainsString('[DONE]', $streamed);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
        $this->assertSame(0, (int) $knowledgeBase->fresh()->usage_count);
    }

    public function test_ai_generation_holds_the_model_mutation_lock_for_the_lazy_stream_lifetime_and_releases_it(): void
    {
        $admin = $this->createAdmin('assistant_stream_lock');
        $model = $this->createModel();
        $blockedMutation = null;
        MarkdownContentWriterAgent::fake(function () use ($admin, $model, &$blockedMutation): string {
            $blockedMutation = app(AdminAiModelMutationService::class)->update(
                $admin,
                (int) $model->id,
                ['name' => 'blocked during editor stream'],
                AiModel::ACCESS_SCOPE_USER_CONTENT,
            );

            return '锁内生成内容';
        })->preventStrayPrompts();
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => '锁测试',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
            ]);
        $response->assertOk();
        $this->assertStringContainsString('[DONE]', $response->streamedContent());

        $this->assertNotNull($blockedMutation);
        $this->assertFalse($blockedMutation->succeeded());
        $this->assertSame('task', $blockedMutation->error);
        $this->assertTrue(app(AdminAiModelMutationService::class)->update(
            $admin,
            (int) $model->id,
            ['name' => 'allowed after editor stream'],
            AiModel::ACCESS_SCOPE_USER_CONTENT,
        )->succeeded());
    }

    public function test_ai_generation_rechecks_access_after_provider_success_before_final_replacement_and_knowledge_usage(): void
    {
        $admin = $this->createAdmin('assistant_final_revoked');
        $model = $this->createModel();
        MarkdownContentWriterAgent::fake(['provider 已完整返回'])->preventStrayPrompts();
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();
        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => '最终落库前撤权',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
            ]);
        $adminReads = 0;
        $revoked = false;
        DB::listen(function (QueryExecuted $query) use ($admin, &$adminReads, &$revoked): void {
            $sql = strtolower($query->sql);
            if ($revoked || (! str_contains($sql, 'from "admins"') && ! str_contains($sql, 'from `admins`'))) {
                return;
            }
            $adminReads++;
            if ($adminReads === 8) {
                $revoked = true;
                DB::table('admins')->where('id', $admin->id)->increment('ai_config_access_version');
            }
        });

        $response->assertOk();
        $streamed = $response->streamedContent();
        $this->assertTrue($revoked);
        $this->assertStringContainsString('ai_config_access_revoked', $streamed);
        $this->assertStringNotContainsString('article_content_replacement', $streamed);
        $this->assertStringNotContainsString('[DONE]', $streamed);
        $this->assertSame(0, (int) $knowledgeBase->fresh()->usage_count);
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used, 'A revoked result cannot be counted as a delivered success.');
        $usageEvent = AiModelUsageEvent::query()->sole();
        $this->assertSame(AiModelUsageEvent::STATUS_REVOKED, $usageEvent->status);
        $this->assertSame('ai_config_access_revoked', $usageEvent->error_code);
    }

    public function test_ai_generation_releases_the_invocation_lock_after_a_stream_exception(): void
    {
        $admin = $this->createAdmin('assistant_stream_exception_lock');
        $model = $this->createModel();
        MarkdownContentWriterAgent::fake(
            fn (): never => throw new \RuntimeException('secret provider failure'),
        )->preventStrayPrompts();
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => '异常锁释放',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
            ]);
        $response->assertOk();
        $streamed = $response->streamedContent();
        $this->assertStringContainsString('ai_model_unavailable', $streamed);
        $this->assertStringNotContainsString('secret provider failure', $streamed);
        $this->assertTrue(app(AdminAiModelMutationService::class)->update(
            $admin,
            (int) $model->id,
            ['name' => 'allowed after stream exception'],
            AiModel::ACCESS_SCOPE_USER_CONTENT,
        )->succeeded());
    }

    public function test_ai_generation_reserves_daily_quota_atomically(): void
    {
        $model = $this->createModel(['daily_limit' => 1]);
        $firstSnapshot = $model->fresh();
        $secondSnapshot = $model->fresh();
        $generationService = app(ArticleContentGenerationService::class);

        $reservation = $generationService->reserveDailyUsage($firstSnapshot);
        $this->assertNotNull($reservation);
        $this->assertNull($generationService->reserveDailyUsage($secondSnapshot));
        $this->assertSame(1, (int) $model->fresh()->used_today);

        $generationService->releaseDailyUsage($reservation);
        $this->assertSame(0, (int) $model->fresh()->used_today);
    }

    public function test_ai_generation_resets_yesterdays_usage_before_reserving_quota(): void
    {
        $model = $this->createModel([
            'daily_limit' => 1,
            'used_today' => 1,
        ]);
        DB::table('ai_models')
            ->where('id', (int) $model->id)
            ->update(['usage_date' => now()->subDay()->toDateString()]);

        $this->assertNotNull(app(ArticleContentGenerationService::class)->reserveDailyUsage($model));

        $model->refresh();
        $this->assertSame(now()->toDateString(), $model->usage_date?->toDateString());
        $this->assertSame(1, (int) $model->used_today);
    }

    public function test_ai_generation_rejects_requests_after_daily_quota_is_used(): void
    {
        MarkdownContentWriterAgent::fake(['## 第一次生成'])->preventStrayPrompts();

        $admin = $this->createAdmin('assistant_quota');
        $provider = $this->namedAdmin('assistant_quota_shared_provider', 'super_admin');
        $admin->forceFill(['shared_ai_config_owner_id' => $provider->id])->save();
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();
        $model = $this->createModel(['daily_limit' => 1]);
        $this->modelOwner = $provider;
        $shared = $this->createModel(['name' => '额度后备共享模型', 'model_id' => 'quota-shared-chat']);
        $payload = [
            'title' => '额度测试文章',
            'knowledge_base_id' => $knowledgeBase->id,
            'prompt_id' => $prompt->id,
            'ai_model_id' => $model->id,
        ];

        $firstResponse = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), $payload);

        $firstResponse->assertOk();
        $firstResponse->streamedContent();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'AI 模型不可用或已达到今日调用上限');

        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
        $this->assertSame(0, (int) $shared->fresh()->total_used, 'An explicitly selected exhausted model cannot be silently replaced.');
    }

    public function test_ai_generation_does_not_reserve_quota_when_model_setup_fails(): void
    {
        $admin = $this->createAdmin('assistant_setup_failure');
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();
        $model = $this->createModel([
            'api_url' => '',
            'daily_limit' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => '模型配置错误测试',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'AI 模型 API 地址为空');

        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_ai_generation_releases_reserved_quota_when_stream_fails(): void
    {
        MarkdownContentWriterAgent::fake(
            fn (): never => throw new \RuntimeException('upstream stream failed'),
        )->preventStrayPrompts();

        $model = $this->createModel(['daily_limit' => 1]);
        $stream = app(ArticleContentGenerationService::class)->stream($model, '生成一篇测试文章');

        try {
            iterator_to_array($stream);
            $this->fail('Expected the stream to fail.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('upstream stream failed', $exception->getMessage());
        }

        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
    }

    public function test_empty_ai_stream_does_not_consume_usage_or_knowledge_statistics(): void
    {
        MarkdownContentWriterAgent::fake([''])->preventStrayPrompts();

        $admin = $this->createAdmin('assistant_empty_stream');
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();
        $model = $this->createModel(['daily_limit' => 1]);

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => '空流测试文章',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
            ]);

        $response->assertOk();
        $this->assertStringContainsString('data: [DONE]', $response->streamedContent());
        $this->assertSame(0, (int) $model->fresh()->used_today);
        $this->assertSame(0, (int) $model->fresh()->total_used);
        $this->assertSame(0, (int) $knowledgeBase->fresh()->usage_count);
    }

    public function test_ai_generation_rejects_non_content_prompt(): void
    {
        $admin = $this->createAdmin('assistant_validation');
        $knowledgeBase = $this->createKnowledgeBase();
        $prompt = $this->createPrompt(['type' => 'title']);
        $model = $this->createModel();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => '测试文章',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('prompt_id');
    }

    public function test_ai_generation_requires_a_knowledge_base(): void
    {
        $admin = $this->createAdmin('assistant_knowledge_required');
        $prompt = $this->createPrompt();
        $model = $this->createModel();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => '测试文章',
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('knowledge_base_id');
    }

    public function test_ai_generation_rejects_a_knowledge_base_without_usable_evidence(): void
    {
        MarkdownContentWriterAgent::fake()->preventStrayPrompts();

        $admin = $this->createAdmin('assistant_empty_knowledge');
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '空知识库',
            'content' => '',
            'usage_count' => 0,
        ]);
        $prompt = $this->createPrompt();
        $model = $this->createModel();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.articles.editor.generate'), [
                'title' => '测试文章',
                'knowledge_base_id' => $knowledgeBase->id,
                'prompt_id' => $prompt->id,
                'ai_model_id' => $model->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('admin.article_assistant.generate.knowledge_unavailable'));

        MarkdownContentWriterAgent::assertNeverPrompted();
    }

    private function createAdmin(string $suffix): Admin
    {
        $this->modelOwner = $this->namedAdmin('article_'.$suffix, 'admin', $suffix.'@example.com');

        return $this->modelOwner;
    }

    private function namedAdmin(string $username, string $role, ?string $email = null): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $email ?? $username.'@example.com',
            'display_name' => 'Article Assistant Admin',
            'role' => $role,
            'status' => 'active',
        ]);
    }

    private function createPrompt(array $overrides = []): Prompt
    {
        return Prompt::query()->create(array_merge([
            'name' => 'GEO 正文提示词',
            'type' => 'content',
            'content' => '请围绕“{{title}}”生成正文。',
            'variables' => 'title,keyword',
        ], $overrides));
    }

    private function createKnowledgeBase(string $content = 'GEOFlow 知识库提供经过审核的产品资料。'): KnowledgeBase
    {
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => 'GEO 产品知识库',
            'content' => $content,
            'usage_count' => 0,
            'risk_level' => 'low',
            'review_status' => 'reviewed',
        ]);
        KnowledgeChunk::query()->create([
            'knowledge_base_id' => $knowledgeBase->id,
            'chunk_index' => 0,
            'content' => $content,
            'chunk_title' => 'GEO 产品资料',
        ]);

        return $knowledgeBase;
    }

    private function createModel(array $overrides = []): AiModel
    {
        $model = AiModel::query()->create(array_merge([
            'name' => '测试聊天模型',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ], $overrides));
        if ($this->modelOwner instanceof Admin) {
            $model->forceFill([
                'owner_admin_id' => $this->modelOwner->id,
                'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            ])->save();
        }

        return $model;
    }
}
