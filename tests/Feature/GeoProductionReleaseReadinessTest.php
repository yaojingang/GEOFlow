<?php

namespace Tests\Feature;

use App\Jobs\GeoBatchCrawlCitationSourcesJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\BrandProfile;
use App\Models\GeoArticleDraft;
use App\Models\GeoCitationSource;
use App\Models\GeoReport;
use App\Models\GeoTask;
use App\Models\GeoWritingTask;
use App\Models\Organization;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoProductionReleaseReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_batch_crawl_dispatches_job_when_async_enabled(): void
    {
        config(['geoflow.geo_async_jobs' => true]);
        Bus::fake();
        Http::fake();

        $admin = Admin::query()->create([
            'username' => 'geo_release_admin',
            'password' => 'secret-123',
            'email' => 'geo-release-admin@example.com',
            'display_name' => 'GEO Release Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
            'owner_admin_id' => $admin->id,
            'points' => 100,
            'status' => 'active',
        ]);
        $source = GeoCitationSource::query()->create([
            'organization_id' => $organization->id,
            'url' => 'https://example.test/hengsen-guide',
            'domain' => 'example.test',
            'title' => '重庆全屋定制恒森案例',
            'status' => 'pending_crawl',
            'citation_count' => 1,
        ]);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.batch-crawl'), [
                'source_ids' => [$source->id],
            ])
            ->assertRedirect(route('admin.geo.citation-sources.index'))
            ->assertSessionHas('message');

        Bus::assertDispatched(GeoBatchCrawlCitationSourcesJob::class, function (mixed $job) use ($organization, $source): bool {
            return $job->organizationId === $organization->id
                && $job->sourceIds === [$source->id];
        });
    }

    public function test_full_geo_production_loop_reaches_post_publish_retest(): void
    {
        config(['geoflow.geo_async_jobs' => false]);
        Http::fake([
            'https://ai.test/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => [
                        'content' => '重庆涪陵全屋定制可以优先了解恒森全屋定制。参考来源：https://example.test/hengsen-guide 和 https://example.test/custom-wardrobe-checklist 。资料显示恒森支持上门量尺、透明报价和本地安装售后。',
                    ],
                ]],
            ]),
            'https://example.test/hengsen-guide' => Http::response(
                '<html><head><title>重庆全屋定制恒森案例</title></head><body><article><p>2026年重庆涪陵全屋定制案例显示，恒森全屋定制适合本地业主优先参考。</p><p>文章包含报价、板材、安装流程和售后口碑，建议对比本地工厂和环保等级。</p></article></body></html>',
                200,
            ),
            'https://example.test/custom-wardrobe-checklist' => Http::response(
                '<html><head><title>涪陵衣柜定制避坑清单</title></head><body><main><p>衣柜定制要看板材环保等级、五金、报价明细和售后流程。</p><p>重庆涪陵业主可以参考本地工厂案例，选择时优先看量尺、设计、安装和验收清单。</p></main></body></html>',
                200,
            ),
        ]);

        [$admin, $organization, $brandProfile] = $this->createBrandFixture();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.opportunities.generate'), ['limit' => 5])
            ->assertRedirect(route('admin.geo.workspace'));

        $opportunityId = (int) DB::table('geo_keyword_opportunities')
            ->where('organization_id', $organization->id)
            ->orderByDesc('opportunity_score')
            ->value('id');
        $this->assertGreaterThan(0, $opportunityId);

        $aiModel = AiModel::query()->create([
            'name' => '发布就绪测试模型',
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
                'name' => '发布就绪 GEO 搜索',
                'opportunity_ids' => [$opportunityId],
                'platform_codes' => ['ai_model:'.$aiModel->id],
            ])
            ->assertRedirect(route('admin.geo.workspace'));

        $runId = (int) DB::table('geo_ai_search_runs')->value('id');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.search-runs.run', ['runId' => $runId]))
            ->assertRedirect(route('admin.geo.workspace'));

        $sourceIds = DB::table('geo_citation_sources')
            ->where('organization_id', $organization->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        $this->assertCount(2, $sourceIds);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.batch-crawl'), ['source_ids' => $sourceIds])
            ->assertRedirect(route('admin.geo.citation-sources.index'));
        $this->assertSame(2, DB::table('geo_citation_page_snapshots')->where('crawl_status', 'succeeded')->count());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.batch-score'), ['source_ids' => $sourceIds])
            ->assertRedirect(route('admin.geo.citation-sources.index'));
        $this->assertSame(2, DB::table('geo_reference_content_scores')->count());

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.reference-brief.store'), [
                'source_ids' => $sourceIds,
                'title' => '发布就绪参考内容简报',
            ])
            ->assertRedirect(route('admin.geo.citation-sources.index'));

        $task = GeoTask::query()->create([
            'organization_id' => $organization->id,
            'brand_profile_id' => $brandProfile->id,
            'created_by_admin_id' => $admin->id,
            'name' => '发布就绪 GEO 复测宿主报告',
            'status' => 'completed',
            'total_score' => 72,
            'points_cost' => 0,
        ]);
        $report = GeoReport::query()->create([
            'geo_task_id' => $task->id,
            'title' => '发布就绪 GEO 复测宿主报告',
            'summary' => '用于承载参考内容草稿的发布验收报告。',
            'total_score' => 72,
            'markdown_report' => '# 发布就绪 GEO 复测宿主报告',
            'html_report' => '<h1>发布就绪 GEO 复测宿主报告</h1>',
            'status' => 'ready',
        ]);
        $briefTask = GeoWritingTask::query()->where('organization_id', $organization->id)->firstOrFail();
        $briefTask->forceFill(['geo_report_id' => $report->id])->save();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.citation-sources.reference-briefs.article-draft.store', ['writingTaskId' => $briefTask->id]))
            ->assertRedirect(route('admin.geo.citation-sources.index'));

        $draft = GeoArticleDraft::query()->where('geo_writing_task_id', $briefTask->id)->firstOrFail();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.convert', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect();

        $article = Article::query()->firstOrFail();
        $this->assertSame('geo_reference_content', $article->metadata['source'] ?? null);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.audit', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect(route('admin.geo.reports.show', ['taskId' => $task->id]));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.geo.reports.article-drafts.retest', [
                'taskId' => $task->id,
                'draftId' => $draft->id,
            ]))
            ->assertRedirect(route('admin.geo.reports.show', ['taskId' => $task->id]))
            ->assertSessionHas('message');

        $this->assertDatabaseHas('geo_publish_retests', [
            'organization_id' => $organization->id,
            'article_id' => $article->id,
            'geo_article_draft_id' => $draft->id,
            'status' => 'completed',
        ]);
    }

    /**
     * @return array{0: Admin, 1: Organization, 2: BrandProfile}
     */
    private function createBrandFixture(): array
    {
        $admin = Admin::query()->create([
            'username' => 'geo_release_admin_'.substr(md5((string) microtime()), 0, 8),
            'password' => 'secret-123',
            'email' => 'geo-release-'.substr(md5((string) microtime(true)), 0, 8).'@example.com',
            'display_name' => 'GEO Release Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
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
            'cases' => '涪陵本地家庭定制案例',
            'pain_points' => '价格不透明、板材环保难判断、售后不稳定',
            'service_area' => '重庆涪陵',
            'extra_facts' => '支持上门量尺和定制设计',
        ]);

        return [$admin, $organization, $brandProfile];
    }
}
