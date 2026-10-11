<?php

namespace Tests\Feature;

use App\Contracts\Outbound\HostResolver;
use App\Jobs\CheckLinkPoolTargetUrl;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleSlugHistory;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Services\LinkPool\LinkCampaignService;
use App\Services\LinkPool\LinkPoolImportService;
use App\Services\LinkPool\LinkPoolUrlHealthService;
use App\Services\Site\LinkPoolRecommendationService;
use App\Services\Site\LinkPoolSiteService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

class LinkPoolExternalLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.url', 'https://primary.test');
        config()->set('geoflow.site_url', 'https://primary.test');
        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.primary_hosts', ['primary.test']);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
        Http::preventStrayRequests();
        $this->app->instance(HostResolver::class, new FakeHostResolver);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'article_recommendations'], [
            'setting_value' => json_encode(['enabled' => true, 'heading' => '推荐内容', 'default_limit' => 4, 'rotation_hours' => 24]),
        ]);
    }

    public function test_external_homepage_and_query_fragment_urls_import_without_title_confirmation(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $imports = app(LinkPoolImportService::class);
        $rows = $imports->preflight("https://www.aichafen.com/,爱查分\nHTTP://EXAMPLE.COM:80/product?q=one&lang=zh#details,产品\nhttp://example.com/product?q=one&lang=zh#details,重复");
        $this->assertSame(['new', 'new', 'duplicate'], array_column($rows, 'status'));
        $this->assertSame('external', $rows[0]['target_type']);
        $this->assertNull($rows[0]['article_id']);
        $this->assertFalse($rows[0]['title_differs']);
        $result = $imports->import($rows, 1, false);
        $this->assertSame(2, $result['new']);
        $this->assertDatabaseHas('link_pool_targets', ['input_url' => 'https://www.aichafen.com/', 'display_title' => '爱查分', 'article_id' => null, 'health_status' => 'pending']);
        $this->assertDatabaseHas('link_pool_targets', ['input_url' => 'http://example.com/product?q=one&lang=zh#details']);
        Queue::assertPushed(CheckLinkPoolTargetUrl::class, 2);
        $id = (int) DB::table('link_pool_targets')->where('input_url', 'https://www.aichafen.com/')->value('id');
        $imports->import($imports->preflight('https://WWW.AICHAFEN.COM:443/,新标题'), 1, false);
        $this->assertSame(2, DB::table('link_pool_targets')->count());
        $this->assertDatabaseHas('link_pool_targets', ['id' => $id, 'display_title' => '新标题']);
    }

    public function test_invalid_web_urls_are_rejected_and_owned_article_variants_keep_article_identity(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Owned article', 'owned');
        $imports = app(LinkPoolImportService::class);
        $rows = $imports->preflight("javascript:alert(1),脚本\ndata:text/html;base64,a\nhttps://user:pass@example.com/,账户\nhttps://example.com:99999/,端口\nhttps://primary.test/article/owned?utm=1#body,Owned article");
        $this->assertSame(['error', 'error', 'error', 'error', 'new'], array_column($rows, 'status'));
        $this->assertSame('article', $rows[4]['target_type']);
        $source->update(['status' => 'draft']);
        $this->assertSame('error', $imports->preflight('http://primary.test/article/owned?utm=1,Owned article')[0]['status']);
        $this->assertSame(1, $imports->import([$rows[4]], 1, false)['error']);
        $this->assertSame(0, DB::table('link_pool_targets')->count());
        Http::assertNothingSent();
    }

    public function test_owned_encoded_trailing_and_historical_urls_keep_withdrawal_checks(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $article = $this->article('Owned article', 'owned');
        ArticleSlugHistory::query()->create(['article_id' => $article->id, 'slug' => 'old-owned']);
        $imports = app(LinkPoolImportService::class);
        $input = "https://primary.test/article/%6fwned,Owned article\nhttps://primary.test/article/owned/,Owned article\nhttps://primary.test/article/old-owned,Owned article";
        $rows = $imports->preflight($input);
        $this->assertSame(['new', 'duplicate', 'duplicate'], array_column($rows, 'status'));
        $this->assertSame(['article'], array_values(array_unique(array_column($rows, 'target_type'))));
        $article->update(['status' => 'draft']);
        $this->assertSame(['error', 'error', 'error'], array_column($imports->preflight($input), 'status'));
    }

    public function test_queue_outage_preserves_all_imported_links_and_undo_batch(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $dispatcher = app(Dispatcher::class);
        $this->mock(Dispatcher::class)
            ->shouldReceive('dispatch')->twice()->andThrow(new RuntimeException('Queue unavailable'));
        $imports = app(LinkPoolImportService::class);
        $result = $imports->import($imports->preflight("https://www.aichafen.com/,爱查分\nhttps://example.com/product,产品"), 1, false);
        $this->assertSame(2, $result['new']);
        $this->assertSame(2, DB::table('link_pool_targets')->where('health_status', 'unchecked')->where('status', 'active')->count());
        $this->assertDatabaseHas('link_pool_import_batches', ['id' => $result['batch_id']]);
        Bus::swap($dispatcher);
        app(LinkPoolUrlHealthService::class)->enqueue((int) DB::table('link_pool_targets')->value('id'));
        Queue::assertPushed(CheckLinkPoolTargetUrl::class, 1);
        $this->assertSame(['removed' => 2, 'retained' => 0], $imports->undo($result['batch_id'], 1));
    }

    public function test_multiple_external_links_render_without_review_and_have_separate_metrics(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Source', 'source');
        $ids = $this->import("https://www.aichafen.com/,爱查分\nhttps://example.com/product?q=1&lang=zh#spec,产品 & 详情");
        $campaign = $this->publish('primary', $ids);
        $this->assertSame(['not_required'], DB::table('link_campaign_targets')->where('campaign_id', $campaign)->distinct()->pluck('cross_site_review_status')->all());
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
        $this->get('https://primary.test/article/source')->assertOk()
            ->assertSee('href="https://www.aichafen.com/"', false)
            ->assertSee('href="https://example.com/product?q=1&amp;lang=zh#spec"', false)
            ->assertSee('产品 &amp; 详情', false);
        $this->assertSame(2, DB::table('link_placements')->count());
        $this->assertSame(2, DB::table('link_metrics_daily')->count());
        foreach (DB::table('link_placements')->get() as $placement) {
            foreach (['viewable', 'click'] as $event) {
                $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome/128.0')->postJson('https://primary.test/link-pool/events', [
                    'placement_id' => $placement->id, 'event_uuid' => (string) Str::uuid(), 'event_type' => $event,
                ])->assertOk()->assertJsonPath('accepted', true);
            }
        }
        $this->assertSame(2, (int) DB::table('link_metrics_daily')->sum('human_clicks'));
        $this->assertSame($ids, DB::table('link_metrics_daily')->orderBy('target_id')->pluck('target_id')->map(fn ($id) => (int) $id)->all());
        $this->actingAs($this->admin(), 'admin')->get('https://primary.test'.route('admin.link-pool.targets', ['target_site' => 'external'], false))
            ->assertOk()->assertSee('爱查分')->assertSee('等待访问检查');
        $this->get('https://primary.test'.route('admin.link-pool.dashboard', ['target_site' => 'external'], false))
            ->assertOk()->assertSee('GEOFlow 无法采集外部网站的访问日志');
        Http::assertNothingSent();
    }

    public function test_external_pool_participates_in_random_related_and_mixed_modes(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Source', 'source');
        $source->update(['keywords' => '教育,成绩']);
        $ids = $this->import("https://www.aichafen.com/,爱查分,教育\nhttps://example.com/product,产品,商品");
        foreach (['random' => 2, 'related' => 1, 'mixed' => 2] as $mode => $count) {
            DB::table('link_campaigns')->update(['status' => 'paused']);
            $this->publish('primary', [], $mode);
            app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
            $preview = app(LinkPoolRecommendationService::class)->preview('primary', $source);
            $this->assertCount($count, $preview['items'], $mode);
            if ($mode === 'related') {
                $this->assertSame($ids[0], $preview['items'][0]['target_id']);
            }
            app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
            $first = app(LinkPoolRecommendationService::class)->forSiteArticle('primary', $source);
            $this->assertSame($first, app(LinkPoolRecommendationService::class)->forSiteArticle('primary', $source));
        }
    }

    public function test_health_is_advisory_and_paused_expired_targets_stop_output_and_events(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Source', 'source');
        $ids = $this->import("https://example.com/unavailable,暂时不可访问\nhttps://example.com/expired,到期");
        DB::table('link_pool_targets')->where('id', $ids[0])->update(['health_status' => 'unreachable', 'health_http_status' => 404]);
        $this->publish('primary', $ids);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
        $this->get('https://primary.test/article/source')->assertSee('https://example.com/unavailable');
        $placement = DB::table('link_placements')->where('target_id', $ids[0])->first();
        DB::table('link_pool_targets')->where('id', $ids[0])->update(['status' => 'paused']);
        DB::table('link_pool_targets')->where('id', $ids[1])->update(['expires_at' => now()->subSecond()]);
        $this->get('https://primary.test/article/source')->assertDontSee('https://example.com/unavailable')->assertDontSee('https://example.com/expired');
        $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome/128.0')->postJson('https://primary.test/link-pool/events', [
            'placement_id' => $placement->id, 'event_uuid' => (string) Str::uuid(), 'event_type' => 'click',
        ])->assertOk()->assertJsonPath('accepted', false);
    }

    public function test_health_follows_public_redirects_strips_fragments_and_reports_restrictions(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $ids = $this->import("https://example.com/redirect#section,跳转\nhttps://example.com/restricted,受限\nhttps://example.com/missing,失效");
        Http::fake([
            'https://example.com/redirect' => Http::response('', 302, ['Location' => 'https://public.test/final']),
            'https://public.test/final' => Http::response('ok', 200),
            'https://example.com/restricted' => Http::response('', 403),
            'https://example.com/missing' => Http::response('', 404),
        ]);
        foreach ($ids as $id) {
            $target = DB::table('link_pool_targets')->find($id);
            app(LinkPoolUrlHealthService::class)->check($id, $target->url_sha256);
        }
        $this->assertSame(['reachable', 'restricted', 'unreachable'], DB::table('link_pool_targets')->orderBy('id')->pluck('health_status')->all());
        $this->assertSame(['active'], DB::table('link_pool_targets')->distinct()->pluck('status')->all());
        Http::assertSentCount(4);
    }

    public function test_health_blocks_private_destinations_and_redirects_before_transport(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $ids = $this->import("http://127.0.0.1/private,内部地址\nhttps://example.com/redirect,跳转");
        Http::fake(['https://example.com/redirect' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data'])]);
        foreach ($ids as $id) {
            $target = DB::table('link_pool_targets')->find($id);
            app(LinkPoolUrlHealthService::class)->check($id, $target->url_sha256);
        }
        $this->assertSame(['unchecked'], DB::table('link_pool_targets')->distinct()->pluck('health_status')->all());
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254') || str_contains($request->url(), '127.0.0.1'));
    }

    public function test_network_failure_and_outdated_job_do_not_disable_or_overwrite_links(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $id = $this->import('https://example.com/timeout,超时')[0];
        $target = DB::table('link_pool_targets')->find($id);
        Http::fake(fn () => throw new ConnectionException('timeout'));
        app(LinkPoolUrlHealthService::class)->check($id, $target->url_sha256);
        $this->assertDatabaseHas('link_pool_targets', ['id' => $id, 'health_status' => 'unchecked', 'status' => 'active']);
        DB::table('link_pool_targets')->where('id', $id)->update(['url_sha256' => hash('sha256', 'updated'), 'health_status' => 'pending']);
        (new CheckLinkPoolTargetUrl($id, $target->url_sha256))->handle(app(LinkPoolUrlHealthService::class));
        $this->assertDatabaseHas('link_pool_targets', ['id' => $id, 'health_status' => 'pending']);
        Http::assertSentCount(0);
    }

    public function test_admin_can_recheck_and_read_only_admin_cannot_mutate_links(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $id = $this->import('https://www.aichafen.com/,爱查分')[0];
        $this->actingAs($this->admin(), 'admin')->post('https://primary.test'.route('admin.link-pool.targets.check', $id, false))->assertRedirect();
        Queue::assertPushed(CheckLinkPoolTargetUrl::class, 1);
        $this->actingAs($this->admin('admin'), 'admin')->get('https://primary.test'.route('admin.link-pool.targets', [], false))->assertOk();
        $this->post('https://primary.test'.route('admin.link-pool.targets.check', $id, false))->assertForbidden();
        $this->post('https://primary.test'.route('admin.link-pool.import.store', [], false), ['links' => 'https://example.com/,示例'])->assertForbidden();
    }

    public function test_hosted_private_source_can_recommend_external_links_and_noindex_hides_them(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $channel = DistributionChannel::query()->create([
            'name' => 'External hosted', 'domain' => 'external.sites.test', 'endpoint_url' => 'https://external.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
            'site_settings' => ['article_recommendations' => ['enabled' => true, 'heading' => '推荐内容', 'default_limit' => 4, 'rotation_hours' => 24]],
        ]);
        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id, 'hostname' => 'external.sites.test', 'root_domain' => 'sites.test',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE, 'indexing_status' => HostedSiteProfile::INDEXING_INDEX, 'quality_status' => HostedSiteProfile::QUALITY_PASSED,
        ]);
        $source = $this->article('Hosted source', 'hosted-source');
        $source->update(['status' => 'private']);
        $source->task->update(['publish_scope' => 'distribution_only']);
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $source->id, 'hosted_site_profile_id' => $profile->id, 'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
            'content_fingerprint' => hash('sha256', (string) $source->id), 'capacity_date' => now()->toDateString(), 'assigned_at' => now(), 'published_at' => now(),
        ]);
        $ids = $this->import('https://www.aichafen.com/,爱查分');
        $siteKey = 'hosted:'.$profile->id;
        $this->publish($siteKey, $ids);
        app(LinkPoolRecommendationService::class)->refresh($siteKey, (int) $source->id);
        $this->get('https://external.sites.test/article/hosted-source')->assertOk()->assertSee('href="https://www.aichafen.com/"', false);
        $placement = DB::table('link_placements')->first();
        $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome/128.0')->postJson('https://external.sites.test/link-pool/events', [
            'placement_id' => $placement->id, 'event_uuid' => (string) Str::uuid(), 'event_type' => 'click',
        ])->assertOk()->assertJsonPath('accepted', true);
        $profile->update(['indexing_status' => HostedSiteProfile::INDEXING_NOINDEX]);
        $this->get('https://external.sites.test/article/hosted-source')->assertOk()->assertDontSee('https://www.aichafen.com/');
        Http::assertNothingSent();
    }

    public function test_undo_external_import_preserves_a_target_referenced_by_an_active_campaign(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $this->article('Source', 'source');
        $imports = app(LinkPoolImportService::class);
        $result = $imports->import($imports->preflight("https://example.com/keep,保留\nhttps://example.com/remove,撤销"), 1, false);
        $ids = DB::table('link_pool_targets')->orderBy('id')->pluck('id')->all();
        $this->publish('primary', [$ids[0]]);
        $imports->undo($result['batch_id'], 1);
        $this->assertDatabaseHas('link_pool_targets', ['id' => $ids[0]]);
        $this->assertDatabaseMissing('link_pool_targets', ['id' => $ids[1]]);
    }

    public function test_existing_article_placements_and_metrics_survive_the_migration(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Source', 'source');
        $target = $this->article('Target', 'target');
        $target->update(['category_id' => Category::query()->create(['name' => 'Target', 'slug' => 'target'])->id]);
        $ids = $this->import('https://primary.test/article/target,Target');
        $this->publish('primary', $ids);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
        $this->get('https://primary.test/article/source')->assertOk()->assertSee('https://primary.test/article/target');
        $key = DB::table('link_metrics_daily')->value('metric_key');
        $migration = require database_path('migrations/2026_09_30_202116_add_external_targets_to_link_pool.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('link_placements', ['target_id' => $ids[0], 'target_article_id' => $target->id]);
        $this->assertDatabaseHas('link_metrics_daily', ['target_id' => $ids[0], 'metric_key' => $key, 'html_served' => 1]);
        $this->get('https://primary.test/article/source')->assertOk()->assertSee('https://primary.test/article/target');
        $this->assertSame(1, DB::table('link_metrics_daily')->count());
        $this->assertDatabaseHas('link_metrics_daily', ['metric_key' => $key, 'html_served' => 2]);
    }

    public function test_schema_rollback_refuses_to_discard_external_link_data(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $this->import('https://www.aichafen.com/,爱查分');
        $migration = require database_path('migrations/2026_09_30_202116_add_external_targets_to_link_pool.php');
        try {
            $migration->down();
            $this->fail('Rollback must preserve external link data.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('External link data exists', $exception->getMessage());
            $this->assertDatabaseHas('link_pool_targets', ['target_type' => 'external', 'input_url' => 'https://www.aichafen.com/']);
        }
    }

    public function test_import_title_validation_preserves_preflight_and_allows_retry(): void
    {
        $this->article('Original title', 'owned');
        $input = 'https://primary.test/article/owned,Custom title';
        $form = $this->adminUrl('admin.link-pool.import.form', ['site' => 'primary']);
        $preflight = $this->adminUrl('admin.link-pool.import.preflight', ['site' => 'primary']);
        $response = $this->actingAs($this->admin(), 'admin')->from($preflight)
            ->post($this->adminUrl('admin.link-pool.import.store', ['site' => 'primary']), ['links' => $input]);
        $response->assertRedirect($form)->assertSessionHasErrors('ack_titles');
        $this->get($form)->assertOk()->assertSee($input)->assertSee('Original title')
            ->assertSee('确认导入有效行');
        $this->post($this->adminUrl('admin.link-pool.import.store', ['site' => 'primary']), [
            'links' => $input, 'ack_titles' => 1,
        ])->assertOk()->assertSee('导入完成');
        $this->assertDatabaseHas('link_pool_targets', ['display_title' => 'Custom title']);
    }

    public function test_campaign_and_settings_validation_preserve_user_input_and_conflict_revision(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $ids = $this->import('https://example.com/,External target');
        $this->actingAs($this->admin(), 'admin');
        $editor = $this->adminUrl('admin.link-pool.campaigns.create', ['site' => 'primary']);
        $this->from($editor)->post($this->adminUrl('admin.link-pool.campaigns.draft'), [
            'source_site_key' => 'primary', 'base_revision' => 0, 'name' => 'Keep draft',
            'mode' => 'manual', 'scope' => 'keyword', 'keyword' => '',
            'limit' => 4, 'priority' => 0, 'target_ids' => $ids, 'pinned_ids' => $ids,
        ])->assertSessionHasErrors();
        $page = $this->get($editor)->assertOk()->assertSee('Keep draft');
        $this->assertMatchesRegularExpression('/name="target_ids\[\]" value="'.$ids[0].'"\s+checked/', $page->getContent());
        $this->assertMatchesRegularExpression('/name="pinned_ids\[\]" value="'.$ids[0].'"\s+checked/', $page->getContent());
        $settings = $this->adminUrl('admin.link-pool.settings', ['site' => 'primary']);
        $this->from($settings)->post($this->adminUrl('admin.link-pool.settings.update'), [
            'site_key' => 'primary', 'revision' => 999, 'heading' => 'Keep heading',
            'default_limit' => 5, 'rotation_hours' => 36,
        ])->assertSessionHasErrors('revision');
        $page = $this->get($settings)->assertOk()->assertSee('Keep heading');
        $this->assertMatchesRegularExpression('/name="default_limit"[^>]+value="5"/', $page->getContent());
        $this->assertMatchesRegularExpression('/name="rotation_hours"[^>]+value="36"/', $page->getContent());
        $page->assertSee('name="revision" value="999"', false);
        $this->assertStringNotContainsString('name="enabled" value="1" checked', $page->getContent());
    }

    public function test_library_changes_refresh_empty_pool_and_related_rules_without_waiting_for_rotation(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Source', 'source');
        $source->update(['keywords' => '教育']);
        $this->publish('primary', [], 'related');
        $recommendations = app(LinkPoolRecommendationService::class);
        $recommendations->refresh('primary', (int) $source->id);
        $this->assertSame(0, DB::table('link_placements')->count());
        $ids = $this->import('https://example.com/,External,教育');
        $recommendations->refresh('primary', (int) $source->id);
        $this->assertCount(1, $recommendations->forSiteArticle('primary', $source)['items']);
        $this->actingAs($this->admin(), 'admin')->patch($this->adminUrl('admin.link-pool.targets.update', $ids[0]), [
            'display_title' => 'External', 'topic' => '汽车', 'priority' => 1, 'status' => 'active',
        ])->assertRedirect();
        $recommendations->refresh('primary', (int) $source->id);
        $this->assertNull($recommendations->forSiteArticle('primary', $source));
    }

    public function test_dashboard_drops_stale_edges_when_campaign_source_scope_changes(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $sourceA = $this->article('Source A', 'source-a');
        $sourceB = $this->article('Source B', 'source-b');
        $ids = $this->import('https://example.com/,External');
        $campaign = $this->publish('primary', $ids);
        $recommendations = app(LinkPoolRecommendationService::class);
        $recommendations->refresh('primary', (int) $sourceA->id);
        $service = app(LinkCampaignService::class);
        $service->saveDraft($campaign, ['source_site_key' => 'primary', 'name' => 'Updated scope',
            'mode' => 'manual', 'scope' => 'articles', 'source_article_ids' => [$sourceB->id],
            'target_ids' => $ids, 'base_revision' => 1, 'limit' => 4, 'priority' => 10], 1);
        $service->publish($campaign, 1);
        $recommendations->refresh('primary', (int) $sourceB->id);
        $this->assertNull($recommendations->forSiteArticle('primary', $sourceA));
        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('admin.link-pool.overview', ['site' => 'primary']))
            ->assertOk()->assertViewHas('summary', fn ($summary) => $summary['edge_count'] === 1 && $summary['source_count'] === 1);
    }

    public function test_queue_outage_keeps_public_article_available_and_refresh_can_retry(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Source', 'source');
        $ids = $this->import('https://example.com/,External');
        $this->publish('primary', $ids);
        $dispatcher = app(Dispatcher::class);
        $mock = $this->mock(Dispatcher::class);
        $mock->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
        Bus::swap($mock);
        $this->get('https://primary.test/article/source')->assertOk()->assertSee('Source')
            ->assertDontSee('href="https://example.com/"', false);
        Bus::swap($dispatcher);
        $this->get('https://primary.test/article/source')->assertOk()->assertSee('href="https://example.com/"', false);
    }

    public function test_draft_preview_automatically_selects_a_matching_article_without_creating_metrics(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Matching source', 'matching');
        $this->article('Other source', 'other');
        $ids = $this->import('https://example.com/,External');
        $service = app(LinkCampaignService::class);
        $campaign = $service->saveDraft(null, ['source_site_key' => 'primary', 'name' => 'Preview',
            'mode' => 'manual', 'scope' => 'articles', 'source_article_ids' => [$source->id],
            'target_ids' => $ids, 'base_revision' => 0, 'limit' => 4, 'priority' => 10], 1);
        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('admin.link-pool.preview', [
            'site' => 'primary', 'campaign_id' => $campaign, 'version' => 'draft',
        ]))->assertOk()->assertViewHas('articleId', (int) $source->id)
            ->assertSee('href="https://example.com/"', false)->assertSee('Matching source');
        $this->assertSame(0, DB::table('link_selection_heads')->count());
        $this->assertSame(0, DB::table('link_metrics_daily')->count());
    }

    public function test_unchanged_import_and_save_keep_the_current_recommendations(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Source', 'source');
        $input = 'https://example.com/,External';
        $ids = $this->import($input);
        $this->publish('primary', $ids);
        $recommendations = app(LinkPoolRecommendationService::class);
        $recommendations->refresh('primary', (int) $source->id);
        $revision = DB::table('site_settings')->where('setting_key', 'link_pool_library_revision')->value('setting_value');
        $this->import($input);
        $this->actingAs($this->admin(), 'admin')->patch($this->adminUrl('admin.link-pool.targets.update', $ids[0]), [
            'display_title' => 'External', 'topic' => '', 'priority' => 1, 'status' => 'active',
        ])->assertRedirect();
        $this->assertSame($revision, DB::table('site_settings')->where('setting_key', 'link_pool_library_revision')->value('setting_value'));
        $this->assertCount(1, $recommendations->forSiteArticle('primary', $source, [], false)['items']);
        $this->assertSame(1, DB::table('link_selection_batches')->count());
    }

    public function test_title_search_without_a_match_does_not_display_an_unrelated_sample(): void
    {
        $this->article('Other source', 'other');
        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('admin.link-pool.preview', [
            'site' => 'primary', 'article_q' => 'Unknown title 123',
        ]))->assertOk()->assertViewHas('articleId', 0)
            ->assertSee('未找到该标题的文章，请调整搜索词。')
            ->assertViewHas('articleChoices', fn ($choices) => $choices->isEmpty());
    }

    public function test_dashboard_reads_long_article_metadata_with_bounded_memory(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Source', 'source');
        $ids = $this->import('https://example.com/,External');
        $this->publish('primary', $ids);
        $recommendations = app(LinkPoolRecommendationService::class);
        $recommendations->refresh('primary', (int) $source->id);
        $head = DB::table('link_selection_heads')->first();
        $batch = (array) DB::table('link_selection_batches')->first();
        unset($batch['id']);
        $attributes = $source->getAttributes();
        unset($attributes['id']);
        $attributes['content'] = str_repeat('长文内容', 40000);
        for ($index = 0; $index < 60; $index++) {
            $id = DB::table('articles')->insertGetId([...$attributes, 'slug' => 'long-'.$index]);
            $batchId = DB::table('link_selection_batches')->insertGetId([...$batch, 'source_article_id' => $id]);
            DB::table('link_selection_heads')->insert([
                'source_site_key' => 'primary', 'source_article_id' => $id, 'batch_id' => $batchId, 'revision' => $head->revision,
            ]);
        }
        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $this->assertCount(61, $recommendations->currentBatchIds(['primary']));
        $this->assertLessThan(12 * 1024 * 1024, memory_get_peak_usage(true) - $before);

        memory_reset_peak_usage();
        $before = memory_get_usage(true);
        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('admin.link-pool.preview', ['site' => 'primary']))
            ->assertOk()->assertViewHas('articleChoices', fn ($choices) => $choices->count() === 61);
        $this->assertLessThan(12 * 1024 * 1024, memory_get_peak_usage(true) - $before);
    }

    public function test_spreadsheet_paste_accepts_tabs_and_preserves_csv_quoted_commas(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $imports = app(LinkPoolImportService::class);
        $rows = $imports->preflight("\xEF\xBB\xBFurl\ttitle\ttopic\nhttps://www.aichafen.com/\t爱查分\t教育\nhttps://example.com/product\t产品,说明\t教育");
        $this->assertSame(['new', 'new'], array_column($rows, 'status'));
        $this->assertSame('产品,说明', $rows[1]['title']);
        $this->assertSame('教育', $rows[1]['topic']);
        $this->assertSame(2, $imports->import($rows, 1, false)['new']);
        $csv = $imports->preflight("https://example.org/,\"标题,带逗号\",\"主题\t内容\"");
        $this->assertSame('new', $csv[0]['status']);
        $this->assertSame('标题,带逗号', $csv[0]['title']);
        $this->assertSame("主题\t内容", $csv[0]['topic']);
        $unquoted = $imports->preflight("https://example.org/,Product\tGuide", 'csv');
        $this->assertSame('https://example.org/', $unquoted[0]['canonical_url']);
        $this->assertSame("Product\tGuide", $unquoted[0]['title']);
        $this->assertSame('new', $imports->preflight("\nurl\ttitle\nhttps://example.net/\t示例")[0]['status']);
    }

    public function test_settings_json_save_returns_revision_and_rejects_stale_or_read_only_updates(): void
    {
        $this->actingAs($this->admin(), 'admin');
        $data = ['site_key' => 'primary', 'heading' => '为你推荐', 'default_limit' => 2, 'rotation_hours' => 12, 'enabled' => 0, 'revision' => 0];
        $url = $this->adminUrl('admin.link-pool.settings.update');
        $this->postJson($url, $data)->assertOk()->assertJsonPath('revision', 1);
        $this->assertSame('为你推荐', app(LinkPoolSiteService::class)->settings('primary')['heading']);
        $this->postJson($url, [...$data, 'heading' => 'Stale'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->actingAs($this->admin('admin'), 'admin')->postJson($url, [...$data, 'revision' => 1])->assertForbidden();
        $this->assertSame('为你推荐', app(LinkPoolSiteService::class)->settings('primary')['heading']);
    }

    public function test_campaign_choosers_and_preview_keep_human_readable_context(): void
    {
        $source = $this->article('Readable source', 'readable');
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $ids = $this->import('https://example.com/,External');
        $id = app(LinkCampaignService::class)->saveDraft(null, ['name' => 'Readable campaign', 'source_site_key' => 'primary',
            'mode' => 'manual', 'scope' => 'articles', 'source_article_ids' => [$source->id], 'target_ids' => $ids, 'limit' => 4, 'priority' => 10], 1);
        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('admin.link-pool.campaigns.edit', $id))
            ->assertOk()->assertSee('选择分类')->assertSee('选择投放文章')->assertSee('Readable source')->assertDontSee('来源文章 ID（逗号分隔）');
        $this->get($this->adminUrl('admin.link-pool.preview', ['site' => 'primary', 'campaign_id' => $id]))
            ->assertOk()->assertSee('Readable campaign')->assertSee('返回活动，继续配置')->assertSee('当前暂无公开推荐')
            ->assertDontSee('没有符合条件的目标');
        $this->get($this->adminUrl('admin.link-pool.settings', ['site' => 'primary', 'campaign_id' => $id]))
            ->assertOk()->assertSee('返回活动，继续发布');
    }

    public function test_source_choice_validation_preserves_legacy_lists_and_explicitly_cleared_choices(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Readable source', 'readable');
        $other = $this->article('Other source', 'other');
        $ids = $this->import('https://example.com/,External');
        $payload = ['source_site_key' => 'primary', 'name' => 'Choices', 'mode' => 'manual', 'scope' => 'articles',
            'source_article_ids' => [$source->id], 'exclusion_ids' => [$other->id], 'target_ids' => $ids, 'limit' => 4, 'priority' => 10];
        $id = app(LinkCampaignService::class)->saveDraft(null, $payload, 1);
        $edit = $this->adminUrl('admin.link-pool.campaigns.edit', $id);
        $save = $this->adminUrl('admin.link-pool.campaigns.draft');
        $this->actingAs($this->admin(), 'admin')->from($edit)->post($save, [...$payload, 'campaign_id' => $id,
            'source_article_ids' => $source->id.','.$other->id, 'exclusion_ids' => '', 'limit' => 9])->assertSessionHasErrors('campaign');
        $this->get($edit)->assertOk()->assertViewHas('payload', fn ($data) => $data['source_article_ids'] === [$source->id, $other->id] && $data['exclusion_ids'] === []);
        $this->from($edit)->post($save, ['campaign_id' => $id, 'source_site_key' => 'primary', 'name' => 'Choices',
            'scope' => 'articles', 'mode' => 'manual', 'target_ids' => $ids, 'limit' => 4])->assertSessionHasErrors('campaign');
        $this->get($edit)->assertOk()->assertViewHas('payload', fn ($data) => $data['source_article_ids'] === [] && $data['exclusion_ids'] === []);
    }

    public function test_hosted_settings_json_save_returns_the_new_profile_revision_and_preserves_other_settings(): void
    {
        $channel = DistributionChannel::query()->create(['name' => 'Hosted', 'domain' => 'settings.sites.test',
            'endpoint_url' => 'https://settings.sites.test', 'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE, 'site_settings' => ['site_name' => 'Keep site name']]);
        $profile = HostedSiteProfile::query()->create(['distribution_channel_id' => $channel->id,
            'hostname' => 'settings.sites.test', 'root_domain' => 'sites.test', 'serving_status' => HostedSiteProfile::SERVING_ONLINE,
            'indexing_status' => HostedSiteProfile::INDEXING_INDEX, 'quality_status' => HostedSiteProfile::QUALITY_PASSED]);
        $revision = (int) $profile->settings_version;
        $data = ['site_key' => 'hosted:'.$profile->id, 'heading' => '托管站精选', 'default_limit' => 3,
            'rotation_hours' => 12, 'enabled' => 0, 'revision' => $revision];
        $this->actingAs($this->admin(), 'admin')->postJson($this->adminUrl('admin.link-pool.settings.update'), $data)
            ->assertOk()->assertJsonPath('revision', $revision + 1);
        $this->assertSame($revision + 1, (int) $profile->fresh()->settings_version);
        $this->assertSame('托管站精选', $channel->fresh()->site_settings['article_recommendations']['heading']);
        $this->assertSame('Keep site name', $channel->fresh()->site_settings['site_name']);
    }

    public function test_draft_preview_without_an_activity_does_not_misrepresent_the_public_batch(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Source', 'source');
        $ids = $this->import('https://example.com/,External');
        $this->publish('primary', $ids);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('admin.link-pool.preview', ['site' => 'primary', 'version' => 'draft']))
            ->assertOk()->assertViewHas('block', null)->assertSee('请选择投放活动后查看草稿推荐。')->assertDontSee('href="https://example.com/"', false);
    }

    public function test_pausing_a_campaign_with_a_pending_draft_preserves_it_and_allows_resume(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $this->article('Source', 'source');
        $ids = $this->import('https://example.com/,External');
        $id = $this->publish('primary', $ids);
        $service = app(LinkCampaignService::class);
        $revision = (int) DB::table('link_campaigns')->where('id', $id)->value('revision');
        $service->saveDraft($id, ['source_site_key' => 'primary', 'name' => 'Keep pending changes', 'mode' => 'manual',
            'scope' => 'all', 'target_ids' => $ids, 'limit' => 2, 'base_revision' => $revision], 1);
        $service->pause($id);
        $draft = DB::table('link_campaign_drafts')->where('campaign_id', $id)->first();
        $payload = json_decode($draft->payload_json, true);
        $this->assertSame($revision + 1, (int) $draft->base_revision);
        $this->assertSame($revision + 1, $payload['base_revision']);
        $this->assertSame('Keep pending changes', $payload['name']);
        $service->pause($id);
        $this->assertSame($revision + 1, (int) DB::table('link_campaigns')->where('id', $id)->value('revision'));
        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('admin.link-pool.campaigns.edit', $id))
            ->assertOk()->assertSee('活动已暂停')->assertSee('Keep pending changes');
        $this->post($this->adminUrl('admin.link-pool.campaigns.publish', $id))->assertRedirect();
        $this->assertDatabaseHas('link_campaigns', ['id' => $id, 'status' => 'active', 'name' => 'Keep pending changes', 'limit' => 2]);
        $this->assertSame(0, DB::table('link_campaign_drafts')->where('campaign_id', $id)->count());
    }

    public function test_public_campaign_without_a_draft_is_not_shown_as_a_draft_simulation(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $source = $this->article('Source', 'source');
        $ids = $this->import('https://example.com/,External');
        $id = $this->publish('primary', $ids);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
        $this->actingAs($this->admin(), 'admin')->get($this->adminUrl('admin.link-pool.preview', [
            'site' => 'primary', 'campaign_id' => $id, 'version' => 'draft',
        ]))->assertOk()->assertViewHas('block', null)->assertSee('此活动没有待发布草稿')->assertDontSee('href="https://example.com/"', false);
        $this->assertSame([], app(LinkPoolRecommendationService::class)->previewDraft('primary', $source, $id)['items']);
    }

    public function test_ambiguous_paste_offers_one_click_format_retry_and_retains_it_for_import(): void
    {
        Queue::fake([CheckLinkPoolTargetUrl::class]);
        $input = "https://example.com/search?ids=1,2\tSearch results";
        $form = $this->adminUrl('admin.link-pool.import.form', ['site' => 'primary']);
        $preflight = $this->adminUrl('admin.link-pool.import.preflight', ['site' => 'primary']);
        $this->actingAs($this->admin(), 'admin')->from($form)->post($preflight, ['links' => $input])
            ->assertRedirect($form)->assertSessionHasErrors('delimiter');
        $this->get($form)->assertOk()->assertSee('按表格重新预检')->assertSee('按 CSV 重新预检')->assertSee($input);
        $this->post($preflight, ['links' => $input, 'delimiter' => 'tsv'])->assertOk()
            ->assertSee('可新增 1')->assertSee('name="delimiter" value="tsv"', false);
        $this->post($this->adminUrl('admin.link-pool.import.store'), ['links' => $input, 'delimiter' => 'tsv'])->assertOk();
        $this->assertDatabaseHas('link_pool_targets', ['input_url' => 'https://example.com/search?ids=1,2', 'display_title' => 'Search results']);
        $this->assertSame(1, DB::table('link_pool_targets')->count());
    }

    public function test_invalid_import_format_recovers_without_losing_link_input(): void
    {
        $input = 'https://example.com/,Example';
        $form = $this->adminUrl('admin.link-pool.import.form', ['site' => 'primary']);
        $this->actingAs($this->admin(), 'admin')->get($form.'&delimiter[]=tsv')->assertOk()
            ->assertViewHas('importDelimiter', null);
        $this->post($this->adminUrl('admin.link-pool.import.preflight', ['site' => 'primary']), [
            'links' => $input, 'delimiter' => ['tsv'],
        ])->assertRedirect($form)->assertSessionHasErrors('delimiter');
        $this->get($form)->assertOk()->assertViewHas('importDelimiter', null)->assertSee($input);
        $this->assertSame(0, DB::table('link_pool_targets')->count());
    }

    private function adminUrl(string $name, array|int $parameters = []): string
    {
        return 'https://primary.test'.route($name, $parameters, false);
    }

    private function import(string $input): array
    {
        $service = app(LinkPoolImportService::class);
        $service->import($service->preflight($input), 1, false);

        return DB::table('link_pool_targets')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function publish(string $site, array $targets, string $mode = 'manual'): int
    {
        $service = app(LinkCampaignService::class);
        $id = $service->saveDraft(null, ['source_site_key' => $site, 'name' => 'External '.$mode, 'mode' => $mode,
            'priority' => 10, 'limit' => 4, 'scope' => 'all', 'target_ids' => $targets, 'base_revision' => 0], 1);
        $service->publish($id, 1);

        return $id;
    }

    private function article(string $title, string $slug): Article
    {
        $task = Task::query()->create(['name' => $title.' task', 'status' => 'active', 'publish_scope' => 'local_and_distribution']);
        $category = Category::query()->firstOrCreate(['slug' => 'source'], ['name' => 'Source', 'description' => '', 'sort_order' => 0]);
        $author = Author::query()->firstOrCreate(['email' => 'external-test@example.test'], ['name' => 'Author']);

        return Article::query()->create(['title' => $title, 'slug' => $slug, 'content' => $title.' body', 'category_id' => $category->id,
            'author_id' => $author->id, 'task_id' => $task->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()]);
    }

    private function admin(string $role = 'super_admin'): Admin
    {
        return Admin::query()->create(['username' => 'external-'.$role, 'password' => 'Password123!',
            'email' => $role.'@external.test', 'role' => $role, 'status' => 'active']);
    }
}
