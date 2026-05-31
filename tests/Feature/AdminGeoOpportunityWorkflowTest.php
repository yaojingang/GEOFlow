<?php

namespace Tests\Feature;

use App\Jobs\GeoTopicPipelineJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\BrandProfile;
use App\Models\GeoAiSearchRun;
use App\Models\GeoArticleDraft;
use App\Models\GeoKeywordOpportunity;
use App\Models\GeoPublishRecord;
use App\Models\GeoWritingTask;
use App\Models\Organization;
use App\Services\Geo\GeoSearchBatchRunner;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminGeoOpportunityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_admin_can_generate_keyword_opportunities_from_brand_profile(): void
    {
        [$admin, $organization] = $this->createBrandFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.opportunities.generate'), [
                'limit' => 8,
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $this->assertGreaterThanOrEqual(
            8,
            DB::table('geo_keyword_opportunities')
                ->where('organization_id', $organization->id)
                ->count()
        );
        $this->assertDatabaseHas('geo_keyword_opportunities', [
            'organization_id' => $organization->id,
            'keyword' => '重庆涪陵全屋定制哪家靠谱',
            'intent' => 'decision',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('geo_keyword_opportunities', [
            'organization_id' => $organization->id,
            'keyword' => '重庆涪陵衣柜定制避坑',
            'intent' => 'pain_point',
            'status' => 'active',
        ]);

        $topOpportunity = DB::table('geo_keyword_opportunities')
            ->where('organization_id', $organization->id)
            ->orderByDesc('opportunity_score')
            ->first();

        $this->assertNotNull($topOpportunity);
        $this->assertGreaterThanOrEqual(70, (int) $topOpportunity->opportunity_score);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('关键词机会库')
            ->assertSee('机会分')
            ->assertSee('重庆涪陵全屋定制哪家靠谱');
    }

    public function test_admin_can_expand_keyword_opportunities_with_abcdef_combinations(): void
    {
        [$admin, $organization] = $this->createBrandFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.opportunities.expand'), [
                'area_prefixes' => '重庆涪陵',
                'modifiers' => "靠谱的\n口碑好的",
                'core_terms' => '全屋定制',
                'entity_terms' => "品牌\n厂家",
                'recommend_terms' => '推荐',
                'question_terms' => '哪家好',
                'combination_patterns' => [
                    'C+D',
                    'A+C+D',
                    'B+C+D',
                    'A+B+C+D',
                    'C+D+E',
                    'C+D+F',
                ],
                'limit' => 50,
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        foreach ([
            '全屋定制品牌',
            '重庆涪陵全屋定制品牌',
            '靠谱的全屋定制品牌',
            '重庆涪陵靠谱的全屋定制品牌',
            '全屋定制品牌推荐',
            '全屋定制品牌哪家好',
        ] as $keyword) {
            $this->assertDatabaseHas('geo_keyword_opportunities', [
                'organization_id' => $organization->id,
                'keyword' => $keyword,
                'intent' => 'manual_expansion',
                'status' => 'active',
                'generation_source' => 'manual_abcdef',
            ]);
        }

        $opportunity = GeoKeywordOpportunity::query()
            ->where('organization_id', $organization->id)
            ->where('keyword', '重庆涪陵靠谱的全屋定制品牌')
            ->firstOrFail();

        $this->assertSame('A+B+C+D', $opportunity->metadata['pattern']);
        $this->assertGreaterThanOrEqual(70, $opportunity->opportunity_score);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('手工拓词')
            ->assertSee('重庆涪陵靠谱的全屋定制品牌');
    }

    public function test_generated_opportunities_are_synced_to_keyword_and_title_libraries(): void
    {
        [$admin, $organization] = $this->createBrandFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.opportunities.generate'), [
                'limit' => 4,
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $keywordLibrary = DB::table('keyword_libraries')
            ->where('name', '恒森全屋定制 GEO机会词库')
            ->first();
        $this->assertNotNull($keywordLibrary);
        $this->assertGreaterThanOrEqual(4, (int) $keywordLibrary->keyword_count);
        $this->assertDatabaseHas('keywords', [
            'library_id' => (int) $keywordLibrary->id,
            'keyword' => '重庆涪陵全屋定制哪家靠谱',
        ]);

        $titleLibrary = DB::table('title_libraries')
            ->where('name', '恒森全屋定制 GEO机会标题库')
            ->first();
        $this->assertNotNull($titleLibrary);
        $this->assertSame((int) $keywordLibrary->id, (int) $titleLibrary->keyword_library_id);
        $this->assertGreaterThanOrEqual(4, (int) $titleLibrary->title_count);
        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $titleLibrary->id,
            'keyword' => '重庆涪陵全屋定制哪家靠谱',
            'is_ai_generated' => true,
        ]);

        $opportunity = GeoKeywordOpportunity::query()
            ->where('organization_id', $organization->id)
            ->where('keyword', '重庆涪陵全屋定制哪家靠谱')
            ->firstOrFail();
        $this->assertSame((int) $keywordLibrary->id, (int) ($opportunity->metadata['material_sync']['keyword_library_id'] ?? 0));
        $this->assertSame((int) $titleLibrary->id, (int) ($opportunity->metadata['material_sync']['title_library_id'] ?? 0));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.opportunities.expand'), [
                'area_prefixes' => '重庆涪陵',
                'modifiers' => '靠谱的',
                'core_terms' => '全屋定制',
                'entity_terms' => '品牌',
                'recommend_terms' => '推荐',
                'question_terms' => '哪家好',
                'combination_patterns' => ['A+B+C+D'],
                'limit' => 10,
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $this->assertDatabaseHas('keywords', [
            'library_id' => (int) $keywordLibrary->id,
            'keyword' => '重庆涪陵靠谱的全屋定制品牌',
        ]);
        $this->assertDatabaseHas('titles', [
            'library_id' => (int) $titleLibrary->id,
            'keyword' => '重庆涪陵靠谱的全屋定制品牌',
            'is_ai_generated' => true,
        ]);
    }

    public function test_admin_can_run_ai_search_batch_and_extract_citation_sources(): void
    {
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '重庆涪陵全屋定制可以优先了解恒森全屋定制，也可以对比佳诚定制。参考来源：https://example.test/hengsen-guide 资料显示恒森支持上门量尺、透明报价。',
                    ],
                ]],
            ]),
        ]);

        [$admin, $organization] = $this->createBrandFixture();
        $opportunityId = (int) DB::table('geo_keyword_opportunities')->insertGetId([
            'organization_id' => $organization->id,
            'brand_profile_id' => BrandProfile::query()->where('organization_id', $organization->id)->value('id'),
            'keyword' => '重庆涪陵全屋定制哪家靠谱',
            'intent' => 'decision',
            'cluster_name' => '本地决策词',
            'status' => 'active',
            'business_value' => 90,
            'visibility_gap' => 80,
            'source_availability' => 70,
            'local_relevance' => 95,
            'opportunity_score' => 86,
            'generation_source' => 'test',
            'rationale' => '本地成交意图明确',
            'created_by_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $aiModel = AiModel::query()->create([
            'name' => '测试搜索模型',
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
            ->post(route('admin.geo.search-runs.store'), [
                'name' => '第一批 GEO 机会搜索',
                'opportunity_ids' => [$opportunityId],
                'platform_codes' => ['ai_model:'.$aiModel->id],
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $runId = (int) DB::table('geo_ai_search_runs')->value('id');
        $this->assertDatabaseHas('geo_ai_search_runs', [
            'id' => $runId,
            'organization_id' => $organization->id,
            'name' => '第一批 GEO 机会搜索',
            'status' => 'pending',
            'total_questions' => 1,
        ]);
        $this->assertDatabaseHas('geo_ai_search_questions', [
            'geo_ai_search_run_id' => $runId,
            'geo_keyword_opportunity_id' => $opportunityId,
            'question' => '重庆涪陵全屋定制哪家靠谱',
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.run', ['runId' => $runId]))
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $aiModel->refresh();

        $this->assertDatabaseHas('geo_ai_search_runs', [
            'id' => $runId,
            'status' => 'completed',
            'completed_questions' => 1,
            'failed_questions' => 0,
        ]);
        $this->assertDatabaseHas('geo_ai_search_answers', [
            'geo_ai_search_run_id' => $runId,
            'geo_keyword_opportunity_id' => $opportunityId,
            'platform_code' => 'ai_model:'.$aiModel->id,
            'status' => 'succeeded',
            'brand_mentioned' => true,
        ]);
        $answer = DB::table('geo_ai_search_answers')->first();
        $this->assertNotNull($answer);
        $this->assertStringContainsString('example.test/hengsen-guide', (string) $answer->raw_answer);
        $this->assertContains('佳诚定制', json_decode((string) $answer->competitors_mentioned, true));
        $this->assertContains('https://example.test/hengsen-guide', json_decode((string) $answer->source_urls, true));

        $this->assertDatabaseHas('geo_citation_sources', [
            'organization_id' => $organization->id,
            'url' => 'https://example.test/hengsen-guide',
            'domain' => 'example.test',
            'status' => 'pending_crawl',
        ]);
        $this->assertSame(1, (int) $aiModel->used_today);
        $this->assertSame(1, (int) $aiModel->total_used);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://ai.test/v1/chat/completions'
            && $request['model'] === 'test-chat-model'
            && str_contains((string) $request['messages'][1]['content'], '重庆涪陵全屋定制哪家靠谱')
            && str_contains((string) $request['messages'][1]['content'], '请像真实用户在 AI 搜索里提问一样回答'));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('AI 搜索批次')
            ->assertSee('引用来源库')
            ->assertSee('第一批 GEO 机会搜索')
            ->assertSee('example.test');
    }

    public function test_admin_can_run_ai_search_batch_with_local_ai_web_workbench(): void
    {
        Process::fake([
            '*' => Process::result(json_encode([
                'ok' => true,
                'taskId' => 'task-geo-search-workbench',
                'markdownPath' => '/tmp/task-geo-search-workbench.md',
                'sentCount' => 2,
                'completedCount' => 2,
                'manualCount' => 0,
                'runs' => [
                    [
                        'platformId' => 'chatgpt',
                        'platformName' => 'ChatGPT',
                        'status' => '完成',
                        'answerSource' => 'auto',
                        'answerText' => '重庆涪陵全屋定制可以优先了解恒森全屋定制，也可参考 https://example.test/hengsen-guide 。',
                        'citations' => [[
                            'url' => 'https://example.test/hengsen-guide',
                            'title' => '恒森全屋定制参考',
                        ]],
                    ],
                    [
                        'platformId' => 'yuanbao',
                        'platformName' => '腾讯元宝',
                        'status' => '完成',
                        'answerSource' => 'auto',
                        'answerText' => '恒森定制、本地工厂和佳诚定制都可以放在同一批对比。',
                        'citations' => [],
                    ],
                ],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '', 0),
        ]);

        [$admin, $organization] = $this->createBrandFixture();
        $brandProfileId = (int) BrandProfile::query()->where('organization_id', $organization->id)->value('id');
        $opportunityId = (int) DB::table('geo_keyword_opportunities')->insertGetId([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfileId,
            'keyword' => '重庆涪陵全屋定制哪家靠谱',
            'intent' => 'decision',
            'cluster_name' => '本地决策词',
            'status' => 'active',
            'business_value' => 90,
            'visibility_gap' => 80,
            'source_availability' => 70,
            'local_relevance' => 95,
            'opportunity_score' => 86,
            'generation_source' => 'test',
            'rationale' => '本地成交意图明确',
            'created_by_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.store'), [
                'name' => '本机搜索工作台批次',
                'opportunity_ids' => [$opportunityId],
                'platform_codes' => ['ai_web_workbench'],
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $runId = (int) DB::table('geo_ai_search_runs')->value('id');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.run', ['runId' => $runId]))
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message');

        $this->assertDatabaseHas('geo_ai_search_runs', [
            'id' => $runId,
            'status' => 'completed',
            'completed_questions' => 1,
            'failed_questions' => 0,
        ]);
        $this->assertDatabaseHas('geo_ai_search_answers', [
            'geo_ai_search_run_id' => $runId,
            'platform_code' => 'ai_web_workbench',
            'status' => 'succeeded',
            'brand_mentioned' => true,
        ]);

        $answer = DB::table('geo_ai_search_answers')->first();
        $this->assertStringContainsString('ChatGPT', (string) $answer->raw_answer);
        $this->assertContains('https://example.test/hengsen-guide', json_decode((string) $answer->source_urls, true));
        $this->assertDatabaseHas('geo_citation_sources', [
            'organization_id' => $organization->id,
            'url' => 'https://example.test/hengsen-guide',
            'domain' => 'example.test',
            'status' => 'pending_crawl',
        ]);

        Process::assertRan(function ($process): bool {
            $command = is_array($process->command) ? $process->command : [$process->command];

            return in_array('run', $command, true)
            && in_array('--json', $command, true)
            && in_array('重庆涪陵全屋定制哪家靠谱', $command, true);
        });
    }

    public function test_ai_search_batch_marks_failed_when_all_platform_answers_fail(): void
    {
        Process::fake([
            '*' => Process::result('', 'sh: line 0: exec: ai-web-workbench: not found', 127),
        ]);

        [$admin, $organization] = $this->createBrandFixture();
        $brandProfileId = (int) BrandProfile::query()->where('organization_id', $organization->id)->value('id');
        $opportunityId = (int) DB::table('geo_keyword_opportunities')->insertGetId([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfileId,
            'keyword' => '重庆涪陵全屋定制哪家靠谱',
            'intent' => 'decision',
            'cluster_name' => '本地决策词',
            'status' => 'active',
            'business_value' => 90,
            'visibility_gap' => 80,
            'source_availability' => 70,
            'local_relevance' => 95,
            'opportunity_score' => 86,
            'generation_source' => 'test',
            'rationale' => '本地成交意图明确',
            'created_by_admin_id' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.store'), [
                'name' => '全部失败搜索批次',
                'opportunity_ids' => [$opportunityId],
                'platform_codes' => ['ai_web_workbench'],
            ])
            ->assertRedirect(route('admin.geo.workspace'));

        $runId = (int) DB::table('geo_ai_search_runs')->where('name', '全部失败搜索批次')->value('id');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.run', ['runId' => $runId]))
            ->assertRedirect(route('admin.geo.workspace'));

        $this->assertDatabaseHas('geo_ai_search_runs', [
            'id' => $runId,
            'status' => 'failed',
            'completed_questions' => 0,
            'failed_questions' => 1,
        ]);
    }

    public function test_admin_can_create_external_qa_inspection_and_review_answer_evidence(): void
    {
        [$admin, $organization] = $this->createBrandFixture();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace'))
            ->assertOk()
            ->assertSee('外部问答检视')
            ->assertSee('预设检视')
            ->assertSee('品牌可见度检视')
            ->assertSee('本地获客检视')
            ->assertSee('自定义检视')
            ->assertSee('问题矩阵')
            ->assertSee('目标预期值')
            ->assertSee('预期关键词命中率')
            ->assertSee('创作文章优化方向')
            ->assertSee('多轮优化波动图')
            ->assertSee('data-geo-inspection-preset', false)
            ->assertSee('data-geo-custom-inspection', false)
            ->assertSee('data-geo-tab-panel="external-qa"', false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.external-inspections.store'), [
                'name' => '首轮外部问答检视',
                'questions_text' => "涪陵全屋定制哪家靠谱\n重庆涪陵衣柜橱柜推荐谁",
                'target_keyword_hit_rate' => 72,
                'platform_codes' => ['deepseek_mock', 'kimi_mock'],
            ])
            ->assertRedirect(route('admin.geo.workspace').'#external-qa')
            ->assertSessionHas('message');

        $run = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('name', '首轮外部问答检视')
            ->firstOrFail();

        $this->assertSame('pending', $run->status);
        $this->assertSame(2, $run->total_questions);
        $this->assertSame(4, $run->points_cost);
        $this->assertSame(['deepseek_mock', 'kimi_mock'], $run->platform_codes);
        $this->assertSame(72, $run->target_keyword_hit_rate);
        $this->assertNull($run->previous_keyword_hit_rate);
        $this->assertNull($run->baseline_keyword_hit_rate);
        $this->assertIsArray($run->optimization_directions);
        $this->assertStringContainsString('首轮外部问答检视', $run->optimization_directions[0]['body'] ?? '');

        $this->assertDatabaseHas('geo_keyword_opportunities', [
            'organization_id' => $organization->id,
            'keyword' => '涪陵全屋定制哪家靠谱',
            'generation_source' => 'external_qa_inspection',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('geo_ai_search_questions', [
            'geo_ai_search_run_id' => $run->id,
            'question' => '重庆涪陵衣柜橱柜推荐谁',
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.run', ['runId' => $run->id]))
            ->assertRedirect(route('admin.geo.search-runs.show', ['runId' => $run->id]))
            ->assertSessionHas('message');

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->completed_questions);
        $this->assertSame(100, $run->keyword_hit_rate);
        $this->assertSame(4, $run->keyword_hit_count);
        $this->assertSame(4, $run->keyword_check_count);
        $this->assertNull($run->keyword_hit_rate_delta);
        $this->assertSame(4, DB::table('geo_ai_search_answers')->where('geo_ai_search_run_id', $run->id)->count());

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.search-runs.show', ['runId' => $run->id]))
            ->assertOk()
            ->assertSee('外部问答检视证据')
            ->assertSee('首轮外部问答检视')
            ->assertSee('目标命中率')
            ->assertSee('关键词命中率')
            ->assertSee('创作文章优化方向')
            ->assertSee('原始回答')
            ->assertSee('品牌命中')
            ->assertSee('可见度分')
            ->assertSee('DeepSeek 模拟')
            ->assertSee('恒森全屋定制');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace').'#external-qa')
            ->assertOk()
            ->assertSee('最新检视')
            ->assertSee('首轮外部问答检视')
            ->assertSee('进度 2 / 2 · 100%')
            ->assertSee('关键词命中 100% / 目标 72%')
            ->assertSee('结果：品牌命中 100% · 引用率 100% · 平均 70')
            ->assertSee(route('admin.geo.search-runs.show', ['runId' => $run->id]), false);
    }

    public function test_external_qa_tracks_before_after_keyword_hit_rate_and_trend(): void
    {
        [$admin, $organization] = $this->createBrandFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.external-inspections.store'), [
                'name' => '第一轮检视',
                'questions_text' => '涪陵全屋定制哪家靠谱',
                'target_keyword_hit_rate' => 70,
                'platform_codes' => ['deepseek_mock'],
            ])
            ->assertRedirect(route('admin.geo.workspace').'#external-qa');

        $firstRun = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('name', '第一轮检视')
            ->firstOrFail();
        $firstRun->forceFill([
            'status' => 'completed',
            'completed_questions' => 1,
            'keyword_hit_rate' => 55,
            'keyword_hit_count' => 1,
            'keyword_check_count' => 2,
            'finished_at' => now()->subDay(),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.external-inspections.store'), [
                'name' => '第二轮优化复测',
                'questions_text' => "涪陵全屋定制哪家靠谱\n涪陵全屋定制报价怎么算",
                'target_keyword_hit_rate' => 85,
                'platform_codes' => ['deepseek_mock'],
            ])
            ->assertRedirect(route('admin.geo.workspace').'#external-qa');

        $secondRun = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('name', '第二轮优化复测')
            ->firstOrFail();
        $this->assertSame(55, $secondRun->previous_keyword_hit_rate);
        $this->assertSame(55, $secondRun->baseline_keyword_hit_rate);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.run', ['runId' => $secondRun->id]))
            ->assertRedirect(route('admin.geo.search-runs.show', ['runId' => $secondRun->id]));

        $secondRun->refresh();
        $this->assertSame(100, $secondRun->keyword_hit_rate);
        $this->assertSame(45, $secondRun->keyword_hit_rate_delta);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace').'#external-qa')
            ->assertOk()
            ->assertSee('第二轮优化复测')
            ->assertSee('上轮 55%')
            ->assertSee('变化 +45%')
            ->assertSee('多轮优化波动图')
            ->assertSee('data-geo-keyword-hit-trend', false)
            ->assertSee('55%')
            ->assertSee('100%');
    }

    public function test_duplicate_pending_external_qa_submission_reuses_existing_run(): void
    {
        [$admin, $organization] = $this->createBrandFixture();

        $payload = [
            'name' => '重复点击外部问答检视',
            'questions_text' => "涪陵全屋定制哪家靠谱\n重庆涪陵衣柜橱柜推荐谁",
            'platform_codes' => ['deepseek_mock'],
        ];

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.external-inspections.store'), $payload)
            ->assertRedirect(route('admin.geo.workspace').'#external-qa');

        $firstRun = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('name', '重复点击外部问答检视')
            ->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.external-inspections.store'), $payload)
            ->assertRedirect(route('admin.geo.workspace').'#external-qa');

        $this->assertSame(
            1,
            GeoAiSearchRun::query()
                ->where('organization_id', $organization->id)
                ->where('name', '重复点击外部问答检视')
                ->count()
        );
        $this->assertSame(2, $firstRun->questions()->count());
    }

    public function test_external_qa_dashboard_orders_same_second_runs_by_newest_id(): void
    {
        [$admin, $organization] = $this->createBrandFixture();
        $createdAt = now()->subMinute();

        foreach (range(1, 9) as $index) {
            $name = sprintf('同秒第%02d检视', $index);

            $this->actingAs($admin, 'admin')
                ->post(route('admin.geo.external-inspections.store'), [
                    'name' => $name,
                    'questions_text' => '涪陵全屋定制哪家靠谱',
                    'platform_codes' => ['deepseek_mock'],
                ])
                ->assertRedirect(route('admin.geo.workspace').'#external-qa');

            GeoAiSearchRun::query()
                ->where('organization_id', $organization->id)
                ->where('name', $name)
                ->update([
                    'created_at' => $createdAt,
                    'updated_at' => $createdAt,
                ]);
        }

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace').'#external-qa')
            ->assertOk()
            ->assertSee('同秒第09检视')
            ->assertSeeInOrder(['同秒第09检视', '同秒第08检视', '同秒第07检视']);
    }

    public function test_stale_external_qa_running_batch_is_unlocked_for_retry(): void
    {
        [$admin, $organization] = $this->createBrandFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.external-inspections.store'), [
                'name' => '卡住的外部问答检视',
                'questions_text' => "涪陵全屋定制哪家靠谱\n重庆涪陵衣柜橱柜推荐谁",
                'platform_codes' => ['deepseek_mock'],
            ])
            ->assertRedirect(route('admin.geo.workspace').'#external-qa');

        $run = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('name', '卡住的外部问答检视')
            ->firstOrFail();
        $run->questions()->update(['status' => 'running']);
        $run->forceFill([
            'status' => 'running',
            'started_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(20),
        ])->save();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.workspace').'#external-qa')
            ->assertOk()
            ->assertSee('运行超时，可重试')
            ->assertSee('重新运行检视');

        $run->refresh();
        $this->assertSame('failed', $run->status);
        $this->assertSame(0, $run->completed_questions);
        $this->assertSame(2, $run->failed_questions);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.run', ['runId' => $run->id]))
            ->assertRedirect(route('admin.geo.search-runs.show', ['runId' => $run->id]))
            ->assertSessionHas('message');

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->completed_questions);
        $this->assertSame(2, DB::table('geo_ai_search_answers')->where('geo_ai_search_run_id', $run->id)->count());
    }

    public function test_external_qa_web_workbench_run_starts_in_background(): void
    {
        config(['geoflow.ai_web_workbench.command' => '/usr/local/bin/ai-web-workbench']);
        Process::fake([
            '*' => Process::result('', '', 0),
        ]);
        [$admin, $organization] = $this->createBrandFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.external-inspections.store'), [
                'name' => '真实平台后台检视',
                'questions_text' => "涪陵全屋定制哪家靠谱\n重庆涪陵衣柜橱柜推荐谁",
                'platform_codes' => ['ai_web_workbench'],
            ])
            ->assertRedirect(route('admin.geo.workspace').'#external-qa');

        $run = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('name', '真实平台后台检视')
            ->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.run', ['runId' => $run->id]))
            ->assertRedirect(route('admin.geo.search-runs.show', ['runId' => $run->id]))
            ->assertSessionHas('message');

        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertNotNull($run->started_at);

        Process::assertRan(function ($process) use ($run): bool {
            $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;
            $env = (array) ($process->environment ?? []);

            return str_contains($command, 'geo:search-run')
                && str_contains($command, (string) $run->id)
                && str_starts_with((string) ($env['GEOAMPLIFY_AI_WEB_WORKBENCH_COMMAND'] ?? ''), '/')
                && ($env['APP_ENV'] ?? null) === 'testing'
                && ($env['DB_CONNECTION'] ?? null) === 'sqlite'
                && ($env['DB_DATABASE'] ?? null) === ':memory:'
                && array_key_exists('DB_URL', $env);
        });
    }

    public function test_external_qa_web_workbench_single_platform_runs_cli_with_platform_filter(): void
    {
        config(['geoflow.ai_web_workbench.command' => '/usr/local/bin/ai-web-workbench']);
        Process::fake([
            '*' => Process::result(json_encode([
                'ok' => true,
                'taskId' => 'task-single-chatgpt',
                'markdownPath' => '/tmp/task-single-chatgpt.md',
                'sentCount' => 1,
                'completedCount' => 1,
                'manualCount' => 0,
                'runs' => [[
                    'platformId' => 'chatgpt',
                    'platformName' => 'ChatGPT',
                    'status' => '完成',
                    'answerText' => '恒森全屋定制适合重庆涪陵客户了解。',
                    'citations' => [],
                ]],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '', 0),
        ]);
        [$admin, $organization] = $this->createBrandFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.external-inspections.store'), [
                'name' => 'ChatGPT 单平台检视',
                'questions_text' => '恒森全屋定制怎么样',
                'platform_codes' => ['ai_web_workbench:chatgpt'],
            ])
            ->assertRedirect(route('admin.geo.workspace').'#external-qa');

        $run = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('name', 'ChatGPT 单平台检视')
            ->firstOrFail();

        app(GeoSearchBatchRunner::class)->run($run);

        $run->refresh();
        $this->assertSame(['ai_web_workbench:chatgpt'], $run->platform_codes);
        $this->assertSame(1, $run->points_cost);
        $this->assertSame('completed', $run->status);
        $organization->refresh();
        $this->assertSame(99, (int) $organization->points);
        $this->assertDatabaseHas('point_logs', [
            'organization_id' => $organization->id,
            'admin_id' => $admin->id,
            'action' => 'geo_search_run',
            'points_delta' => -1,
            'ref_type' => GeoAiSearchRun::class,
            'ref_id' => $run->id,
        ]);

        Process::assertRan(function ($process): bool {
            $command = is_array($process->command) ? $process->command : [$process->command];

            return in_array('run', $command, true)
                && in_array('--json', $command, true)
                && in_array('--platform', $command, true)
                && in_array('chatgpt', $command, true);
        });
    }

    public function test_external_qa_search_run_stops_when_points_are_insufficient(): void
    {
        [$admin, $organization] = $this->createBrandFixture();
        $organization->forceFill(['points' => 0])->save();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.external-inspections.store'), [
                'name' => '余额不足检视',
                'questions_text' => '恒森全屋定制怎么样',
                'platform_codes' => ['deepseek_mock'],
            ])
            ->assertRedirect(route('admin.geo.workspace').'#external-qa');

        $run = GeoAiSearchRun::query()
            ->where('organization_id', $organization->id)
            ->where('name', '余额不足检视')
            ->firstOrFail();

        $this->actingAs($admin, 'admin')
            ->from(route('admin.geo.search-runs.show', ['runId' => $run->id]))
            ->post(route('admin.geo.search-runs.run', ['runId' => $run->id]))
            ->assertRedirect(route('admin.geo.search-runs.show', ['runId' => $run->id]))
            ->assertSessionHasErrors();

        $run->refresh();
        $organization->refresh();
        $this->assertSame('pending', $run->status);
        $this->assertSame(0, (int) $organization->points);
        $this->assertSame(0, DB::table('geo_ai_search_answers')->where('geo_ai_search_run_id', $run->id)->count());
        $this->assertDatabaseMissing('point_logs', [
            'organization_id' => $organization->id,
            'action' => 'geo_search_run',
            'ref_type' => GeoAiSearchRun::class,
            'ref_id' => $run->id,
        ]);
    }

    public function test_citation_occurrences_keep_shared_urls_attached_to_each_search_run(): void
    {
        Http::fake([
            'https://ai-a.test/v1/chat/completions' => Http::sequence()
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => '恒森全屋定制可参考报价透明资料：https://example.test/shared-guide',
                        ],
                    ]],
                ])
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => '第二轮仍然引用同一篇资料：https://example.test/shared-guide，恒森全屋定制需要补报价说明。',
                        ],
                    ]],
                ]),
        ]);

        [$admin, $organization] = $this->createBrandFixture();
        $brandProfile = BrandProfile::query()->where('organization_id', $organization->id)->firstOrFail();
        $model = $this->createAiModel('测试平台A', 'https://ai-a.test');

        $firstRun = $this->createSearchRunForQuestion($admin, $organization, $brandProfile, '第一轮引用归因', '恒森全屋定制报价透明吗', ['ai_model:'.$model->id]);
        $secondRun = $this->createSearchRunForQuestion($admin, $organization, $brandProfile, '第二轮引用归因', '恒森全屋定制怎么解释报价', ['ai_model:'.$model->id]);

        app(GeoSearchBatchRunner::class)->run($firstRun);
        app(GeoSearchBatchRunner::class)->run($secondRun);

        $this->assertSame(1, DB::table('geo_citation_sources')->where('url', 'https://example.test/shared-guide')->count());
        $this->assertSame(2, DB::table('geo_citation_occurrences')->where('url', 'https://example.test/shared-guide')->count());
        $this->assertDatabaseHas('geo_citation_occurrences', [
            'organization_id' => $organization->id,
            'geo_ai_search_run_id' => $firstRun->id,
            'platform_code' => 'ai_model:'.$model->id,
            'url' => 'https://example.test/shared-guide',
        ]);
        $this->assertDatabaseHas('geo_citation_occurrences', [
            'organization_id' => $organization->id,
            'geo_ai_search_run_id' => $secondRun->id,
            'platform_code' => 'ai_model:'.$model->id,
            'url' => 'https://example.test/shared-guide',
        ]);
    }

    public function test_topic_pipeline_dispatches_queue_job_when_async_jobs_are_enabled(): void
    {
        Bus::fake();
        config(['geoflow.geo_async_jobs' => true]);
        [$admin] = $this->createBrandFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.topic-pipeline.run'), [
                'topic' => '全屋定制报价怎么避免增项',
                'platform_codes' => ['deepseek_mock', 'kimi_mock'],
                'max_references' => 2,
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHas('message', '完整 GEO 选题链路已进入队列，完成后可在草稿到发布链路查看结果');

        Bus::assertDispatched(GeoTopicPipelineJob::class, function (GeoTopicPipelineJob $job): bool {
            return $job->topic === '全屋定制报价怎么避免增项'
                && $job->platformCodes === ['deepseek_mock', 'kimi_mock']
                && $job->maxReferences === 2;
        });
        $this->assertSame(0, GeoArticleDraft::query()->count());
    }

    public function test_admin_can_run_topic_to_publish_package_pipeline(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Http::fake([
            'https://ai-a.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '全屋定制矩阵账号要先分老板号、案例号和问题号。恒森全屋定制可以围绕重庆涪陵本地案例承接私域。参考来源：https://example.test/matrix-guide',
                    ],
                ]],
            ]),
            'https://ai-b.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '客户主动加微信之前，需要先看到报价、板材、案例、流程和售后。可以对比恒森全屋定制与本地工厂内容。参考文章：https://example.test/customer-trust',
                    ],
                ]],
            ]),
            'https://example.test/matrix-guide' => Http::response(
                '<html><head><title>全屋定制品牌矩阵账号怎么做</title><meta name="description" content="矩阵账号获客参考"></head><body><article><h1>全屋定制矩阵账号怎么做</h1><p>2026年全屋定制获客要把老板号、工厂号、设计师号和问题号分工清楚。</p><p>内容要覆盖报价、板材、案例、流程、售后和私域承接，客户才会主动加微信。</p><p>建议用本地案例、评论区问题和搜索型标题做矩阵。</p></article></body></html>',
                200,
            ),
            'https://example.test/customer-trust' => Http::response(
                '<html><head><title>客户为什么不加你？先解决信任问题</title><meta name="description" content="高客单服务获客参考"></head><body><main><p>高客单服务获客需要先解决真实本地、专业解释和低压力咨询入口。</p><p>全屋定制内容可以用报价清单、板材说明、案例拆解和售后流程提升信任。</p><p>评论区承接要给用户明确资料，例如报价表、板材表和本地案例。</p></main></body></html>',
                200,
            ),
        ]);

        [$admin, $organization] = $this->createBrandFixture();
        $modelA = $this->createAiModel('测试平台A', 'https://ai-a.test');
        $modelB = $this->createAiModel('测试平台B', 'https://ai-b.test');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.topic-pipeline.run'), [
                'topic' => '全屋定制怎么做矩阵账号，才能让客户主动加你',
                'platform_codes' => ['ai_model:'.$modelA->id, 'ai_model:'.$modelB->id],
                'max_references' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHas('message');

        $this->assertDatabaseHas('geo_keyword_opportunities', [
            'organization_id' => $organization->id,
            'keyword' => '全屋定制怎么做矩阵账号，才能让客户主动加你',
            'generation_source' => 'topic_pipeline',
        ]);
        $this->assertDatabaseHas('geo_ai_search_runs', [
            'organization_id' => $organization->id,
            'status' => 'completed',
            'total_questions' => 1,
            'completed_questions' => 1,
            'failed_questions' => 0,
        ]);
        $this->assertSame(2, DB::table('geo_ai_search_answers')->where('status', 'succeeded')->count());
        $this->assertSame(2, DB::table('geo_citation_sources')->count());
        $this->assertSame(2, DB::table('geo_citation_page_snapshots')->where('crawl_status', 'succeeded')->count());
        $this->assertSame(2, DB::table('geo_reference_content_scores')->count());

        $draft = GeoArticleDraft::query()->latest()->firstOrFail();
        $draft->load('writingTask');
        $brief = (array) $draft->writingTask?->brief;

        $this->assertSame('topic_pipeline_reference_imitation', $brief['source'] ?? null);
        $this->assertSame('全屋定制怎么做矩阵账号，才能让客户主动加你', $brief['topic'] ?? null);
        $this->assertCount(2, $brief['references'] ?? []);
        $this->assertNotEmpty($brief['search_comparison']['answers'] ?? []);
        $this->assertSame('topic_pipeline_research_notes_v1', data_get($brief, 'research_notes.version'));
        $this->assertNotEmpty($brief['selected_reference_ids'] ?? []);
        $this->assertNotEmpty($brief['pipeline_stages']['publish_package']['completed_at'] ?? null);
        $this->assertSame('visual_publish_pack_v1', data_get($brief, 'visual_publish_package.version'));
        $this->assertSame('wxmp_publish_package_v1', data_get($brief, 'publish_package.version'));
        $this->assertStringContainsString('![封面图]', (string) $draft->content_markdown);
        $this->assertStringContainsString('![到店核验清单图]', (string) $draft->content_markdown);
        $this->assertStringNotContainsString('## 多平台回答交叉对比', (string) $draft->content_markdown);
        $this->assertStringNotContainsString('## 参考文章筛选结果', (string) $draft->content_markdown);
        $this->assertStringNotContainsString('## 写作依据', (string) $draft->content_markdown);
        $this->assertStringNotContainsString('## 本地案例怎么补', (string) $draft->content_markdown);
        $this->assertStringNotContainsString('## 发布前检查', (string) $draft->content_markdown);

        $supportingFiles = data_get($brief, 'publish_package.supporting_files', []);
        $this->assertCount(3, $supportingFiles);
        $supportingPaths = collect($supportingFiles)->pluck('path')->all();
        $this->assertContains('geo_publish_packages/draft-'.$draft->id.'/notes/research-summary.md', $supportingPaths);
        $this->assertContains('geo_publish_packages/draft-'.$draft->id.'/notes/platform-comparison.md', $supportingPaths);
        $this->assertContains('geo_publish_packages/draft-'.$draft->id.'/notes/selected-references.md', $supportingPaths);

        Storage::disk('local')->assertExists(data_get($brief, 'publish_package.markdown_path'));
        Storage::disk('local')->assertExists(data_get($brief, 'publish_package.manifest_path'));
        Storage::disk('local')->assertExists('geo_publish_packages/draft-'.$draft->id.'/notes/platform-comparison.md');
        Storage::disk('local')->assertExists('geo_publish_packages/draft-'.$draft->id.'/notes/selected-references.md');
        $comparisonNote = Storage::disk('local')->get('geo_publish_packages/draft-'.$draft->id.'/notes/platform-comparison.md');
        $this->assertStringContainsString('多平台回答交叉对比', $comparisonNote);
        $this->assertStringContainsString('ai_model:'.$modelA->id, $comparisonNote);
        $summaryNote = Storage::disk('local')->get('geo_publish_packages/draft-'.$draft->id.'/notes/research-summary.md');
        $this->assertStringContainsString('本地案例怎么补', $summaryNote);
        $this->assertStringContainsString('发布前检查', $summaryNote);
    }

    public function test_topic_pipeline_writes_topic_specific_article_without_forcing_matrix_sections(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Http::fake([
            'https://ai-a.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '全屋定制报价要看柜体、门板、五金、安装、运输和售后是否拆开，参考：https://example.test/quote-risk',
                    ],
                ]],
            ]),
            'https://ai-b.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '避免增项要先确认投影面积、展开面积、套餐外项目和合同边界，参考来源：https://example.test/contract-checklist',
                    ],
                ]],
            ]),
            'https://example.test/quote-risk' => Http::response(
                '<html><head><title>全屋定制报价避坑清单</title><meta name="description" content="报价拆分和增项风险"></head><body><article><h1>全屋定制报价避坑清单</h1><p>报价要把柜体、门板、五金、台面、安装、运输和售后拆开。</p><p>容易增项的地方包括见光板、拉直器、异形柜、收口条和升级五金。</p></article></body></html>',
                200,
            ),
            'https://example.test/contract-checklist' => Http::response(
                '<html><head><title>合同里哪些项目要提前写清</title><meta name="description" content="合同项目清单"></head><body><main><p>合同里要写清计价方式、板材型号、五金品牌、安装边界和售后响应。</p><p>客户到店前可以带户型图和预算，让门店按项目逐项报价。</p></main></body></html>',
                200,
            ),
        ]);

        [$admin] = $this->createBrandFixture();
        $modelA = $this->createAiModel('测试平台A', 'https://ai-a.test');
        $modelB = $this->createAiModel('测试平台B', 'https://ai-b.test');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.topic-pipeline.run'), [
                'topic' => '全屋定制报价怎么避免增项',
                'platform_codes' => ['ai_model:'.$modelA->id, 'ai_model:'.$modelB->id],
                'max_references' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHas('message');

        $draft = GeoArticleDraft::query()->latest()->firstOrFail();
        $markdown = (string) $draft->content_markdown;
        $draft->load('writingTask');
        $brief = (array) $draft->writingTask?->brief;

        $this->assertStringContainsString('全屋定制报价怎么避免增项', $markdown);
        $this->assertStringContainsString('报价', $markdown);
        $this->assertStringContainsString('增项', $markdown);
        $this->assertStringContainsString('全屋定制报价避坑清单', json_encode($brief['references'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->assertStringNotContainsString('## 账号分工要对应客户决策', $markdown);
        $this->assertStringNotContainsString('老板号适合讲信任和边界', $markdown);
        $this->assertStringNotContainsString('老板号、案例号、问题号', json_encode($brief['article_sections'] ?? [], JSON_UNESCAPED_UNICODE));
    }

    public function test_topic_pipeline_preserves_utf8_shared_signal_terms(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        Http::fake([
            'https://ai-a.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '全屋定制板材环保等级要讲清判断标准、风险边界、本地案例证据和核验清单。参考：https://example.test/board-risk-a',
                    ],
                ]],
            ]),
            'https://ai-b.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '客户到店前要先理解判断标准、风险边界、本地案例证据，再看板材检测资料。参考来源：https://example.test/board-risk-b',
                    ],
                ]],
            ]),
            'https://example.test/board-risk-a' => Http::response(
                '<html><head><title>板材环保等级怎么核验</title><meta name="description" content="板材环保和合同边界"></head><body><article><p>板材环保等级要看检测报告、封边工艺、合同标注和售后边界。</p><p>客户可以要求门店提供型号、等级、用量和验收清单。</p></article></body></html>',
                200,
            ),
            'https://example.test/board-risk-b' => Http::response(
                '<html><head><title>全屋定制板材风险边界</title><meta name="description" content="本地案例和核验清单"></head><body><main><p>合同里要写清板材品牌、环保等级、五金配置、安装范围和售后响应。</p><p>本地案例可以帮助客户判断交付质量和材料落地情况。</p></main></body></html>',
                200,
            ),
        ]);

        [$admin] = $this->createBrandFixture();
        $modelA = $this->createAiModel('测试平台A', 'https://ai-a.test');
        $modelB = $this->createAiModel('测试平台B', 'https://ai-b.test');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.topic-pipeline.run'), [
                'topic' => '全屋定制板材环保等级怎么选',
                'platform_codes' => ['ai_model:'.$modelA->id, 'ai_model:'.$modelB->id],
                'max_references' => 2,
            ])
            ->assertRedirect()
            ->assertSessionHas('message');

        $brief = (array) GeoArticleDraft::query()->latest()->firstOrFail()->writingTask?->brief;

        $this->assertContains('风险边界', data_get($brief, 'search_comparison.shared_signals', []));
        $this->assertNotFalse(json_encode($brief, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    public function test_topic_pipeline_stops_without_reference_sources(): void
    {
        Http::fake([
            'https://ai-a.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '只给出泛泛建议，没有任何可采集链接。'],
                ]],
            ]),
            'https://ai-b.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => '建议做矩阵账号，但没有引用来源。'],
                ]],
            ]),
        ]);

        [$admin] = $this->createBrandFixture();
        $modelA = $this->createAiModel('测试平台A', 'https://ai-a.test');
        $modelB = $this->createAiModel('测试平台B', 'https://ai-b.test');

        $this->actingAs($admin, 'admin')
            ->from(route('admin.geo.workspace'))
            ->post(route('admin.geo.topic-pipeline.run'), [
                'topic' => '全屋定制怎么做矩阵账号，才能让客户主动加你',
                'platform_codes' => ['ai_model:'.$modelA->id, 'ai_model:'.$modelB->id],
            ])
            ->assertRedirect(route('admin.geo.workspace'))
            ->assertSessionHasErrors();

        $this->assertSame(0, GeoArticleDraft::query()->count());
        $this->assertSame(0, GeoWritingTask::query()->count());
    }

    public function test_admin_can_crawl_and_score_citation_source_content(): void
    {
        Http::fake([
            'https://example.test/hengsen-guide' => Http::response(
                '<html><head><title>重庆全屋定制恒森案例</title><meta name="description" content="涪陵全屋定制选择参考"></head><body><article><h1>重庆全屋定制怎么选</h1><p>2026年重庆涪陵全屋定制案例显示，恒森全屋定制适合本地业主优先参考。</p><p>文章包含报价、板材、安装流程和售后口碑，建议对比佳诚定制、本地工厂和环保等级。</p></article></body></html>',
                200,
            ),
        ]);

        [$admin, $organization] = $this->createBrandFixture();
        $sourceId = (int) DB::table('geo_citation_sources')->insertGetId([
            'organization_id' => $organization->id,
            'url' => 'https://example.test/hengsen-guide',
            'domain' => 'example.test',
            'title' => '',
            'platform_name' => '',
            'status' => 'pending_crawl',
            'citation_count' => 2,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.citation-sources.index'))
            ->assertOk()
            ->assertSee('引用来源库')
            ->assertSee('example.test')
            ->assertSee('待采集');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.crawl', ['sourceId' => $sourceId]))
            ->assertRedirect(route('admin.geo.citation-sources.show', ['sourceId' => $sourceId]))
            ->assertSessionHas('message');

        $snapshot = DB::table('geo_citation_page_snapshots')->where('geo_citation_source_id', $sourceId)->first();
        $this->assertNotNull($snapshot);
        $this->assertSame('succeeded', $snapshot->crawl_status);
        $this->assertSame('重庆全屋定制恒森案例', $snapshot->title);
        $this->assertStringContainsString('恒森全屋定制适合本地业主优先参考', (string) $snapshot->content_text);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.score', ['sourceId' => $sourceId]))
            ->assertRedirect(route('admin.geo.citation-sources.show', ['sourceId' => $sourceId]))
            ->assertSessionHas('message');

        $score = DB::table('geo_reference_content_scores')->first();
        $this->assertNotNull($score);
        $this->assertSame((int) $snapshot->id, (int) $score->geo_citation_page_snapshot_id);
        $this->assertGreaterThanOrEqual(50, (int) $score->total_score);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.citation-sources.show', ['sourceId' => $sourceId]))
            ->assertOk()
            ->assertSee('重庆全屋定制恒森案例')
            ->assertSee('质量评分')
            ->assertSee('可借鉴用途');
    }

    public function test_admin_can_archive_high_score_source_and_analyze_citation_structure(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.test/hengsen-guide' => Http::response(
                '<html><head><title>重庆全屋定制恒森案例</title><meta name="description" content="涪陵全屋定制选择参考"></head><body><article><h1>重庆全屋定制怎么选</h1><h2>先看本地交付</h2><p>2026年重庆涪陵全屋定制案例显示，恒森全屋定制适合本地业主优先参考。</p><h2>再看报价和板材</h2><p>文章包含报价、板材环保等级、安装流程和售后口碑，建议对比佳诚定制、本地工厂和验收清单。</p><p>结尾给出门店核验、量尺、合同和售后条款四个行动步骤。</p></article></body></html>',
                200,
            ),
        ]);

        [$admin, $organization] = $this->createBrandFixture();
        $sourceId = $this->createCitationSource($organization, 'https://example.test/hengsen-guide');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.crawl', ['sourceId' => $sourceId]));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.score', ['sourceId' => $sourceId]));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.analyze', ['sourceId' => $sourceId]))
            ->assertRedirect(route('admin.geo.citation-sources.show', ['sourceId' => $sourceId]))
            ->assertSessionHas('message');

        $analysis = DB::table('geo_reference_content_analyses')->first();
        $this->assertNotNull($analysis);
        $this->assertSame($organization->id, (int) $analysis->organization_id);
        $this->assertSame($sourceId, (int) $analysis->geo_citation_source_id);
        $this->assertStringContainsString('文章结构拆解', (string) $analysis->analysis_markdown);
        $this->assertStringContainsString('为什么会被引用', (string) $analysis->analysis_markdown);
        $this->assertStringContainsString('本地交付', (string) $analysis->analysis_markdown);

        $structure = json_decode((string) $analysis->structure_json, true);
        $this->assertContains('标题直接命中搜索意图', $structure['citation_reasons']);
        $this->assertStringContainsString('本地交付', json_encode($structure['article_sections'], JSON_UNESCAPED_UNICODE));

        Storage::disk('local')->assertExists($analysis->markdown_path);
        Storage::disk('local')->assertExists($analysis->json_path);
        $this->assertStringContainsString('重庆全屋定制恒森案例', Storage::disk('local')->get($analysis->markdown_path));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.citation-sources.show', ['sourceId' => $sourceId]))
            ->assertOk()
            ->assertSee('本地分析档案')
            ->assertSee('文章结构拆解')
            ->assertSee('为什么会被引用')
            ->assertSee('标题直接命中搜索意图');
    }

    public function test_admin_can_generate_imitation_article_from_reference_analysis(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.test/hengsen-guide' => Http::response(
                '<html><head><title>重庆全屋定制恒森案例</title><meta name="description" content="涪陵全屋定制选择参考"></head><body><article><h1>重庆全屋定制怎么选</h1><h2>先看本地交付</h2><p>2026年重庆涪陵全屋定制案例显示，恒森全屋定制适合本地业主优先参考。</p><h2>再看报价和板材</h2><p>文章包含报价、板材环保等级、安装流程和售后口碑，建议对比本地工厂和验收清单。</p><p>结尾给出门店核验、量尺、合同和售后条款四个行动步骤。</p></article></body></html>',
                200,
            ),
        ]);

        [$admin, $organization] = $this->createBrandFixture();
        $sourceId = $this->createCitationSource($organization, 'https://example.test/hengsen-guide');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.crawl', ['sourceId' => $sourceId]));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.score', ['sourceId' => $sourceId]));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.analyze', ['sourceId' => $sourceId]));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.citation-sources.show', ['sourceId' => $sourceId]))
            ->assertOk()
            ->assertSee('按结构仿写文章');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.imitation-draft.store', ['sourceId' => $sourceId]))
            ->assertRedirect()
            ->assertSessionHas('message');

        $writingTask = GeoWritingTask::query()
            ->where('organization_id', $organization->id)
            ->where('brief->source', 'reference_imitation')
            ->firstOrFail();

        $this->assertSame('completed', $writingTask->status);
        $this->assertSame($sourceId, (int) $writingTask->brief['source_id']);
        $this->assertStringContainsString('标题直接命中搜索意图', json_encode($writingTask->brief, JSON_UNESCAPED_UNICODE));

        $draft = GeoArticleDraft::query()
            ->where('geo_writing_task_id', $writingTask->id)
            ->firstOrFail();

        $this->assertStringContainsString('恒森全屋定制', $draft->title);
        $this->assertStringContainsString('文章结构参考', (string) $draft->content_markdown);
        $this->assertStringContainsString('标题直接命中搜索意图', (string) $draft->content_markdown);
        $this->assertStringContainsString('报价、板材、环保、安装、售后', (string) $draft->content_markdown);
        $this->assertStringContainsString('本地工厂、环保板材、透明计价', (string) $draft->content_markdown);
        $this->assertStringContainsString('优先复用结构，不照抄原文表达', (string) $draft->content_markdown);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertOk()
            ->assertSee('结构仿写草稿')
            ->assertSee($draft->title);
    }

    public function test_admin_can_generate_publishable_article_from_reference_analysis(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://example.test/hengsen-guide' => Http::response(
                '<html><head><title>重庆涪陵全屋定制哪家靠谱</title><meta name="description" content="涪陵全屋定制选择参考"></head><body><article><h1>重庆涪陵全屋定制哪家靠谱</h1><h2>先看报价</h2><p>重庆涪陵全屋定制选择时，报价、板材、环保、安装和售后都要拆开看。</p><h2>再看本地案例</h2><p>本地案例、门店核验、量尺流程、合同验收和售后条款，是判断品牌是否靠谱的重要依据。</p></article></body></html>',
                200,
            ),
        ]);

        [$admin, $organization] = $this->createBrandFixture();
        $sourceId = $this->createCitationSource($organization, 'https://example.test/hengsen-guide');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.crawl', ['sourceId' => $sourceId]));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.score', ['sourceId' => $sourceId]));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.analyze', ['sourceId' => $sourceId]));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.citation-sources.show', ['sourceId' => $sourceId]))
            ->assertOk()
            ->assertSee('生成可发布正文');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.publishable-draft.store', ['sourceId' => $sourceId]))
            ->assertRedirect()
            ->assertSessionHas('message');

        $draft = GeoArticleDraft::query()->latest()->firstOrFail();
        $draft->load('writingTask');
        $content = (string) $draft->content_markdown;

        $this->assertSame('reference_imitation', $draft->writingTask?->brief['source'] ?? null);
        $this->assertNotEmpty($draft->writingTask?->brief['publishable_generated_at'] ?? null);
        $this->assertStringContainsString('涪陵全屋定制怎么选', $draft->title);
        $this->assertSame('wechat_readable_layout_v1', $draft->writingTask?->brief['publishable_layout'] ?? null);
        $this->assertStringContainsString('## 01 先说结论', $content);
        $this->assertStringContainsString('**真正要看的，不是宣传词有多满，而是这家店能不能把关键问题讲清楚。**', $content);
        $this->assertStringContainsString('| 判断项 | 到店要问 | 看什么细节 |', $content);
        $this->assertStringContainsString('## 06 到店前核验清单', $content);
        $this->assertStringContainsString('很多人在看全屋定制时', $content);
        $this->assertStringContainsString('报价能不能拆清楚', $content);
        $this->assertStringContainsString('恒森全屋定制', $content);
        $this->assertStringContainsString('本地工厂、环保板材、透明计价', $content);
        $this->assertStringContainsString('以现场沟通、合同和实际报价为准', $content);
        $this->assertStringNotContainsString('这篇内容参考了高分来源', $content);
        $this->assertStringNotContainsString('写作依据', $content);
        $this->assertStringNotContainsString('发布前可删除', $content);
        $this->assertStringNotContainsString('文章结构参考', $content);
        $this->assertStringNotContainsString('来源档案', $content);
        $this->assertStringNotContainsString('参考链接', $content);
        $this->assertStringNotContainsString('标题直接命中搜索意图', $content);
        $this->assertStringNotContainsString('行业第一', $content);
        $this->assertStringNotContainsString('百分百环保', $content);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertOk()
            ->assertSee('生成可发布正文')
            ->assertSee('优化正文排版')
            ->assertSee('结构仿写草稿');
    }

    public function test_admin_can_generate_visual_publish_package_for_standalone_article_draft(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$admin, $organization] = $this->createBrandFixture();

        $writingTask = GeoWritingTask::query()->create([
            'organization_id' => $organization->id,
            'geo_report_id' => null,
            'geo_keyword_id' => null,
            'title' => '按结构仿写 - 重庆涪陵全屋定制哪家靠谱',
            'status' => 'completed',
            'brief' => [
                'source' => 'reference_imitation',
                'question' => '重庆涪陵全屋定制哪家靠谱',
                'source_title' => '重庆涪陵全屋定制哪家靠谱',
                'source_score' => 86,
            ],
        ]);

        $draft = GeoArticleDraft::query()->create([
            'organization_id' => $organization->id,
            'geo_writing_task_id' => $writingTask->id,
            'title' => '涪陵全屋定制怎么选？先把这几个问题问清楚',
            'summary' => '从报价、板材、案例、流程和售后几个方面，帮涪陵业主判断全屋定制品牌是否值得进一步到店了解。',
            'content_markdown' => "# 涪陵全屋定制怎么选？先把这几个问题问清楚\n\n## 01 先说结论\n\n很多人在看全屋定制时，第一句话都会问：哪家靠谱，价格大概多少？\n\n## 02 报价能不能拆清楚\n\n报价单能不能拆到板材、门板、五金、安装和增项。\n\n## 05 把恒森全屋定制放进同一张表里看\n\n| 判断项 | 到店要问 | 看什么细节 |\n| --- | --- | --- |\n| 报价 | 柜体、门板、五金、安装和增项怎么拆 | 报价单是否写清楚 |\n\n## 06 到店前核验清单\n\n- 板材环保、封边、五金和质保有没有具体说明。\n- 有没有重庆涪陵本地案例，能不能看到真实完工效果。",
            'content_html' => '',
            'seo_title' => '涪陵全屋定制怎么选？先把这几个问题问清楚',
            'seo_description' => '从报价、板材、案例、流程和售后几个方面判断。',
            'status' => 'draft',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertOk()
            ->assertSee('生成配图与发布包');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.article-drafts.visual-pack', ['draftId' => $draft->id]))
            ->assertRedirect(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertSessionHas('message');

        $writingTask->refresh();
        $brief = (array) $writingTask->brief;
        $package = (array) ($brief['visual_publish_package'] ?? []);
        $items = (array) ($package['items'] ?? []);

        $this->assertSame('visual_publish_pack_v1', $package['version'] ?? null);
        $this->assertNotEmpty($brief['visual_pack_generated_at'] ?? null);
        $this->assertCount(4, $items);
        $this->assertSame('cover_image', $items[0]['type'] ?? null);
        $this->assertStringContainsString('封面图', $items[0]['title'] ?? '');
        $this->assertStringContainsString('中文文字使用微软雅黑', $items[0]['prompt'] ?? '');
        $this->assertStringContainsString('到店核验清单图', json_encode($items, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('量尺到安装流程图', json_encode($items, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('不要伪造案例现场', json_encode($items, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('真实案例图片', json_encode($items, JSON_UNESCAPED_UNICODE));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertOk()
            ->assertSee('配图与发布包')
            ->assertSee('植入配图到正文')
            ->assertSee('封面图')
            ->assertSee('到店核验清单图')
            ->assertSee('不要伪造案例现场');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.article-drafts.visual-pack.insert-images', ['draftId' => $draft->id]))
            ->assertRedirect(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertSessionHas('message');

        $draft->refresh();
        $writingTask->refresh();
        $content = (string) $draft->content_markdown;
        $insertedBrief = (array) $writingTask->brief;

        $this->assertNotEmpty($insertedBrief['visual_images_inserted_at'] ?? null);
        $this->assertStringContainsString('![封面图](uploads/geo/visual-pack/draft-'.$draft->id.'/cover-image.png)', $content);
        $this->assertStringContainsString('![到店核验清单图](uploads/geo/visual-pack/draft-'.$draft->id.'/checklist-infographic.png)', $content);
        $this->assertStringContainsString('![量尺到安装流程图](uploads/geo/visual-pack/draft-'.$draft->id.'/process-diagram.png)', $content);
        $this->assertStringNotContainsString('![真实案例图片]', $content);
        $this->assertStringContainsString('src="/storage/uploads/geo/visual-pack/draft-'.$draft->id.'/cover-image.png"', (string) $draft->content_html);

        Storage::disk('public')->put('uploads/geo/visual-pack/draft-'.$draft->id.'/cover-image.png', 'cover image');
        Storage::disk('public')->put('uploads/geo/visual-pack/draft-'.$draft->id.'/process-diagram.png', 'process image');
        Storage::disk('public')->put('uploads/geo/visual-pack/draft-'.$draft->id.'/checklist-infographic.png', 'checklist image');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertOk()
            ->assertSee('导出发布包');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.article-drafts.publish-package', ['draftId' => $draft->id]))
            ->assertRedirect(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertSessionHas('message');

        $writingTask->refresh();
        $exportBrief = (array) $writingTask->brief;
        $publishPackage = (array) ($exportBrief['publish_package'] ?? []);

        $this->assertSame('wxmp_publish_package_v1', $publishPackage['version'] ?? null);
        $this->assertNotEmpty($exportBrief['publish_package_exported_at'] ?? null);
        $this->assertSame(3, $publishPackage['image_count'] ?? null);
        Storage::disk('local')->assertExists($publishPackage['markdown_path']);
        Storage::disk('local')->assertExists($publishPackage['manifest_path']);
        Storage::disk('local')->assertExists($publishPackage['image_dir'].'/01-cover-image.png');
        Storage::disk('local')->assertExists($publishPackage['image_dir'].'/02-process-diagram.png');
        Storage::disk('local')->assertExists($publishPackage['image_dir'].'/03-checklist-infographic.png');

        $exportedMarkdown = Storage::disk('local')->get($publishPackage['markdown_path']);
        $this->assertStringContainsString('标题：涪陵全屋定制怎么选？先把这几个问题问清楚', $exportedMarkdown);
        $this->assertStringContainsString('摘要：从报价、板材、案例、流程和售后几个方面', $exportedMarkdown);
        $this->assertStringContainsString('封面建议：', $exportedMarkdown);
        $this->assertStringContainsString('---', $exportedMarkdown);
        $this->assertStringContainsString('![封面图](images/01-cover-image.png)', $exportedMarkdown);
        $this->assertStringContainsString('![量尺到安装流程图](images/02-process-diagram.png)', $exportedMarkdown);
        $this->assertStringContainsString('![到店核验清单图](images/03-checklist-infographic.png)', $exportedMarkdown);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertOk()
            ->assertSee('发布包已导出')
            ->assertSee($publishPackage['markdown_path'])
            ->assertSee('微信公众号草稿');

        config(['services.yixiaoer.api_key' => 'test-yxe-key']);
        Http::fake([
            'https://www.yixiaoer.cn/api/v2/platform/accounts*' => Http::response([
                'data' => [
                    'data' => [
                        [
                            'id' => 'wxmp-account-1',
                            'platformName' => '微信公众号',
                            'platformAccountName' => '副业库FUYEKU',
                            'status' => 1,
                        ],
                        [
                            'id' => 'shipinhao-expired',
                            'platformName' => '视频号',
                            'platformAccountName' => '明华智媒AI资源库',
                            'status' => 2,
                        ],
                    ],
                ],
            ]),
            'https://www.yixiaoer.cn/api/storages/cloud-publish/upload-url*' => Http::sequence()
                ->push(['data' => ['serviceUrl' => 'https://oss.test/upload/cover', 'key' => 'cloud-publish/cover.png']])
                ->push(['data' => ['serviceUrl' => 'https://oss.test/upload/process', 'key' => 'cloud-publish/process.png']])
                ->push(['data' => ['serviceUrl' => 'https://oss.test/upload/checklist', 'key' => 'cloud-publish/checklist.png']]),
            'https://oss.test/upload/*' => Http::response('', 200),
            'https://www.yixiaoer.cn/api/taskSets/v2' => Http::response([
                'data' => [
                    'taskSetId' => 'TS_GEO_DRAFT_5',
                ],
            ]),
            'https://www.yixiaoer.cn/api/v2/taskSets/TS_GEO_DRAFT_5/tasks' => Http::response([
                'data' => [
                    'tasks' => [
                        [
                            'platformName' => '微信公众号',
                            'stageStatus' => 'success',
                            'errorMessage' => '',
                            'documentId' => 'wxmp-draft-doc-1',
                        ],
                    ],
                ],
            ]),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.article-drafts.yixiaoer-distribute', ['draftId' => $draft->id]), [
                'platform_codes' => ['weixingongzhonghao'],
            ])
            ->assertRedirect(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertSessionHas('message');

        $record = GeoPublishRecord::query()->firstOrFail();
        $this->assertSame('submitted', $record->status);
        $this->assertSame(['weixingongzhonghao'], $record->platform_codes);
        $this->assertSame('TS_GEO_DRAFT_5', $record->target_url);
        $this->assertNull($record->error_message);
        $this->assertNotNull($record->submitted_at);
        $this->assertNull($record->published_at);
        $this->assertSame('yixiaoer', $record->handoff_payload['channel']);
        $this->assertSame('official_account_article_draft_submitted', $record->handoff_payload['action']);
        $this->assertSame('TS_GEO_DRAFT_5', $record->handoff_payload['task_set_id']);
        $this->assertSame('success', $record->handoff_payload['details_response']['data']['tasks'][0]['stageStatus']);
        $this->assertCount(3, $record->handoff_payload['uploaded_images']);

        $payload = $record->handoff_payload['publish_payload'];
        $this->assertSame('article', $payload['publishType']);
        $this->assertSame(['微信公众号'], $payload['platforms']);
        $this->assertSame('cloud-publish/cover.png', $payload['coverKey']);
        $this->assertCount(1, $payload['publishArgs']['accountForms']);
        $this->assertSame('wxmp-account-1', $payload['publishArgs']['accountForms'][0]['platformAccountId']);
        $this->assertSame(0, $payload['publishArgs']['platformForms']['微信公众号']['pubType']);
        $this->assertSame(0, $payload['publishArgs']['platformForms']['微信公众号']['notifySubscribers']);
        $this->assertSame('涪陵全屋定制怎么选？先把这几个问题问清楚', $payload['publishArgs']['platformForms']['微信公众号']['articles'][0]['title']);
        $this->assertStringContainsString('<h1>涪陵全屋定制怎么选？先把这几个问题问清楚</h1>', $payload['publishArgs']['platformForms']['微信公众号']['articles'][0]['content']);
        $this->assertStringContainsString('src="cloud-publish/process.png"', $payload['publishArgs']['platformForms']['微信公众号']['articles'][0]['content']);
        $this->assertSame('cloud-publish/cover.png', $payload['publishArgs']['platformForms']['微信公众号']['articles'][0]['cover']['key']);

        Http::assertSent(fn ($request) => $request->url() === 'https://www.yixiaoer.cn/api/taskSets/v2');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertOk()
            ->assertSee('已提交蚁小二')
            ->assertSee('微信公众号')
            ->assertDontSee('小红书')
            ->assertSee('TS_GEO_DRAFT_5');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.article-drafts.visual-pack.insert-images', ['draftId' => $draft->id]))
            ->assertRedirect(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]));

        $draft->refresh();
        $this->assertSame(1, substr_count((string) $draft->content_markdown, '![封面图]('));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.article-drafts.edit', ['draftId' => $draft->id]))
            ->assertOk()
            ->assertSee('正文预览')
            ->assertSee('src="/storage/uploads/geo/visual-pack/draft-'.$draft->id.'/cover-image.png"', false)
            ->assertSee('src="/storage/uploads/geo/visual-pack/draft-'.$draft->id.'/checklist-infographic.png"', false)
            ->assertSee('src="/storage/uploads/geo/visual-pack/draft-'.$draft->id.'/process-diagram.png"', false);
    }

    public function test_yixiaoer_official_account_distribution_requires_logged_in_account(): void
    {
        [$admin, $organization] = $this->createBrandFixture();

        $writingTask = GeoWritingTask::query()->create([
            'organization_id' => $organization->id,
            'geo_report_id' => null,
            'geo_keyword_id' => null,
            'title' => '按结构仿写 - 公众号发布测试',
            'status' => 'completed',
            'brief' => ['source' => 'reference_imitation'],
        ]);

        $draft = GeoArticleDraft::query()->create([
            'organization_id' => $organization->id,
            'geo_writing_task_id' => $writingTask->id,
            'title' => '涪陵全屋定制怎么选？先把这几个问题问清楚',
            'summary' => '从报价、板材、案例、流程和售后几个方面判断。',
            'content_markdown' => '# 涪陵全屋定制怎么选？',
            'content_html' => '<h1>涪陵全屋定制怎么选？</h1>',
            'seo_title' => '',
            'seo_description' => '',
            'status' => 'draft',
        ]);

        config(['services.yixiaoer.api_key' => 'test-yxe-key']);
        Http::fake([
            'https://www.yixiaoer.cn/api/v2/platform/accounts*' => Http::response([
                'data' => [
                    'data' => [[
                        'id' => 'wxmp-account-expired',
                        'platformName' => '微信公众号',
                        'platformAccountName' => '副业库FUYEKU',
                        'status' => 2,
                    ]],
                ],
            ]),
            '*' => Http::response('unexpected request', 500),
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.article-drafts.yixiaoer-distribute', ['draftId' => $draft->id]), [
                'platform_codes' => ['weixingongzhonghao'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors();

        $this->assertDatabaseCount('geo_publish_records', 0);
        Http::assertSentCount(1);
    }

    public function test_admin_can_batch_crawl_score_and_create_reference_brief(): void
    {
        Http::fake([
            'https://example.test/hengsen-guide' => Http::response(
                '<html><head><title>重庆全屋定制恒森案例</title></head><body><article><p>2026年重庆涪陵全屋定制案例显示，恒森全屋定制适合本地业主优先参考。</p><p>文章包含报价、板材、安装流程和售后口碑，建议对比佳诚定制、本地工厂和环保等级。</p></article></body></html>',
                200,
            ),
            'https://example.test/custom-wardrobe-checklist' => Http::response(
                '<html><head><title>涪陵衣柜定制避坑清单</title></head><body><main><p>衣柜定制要看板材环保等级、五金、报价明细和售后流程。</p><p>重庆涪陵业主可以参考本地工厂案例，选择时优先看量尺、设计、安装和验收清单。</p></main></body></html>',
                200,
            ),
        ]);

        [$admin, $organization] = $this->createBrandFixture();
        $sourceIds = [
            $this->createCitationSource($organization, 'https://example.test/hengsen-guide'),
            $this->createCitationSource($organization, 'https://example.test/custom-wardrobe-checklist'),
        ];

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.batch-crawl'), [
                'source_ids' => $sourceIds,
            ])
            ->assertRedirect(route('admin.geo.citation-sources.index'))
            ->assertSessionHas('message');

        $this->assertSame(2, DB::table('geo_citation_page_snapshots')->where('crawl_status', 'succeeded')->count());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.batch-score'), [
                'source_ids' => $sourceIds,
            ])
            ->assertRedirect(route('admin.geo.citation-sources.index'))
            ->assertSessionHas('message');

        $this->assertSame(2, DB::table('geo_reference_content_scores')->count());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.reference-brief.store'), [
                'source_ids' => $sourceIds,
                'title' => '涪陵全屋定制参考内容简报',
            ])
            ->assertRedirect(route('admin.geo.citation-sources.index'))
            ->assertSessionHas('message');

        $briefTask = GeoWritingTask::query()->firstOrFail();
        $this->assertSame($organization->id, $briefTask->organization_id);
        $this->assertSame('涪陵全屋定制参考内容简报', $briefTask->title);
        $this->assertSame('pending', $briefTask->status);
        $this->assertSame('reference_content', $briefTask->brief['source']);
        $this->assertCount(2, $briefTask->brief['references']);
        $this->assertStringContainsString('重庆全屋定制', json_encode($briefTask->brief, JSON_UNESCAPED_UNICODE));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.citation-sources.index'))
            ->assertOk()
            ->assertSee('参考内容简报')
            ->assertSee('涪陵全屋定制参考内容简报');
    }

    public function test_admin_can_generate_article_draft_from_reference_brief(): void
    {
        [$admin, $organization] = $this->createBrandFixture();
        $briefTask = GeoWritingTask::query()->create([
            'organization_id' => $organization->id,
            'title' => '涪陵全屋定制参考内容简报',
            'status' => 'pending',
            'brief' => [
                'source' => 'reference_content',
                'references' => [[
                    'title' => '重庆全屋定制恒森案例',
                    'url' => 'https://example.test/hengsen-guide',
                    'summary' => '包含报价、板材、安装流程和售后口碑。',
                    'score' => 82,
                    'content_excerpt' => '恒森全屋定制适合本地业主优先参考。',
                ]],
                'recommended_outline' => [
                    '先用一句话回答用户最关心的问题',
                    '补充本地案例、报价、板材、流程、售后等可验证事实',
                ],
                'evidence_points' => ['报价、板材、安装流程和售后口碑'],
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.reference-briefs.article-draft.store', ['writingTaskId' => $briefTask->id]))
            ->assertRedirect(route('admin.geo.citation-sources.index'))
            ->assertSessionHas('message');

        $this->assertDatabaseHas('geo_article_drafts', [
            'geo_writing_task_id' => $briefTask->id,
            'status' => 'draft',
        ]);

        $draft = GeoArticleDraft::query()->where('geo_writing_task_id', $briefTask->id)->firstOrFail();
        $this->assertStringContainsString('重庆全屋定制恒森案例', (string) $draft->content_markdown);
        $this->assertStringContainsString('报价、板材、安装流程', (string) $draft->content_markdown);
        $this->assertStringContainsString('恒森全屋定制', $draft->title);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.geo.citation-sources.index'))
            ->assertOk()
            ->assertSee('涪陵全屋定制参考内容简报')
            ->assertSee('已生成草稿');
    }

    /**
     * @return array{0: Admin, 1: Organization}
     */
    private function createBrandFixture(): array
    {
        $admin = Admin::query()->create([
            'username' => 'geo_opportunity_admin',
            'password' => 'secret-123',
            'email' => 'geo-opportunity-admin@example.com',
            'display_name' => 'GEO Opportunity Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        BrandProfile::query()->create([
            'organization_id' => $organization->id,
            'brand_name' => '恒森全屋定制',
            'aliases' => ['恒森定制'],
            'products' => '衣柜、橱柜、鞋柜、全屋定制',
            'advantages' => '本地工厂、环保板材、透明计价',
            'cases' => '涪陵本地家庭定制案例',
            'pain_points' => '价格不透明、板材环保难判断、售后不稳定',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺和定制设计',
        ]);

        return [$admin, $organization];
    }

    private function createCitationSource(Organization $organization, string $url): int
    {
        return (int) DB::table('geo_citation_sources')->insertGetId([
            'organization_id' => $organization->id,
            'url' => $url,
            'domain' => parse_url($url, PHP_URL_HOST),
            'title' => '',
            'platform_name' => '',
            'status' => 'pending_crawl',
            'citation_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<string>  $platformCodes
     */
    private function createSearchRunForQuestion(Admin $admin, Organization $organization, BrandProfile $brandProfile, string $name, string $question, array $platformCodes): GeoAiSearchRun
    {
        $opportunity = GeoKeywordOpportunity::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'keyword' => $question,
            'intent' => 'decision',
            'cluster_name' => '测试引用归因',
            'status' => 'active',
            'business_value' => 80,
            'visibility_gap' => 80,
            'source_availability' => 80,
            'local_relevance' => 80,
            'opportunity_score' => 80,
            'generation_source' => 'test',
        ]);

        $run = GeoAiSearchRun::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => $name,
            'status' => 'pending',
            'platform_codes' => $platformCodes,
            'points_cost' => count($platformCodes),
            'total_questions' => 1,
        ]);

        $run->questions()->create([
            'geo_keyword_opportunity_id' => $opportunity->id,
            'question' => $question,
            'intent' => 'decision',
            'status' => 'pending',
        ]);

        return $run;
    }

    private function createAiModel(string $name, string $apiUrl): AiModel
    {
        return AiModel::query()->create([
            'name' => $name,
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('test-api-key'),
            'model_id' => 'test-chat-model',
            'model_type' => 'chat',
            'api_url' => $apiUrl,
            'failover_priority' => 10,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);
    }
}
