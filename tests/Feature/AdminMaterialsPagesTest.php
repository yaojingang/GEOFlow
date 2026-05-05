<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * 素材管理模块最小可用测试：
 * - 路由鉴权
 * - 主要列表/创建页可访问
 * - 知识库创建链路可用
 */
class AdminMaterialsPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function createReadyUrlImportAiModel(string $apiUrl = 'https://ai.test/v1'): AiModel
    {
        return AiModel::query()->create([
            'name' => 'URL Import AI Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => $apiUrl,
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
    }

    public function test_guest_is_redirected_from_material_pages(): void
    {
        $routes = [
            'admin.materials.index',
            'admin.authors.index',
            'admin.keyword-libraries.index',
            'admin.title-libraries.index',
            'admin.image-libraries.index',
            'admin.knowledge-bases.index',
            'admin.url-import',
            'admin.url-import.history',
        ];

        foreach ($routes as $routeName) {
            $this->get(route($routeName))->assertRedirect(route('admin.login'));
        }

        $this->get(route('admin.keyword-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.title-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.image-libraries.detail', ['libraryId' => 1]))->assertRedirect(route('admin.login'));
        $this->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => 1]))->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_open_material_pages(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_admin',
            'password' => 'secret-123',
            'email' => 'materials-admin@example.com',
            'display_name' => 'Materials Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.materials.index'))
            ->assertOk()
            ->assertSee(__('admin.materials.page_title'))
            ->assertSee(__('admin.materials.url_import'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.authors.index'))
            ->assertOk()
            ->assertSee(__('admin.authors.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.keyword_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.title_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.create'))
            ->assertOk()
            ->assertSee(__('admin.image_libraries.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.create'))
            ->assertOk()
            ->assertSee(__('admin.knowledge_bases.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import'))
            ->assertOk()
            ->assertSee(__('admin.url_import.page_title'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import.history'))
            ->assertOk()
            ->assertSee(__('admin.url_import_history.page_title'));
    }

    public function test_admin_can_create_knowledge_base_from_form(): void
    {
        $admin = Admin::query()->create([
            'username' => 'knowledge_create_admin',
            'password' => 'secret-123',
            'email' => 'knowledge-create-admin@example.com',
            'display_name' => 'Knowledge Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.knowledge-bases.store'), [
                'name' => '测试知识库',
                'description' => '测试描述',
                'file_type' => 'markdown',
                'content' => "第一段内容。\n\n第二段内容。",
            ]);

        $response->assertRedirect(route('admin.knowledge-bases.index'));
        $this->assertDatabaseHas('knowledge_bases', [
            'name' => '测试知识库',
            'file_type' => 'markdown',
        ]);
        $this->assertGreaterThan(0, KnowledgeBase::query()->count());
    }

    public function test_admin_can_create_url_import_job_without_url_scheme(): void
    {
        Http::fake([
            'https://example.test/report' => Http::response(
                '<!doctype html><html><head><title>示例项目</title><meta name="description" content="示例项目页面摘要"></head><body><main><h1>示例项目</h1><p>这是一个用于采集测试的 GEO 页面。</p></main></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_admin',
            'password' => 'secret-123',
            'email' => 'url-import-admin@example.com',
            'display_name' => 'Url Import Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $response = $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/report',
                'project_name' => '示例项目',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('url_import_jobs', [
            'url' => 'example.test/report',
            'normalized_url' => 'https://example.test/report',
            'source_domain' => 'example.test',
            'status' => 'queued',
            'created_by' => 'url_import_admin',
        ]);

        $job = UrlImportJob::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->get(route('admin.url-import.show', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertSee('name="csrf-token"', false)
            ->assertSee('data-run-url', false)
            ->assertSee('data-status="queued"', false)
            ->assertSee('data-has-result="0"', false)
            ->assertDontSee('sessionStorage', false)
            ->assertDontSee('setTimeout(() => window.location.reload(), 1000)', false);

        $this->assertDatabaseHas('url_import_jobs', [
            'id' => (int) $job->id,
            'status' => 'queued',
            'current_step' => 'queued',
        ]);
    }

    public function test_url_import_requires_ready_ai_model_before_creating_job(): void
    {
        $admin = Admin::query()->create([
            'username' => 'url_import_no_model_admin',
            'password' => 'secret-123',
            'email' => 'url-import-no-model@example.com',
            'display_name' => 'Url Import No Model Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/report',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect(route('admin.ai-models.index'))
            ->assertSessionHasErrors('ai_model');

        $this->assertDatabaseCount('url_import_jobs', 0);
    }

    public function test_admin_can_run_and_commit_url_import_job(): void
    {
        Http::fake([
            'https://example.test/report' => Http::response(
                '<!doctype html><html><head><title>GEO 内容报告</title><meta name="description" content="这是一份关于 GEO 内容系统的页面摘要"><meta property="og:image" content="https://example.test/cover.jpg"></head><body><article><h1>GEO 内容报告</h1><p>GEO 内容系统需要知识库、关键词库和标题库协同工作。</p><img src="/body.png" alt="正文配图"></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'clean_title' => 'GEO 内容报告',
                                'clean_summary' => 'GEO 内容系统需要知识库、关键词库和标题库协同工作。',
                                'clean_text' => 'GEO 内容系统需要知识库、关键词库和标题库协同工作。',
                                'core_business' => [
                                    'industry' => 'GEO 内容系统',
                                    'products_services' => ['内容资产管理'],
                                    'target_audience' => ['内容运营团队'],
                                    'commercial_scenarios' => ['AI 搜索优化'],
                                    'value_proposition' => '沉淀真实素材并自动生成内容',
                                    'evidence_limits' => '仅来自测试页面',
                                ],
                                'entities' => ['GEO 内容系统', '知识库', '关键词库'],
                                'facts' => ['GEO 内容系统需要知识库、关键词库和标题库协同工作。'],
                                'noise_removed' => [],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'summary' => 'GEO 内容系统需要知识库、关键词库和标题库协同工作。',
                                'library_name' => 'GEO 内容报告',
                                'knowledge_markdown' => "# GEO 内容报告\n\n- 来源 URL：https://example.test/report\n- 原子化事实：GEO 内容系统需要知识库、关键词库和标题库协同工作。",
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode(['keywords' => ['内容资产', '知识库', '标题库', '关键词库']], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200)
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode(['titles' => ['GEO 内容系统如何建立可信内容资产', '知识库如何支撑 GEO 内容生成']], JSON_UNESCAPED_UNICODE),
                        ],
                    ]],
                ], 200)
                ,
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_runner',
            'password' => 'secret-123',
            'email' => 'url-import-runner@example.com',
            'display_name' => 'Url Import Runner',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'example.test/report',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('current_step', 'preview')
            ->assertJsonPath('result_ready', true)
            ->assertJsonPath('progress_percent', 100);

        $job->refresh();
        $this->assertSame('completed', $job->status);
        $this->assertStringContainsString('GEO 内容报告', (string) $job->result_json);
        $this->assertDatabaseHas('url_import_job_logs', [
            'job_id' => (int) $job->id,
            'step' => 'keywords',
        ]);
        $this->assertDatabaseHas('url_import_job_logs', [
            'job_id' => (int) $job->id,
            'step' => 'preview',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.commit', ['jobId' => (int) $job->id]))
            ->assertRedirect(route('admin.url-import.show', ['jobId' => (int) $job->id]));

        $this->assertDatabaseHas('knowledge_bases', ['name' => 'GEO 内容报告 知识库']);
        $this->assertDatabaseHas('keyword_libraries', ['name' => 'GEO 内容报告 关键词库']);
        $this->assertDatabaseHas('title_libraries', ['name' => 'GEO 内容报告 标题库']);
        $this->assertDatabaseMissing('image_libraries', ['name' => 'GEO 内容报告 图片库']);
        $this->assertDatabaseHas('url_import_jobs', [
            'id' => (int) $job->id,
            'current_step' => 'imported',
        ]);
    }

    public function test_url_import_analysis_prefers_active_ai_model_and_backend_prompts(): void
    {
        Http::fake([
            'https://source.test/report' => Http::response(
                '<!doctype html><html><head><title>原始页面标题</title><meta name="description" content="原始页面摘要"></head><body><article><h1>原始页面标题</h1><p>页面正文包含 CRM、GEO 和知识库信息。</p><img src="/hero.png" alt="GEO 服务主图"></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push([
                    'id' => 'chatcmpl-clean',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'clean_title' => 'AI清洗标题',
                                'clean_summary' => 'AI 生成的页面摘要，用于描述页面的核心内容和素材价值。',
                                'clean_text' => '页面正文包含 CRM、GEO 和知识库信息。',
                                'entities' => ['CRM', 'GEO', '知识库'],
                                'facts' => ['页面正文包含 CRM、GEO 和知识库信息。'],
                                'noise_removed' => ['导航'],
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-knowledge',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode([
                                'summary' => 'AI 生成的页面摘要，用于描述页面的核心内容和素材价值。',
                                'library_name' => 'AI命名素材',
                                'knowledge_markdown' => "# AI知识库\n\n- 来源真实\n- 可用于 GEO 内容生成",
                            ], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-keywords',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode(['keywords' => ['AI关键词一', 'AI关键词二', '查看详情']], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200)
                ->push([
                    'id' => 'chatcmpl-titles',
                    'object' => 'chat.completion',
                    'choices' => [[
                        'index' => 0,
                        'message' => [
                            'role' => 'assistant',
                            'content' => json_encode(['titles' => ['AI生成标题一', 'AI生成标题二']], JSON_UNESCAPED_UNICODE),
                        ],
                        'finish_reason' => 'stop',
                    ]],
                ], 200)
                ,
        ]);

        Prompt::query()->create([
            'name' => '关键词提示词',
            'type' => 'keyword',
            'content' => '请提炼关键词',
            'variables' => '',
        ]);
        Prompt::query()->create([
            'name' => '正文提示词',
            'type' => 'content',
            'content' => '请生成真实可信内容',
            'variables' => '',
        ]);
        AiModel::query()->create([
            'name' => 'AI Test Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => 'test-chat',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_ai_runner',
            'password' => 'secret-123',
            'email' => 'url-import-ai-runner@example.com',
            'display_name' => 'Url Import AI Runner',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/report',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);

        $this->assertSame('ai', $result['analysis']['analysis_source'] ?? null);
        $this->assertSame('AI命名素材', $result['analysis']['library_name'] ?? null);
        $this->assertContains('AI关键词一', $result['analysis']['keywords'] ?? []);
        $this->assertNotContains('查看详情', $result['analysis']['keywords'] ?? []);
        $this->assertContains('AI生成标题一', $result['analysis']['titles'] ?? []);
        $this->assertArrayNotHasKey('images', $result['analysis'] ?? []);
    }

    public function test_url_import_accepts_ai_json_wrapped_in_markdown_or_reasoning_text(): void
    {
        Http::fake([
            'https://source.test/wrapped-json' => Http::response(
                '<!doctype html><html><head><title>CRM 业务页</title><meta name="description" content="CRM 业务页摘要"></head><body><article><h1>CRM 业务页</h1><p>面向销售团队的客户数据管理和流程自动化服务。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => "<think>先分析页面主体。</think>\n```json\n".json_encode([
                    'clean_title' => 'CRM 业务页',
                    'clean_summary' => '面向销售团队的客户数据管理和流程自动化服务。',
                    'clean_text' => '面向销售团队的客户数据管理和流程自动化服务。',
                    'core_business' => ['industry' => 'CRM', 'products_services' => ['客户数据管理', '流程自动化']],
                    'entities' => ['CRM', '销售团队'],
                    'facts' => ['页面介绍客户数据管理和流程自动化服务。'],
                    'noise_removed' => ['导航'],
                ], JSON_UNESCAPED_UNICODE)."\n```"]]]], 200)
                ->push(['choices' => [['message' => ['content' => "以下是结构化 JSON：\n".json_encode([
                    'summary' => '面向销售团队的客户数据管理和流程自动化服务。',
                    'library_name' => 'CRM 业务知识库',
                    'knowledge_markdown' => "# CRM 业务知识库\n\n- 来源 URL：https://source.test/wrapped-json\n- 服务面向销售团队。",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => "```json\n".json_encode(['keywords' => ['客户管理', '销售自动化', 'CRM选型']], JSON_UNESCAPED_UNICODE)."\n```"]]]], 200)
                ->push(['choices' => [['message' => ['content' => "已生成：\n".json_encode(['titles' => ['客户管理系统如何帮助销售团队提升效率']], JSON_UNESCAPED_UNICODE)."\n请查收。"]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_wrapped_json_admin',
            'password' => 'secret-123',
            'email' => 'url-import-wrapped-json@example.com',
            'display_name' => 'Url Import Wrapped Json Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/wrapped-json',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);

        $this->assertSame('CRM 业务知识库', $result['analysis']['library_name'] ?? null);
        $this->assertContains('客户管理', $result['analysis']['keywords'] ?? []);
        $this->assertContains('客户管理系统如何帮助销售团队提升效率', $result['analysis']['titles'] ?? []);
    }

    public function test_url_import_fails_over_to_next_available_ai_model(): void
    {
        Http::fake([
            'https://source.test/failover' => Http::response(
                '<!doctype html><html><head><title>GEO 采集页</title><meta name="description" content="GEO 采集页摘要"></head><body><article><h1>GEO 采集页</h1><p>面向企业的内容资产管理服务。</p></article></body></html>',
                200,
                ['Content-Type' => 'text/html; charset=utf-8']
            ),
            'https://bad.test/v1/chat/completions' => Http::response(['detail' => 'API Key 无效'], 401),
            'https://ai.test/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'clean_title' => 'GEO 采集页',
                    'clean_summary' => '面向企业的内容资产管理服务。',
                    'clean_text' => '面向企业的内容资产管理服务。',
                    'core_business' => ['industry' => '内容管理', 'products_services' => ['内容资产管理']],
                    'entities' => ['内容资产管理'],
                    'facts' => ['面向企业的内容资产管理服务。'],
                    'noise_removed' => [],
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode([
                    'summary' => '面向企业的内容资产管理服务。',
                    'library_name' => 'GEO 采集页',
                    'knowledge_markdown' => "# GEO 采集页\n\n- 面向企业的内容资产管理服务。",
                ], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode(['keywords' => ['内容资产', '内容管理']], JSON_UNESCAPED_UNICODE)]]]], 200)
                ->push(['choices' => [['message' => ['content' => json_encode(['titles' => ['内容资产管理如何支撑 GEO 运营']], JSON_UNESCAPED_UNICODE)]]]], 200),
        ]);

        $admin = Admin::query()->create([
            'username' => 'url_import_failover_admin',
            'password' => 'secret-123',
            'email' => 'url-import-failover@example.com',
            'display_name' => 'Url Import Failover Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        AiModel::query()->create([
            'name' => 'Bad Model',
            'version' => '',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('bad-key'),
            'model_id' => 'bad-chat',
            'model_type' => 'chat',
            'api_url' => 'https://bad.test/v1',
            'failover_priority' => 1,
            'daily_limit' => 100,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $this->createReadyUrlImportAiModel();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.url-import.store'), [
                'url' => 'source.test/failover',
                'outputs' => ['knowledge', 'keywords', 'titles'],
            ])
            ->assertRedirect();

        $job = UrlImportJob::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.url-import.run', ['jobId' => (int) $job->id]))
            ->assertOk()
            ->assertJsonPath('status', 'completed');

        $result = json_decode((string) $job->refresh()->result_json, true);
        $this->assertSame('URL Import AI Model', $result['analysis']['model']['name'] ?? null);
        $this->assertDatabaseHas('url_import_job_logs', [
            'job_id' => (int) $job->id,
            'level' => 'warning',
        ]);
    }

    public function test_admin_can_open_all_material_detail_pages(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_detail_admin',
            'password' => 'secret-123',
            'email' => 'materials-detail-admin@example.com',
            'display_name' => 'Materials Detail Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '关键词库A',
            'description' => 'desc',
            'keyword_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库A',
            'description' => 'desc',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);
        $imageLibrary = ImageLibrary::query()->create([
            'name' => '图片库A',
            'description' => 'desc',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);
        Image::query()->create([
            'library_id' => (int) $imageLibrary->id,
            'filename' => 'demo.png',
            'original_name' => 'demo.png',
            'file_name' => 'demo.png',
            'file_path' => 'storage/uploads/images/demo.png',
            'file_size' => 1024,
            'mime_type' => 'image/png',
            'width' => 100,
            'height' => 100,
            'tags' => '',
            'used_count' => 0,
            'usage_count' => 0,
        ]);
        $knowledgeBase = KnowledgeBase::query()->create([
            'name' => '知识库A',
            'description' => 'desc',
            'content' => '知识内容',
            'character_count' => 4,
            'used_task_count' => 0,
            'file_type' => 'markdown',
            'file_path' => '',
            'word_count' => 4,
            'usage_count' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]))
            ->assertOk()
            ->assertSee($keywordLibrary->name);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]))
            ->assertOk()
            ->assertSee($titleLibrary->name);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.image-libraries.detail', ['libraryId' => (int) $imageLibrary->id]))
            ->assertOk()
            ->assertSee($imageLibrary->name)
            ->assertSee('storage/uploads/images/demo.png');
        $this->actingAs($admin, 'admin')
            ->get(route('admin.knowledge-bases.detail', ['knowledgeBaseId' => (int) $knowledgeBase->id]))
            ->assertOk()
            ->assertSee(__('admin.knowledge_detail.heading'));
    }

    public function test_admin_can_manage_keyword_and_title_details(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_ops_admin',
            'password' => 'secret-123',
            'email' => 'materials-ops-admin@example.com',
            'display_name' => 'Materials Ops Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $keywordLibrary = KeywordLibrary::query()->create([
            'name' => '关键词库B',
            'description' => 'desc',
            'keyword_count' => 0,
        ]);
        $titleLibrary = TitleLibrary::query()->create([
            'name' => '标题库B',
            'description' => 'desc',
            'title_count' => 0,
            'generation_type' => 'manual',
            'generation_rounds' => 1,
            'is_ai_generated' => 0,
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.keyword-libraries.keywords.store', ['libraryId' => (int) $keywordLibrary->id]), [
            'keyword' => '增长策略',
        ])->assertRedirect(route('admin.keyword-libraries.detail', ['libraryId' => (int) $keywordLibrary->id]));
        $this->assertDatabaseHas('keywords', [
            'library_id' => (int) $keywordLibrary->id,
            'keyword' => '增长策略',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.titles.store', ['libraryId' => (int) $titleLibrary->id]), [
            'title' => '增长策略完整指南',
            'keyword' => '增长策略',
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]));
        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $titleLibrary->id,
            'title' => '增长策略完整指南',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.import', ['libraryId' => (int) $titleLibrary->id]), [
            'titles_text' => "标题A|关键词A\n标题B",
        ])->assertRedirect(route('admin.title-libraries.detail', ['libraryId' => (int) $titleLibrary->id]));
        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $titleLibrary->id,
            'title' => '标题A',
        ]);

        $this->actingAs($admin, 'admin')->post(route('admin.title-libraries.ai-generate.submit', ['libraryId' => (int) $titleLibrary->id]), [
            'keyword_library_id' => (int) $keywordLibrary->id,
            'ai_model_id' => 1,
            'title_count' => 3,
            'title_style' => 'professional',
            'custom_prompt' => '',
        ])->assertSessionHasErrors();
    }

    public function test_admin_can_upload_image_and_knowledge_file_from_detail_flow(): void
    {
        $admin = Admin::query()->create([
            'username' => 'materials_upload_admin',
            'password' => 'secret-123',
            'email' => 'materials-upload-admin@example.com',
            'display_name' => 'Materials Upload Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);

        $imageLibrary = ImageLibrary::query()->create([
            'name' => '图片库C',
            'description' => 'desc',
            'image_count' => 0,
            'used_task_count' => 0,
        ]);

        $image = UploadedFile::fake()->image('banner.png', 100, 100);
        $this->actingAs($admin, 'admin')->post(route('admin.image-libraries.images.upload', ['libraryId' => (int) $imageLibrary->id]), [
            'images' => [$image],
        ])->assertRedirect(route('admin.image-libraries.detail', ['libraryId' => (int) $imageLibrary->id]));

        $this->assertDatabaseHas('images', [
            'library_id' => (int) $imageLibrary->id,
            'original_name' => 'banner.png',
        ]);

        $knowledgeFile = UploadedFile::fake()->createWithContent('manual.md', "# 标题\n内容段落");
        $this->actingAs($admin, 'admin')->post(route('admin.knowledge-bases.upload'), [
            'name' => '上传知识库',
            'description' => '测试上传',
            'knowledge_file' => $knowledgeFile,
        ])->assertRedirect(route('admin.knowledge-bases.index'));

        $this->assertDatabaseHas('knowledge_bases', [
            'name' => '上传知识库',
        ]);
    }
}
