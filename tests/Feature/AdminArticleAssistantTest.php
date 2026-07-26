<?php

namespace Tests\Feature;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\ArticleContentGenerationService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminArticleAssistantTest extends TestCase
{
    use RefreshDatabase;

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
                'content' => "## 正文\n\nAI 生成的文章内容。",
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

    public function test_ai_generation_streams_content_and_counts_model_usage(): void
    {
        MarkdownContentWriterAgent::fake(['## 生成正文 内容完整。'])->preventStrayPrompts();

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
        $this->assertStringContainsString('data: [DONE]', $streamedContent);
        $this->assertSame(1, (int) $model->fresh()->used_today);
        $this->assertSame(1, (int) $model->fresh()->total_used);
        $this->assertSame(1, (int) $knowledgeBase->fresh()->usage_count);

        MarkdownContentWriterAgent::assertPrompted(
            fn ($prompt): bool => str_contains($prompt->prompt, 'GEO 内容工程')
                && str_contains($prompt->prompt, 'GEO 内容工程需要结合检索证据')
                && str_contains($prompt->prompt, '【知识库证据】')
                && str_contains($prompt->prompt, '禁止输出任何引用占位符')
                && ! str_contains($prompt->prompt, '并在相关句子后标注证据编号'),
        );
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
        $prompt = $this->createPrompt();
        $knowledgeBase = $this->createKnowledgeBase();
        $model = $this->createModel(['daily_limit' => 1]);
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
        return Admin::query()->create([
            'username' => 'article_'.$suffix,
            'password' => 'secret-123',
            'email' => $suffix.'@example.com',
            'display_name' => 'Article Assistant Admin',
            'role' => 'admin',
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
        return AiModel::query()->create(array_merge([
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
    }
}
