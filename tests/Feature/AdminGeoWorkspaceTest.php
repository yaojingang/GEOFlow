<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\BrandProfile;
use App\Models\Category;
use App\Models\GeoAiSearchRun;
use App\Models\GeoAnswer;
use App\Models\GeoArticleAudit;
use App\Models\GeoArticleDraft;
use App\Models\GeoCitationSource;
use App\Models\GeoKeyword;
use App\Models\GeoKeywordOpportunity;
use App\Models\GeoPublishRecord;
use App\Models\GeoPublishRetest;
use App\Models\GeoPublishTarget;
use App\Models\GeoReport;
use App\Models\GeoScore;
use App\Models\GeoTask;
use App\Models\GeoTaskQuestion;
use App\Models\GeoWritingTask;
use App\Models\ImageLibrary;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\Organization;
use App\Models\PointLog;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class AdminGeoWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_guest_is_redirected_from_geo_workspace(): void
    {
        $this->get(route('admin.geo.workspace'))->assertRedirect(route('admin.login'));
    }

    public function test_authenticated_admin_can_open_geo_workspace(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('data-admin-gui-shell', false)
            ->assertSee('data-admin-primary-nav', false)
            ->assertSee('data-geo-flow-shell', false)
            ->assertSee('data-geo-flow-progress', false)
            ->assertSee('data-geo-tabs-compact', false)
            ->assertSee('AI 可见度工作台')
            ->assertSee('企业业务身份证')
            ->assertSee('AI 里搜得到你吗')
            ->assertSee('AI 引用了哪些网页')
            ->assertSee('内容资产')
            ->assertSee('发布与复测')
            ->assertSee('企业资料')
            ->assertSee('检视任务')
            ->assertSee('data-geo-inspection-preset', false)
            ->assertSee('data-geo-custom-inspection', false)
            ->assertSee('预设检视')
            ->assertSee('自定义检视')
            ->assertSee('引用来源')
            ->assertSee('发布复测')
            ->assertSee('素材库')
            ->assertSee('模型接入')
            ->assertSee('data-geo-tab-panel="overview"', false)
            ->assertSee('data-geo-tab-panel="search"', false)
            ->assertSee('data-geo-tab-panel="ai-platforms"', false)
            ->assertSee('data-geo-tab-panel="articles"', false)
            ->assertSee('data-geo-tab-panel="materials"', false)
            ->assertSee('搜索 AI 平台')
            ->assertSee('已接入真实平台')
            ->assertSee('打开搜索软件')
            ->assertSee('诊断准备台')
            ->assertSee('品牌完整度')
            ->assertSee('诊断前检查')
            ->assertSee('已接入本机搜索软件')
            ->assertSee('品牌知识库')
            ->assertSee('关键词库')
            ->assertSee('批量添加关键词')
            ->assertSee('创建诊断任务')
            ->assertSee('data-geo-diagnosis-summary', false)
            ->assertSee('本机多平台AI搜索工作台');
    }

    public function test_ai_platforms_tab_shows_web_workbench_platform_monitoring(): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'ok' => true,
                'platforms' => [
                    [
                        'platformId' => 'chatgpt',
                        'platformName' => 'ChatGPT',
                        'loginOk' => true,
                        'loginStatus' => '已登录',
                        'loginHint' => '可直接检测',
                        'completedCount' => 10,
                        'runCount' => 10,
                    ],
                    [
                        'platformId' => 'kimi',
                        'platformName' => 'Kimi',
                        'loginOk' => false,
                        'loginStatus' => '需要登录',
                        'loginHint' => '请打开工作台 UI 登录 Kimi 后再检测',
                        'lastError' => 'Kimi 当前未登录（已尝试 1 次）',
                    ],
                ],
                'tasks' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '', 0),
        ]);

        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace').'#ai-platforms')
            ->assertOk()
            ->assertSee('平台监视')
            ->assertSee('ChatGPT')
            ->assertSee('Kimi')
            ->assertSee('需要登录')
            ->assertSee('请打开工作台 UI 登录 Kimi 后再检测')
            ->assertSee('单平台检测')
            ->assertSee('name="platform_ids[]"', false);
    }

    public function test_custom_external_inspection_form_shows_web_workbench_single_platform_options(): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'ok' => true,
                'platforms' => [
                    [
                        'platformId' => 'chatgpt',
                        'platformName' => 'ChatGPT',
                        'loginOk' => true,
                        'loginStatus' => '已登录',
                        'loginHint' => '可直接检测',
                    ],
                    [
                        'platformId' => 'kimi',
                        'platformName' => 'Kimi',
                        'loginOk' => false,
                        'loginStatus' => '需要登录',
                        'loginHint' => '请打开工作台 UI 登录 Kimi 后再检测',
                    ],
                ],
                'tasks' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '', 0),
        ]);

        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace').'#external-qa')
            ->assertOk()
            ->assertSee('平台检测选项')
            ->assertSee('ChatGPT')
            ->assertSee('Kimi')
            ->assertSee('需要登录')
            ->assertSee('打开登录')
            ->assertSee('一键检查登录状态')
            ->assertSee('id="geo-web-workbench-open-login-form"', false)
            ->assertSee('id="geo-web-workbench-check-logins-form"', false)
            ->assertSee('form="geo-web-workbench-open-login-form"', false)
            ->assertSee('form="geo-web-workbench-check-logins-form"', false)
            ->assertSee('data-geo-workbench-action', false)
            ->assertSee('data-geo-workbench-platform-module', false)
            ->assertSee(route('admin.geo.web-workbench.platform-statuses'), false)
            ->assertSee("hasAttribute('data-geo-workbench-async')", false)
            ->assertSee(route('admin.geo.web-workbench.open'), false)
            ->assertSee(route('admin.geo.web-workbench.check-logins'), false)
            ->assertSee('value="ai_web_workbench:chatgpt"', false)
            ->assertSee('value="ai_web_workbench:kimi"', false);
    }

    public function test_geo_workspace_defers_yixiaoer_published_content_overview_until_requested(): void
    {
        $admin = $this->createAdmin();
        config(['services.yixiaoer.api_key' => 'test-yxe-key']);

        Http::fake([
            'https://www.yixiaoer.cn/api/contents/overviews*' => Http::response([
                'data' => [
                    [
                        'accountName' => '柜宝说',
                        'type' => 'video',
                        'updatedAt' => 1779827355351,
                        'contentData' => [
                            'id' => 'douyin-1',
                            'desc' => '装修开心秘诀：别只靠记性',
                            'play' => '1,735',
                            'great' => '223',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('发布作品数据')
            ->assertSee('点击加载后同步蚁小二作品数据')
            ->assertDontSee('柜宝说');

        Http::assertNotSent(fn ($request): bool => str_starts_with($request->url(), 'https://www.yixiaoer.cn/api/contents/overviews'));
    }

    public function test_geo_workspace_embeds_article_and_material_management_with_chain_links(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 88,
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => 'GEO内容',
            'slug' => 'geo-content',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOAmplify',
        ]);
        $article = Article::query()->create([
            'title' => '恒森全屋定制 GEO 引用文章',
            'slug' => 'hengsen-geo-citation-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 1,
            'metadata' => [
                'source' => 'geo_reference_imitation',
                'target_question' => '涪陵全屋定制哪家好',
            ],
        ]);
        $writingTask = GeoWritingTask::query()->create([
            'organization_id' => $organization->id,
            'title' => '引用源仿写任务',
            'status' => 'completed',
            'brief' => [
                'source' => 'reference_imitation',
                'question' => '涪陵全屋定制哪家好',
            ],
        ]);
        $draft = GeoArticleDraft::query()->create([
            'organization_id' => $organization->id,
            'geo_writing_task_id' => $writingTask->id,
            'article_id' => $article->id,
            'title' => '恒森全屋定制 GEO 引用文章',
            'summary' => '用于测试工作台文章串联',
            'content_markdown' => '正文',
            'status' => 'converted',
        ]);
        GeoPublishRecord::query()->create([
            'geo_article_draft_id' => $draft->id,
            'platform_codes' => ['weixingongzhonghao'],
            'status' => 'failed',
            'error_message' => '需要先登录公众号',
        ]);
        KeywordLibrary::query()->create([
            'name' => '恒森GEO关键词库',
            'keyword_count' => 12,
        ]);
        TitleLibrary::query()->create([
            'name' => '恒森GEO标题库',
            'title_count' => 8,
        ]);
        ImageLibrary::query()->create([
            'name' => '公众号配图素材',
            'image_count' => 6,
        ]);
        KnowledgeBase::query()->create([
            'name' => '恒森品牌资料库',
            'content' => '恒森全屋定制品牌资料',
            'file_type' => 'markdown',
            'character_count' => 12,
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('data-geo-tab-panel="articles"', false)
            ->assertSee('GEO 文章管理')
            ->assertSee('恒森全屋定制 GEO 引用文章')
            ->assertSee('GEO 引用仿写')
            ->assertSee('公众号草稿')
            ->assertSee('需要先登录公众号')
            ->assertSee(route('admin.geo.article-drafts.edit', ['draftId' => (int) $draft->id]), false)
            ->assertSee(route('admin.articles.edit', ['articleId' => (int) $article->id]), false)
            ->assertSee(route('admin.geo.citation-sources.index'), false)
            ->assertSee('data-geo-tab-panel="materials"', false)
            ->assertSee('GEO 素材管理')
            ->assertSee('恒森GEO关键词库')
            ->assertSee('恒森GEO标题库')
            ->assertSee('公众号配图素材')
            ->assertSee('恒森品牌资料库')
            ->assertSee(route('admin.keyword-libraries.index'), false)
            ->assertSee(route('admin.title-libraries.index'), false)
            ->assertSee(route('admin.image-libraries.index'), false)
            ->assertSee(route('admin.knowledge-bases.index'), false)
            ->assertSee(route('admin.url-import'), false);
    }

    public function test_geo_workspace_connects_all_functions_into_one_operator_chain(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 88,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['恒森定制'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、透明计价',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺',
            'status' => 'active',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);
        GeoKeywordOpportunity::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'source_geo_keyword_id' => $keyword->id,
            'created_by_admin_id' => $admin->id,
            'keyword' => '涪陵全屋定制推荐',
            'intent' => 'commercial',
            'status' => 'new',
            'business_value' => 80,
            'visibility_gap' => 70,
            'source_availability' => 75,
            'local_relevance' => 90,
            'opportunity_score' => 82,
            'generation_source' => 'test',
            'rationale' => '本地成交意图强',
        ]);
        GeoAiSearchRun::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => '真实AI搜索批次',
            'status' => 'completed',
            'platform_codes' => ['ai_web_workbench'],
            'points_cost' => 1,
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'average_score' => 78,
        ]);
        GeoCitationSource::query()->create([
            'organization_id' => $organization->id,
            'url' => 'https://example.test/hengsen-guide',
            'domain' => 'example.test',
            'title' => '涪陵全屋定制避坑指南',
            'platform_name' => 'ChatGPT',
            'status' => 'crawled',
            'citation_count' => 2,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
        $task = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => 'GEO 诊断 - 恒森全屋定制',
            'status' => 'completed',
            'total_score' => 85,
            'points_cost' => 1,
        ]);
        $report = GeoReport::query()->create([
            'geo_task_id' => $task->id,
            'title' => '恒森 GEO 诊断报告',
            'summary' => '可见度已有提升空间',
            'markdown_report' => '报告正文',
            'total_score' => 85,
            'status' => 'ready',
        ]);
        $category = Category::query()->create([
            'name' => 'GEO内容',
            'slug' => 'geo-chain-content',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOAmplify',
        ]);
        $article = Article::query()->create([
            'title' => '恒森全屋定制 GEO 文章',
            'slug' => 'hengsen-geo-chain-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 1,
            'metadata' => ['source' => 'geo_report'],
        ]);
        $writingTask = GeoWritingTask::query()->create([
            'organization_id' => $organization->id,
            'geo_report_id' => $report->id,
            'geo_keyword_id' => $keyword->id,
            'title' => 'GEO 文章任务',
            'status' => 'completed',
            'brief' => ['source' => 'geo_report', 'question' => '涪陵全屋定制哪家好'],
        ]);
        $draft = GeoArticleDraft::query()->create([
            'organization_id' => $organization->id,
            'geo_writing_task_id' => $writingTask->id,
            'article_id' => $article->id,
            'title' => '恒森全屋定制 GEO 文章',
            'summary' => '链路测试草稿',
            'content_markdown' => '正文',
            'status' => 'converted',
        ]);
        GeoPublishRecord::query()->create([
            'geo_article_draft_id' => $draft->id,
            'platform_codes' => ['weixingongzhonghao'],
            'status' => 'ready_handoff',
            'handoff_payload' => ['channel' => 'yixiaoer'],
        ]);
        GeoPublishRetest::query()->create([
            'organization_id' => $organization->id,
            'article_id' => $article->id,
            'geo_article_draft_id' => $draft->id,
            'before_score' => 72,
            'after_score' => 86,
            'status' => 'completed',
            'article_url' => 'https://example.test/article',
            'summary' => '发布后可见度提升',
            'tested_at' => now(),
        ]);
        KeywordLibrary::query()->create(['name' => 'GEO关键词库', 'keyword_count' => 12]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('GEO 全链路执行台')
            ->assertSee('9 / 9 已推进')
            ->assertSee('data-geo-chain-step="brand"', false)
            ->assertSee('data-geo-chain-step="opportunities"', false)
            ->assertSee('data-geo-chain-step="ai-search"', false)
            ->assertSee('data-geo-chain-step="citations"', false)
            ->assertSee('data-geo-chain-step="drafts"', false)
            ->assertSee('data-geo-chain-step="materials"', false)
            ->assertSee('data-geo-chain-step="articles"', false)
            ->assertSee('data-geo-chain-step="publish"', false)
            ->assertSee('data-geo-chain-step="retest"', false)
            ->assertSee('品牌资料')
            ->assertSee('机会搜索')
            ->assertSee('真实AI搜索')
            ->assertSee('引用源分析')
            ->assertSee('仿写草稿')
            ->assertSee('素材补齐')
            ->assertSee('正式文章')
            ->assertSee('公众号交接')
            ->assertSee('复测报告')
            ->assertSee('运营底座')
            ->assertSee('任务管理')
            ->assertSee('AI配置器')
            ->assertSee('站点设置')
            ->assertSee('href="#setup"', false)
            ->assertSee('href="#search"', false)
            ->assertSee('href="#ai-platforms"', false)
            ->assertSee('href="#articles"', false)
            ->assertSee('href="#materials"', false)
            ->assertSee(route('admin.geo.citation-sources.index'), false)
            ->assertSee(route('admin.articles.index'), false)
            ->assertSee(route('admin.tasks.index'), false)
            ->assertSee(route('admin.ai.configurator'), false)
            ->assertSee(route('admin.site-settings.index'), false);
    }

    public function test_geo_opportunity_search_outputs_are_reflected_in_materials_tab(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 88,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'products' => '全屋定制',
            'advantages' => '本地工厂',
            'service_area' => '重庆涪陵',
            'status' => 'active',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);
        $opportunity = GeoKeywordOpportunity::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'source_geo_keyword_id' => $keyword->id,
            'created_by_admin_id' => $admin->id,
            'keyword' => '涪陵全屋定制推荐',
            'intent' => 'commercial',
            'cluster_name' => '本地推荐词',
            'status' => 'new',
            'business_value' => 80,
            'visibility_gap' => 70,
            'source_availability' => 75,
            'local_relevance' => 90,
            'opportunity_score' => 82,
            'generation_source' => 'manual_combination',
            'rationale' => '本地成交意图强，可用于文章选题',
        ]);
        GeoAiSearchRun::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => '真实AI搜索批次',
            'status' => 'completed',
            'platform_codes' => ['ai_web_workbench'],
            'points_cost' => 1,
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
            'average_score' => 78,
        ]);
        $source = GeoCitationSource::query()->create([
            'organization_id' => $organization->id,
            'url' => 'https://example.test/hengsen-guide',
            'domain' => 'example.test',
            'title' => '涪陵全屋定制避坑指南',
            'platform_name' => 'ChatGPT',
            'status' => 'crawled',
            'citation_count' => 2,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'metadata' => ['opportunity_id' => $opportunity->id],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('data-geo-tab-panel="materials"', false)
            ->assertSee('机会搜索素材沉淀')
            ->assertSee('机会词素材')
            ->assertSee('涪陵全屋定制推荐')
            ->assertSee('本地推荐词')
            ->assertSee('本地成交意图强，可用于文章选题')
            ->assertSee('真实搜索素材')
            ->assertSee('真实AI搜索批次')
            ->assertSee('引用源素材')
            ->assertSee('example.test')
            ->assertSee('涪陵全屋定制避坑指南')
            ->assertSee('href="#search"', false)
            ->assertSee(route('admin.geo.citation-sources.show', ['sourceId' => $source->id]), false);
    }

    public function test_admin_can_open_local_ai_web_workbench_from_geo_workspace(): void
    {
        Process::fake([
            '*' => Process::result('', '', 0),
        ]);

        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.web-workbench.open'))
            ->assertRedirect(route('admin.geo.workspace').'#ai-platforms')
            ->assertSessionHas('message', '本机多平台 AI 搜索工作台已打开');

        Process::assertRan(function ($process): bool {
            $command = is_array($process->command) ? $process->command : [$process->command];
            $commandLine = implode(' ', $command);
            $path = (string) ($process->environment['PATH'] ?? '');

            return in_array('bash', $command, true)
                && str_contains($commandLine, 'ai-web-workbench')
                && str_contains($commandLine, ' ui ')
                && str_contains($commandLine, 'nohup')
                && str_contains($path, '/usr/bin');
        });
    }

    public function test_admin_can_open_single_platform_login_window_from_geo_workspace(): void
    {
        Process::fake([
            '*' => Process::result('', '', 0),
        ]);

        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.web-workbench.open'), [
                'platform_id' => 'kimi',
                'return_tab' => 'external-qa',
            ])
            ->assertRedirect(route('admin.geo.workspace').'#external-qa')
            ->assertSessionHas('message', 'Kimi 登录窗口已打开');

        Process::assertRan(function ($process): bool {
            $command = is_array($process->command) ? $process->command : [$process->command];
            $commandLine = implode(' ', $command);

            return str_contains($commandLine, 'ai-web-workbench')
                && str_contains($commandLine, ' ui ')
                && str_contains($commandLine, '--platform')
                && str_contains($commandLine, 'kimi');
        });
    }

    public function test_admin_can_check_web_workbench_login_statuses_from_geo_workspace(): void
    {
        Process::fake([
            '*' => Process::result('', '', 0),
        ]);

        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.web-workbench.check-logins'), [
                'platform_ids' => ['chatgpt', 'kimi'],
                'return_tab' => 'external-qa',
            ])
            ->assertRedirect(route('admin.geo.workspace').'#external-qa')
            ->assertSessionHas('message', '登录状态检测已启动：正在后台检查 2 个平台，稍后刷新页面查看结果');

        Process::assertRan(function ($process): bool {
            $command = is_array($process->command) ? $process->command : [$process->command];
            $commandLine = implode(' ', $command);

            return in_array('bash', $command, true)
                && str_contains($commandLine, 'nohup')
                && str_contains($commandLine, 'ai-web-workbench')
                && str_contains($commandLine, ' check-logins ')
                && str_contains($commandLine, '--timeout-ms 90000')
                && str_contains($commandLine, '--platform')
                && str_contains($commandLine, 'chatgpt')
                && str_contains($commandLine, 'kimi')
                && str_contains($commandLine, '--json');
        });
    }

    public function test_admin_can_check_web_workbench_logins_without_full_page_refresh(): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'ok' => true,
                'platforms' => [
                    [
                        'platformId' => 'chatgpt',
                        'platformName' => 'ChatGPT',
                        'loginOk' => true,
                        'loginStatus' => '已登录',
                    ],
                    [
                        'platformId' => 'kimi',
                        'platformName' => 'Kimi',
                        'loginOk' => null,
                        'loginStatus' => '检测中',
                    ],
                ],
                'tasks' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '', 0),
        ]);

        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.geo.web-workbench.check-logins'), [
                'platform_ids' => ['chatgpt', 'kimi'],
                'return_tab' => 'external-qa',
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', '登录状态检测已启动：正在后台检查 2 个平台')
            ->assertJsonStructure(['html', 'platforms']);

        $this->assertStringContainsString('data-geo-workbench-platform-options', (string) $response->json('html'));
        $this->assertStringContainsString('Kimi', (string) $response->json('html'));
        $this->assertStringNotContainsString('data-admin-gui-shell', (string) $response->json('html'));
    }

    public function test_admin_can_refresh_web_workbench_platform_status_partial(): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'ok' => true,
                'platforms' => [
                    [
                        'platformId' => 'chatgpt',
                        'platformName' => 'ChatGPT',
                        'loginOk' => true,
                        'loginStatus' => '已登录',
                    ],
                    [
                        'platformId' => 'kimi',
                        'platformName' => 'Kimi',
                        'loginOk' => false,
                        'loginStatus' => '需要登录',
                    ],
                ],
                'tasks' => [],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '', 0),
        ]);

        $admin = $this->createAdmin();

        $response = $this->actingAs($admin, 'admin')
            ->getJson(route('admin.geo.web-workbench.platform-statuses'));

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['html', 'platforms']);

        $this->assertStringContainsString('data-geo-workbench-platform-options', (string) $response->json('html'));
        $this->assertStringContainsString('ChatGPT', (string) $response->json('html'));
        $this->assertStringContainsString('Kimi', (string) $response->json('html'));
    }

    public function test_admin_can_delete_duplicate_external_inspection_run(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['恒森定制'],
            'products' => '衣柜、橱柜、全屋定制',
            'advantages' => '本地工厂',
            'service_area' => '重庆涪陵',
            'status' => 'active',
        ]);
        $opportunity = GeoKeywordOpportunity::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'keyword' => '重庆涪陵及周边区域全屋定制哪家靠谱',
            'intent' => 'decision',
            'status' => 'active',
            'generation_source' => 'external_qa_inspection',
        ]);
        $run = GeoAiSearchRun::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => '重庆涪陵及周边区域全屋定制哪家靠谱',
            'status' => 'pending',
            'platform_codes' => ['ai_web_workbench:kimi'],
            'total_questions' => 1,
        ]);
        $question = $run->questions()->create([
            'geo_keyword_opportunity_id' => $opportunity->id,
            'question' => '重庆涪陵全屋定制哪家靠谱',
            'intent' => 'decision',
            'status' => 'pending',
        ]);
        $answer = $run->answers()->create([
            'geo_ai_search_question_id' => $question->id,
            'geo_keyword_opportunity_id' => $opportunity->id,
            'platform_code' => 'ai_web_workbench:kimi',
            'prompt' => '测试',
            'raw_answer' => '测试回答',
            'status' => 'completed',
        ]);
        $source = GeoCitationSource::query()->create([
            'organization_id' => $organization->id,
            'geo_ai_search_answer_id' => $answer->id,
            'url' => 'https://example.test/hengsen',
            'domain' => 'example.test',
            'title' => '恒森资料',
            'status' => 'pending_crawl',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace').'#external-qa')
            ->assertOk()
            ->assertSee('删除')
            ->assertSee('data-geo-search-run-delete-form', false)
            ->assertSee('data-geo-search-run-card="'.$run->id.'"', false)
            ->assertSee(route('admin.geo.search-runs.delete', ['runId' => $run->id]), false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.delete', ['runId' => $run->id]))
            ->assertRedirect(route('admin.geo.workspace').'#external-qa')
            ->assertSessionHas('message', '检视批次已删除');

        $this->assertDatabaseMissing('geo_ai_search_runs', ['id' => $run->id]);
        $this->assertDatabaseMissing('geo_ai_search_questions', ['id' => $question->id]);
        $this->assertDatabaseMissing('geo_ai_search_answers', ['id' => $answer->id]);
        $this->assertDatabaseHas('geo_citation_sources', [
            'id' => $source->id,
            'geo_ai_search_answer_id' => null,
        ]);
    }

    public function test_admin_delete_missing_external_inspection_run_is_idempotent(): void
    {
        $admin = $this->createAdmin();

        Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.delete', ['runId' => 999999]))
            ->assertRedirect(route('admin.geo.workspace').'#external-qa')
            ->assertSessionHas('message', '检视批次已删除或不存在');
    }

    public function test_admin_can_delete_external_inspection_run_as_json(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'products' => '全屋定制',
            'advantages' => '本地工厂',
            'status' => 'active',
        ]);
        $run = GeoAiSearchRun::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => '异步删除检视',
            'status' => 'pending',
            'platform_codes' => ['ai_web_workbench:kimi'],
            'total_questions' => 0,
        ]);

        $this->actingAs($admin, 'admin')
            ->postJson(route('admin.geo.search-runs.delete', ['runId' => $run->id]))
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('message', '检视批次已删除')
            ->assertJsonPath('run_id', $run->id);

        $this->assertDatabaseMissing('geo_ai_search_runs', ['id' => $run->id]);
    }

    public function test_admin_deleting_one_duplicate_external_inspection_removes_the_matching_pending_group(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'products' => '全屋定制',
            'advantages' => '本地工厂',
            'status' => 'active',
        ]);
        $opportunity = GeoKeywordOpportunity::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'keyword' => '重庆涪陵及周边区域全屋定制哪家靠谱',
            'intent' => 'decision',
            'status' => 'active',
            'generation_source' => 'external_qa_inspection',
        ]);
        $createRun = function (string $status = 'pending') use ($admin, $organization, $brandProfile, $opportunity): GeoAiSearchRun {
            $run = GeoAiSearchRun::query()->create([
                'organization_id' => $organization->id,
                'brand_profile_id' => $brandProfile->id,
                'created_by_admin_id' => $admin->id,
                'name' => '重庆涪陵及周边区域全屋定制哪家靠谱',
                'status' => $status,
                'platform_codes' => ['ai_web_workbench'],
                'total_questions' => 2,
            ]);
            foreach (['恒森全屋定制怎么样', '重庆涪陵及周边区域全屋定制哪家靠谱'] as $question) {
                $run->questions()->create([
                    'geo_keyword_opportunity_id' => $opportunity->id,
                    'question' => $question,
                    'intent' => 'decision',
                    'status' => $status === 'completed' ? 'completed' : 'pending',
                ]);
            }

            return $run;
        };
        $olderDuplicate = $createRun();
        $newerDuplicate = $createRun();
        $completedRun = $createRun('completed');

        $response = $this->actingAs($admin, 'admin')
            ->postJson(route('admin.geo.search-runs.delete', ['runId' => $newerDuplicate->id]));

        $response
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted_count', 2)
            ->assertJsonPath('message', '已删除 2 个同名重复检视批次');

        $this->assertEqualsCanonicalizing(
            [$olderDuplicate->id, $newerDuplicate->id],
            $response->json('deleted_run_ids')
        );
        $this->assertDatabaseMissing('geo_ai_search_runs', ['id' => $olderDuplicate->id]);
        $this->assertDatabaseMissing('geo_ai_search_runs', ['id' => $newerDuplicate->id]);
        $this->assertDatabaseHas('geo_ai_search_runs', [
            'id' => $completedRun->id,
            'status' => 'completed',
        ]);
    }

    public function test_admin_reuses_old_pending_external_inspection_instead_of_creating_duplicate(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'products' => '全屋定制',
            'advantages' => '本地工厂',
            'status' => 'active',
        ]);
        $opportunity = GeoKeywordOpportunity::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'keyword' => '重庆涪陵及周边区域全屋定制哪家靠谱',
            'intent' => 'decision',
            'status' => 'active',
            'generation_source' => 'external_qa_inspection',
        ]);
        $existingRun = GeoAiSearchRun::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => '重庆涪陵及周边区域全屋定制哪家靠谱',
            'status' => 'pending',
            'platform_codes' => ['ai_web_workbench'],
            'total_questions' => 2,
            'target_keyword_hit_rate' => 70,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        foreach (['恒森全屋定制怎么样', '重庆涪陵及周边区域全屋定制哪家靠谱'] as $question) {
            $existingRun->questions()->create([
                'geo_keyword_opportunity_id' => $opportunity->id,
                'question' => $question,
                'intent' => 'decision',
                'status' => 'pending',
            ]);
        }

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.external-inspections.store'), [
                'name' => '重庆涪陵及周边区域全屋定制哪家靠谱',
                'questions_text' => "恒森全屋定制怎么样\n重庆涪陵及周边区域全屋定制哪家靠谱",
                'target_keyword_hit_rate' => 70,
                'platform_codes' => ['ai_web_workbench'],
            ])
            ->assertRedirect(route('admin.geo.workspace').'#external-qa')
            ->assertSessionHas('message', '外部问答检视已存在，已打开已有批次：重庆涪陵及周边区域全屋定制哪家靠谱');

        $this->assertSame(1, GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('name', '重庆涪陵及周边区域全屋定制哪家靠谱')
            ->count());
    }

    public function test_admin_cannot_delete_running_external_inspection_run(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'products' => '全屋定制',
            'advantages' => '本地工厂',
            'status' => 'active',
        ]);
        $run = GeoAiSearchRun::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => '正在运行的检视',
            'status' => 'running',
            'platform_codes' => ['ai_web_workbench:kimi'],
            'total_questions' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.delete', ['runId' => $run->id]))
            ->assertRedirect(route('admin.geo.workspace').'#external-qa')
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('geo_ai_search_runs', [
            'id' => $run->id,
            'status' => 'running',
        ]);
    }

    public function test_setup_tab_accepts_batch_keywords_and_returns_to_setup_anchor(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'owner_admin_id' => $admin->id,
            'name' => '恒森全屋定制',
            'plan_code' => 'trial',
            'points' => 100,
            'balance' => 0,
            'status' => 'active',
        ]);

        BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['恒森定制'],
            'products' => '衣柜、橱柜、全屋定制',
            'advantages' => '本地工厂、透明计价',
            'cases' => '涪陵本地家庭定制案例',
            'pain_points' => '价格不透明、板材环保难判断',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺',
            'extended_profile' => [
                'short_name' => '恒森',
                'writing_directions' => '用本地案例讲选择标准',
                'copy_types' => ['客户问答'],
                'product_features' => ['E0级板材'],
                'trust_proofs' => ['本地展厅可看样'],
                'promotion_regions' => ['重庆涪陵'],
                'forbidden_claims' => ['行业第一'],
            ],
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.keywords.store'), [
                'keywords_text' => "涪陵全屋定制哪家好\n重庆全屋定制避坑",
                'type' => 'question',
                'intent' => 'commercial',
                'return_tab' => 'setup',
            ])
            ->assertRedirect(route('admin.geo.workspace').'#setup')
            ->assertSessionHas('message');

        $this->assertDatabaseHas('geo_keywords', [
            'organization_id' => $organization->id,
            'keyword' => '涪陵全屋定制哪家好',
            'type' => 'question',
        ]);
        $this->assertDatabaseHas('geo_keywords', [
            'organization_id' => $organization->id,
            'keyword' => '重庆全屋定制避坑',
            'type' => 'question',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('诊断准备台')
            ->assertSee('品牌完整度')
            ->assertSee('资料已完整')
            ->assertSee('全选预估点数')
            ->assertSee('data-geo-diagnosis-ready="1"', false);
    }

    public function test_admin_can_save_brand_keyword_and_create_diagnosis_task(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.brand-profile.save'), [
                'organization_name' => '恒森全屋定制',
                'brand_name' => '恒森全屋定制',
                'aliases_text' => "涪陵恒森全屋定制工厂\n恒森定制",
                'products' => '衣柜、橱柜、鞋柜、全屋定制',
                'advantages' => '本地工厂、环保板材、透明计价',
                'cases' => '涪陵本地家庭定制案例',
                'pain_points' => '价格不透明、板材环保难判断',
                'service_area' => '重庆涪陵',
                'extra_facts' => '支持上门量尺和定制设计',
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $this->assertDatabaseHas('organizations', [
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('brand_profiles', [
            'brand_name' => '恒森全屋定制',
            'service_area' => '重庆涪陵',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.keywords.store'), [
                'keyword' => '涪陵全屋定制哪家好',
                'type' => 'question',
                'intent' => 'commercial',
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $organization = Organization::query()->where('owner_admin_id', $admin->id)->firstOrFail();
        $brandProfile = BrandProfile::query()->where('organization_id', $organization->id)->firstOrFail();
        $keyword = GeoKeyword::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.store'), [
                'keyword_ids' => [$keyword->id],
                'platform_codes' => ['deepseek_mock', 'kimi_mock'],
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $this->assertDatabaseHas('geo_tasks', [
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'status' => 'pending',
            'points_cost' => 2,
        ]);
        $this->assertSame(1, GeoTask::query()->count());
        $this->assertSame(1, GeoTaskQuestion::query()->count());
        $this->assertSame(['deepseek_mock', 'kimi_mock'], GeoTaskQuestion::query()->firstOrFail()->platform_codes);
    }

    public function test_rich_brand_profile_feeds_prompts_and_reference_drafts(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.brand-profile.save'), [
                'organization_name' => '恒森全屋定制',
                'brand_name' => '恒森全屋定制',
                'aliases_text' => "恒森定制\n涪陵恒森",
                'products' => '衣柜、橱柜、鞋柜、全屋定制',
                'advantages' => '本地工厂、透明计价',
                'cases' => '涪陵本地旧房改造案例',
                'pain_points' => '价格不透明、板材环保难判断',
                'service_area' => '重庆涪陵',
                'extra_facts' => '支持上门量尺和定制设计',
                'short_name' => '恒森',
                'writing_directions' => '用本地业主案例讲清楚选择标准',
                'copy_types' => "客户问答\n避坑指南",
                'product_features' => "E0级板材\n自有工厂交付",
                'brand_story' => '扎根涪陵本地服务，重视复购和转介绍',
                'trust_proofs' => "本地展厅可看样\n老客户转介绍",
                'promotion_regions' => "重庆涪陵\n重庆武隆",
                'forbidden_claims' => "行业第一\n百分百环保",
            ])
            ->assertRedirect(route('admin.geo.workspace'));

        $organization = Organization::query()->where('owner_admin_id', $admin->id)->firstOrFail();
        $brandProfile = BrandProfile::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame('恒森', $brandProfile->extended_profile['short_name']);
        $this->assertSame('用本地业主案例讲清楚选择标准', $brandProfile->extended_profile['writing_directions']);
        $this->assertSame(['客户问答', '避坑指南'], $brandProfile->extended_profile['copy_types']);
        $this->assertSame(['E0级板材', '自有工厂交付'], $brandProfile->extended_profile['product_features']);
        $this->assertSame(['本地展厅可看样', '老客户转介绍'], $brandProfile->extended_profile['trust_proofs']);
        $this->assertSame(['重庆涪陵', '重庆武隆'], $brandProfile->extended_profile['promotion_regions']);
        $this->assertSame(['行业第一', '百分百环保'], $brandProfile->extended_profile['forbidden_claims']);

        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制怎么选',
            'intent' => 'commercial',
        ]);
        $task = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => 'GEO 诊断 - 恒森全屋定制',
            'status' => 'pending',
            'points_cost' => 1,
        ]);
        GeoTaskQuestion::query()->create([
            'geo_task_id' => $task->id,
            'geo_keyword_id' => $keyword->id,
            'question' => '涪陵全屋定制怎么选',
            'platform_codes' => ['deepseek_mock'],
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.run', ['taskId' => $task->id]))
            ->assertRedirect(route('admin.geo.workspace'));

        $prompt = (string) GeoAnswer::query()->firstOrFail()->prompt;
        $this->assertStringContainsString('品牌简称：恒森', $prompt);
        $this->assertStringContainsString('写作方向：用本地业主案例讲清楚选择标准', $prompt);
        $this->assertStringContainsString('产品特点：E0级板材、自有工厂交付', $prompt);
        $this->assertStringContainsString('信任背书：本地展厅可看样、老客户转介绍', $prompt);
        $this->assertStringContainsString('禁用表达：行业第一、百分百环保', $prompt);

        $writingTask = GeoWritingTask::query()->create([
            'organization_id' => $organization->id,
            'geo_report_id' => null,
            'geo_keyword_id' => $keyword->id,
            'title' => '涪陵全屋定制参考内容简报',
            'status' => 'ready',
            'brief' => [
                'source' => 'reference_content',
                'references' => [[
                    'title' => '涪陵全屋定制避坑指南',
                    'url' => 'https://example.test/hengsen-guide',
                    'score' => 88,
                    'summary' => '重点讲报价、板材和售后。',
                ]],
                'recommended_outline' => ['先讲选择标准', '再讲本地案例'],
                'evidence_points' => ['需要说明板材、报价和售后'],
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.reference-briefs.article-draft.store', ['writingTaskId' => $writingTask->id]))
            ->assertRedirect();

        $draft = GeoArticleDraft::query()->where('geo_writing_task_id', $writingTask->id)->firstOrFail();
        $this->assertStringContainsString('写作方向：用本地业主案例讲清楚选择标准', (string) $draft->content_markdown);
        $this->assertStringContainsString('产品特点：E0级板材、自有工厂交付', (string) $draft->content_markdown);
        $this->assertStringContainsString('信任背书：本地展厅可看样、老客户转介绍', (string) $draft->content_markdown);
        $this->assertStringContainsString('禁用表达：行业第一、百分百环保', (string) $draft->content_markdown);
    }

    public function test_admin_can_run_mock_diagnosis_and_generate_report(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['涪陵恒森全屋定制工厂', '恒森定制'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺和定制设计',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);
        $task = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => 'GEO 诊断 - 恒森全屋定制',
            'status' => 'pending',
            'points_cost' => 2,
        ]);
        GeoTaskQuestion::query()->create([
            'geo_task_id' => $task->id,
            'geo_keyword_id' => $keyword->id,
            'question' => '涪陵全屋定制哪家好',
            'platform_codes' => ['deepseek_mock', 'kimi_mock'],
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.run', ['taskId' => $task->id]))
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $task->refresh();
        $organization->refresh();

        $this->assertSame('completed', $task->status);
        $this->assertGreaterThanOrEqual(60, $task->total_score);
        $this->assertSame(98, $organization->points);
        $this->assertSame(2, GeoAnswer::query()->where('geo_task_id', $task->id)->count());
        $this->assertSame(2, GeoScore::query()->count());
        $this->assertDatabaseHas('geo_reports', [
            'geo_task_id' => $task->id,
            'status' => 'ready',
        ]);
        $this->assertStringContainsString('恒森全屋定制', (string) GeoReport::query()->firstOrFail()->markdown_report);
        $this->assertDatabaseHas('point_logs', [
            'organization_id' => $organization->id,
            'admin_id' => $admin->id,
            'action' => 'geo_diagnosis',
            'points_delta' => -2,
        ]);
        $this->assertSame(-2, PointLog::query()->firstOrFail()->points_delta);
    }

    public function test_admin_can_run_diagnosis_with_local_ai_web_workbench(): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'ok' => true,
                'taskId' => 'task-geo-workbench',
                'markdownPath' => '/tmp/task-geo-workbench.md',
                'sentCount' => 2,
                'completedCount' => 2,
                'manualCount' => 0,
                'runs' => [
                    [
                        'platformId' => 'chatgpt',
                        'platformName' => 'ChatGPT',
                        'status' => '完成',
                        'answerSource' => 'auto',
                        'answerText' => '涪陵全屋定制可以优先了解恒森全屋定制，参考资料见 https://example.test/hengsen-guide 。',
                        'citations' => [[
                            'url' => 'https://example.test/hengsen-guide',
                            'title' => '恒森全屋定制参考',
                        ]],
                    ],
                    [
                        'platformId' => 'kimi',
                        'platformName' => 'Kimi',
                        'status' => '完成',
                        'answerSource' => 'auto',
                        'answerText' => '恒森定制在本地交付、报价透明和售后响应上值得对比。',
                        'citations' => [],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '', 0),
        ]);

        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['恒森定制'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺和定制设计',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);
        $task = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => 'GEO 诊断 - 本机搜索工作台',
            'status' => 'pending',
            'points_cost' => 1,
        ]);
        GeoTaskQuestion::query()->create([
            'geo_task_id' => $task->id,
            'geo_keyword_id' => $keyword->id,
            'question' => '涪陵全屋定制哪家好',
            'platform_codes' => ['ai_web_workbench'],
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.run', ['taskId' => $task->id]))
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $answer = GeoAnswer::query()->firstOrFail();
        $this->assertSame('ai_web_workbench', $answer->platform_code);
        $this->assertStringContainsString('本机多平台 AI 搜索工作台结果', (string) $answer->raw_answer);
        $this->assertStringContainsString('ChatGPT', (string) $answer->raw_answer);
        $this->assertStringContainsString('example.test/hengsen-guide', (string) $answer->raw_answer);
        $this->assertDatabaseHas('geo_reports', [
            'geo_task_id' => $task->id,
            'status' => 'ready',
        ]);

        Process::assertRan(function ($process): bool {
            $command = is_array($process->command) ? $process->command : [$process->command];

            return in_array('run', $command, true)
                && in_array('--json', $command, true)
                && in_array('涪陵全屋定制哪家好', $command, true);
        });
    }

    public function test_diagnosis_report_modes_split_customer_report_from_internal_recommendations(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['恒森定制'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺和定制设计',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.store'), [
                'keyword_ids' => [$keyword->id],
                'platform_codes' => ['deepseek_mock'],
                'report_mode' => 'visibility_only',
            ])
            ->assertRedirect(route('admin.geo.workspace'));

        $customerTask = GeoTask::query()->firstOrFail();
        $this->assertSame('visibility_only', $customerTask->report_mode);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.run', ['taskId' => $customerTask->id]))
            ->assertRedirect(route('admin.geo.workspace'));

        $customerReport = GeoReport::query()->where('geo_task_id', $customerTask->id)->firstOrFail();
        $this->assertStringContainsString('客户可读可见度报告', (string) $customerReport->markdown_report);
        $this->assertStringNotContainsString('## 优化建议', (string) $customerReport->markdown_report);
        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.reports.show', ['taskId' => $customerTask->id]))
            ->assertOk()
            ->assertSee('客户报告')
            ->assertDontSee('优化建议');

        $internalTask = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => 'GEO 诊断 - 内部版',
            'status' => 'pending',
            'points_cost' => 1,
            'report_mode' => 'with_recommendations',
        ]);
        GeoTaskQuestion::query()->create([
            'geo_task_id' => $internalTask->id,
            'geo_keyword_id' => $keyword->id,
            'question' => '涪陵全屋定制哪家好',
            'platform_codes' => ['deepseek_mock'],
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.run', ['taskId' => $internalTask->id]))
            ->assertRedirect(route('admin.geo.workspace'));

        $internalReport = GeoReport::query()->where('geo_task_id', $internalTask->id)->firstOrFail();
        $this->assertStringContainsString('内部优化建议报告', (string) $internalReport->markdown_report);
        $this->assertStringContainsString('## 优化建议', (string) $internalReport->markdown_report);
    }

    public function test_admin_can_run_diagnosis_with_configured_real_ai_model(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '根据资料显示，重庆涪陵做全屋定制可以优先了解恒森全屋定制。它支持上门量尺和定制设计，适合关注本地交付的家庭。来源：https://example.test/hengsen',
                    ],
                ]],
            ]),
        ]);

        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['涪陵恒森全屋定制工厂', '恒森定制'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺和定制设计',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);
        $aiModel = AiModel::query()->create([
            'name' => '测试聊天模型',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => 'https://ai.test',
            'failover_priority' => 10,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('真实 AI 模型')
            ->assertSee('测试聊天模型')
            ->assertSee('test-chat-model');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.store'), [
                'keyword_ids' => [$keyword->id],
                'platform_codes' => ['ai_model:'.$aiModel->id],
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $task = GeoTask::query()->firstOrFail();
        $this->assertSame($organization->id, $task->organization_id);
        $this->assertSame($brandProfile->id, $task->brand_profile_id);
        $this->assertSame(1, (int) $task->points_cost);
        $this->assertSame(['ai_model:'.$aiModel->id], GeoTaskQuestion::query()->firstOrFail()->platform_codes);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.run', ['taskId' => $task->id]))
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $answer = GeoAnswer::query()->firstOrFail();
        $task->refresh();
        $organization->refresh();
        $aiModel->refresh();

        $this->assertSame('completed', $task->status);
        $this->assertSame(99, $organization->points);
        $this->assertSame('ai_model:'.$aiModel->id, $answer->platform_code);
        $this->assertStringContainsString('可以优先了解恒森全屋定制', $answer->raw_answer);
        $this->assertStringContainsString('用户问题：涪陵全屋定制哪家好', $answer->prompt);
        $this->assertSame(1, (int) $aiModel->used_today);
        $this->assertSame(1, (int) $aiModel->total_used);
        $this->assertDatabaseHas('geo_reports', [
            'geo_task_id' => $task->id,
            'status' => 'ready',
        ]);
        $report = GeoReport::query()->where('geo_task_id', $task->id)->firstOrFail();
        $this->assertStringContainsString('测试聊天模型', $report->markdown_report);
        $this->assertStringNotContainsString('后续接入真实平台 API 后', $report->markdown_report);
        $this->assertStringNotContainsString('模拟回答', $report->summary);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && $request['model'] === 'test-chat-model'
            && str_contains((string) $request['messages'][1]['content'], '涪陵全屋定制哪家好')
            && str_contains((string) $request['messages'][1]['content'], '恒森全屋定制')
            && $request->hasHeader('Authorization', 'Bearer test-api-key'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertOk()
            ->assertSee('测试聊天模型')
            ->assertSee('可以优先了解恒森全屋定制');
    }

    public function test_admin_can_run_diagnosis_with_anthropic_compatible_ai_model(): void
    {
        Http::fake([
            'https://anthropic.test/v1/messages' => Http::response([
                'content' => [
                    [
                        'type' => 'text',
                        'text' => '资料显示，重庆涪陵全屋定制可以优先了解恒森全屋定制，原因是本地工厂、报价透明，并支持上门量尺。来源：https://example.test/hengsen',
                    ],
                ],
            ]),
        ]);

        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['恒森定制'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺和定制设计',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);
        $aiModel = AiModel::query()->create([
            'name' => 'GPT 5.5 Anthropic 兼容',
            'version' => 'anthropic-compatible',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'gpt-5.5',
            'model_type' => 'chat',
            'api_url' => 'https://anthropic.test/v1',
            'failover_priority' => 5,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.store'), [
                'keyword_ids' => [$keyword->id],
                'platform_codes' => ['ai_model:'.$aiModel->id],
            ])
            ->assertRedirect(route('admin.geo.workspace'));

        $task = GeoTask::query()->firstOrFail();
        $this->assertSame($brandProfile->id, $task->brand_profile_id);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.run', ['taskId' => $task->id]))
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $answer = GeoAnswer::query()->firstOrFail();
        $aiModel->refresh();

        $this->assertSame('ai_model:'.$aiModel->id, $answer->platform_code);
        $this->assertStringContainsString('可以优先了解恒森全屋定制', $answer->raw_answer);
        $this->assertSame(1, (int) $aiModel->used_today);
        $this->assertSame(1, (int) $aiModel->total_used);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://anthropic.test/v1/messages'
            && $request['model'] === 'gpt-5.5'
            && $request['messages'][0]['role'] === 'user'
            && str_contains((string) $request['messages'][0]['content'], '涪陵全屋定制哪家好')
            && str_contains((string) $request['system'], '真实 AI 搜索助手')
            && $request->hasHeader('Authorization', 'Bearer test-api-key')
            && $request->hasHeader('anthropic-version', '2023-06-01'));
    }

    public function test_real_ai_diagnosis_failure_marks_task_failed_instead_of_crashing(): void
    {
        Http::fake([
            'https://anthropic.test/v1/messages' => Http::response([
                'error' => ['message' => 'Service temporarily unavailable'],
            ], 503),
        ]);

        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'products' => '全屋定制',
            'advantages' => '本地工厂',
            'service_area' => '重庆涪陵',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);
        $aiModel = AiModel::query()->create([
            'name' => '故障模型',
            'version' => 'anthropic-compatible',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'gpt-5.5',
            'model_type' => 'chat',
            'api_url' => 'https://anthropic.test/v1',
            'failover_priority' => 5,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
        $task = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => 'GEO 诊断 - 故障模型',
            'status' => 'pending',
            'points_cost' => 1,
        ]);
        GeoTaskQuestion::query()->create([
            'geo_task_id' => $task->id,
            'geo_keyword_id' => $keyword->id,
            'question' => '涪陵全屋定制哪家好',
            'platform_codes' => ['ai_model:'.$aiModel->id],
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.diagnosis.run', ['taskId' => $task->id]))
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHasErrors();

        $task->refresh();
        $organization->refresh();
        $aiModel->refresh();

        $this->assertSame('failed', $task->status);
        $this->assertStringContainsString('HTTP 503', (string) $task->error_message);
        $this->assertSame(100, (int) $organization->points);
        $this->assertSame(0, (int) $aiModel->used_today);
        $this->assertSame(0, GeoAnswer::query()->count());
    }

    public function test_admin_can_view_geo_report_detail(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 97,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['涪陵恒森全屋定制工厂'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'service_area' => '重庆涪陵',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);
        $task = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => 'GEO 诊断 - 恒森全屋定制',
            'status' => 'completed',
            'total_score' => 85,
            'points_cost' => 2,
        ]);
        $question = GeoTaskQuestion::query()->create([
            'geo_task_id' => $task->id,
            'geo_keyword_id' => $keyword->id,
            'question' => '涪陵全屋定制哪家好',
            'platform_codes' => ['deepseek_mock'],
            'status' => 'completed',
        ]);
        $answer = GeoAnswer::query()->create([
            'geo_task_id' => $task->id,
            'geo_task_question_id' => $question->id,
            'platform_code' => 'deepseek_mock',
            'prompt' => '请回答涪陵全屋定制哪家好',
            'raw_answer' => '重庆涪陵做全屋定制，可以优先了解恒森全屋定制。',
            'status' => 'succeeded',
            'answered_at' => now(),
        ]);
        GeoScore::query()->create([
            'geo_answer_id' => $answer->id,
            'brand_mentioned' => true,
            'is_recommended' => true,
            'rank_position' => 1,
            'competitors_mentioned' => [],
            'citations' => ['模拟来源：品牌知识库'],
            'score' => 85,
            'analysis_json' => ['has_citation' => true],
        ]);
        GeoReport::query()->create([
            'geo_task_id' => $task->id,
            'title' => '恒森全屋定制 GEO 诊断报告',
            'summary' => 'AI 可见度较好，品牌在模拟回答中稳定出现并被正向推荐。',
            'total_score' => 85,
            'markdown_report' => '# 恒森全屋定制 GEO 诊断报告',
            'html_report' => '<h1>恒森全屋定制 GEO 诊断报告</h1>',
            'status' => 'ready',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertOk()
            ->assertSee('恒森全屋定制 GEO 诊断报告')
            ->assertSee('综合得分')
            ->assertSee('85')
            ->assertSee('DeepSeek 模拟')
            ->assertSee('提及品牌')
            ->assertSee('正向推荐')
            ->assertSee('重庆涪陵做全屋定制，可以优先了解恒森全屋定制。')
            ->assertSee('优化建议')
            ->assertSee(route('admin.geo.workspace'), false);
    }

    public function test_admin_can_generate_article_draft_from_geo_report(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 97,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['涪陵恒森全屋定制工厂'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺和定制设计',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);
        $task = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => 'GEO 诊断 - 恒森全屋定制',
            'status' => 'completed',
            'total_score' => 85,
            'points_cost' => 2,
        ]);
        GeoTaskQuestion::query()->create([
            'geo_task_id' => $task->id,
            'geo_keyword_id' => $keyword->id,
            'question' => '涪陵全屋定制哪家好',
            'platform_codes' => ['deepseek_mock'],
            'status' => 'completed',
        ]);
        GeoReport::query()->create([
            'geo_task_id' => $task->id,
            'title' => '恒森全屋定制 GEO 诊断报告',
            'summary' => 'AI 可见度较好，品牌在模拟回答中稳定出现并被正向推荐。',
            'total_score' => 85,
            'markdown_report' => '# 恒森全屋定制 GEO 诊断报告',
            'html_report' => '<h1>恒森全屋定制 GEO 诊断报告</h1>',
            'status' => 'ready',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-draft.store', ['taskId' => $task->id]))
            ->assertRedirect(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertSessionHas('message');

        $this->assertSame(1, GeoWritingTask::query()->count());
        $this->assertSame(1, GeoArticleDraft::query()->count());

        $draft = GeoArticleDraft::query()->firstOrFail();
        $this->assertStringContainsString('恒森全屋定制', $draft->title);
        $this->assertStringContainsString('涪陵全屋定制哪家好', (string) $draft->content_markdown);
        $this->assertStringContainsString('FAQ', (string) $draft->content_markdown);
        $this->assertSame('draft', $draft->status);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertOk()
            ->assertSee('文章草稿')
            ->assertSee($draft->title)
            ->assertSee('草稿');
    }

    public function test_admin_can_edit_geo_article_draft(): void
    {
        [$admin, $task, $draft] = $this->createReportDraftFixture();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.reports.article-drafts.edit', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertOk()
            ->assertSee('编辑文章草稿')
            ->assertSee($draft->title)
            ->assertSee($draft->content_markdown);

        $this->actingAs($admin, 'admin')
            ->put(route('admin.geo.reports.article-drafts.update', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]), [
                'title' => '更新后的恒森全屋定制草稿',
                'summary' => '更新后的草稿摘要',
                'content_markdown' => "## 新内容\n\n涪陵全屋定制需要补充真实案例和报价说明。",
                'seo_title' => '更新后的 SEO 标题',
                'seo_description' => '更新后的 SEO 描述',
            ])
            ->assertRedirect(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertSessionHas('message');

        $draft->refresh();

        $this->assertSame('更新后的恒森全屋定制草稿', $draft->title);
        $this->assertSame('更新后的草稿摘要', $draft->summary);
        $this->assertSame('更新后的 SEO 标题', $draft->seo_title);
        $this->assertStringContainsString('涪陵全屋定制需要补充真实案例', (string) $draft->content_markdown);
        $this->assertStringContainsString('<h2>新内容</h2>', (string) $draft->content_html);
    }

    public function test_admin_can_convert_geo_article_draft_to_article(): void
    {
        [$admin, $task, $draft] = $this->createReportDraftFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.convert', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect();

        $article = Article::query()->firstOrFail();
        $draft->refresh();

        $this->assertSame($article->id, $draft->article_id);
        $this->assertSame('converted', $draft->status);
        $this->assertSame($draft->title, $article->title);
        $this->assertStringContainsString('涪陵全屋定制哪家好', (string) $article->content);
        $this->assertSame('draft', $article->status);
        $this->assertSame('pending', $article->review_status);
        $this->assertSame(1, (int) $article->is_ai_generated);
        $this->assertSame('涪陵全屋定制哪家好', $article->original_keyword);
        $this->assertDatabaseHas('categories', ['name' => 'GEO内容']);
        $this->assertDatabaseHas('authors', ['name' => 'GEOAmplify']);
        $this->assertTrue(Category::query()->whereKey($article->category_id)->exists());
        $this->assertTrue(Author::query()->whereKey($article->author_id)->exists());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertOk()
            ->assertSee('已转文章')
            ->assertSee('打开文章')
            ->assertSee(route('admin.articles.edit', ['articleId' => $article->id]), false);
    }

    public function test_reference_brief_draft_converts_to_article_with_source_metadata(): void
    {
        [$admin, $task, $draft] = $this->createReportDraftFixture();
        $draft->writingTask?->forceFill([
            'brief' => [
                'source' => 'reference_content',
                'question' => '涪陵全屋定制哪家好',
                'references' => [[
                    'title' => '重庆全屋定制恒森案例',
                    'url' => 'https://example.test/hengsen-guide',
                    'summary' => '包含报价、板材、安装流程和售后口碑。',
                    'score' => 82,
                ]],
            ],
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.reports.article-drafts.edit', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertOk()
            ->assertSee('发布准备')
            ->assertSee('参考内容简报')
            ->assertSee('参考来源 1 条')
            ->assertSee('需要补充');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.convert', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect();

        $article = Article::query()->firstOrFail();

        $this->assertSame('geo_reference_content', $article->metadata['source'] ?? null);
        $this->assertSame($draft->geo_writing_task_id, $article->metadata['geo_writing_task_id'] ?? null);
        $this->assertContains('https://example.test/hengsen-guide', $article->metadata['reference_urls'] ?? []);
        $this->assertContains('重庆全屋定制恒森案例', $article->metadata['reference_titles'] ?? []);
        $this->assertSame('涪陵全屋定制哪家好', $article->metadata['target_question'] ?? null);
        $this->assertNotNull($article->metadata['brand_profile_id'] ?? null);
    }

    public function test_geo_workspace_shows_trend_and_content_pipeline_metrics(): void
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 88,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['恒森定制'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'service_area' => '重庆涪陵',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);

        $oldTask = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => 'GEO 诊断 - 旧',
            'status' => 'completed',
            'total_score' => 62,
            'points_cost' => 2,
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        GeoReport::query()->create([
            'geo_task_id' => $oldTask->id,
            'title' => '旧报告',
            'summary' => '旧报告摘要',
            'total_score' => 62,
            'markdown_report' => '# 旧报告',
            'html_report' => '<h1>旧报告</h1>',
            'status' => 'ready',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $newTask = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => 'GEO 诊断 - 新',
            'status' => 'completed',
            'total_score' => 85,
            'points_cost' => 2,
        ]);
        $newReport = GeoReport::query()->create([
            'geo_task_id' => $newTask->id,
            'title' => '新报告',
            'summary' => '新报告摘要',
            'total_score' => 85,
            'markdown_report' => '# 新报告',
            'html_report' => '<h1>新报告</h1>',
            'status' => 'ready',
        ]);
        $writingTask = GeoWritingTask::query()->create([
            'organization_id' => $organization->id,
            'geo_report_id' => $newReport->id,
            'geo_keyword_id' => $keyword->id,
            'title' => '恒森全屋定制内容任务',
            'status' => 'completed',
            'brief' => ['question' => '涪陵全屋定制哪家好'],
        ]);
        GeoArticleDraft::query()->create([
            'organization_id' => $organization->id,
            'geo_writing_task_id' => $writingTask->id,
            'title' => '恒森全屋定制草稿',
            'summary' => '草稿摘要',
            'content_markdown' => '草稿正文',
            'content_html' => '<p>草稿正文</p>',
            'seo_title' => '恒森全屋定制草稿',
            'seo_description' => '草稿摘要',
            'status' => 'converted',
        ]);
        GeoArticleDraft::query()->create([
            'organization_id' => $organization->id,
            'geo_writing_task_id' => $writingTask->id,
            'title' => '待编辑草稿',
            'summary' => '待编辑摘要',
            'content_markdown' => '待编辑正文',
            'content_html' => '<p>待编辑正文</p>',
            'seo_title' => '待编辑草稿',
            'seo_description' => '待编辑摘要',
            'status' => 'draft',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('GEO 趋势')
            ->assertSee('最新得分')
            ->assertSee('85')
            ->assertSee('平均得分')
            ->assertSee('74')
            ->assertSee('趋势变化')
            ->assertSee('较上次')
            ->assertSee('内容闭环')
            ->assertSee('文章草稿')
            ->assertSee('已转文章')
            ->assertSee('1 / 2');
    }

    public function test_geo_workspace_shows_yixiaoer_published_content_overview(): void
    {
        $admin = $this->createAdmin();
        config(['services.yixiaoer.api_key' => 'test-yxe-key']);

        Http::fake([
            'https://www.yixiaoer.cn/api/contents/overviews*' => Http::response([
                'data' => [
                    [
                        'accountName' => '柜宝说',
                        'type' => 'video',
                        'updatedAt' => 1779827355351,
                        'contentData' => [
                            'id' => '7644090285475040562',
                            'desc' => '装修开心秘诀：别只靠记性',
                            'date' => '2026-05-26 14:54:24',
                            'play' => '1,735',
                            'great' => '223',
                            'comment' => '3',
                            'share' => '0',
                            'collect' => '6',
                            'pageUrl' => 'https://www.iesdouyin.com/share/video/7644090285475040562/',
                        ],
                    ],
                    [
                        'accountName' => '全屋定制工厂老任',
                        'type' => 'article',
                        'updatedAt' => 1779827354463,
                        'contentData' => [
                            'id' => '2042515932132761653',
                            'title' => '为什么很多装修公司天天发视频，还是没有客户',
                            'date' => '2026-05-26 08:02:27',
                            'read' => '1',
                            'great' => '1',
                            'comment' => '0',
                            'collect' => '0',
                            'pageUrl' => 'https://zhuanlan.zhihu.com/p/2042515932132761653',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace', ['published_content_overview' => 1]))
            ->assertOk()
            ->assertSee('发布作品数据')
            ->assertSee('柜宝说')
            ->assertSee('装修开心秘诀')
            ->assertSee('1,735')
            ->assertSee('223')
            ->assertSee('全屋定制工厂老任')
            ->assertSee('为什么很多装修公司天天发视频');

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://www.yixiaoer.cn/api/contents/overviews')
            && $request->hasHeader('Authorization', 'test-yxe-key'));
    }

    public function test_geo_workspace_groups_yixiaoer_content_by_platform_account_and_keyword(): void
    {
        $admin = $this->createAdmin();
        config(['services.yixiaoer.api_key' => 'test-yxe-key']);

        Http::fake([
            'https://www.yixiaoer.cn/api/contents/overviews*' => Http::response([
                'data' => [
                    [
                        'accountName' => '柜宝说',
                        'type' => 'video',
                        'updatedAt' => 1779827355351,
                        'contentData' => [
                            'id' => '7644090285475040562',
                            'desc' => '装修开心秘诀：别只靠记性',
                            'date' => '2026-05-26 14:54:24',
                            'play' => '1,735',
                            'great' => '223',
                            'comment' => '3',
                            'share' => '0',
                            'collect' => '6',
                            'pageUrl' => 'https://www.iesdouyin.com/share/video/7644090285475040562/',
                        ],
                    ],
                    [
                        'platformName' => '抖音',
                        'accountName' => '恒森全屋定制工厂',
                        'type' => 'video',
                        'updatedAt' => 1779827354463,
                        'contentData' => [
                            'id' => 'douyin-2',
                            'desc' => '工厂实拍：柜体安装细节',
                            'date' => '2026-05-26 08:02:27',
                            'play' => '91',
                            'great' => '8',
                        ],
                    ],
                    [
                        'platformName' => '知乎',
                        'accountName' => '全屋定制工厂老任',
                        'type' => 'article',
                        'updatedAt' => 1779827354463,
                        'contentData' => [
                            'id' => 'zhihu-1',
                            'title' => '装修公司获客为什么越来越难',
                            'date' => '2026-05-26 08:02:27',
                            'read' => '25',
                            'great' => '1',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace', ['published_content_keyword' => '开心']))
            ->assertOk()
            ->assertSee('关键词筛选')
            ->assertSee('value="开心"', false)
            ->assertSee('按平台与账号查看')
            ->assertSee('抖音')
            ->assertSee('柜宝说')
            ->assertSee('装修开心秘诀')
            ->assertSee('1 条作品')
            ->assertDontSee('工厂实拍')
            ->assertDontSee('装修公司获客');

        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://www.yixiaoer.cn/api/contents/overviews')
            && str_contains($request->url(), 'size=50'));
    }

    public function test_geo_workspace_filters_yixiaoer_content_with_platform_buttons(): void
    {
        $admin = $this->createAdmin();
        config(['services.yixiaoer.api_key' => 'test-yxe-key']);

        Http::fake([
            'https://www.yixiaoer.cn/api/contents/overviews*' => Http::response([
                'data' => [
                    [
                        'accountName' => '柜宝说',
                        'type' => 'video',
                        'updatedAt' => 1779827355351,
                        'contentData' => [
                            'id' => 'douyin-1',
                            'desc' => '装修开心秘诀：别只靠记性',
                            'date' => '2026-05-26 14:54:24',
                            'play' => '1,735',
                            'great' => '223',
                            'pageUrl' => 'https://www.iesdouyin.com/share/video/douyin-1/',
                        ],
                    ],
                    [
                        'platformName' => '知乎',
                        'accountName' => '全屋定制工厂老任',
                        'type' => 'article',
                        'updatedAt' => 1779827354463,
                        'contentData' => [
                            'id' => 'zhihu-1',
                            'title' => '装修公司获客为什么越来越难',
                            'date' => '2026-05-26 08:02:27',
                            'read' => '25',
                            'great' => '1',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace', ['published_content_platform' => '知乎']))
            ->assertOk()
            ->assertSee('平台筛选')
            ->assertSee('全部平台')
            ->assertSee('抖音')
            ->assertSee('知乎')
            ->assertSee('全屋定制工厂老任')
            ->assertSee('装修公司获客为什么越来越难')
            ->assertDontSee('装修开心秘诀');
    }

    public function test_admin_can_run_geo_article_audit_for_converted_article(): void
    {
        [$admin, $task, $draft] = $this->createReportDraftFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.convert', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect();

        $draft->refresh();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.audit', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertSessionHas('message');

        $audit = GeoArticleAudit::query()->firstOrFail();

        $this->assertSame($draft->id, $audit->geo_article_draft_id);
        $this->assertSame($draft->article_id, $audit->article_id);
        $this->assertGreaterThanOrEqual(80, $audit->score);
        $this->assertSame('ready', $audit->status);
        $this->assertContains('brand_mentioned', $audit->passed_checks);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertOk()
            ->assertSee('发布前 GEO 检查')
            ->assertSee((string) $audit->score)
            ->assertSee('品牌已出现')
            ->assertSee('重新检查');
    }

    public function test_geo_audit_flags_forbidden_terms_missing_reference_and_missing_local_intent(): void
    {
        [$admin, $task, $draft] = $this->createReportDraftFixture();
        $draft->writingTask?->forceFill([
            'brief' => [
                'source' => 'reference_content',
                'question' => '涪陵全屋定制哪家好',
                'references' => [[
                    'title' => '重庆全屋定制恒森案例',
                    'url' => 'https://example.test/hengsen-guide',
                ]],
            ],
        ])->save();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.convert', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect();

        $draft->refresh();
        $draft->article?->update([
            'title' => '恒森全屋定制低价承诺',
            'excerpt' => '我们保证全屋定制全网最低价。',
            'content' => '恒森全屋定制保证全屋定制全网最低价，没有引用来源，也没有本地交付范围说明。',
            'original_keyword' => '涪陵全屋定制哪家好',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.audit', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertSessionHas('message');

        $audit = GeoArticleAudit::query()->firstOrFail();

        $this->assertContains('forbidden_terms', $audit->failed_checks);
        $this->assertContains('reference_coverage', $audit->failed_checks);
        $this->assertContains('local_intent', $audit->failed_checks);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertOk()
            ->assertSee('禁用词检查')
            ->assertSee('参考来源覆盖')
            ->assertSee('本地意图覆盖');
    }

    public function test_admin_can_run_post_publish_retest_for_converted_article(): void
    {
        [$admin, $task, $draft] = $this->createReportDraftFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.convert', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect();

        $draft->refresh();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.audit', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect(route('admin.geo.reports.show', ['taskId' => $task->id]));

        $article = Article::query()->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.retest', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertSessionHas('message');

        $this->assertDatabaseHas('geo_publish_retests', [
            'organization_id' => $task->organization_id,
            'article_id' => $article->id,
            'geo_article_draft_id' => $draft->id,
            'before_score' => 85,
            'status' => 'completed',
        ]);

        $retest = DB::table('geo_publish_retests')->first();
        $this->assertGreaterThanOrEqual(80, (int) $retest->after_score);
        $this->assertStringContainsString(route('site.article', ['slug' => $article->slug]), (string) $retest->article_url);
        $this->assertStringContainsString('涪陵全屋定制哪家好', (string) $retest->summary);
        $this->assertSame($article->id, (int) (json_decode((string) $retest->metadata, true)['article_id'] ?? 0));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertOk()
            ->assertSee('发布后复测')
            ->assertSee('复测得分');
    }

    public function test_admin_can_create_yixiaoer_publish_handoff_after_geo_audit(): void
    {
        [$admin, $task, $draft] = $this->createReportDraftFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.convert', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.yixiaoer-handoff', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]), [
                'platform_codes' => ['xiaohongshu', 'douyin', 'shipinhao'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.audit', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect(route('admin.geo.reports.show', ['taskId' => $task->id]));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.yixiaoer-handoff', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]), [
                'platform_codes' => ['xiaohongshu', 'douyin', 'shipinhao'],
            ])
            ->assertRedirect(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertSessionHas('message');

        $target = GeoPublishTarget::query()->firstOrFail();
        $this->assertSame('yixiaoer', $target->type);
        $this->assertSame('蚁小二发布交接', $target->name);

        $record = GeoPublishRecord::query()->firstOrFail();
        $this->assertSame($draft->id, $record->geo_article_draft_id);
        $this->assertSame($target->id, $record->geo_publish_target_id);
        $this->assertSame('ready_handoff', $record->status);
        $this->assertSame(['xiaohongshu', 'douyin', 'shipinhao'], $record->platform_codes);
        $this->assertSame('yixiaoer', $record->handoff_payload['channel']);
        $this->assertSame('draft_publish_handoff', $record->handoff_payload['action']);
        $this->assertSame($draft->title, $record->handoff_payload['article']['title']);
        $this->assertStringContainsString('恒森全屋定制服务重庆涪陵', $record->handoff_payload['article']['content_markdown']);
        $this->assertSame(100, $record->handoff_payload['geo_audit']['score']);
        $this->assertSame('geo_report', $record->handoff_payload['provenance']['source']);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertOk()
            ->assertSee('蚁小二交接')
            ->assertSee('待蚁小二接管');
    }

    private function createAdmin(): Admin
    {
        return Admin::query()->create([
            'username' => 'geo_workspace_admin',
            'password' => 'secret-123',
            'email' => 'geo-workspace-admin@example.com',
            'display_name' => 'GEO Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    /**
     * @return array{0: Admin, 1: GeoTask, 2: GeoArticleDraft}
     */
    private function createReportDraftFixture(): array
    {
        $admin = $this->createAdmin();
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 97,
            'status' => 'active',
        ]);
        $brandProfile = BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['涪陵恒森全屋定制工厂'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺和定制设计',
        ]);
        $keyword = GeoKeyword::query()->create([
            'organization_id' => $organization->id,
            'type' => 'question',
            'keyword' => '涪陵全屋定制哪家好',
            'intent' => 'commercial',
        ]);
        $task = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => 'GEO 诊断 - 恒森全屋定制',
            'status' => 'completed',
            'total_score' => 85,
            'points_cost' => 2,
        ]);
        GeoTaskQuestion::query()->create([
            'geo_task_id' => $task->id,
            'geo_keyword_id' => $keyword->id,
            'question' => '涪陵全屋定制哪家好',
            'platform_codes' => ['deepseek_mock'],
            'status' => 'completed',
        ]);
        $report = GeoReport::query()->create([
            'geo_task_id' => $task->id,
            'title' => '恒森全屋定制 GEO 诊断报告',
            'summary' => 'AI 可见度较好，品牌在模拟回答中稳定出现并被正向推荐。',
            'total_score' => 85,
            'markdown_report' => '# 恒森全屋定制 GEO 诊断报告',
            'html_report' => '<h1>恒森全屋定制 GEO 诊断报告</h1>',
            'status' => 'ready',
        ]);
        $writingTask = GeoWritingTask::query()->create([
            'organization_id' => $organization->id,
            'geo_report_id' => $report->id,
            'geo_keyword_id' => $keyword->id,
            'title' => '恒森全屋定制：涪陵全屋定制哪家好的选择指南',
            'status' => 'completed',
            'brief' => [
                'source' => 'geo_report',
                'question' => '涪陵全屋定制哪家好',
            ],
        ]);
        $draft = GeoArticleDraft::query()->create([
            'organization_id' => $organization->id,
            'geo_writing_task_id' => $writingTask->id,
            'title' => '恒森全屋定制：涪陵全屋定制哪家好的选择指南',
            'summary' => '基于 GEO 诊断报告生成的草稿摘要',
            'content_markdown' => "# 恒森全屋定制：涪陵全屋定制哪家好的选择指南\n\n## 服务区域\n\n恒森全屋定制服务重庆涪陵，支持上门量尺、报价说明和板材信息确认。\n\n## FAQ\n\n### 涪陵全屋定制哪家好\n\n可以优先了解恒森全屋定制，重点看本地案例、报价透明度和售后服务。",
            'content_html' => '<h1>恒森全屋定制：涪陵全屋定制哪家好的选择指南</h1>',
            'seo_title' => '恒森全屋定制 SEO 标题',
            'seo_description' => '恒森全屋定制 SEO 描述',
            'status' => 'draft',
        ]);

        return [$admin, $task, $draft];
    }
}
