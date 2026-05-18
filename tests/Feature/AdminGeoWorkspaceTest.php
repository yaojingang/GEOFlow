<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\BrandProfile;
use App\Models\Category;
use App\Models\GeoAnswer;
use App\Models\GeoArticleAudit;
use App\Models\GeoArticleDraft;
use App\Models\GeoKeyword;
use App\Models\GeoReport;
use App\Models\GeoScore;
use App\Models\GeoTask;
use App\Models\GeoTaskQuestion;
use App\Models\GeoWritingTask;
use App\Models\Organization;
use App\Models\PointLog;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
            ->assertSee('GEO 工作台')
            ->assertSee('品牌知识库')
            ->assertSee('关键词库')
            ->assertSee('创建诊断任务');
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
        $this->assertDatabaseHas('authors', ['name' => 'GEOFlow']);
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
