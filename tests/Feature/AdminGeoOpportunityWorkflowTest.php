<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\BrandProfile;
use App\Models\GeoArticleDraft;
use App\Models\GeoKeywordOpportunity;
use App\Models\GeoWritingTask;
use App\Models\Organization;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
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
}
