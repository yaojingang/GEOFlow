<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Services\GeoFlow\DistributionChannelDeletionConfirmation;
use App\Services\GeoFlow\DistributionChannelDeletionService;
use App\Services\LinkPool\LinkCampaignService;
use App\Services\LinkPool\LinkPoolImportService;
use App\Services\Site\LinkPoolMetricsService;
use App\Services\Site\LinkPoolRecommendationService;
use App\Services\Site\LinkPoolSiteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LinkPoolFeatureTest extends TestCase
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
        config()->set('queue.default', 'sync');
        SiteSetting::query()->updateOrCreate(['setting_key' => 'article_recommendations'], [
            'setting_value' => json_encode(['enabled' => true, 'heading' => '推荐内容', 'default_limit' => 4, 'rotation_hours' => 24]),
        ]);
    }

    public function test_import_publish_html_metrics_and_withdrawal_on_primary(): void
    {
        $source = $this->article('Source article', 'source', 'local_and_distribution', 'published', 'source-category');
        $target = $this->article('Target article', 'target', 'local_and_distribution', 'published', 'target-category');
        $imports = app(LinkPoolImportService::class);
        $rows = $imports->preflight("url,title\nhttps://primary.test/article/target,Target article");
        $this->assertSame('new', $rows[0]['status']);
        $imported = $imports->import($rows, 1, true);
        $this->assertSame(['new' => 1, 'update' => 0, 'duplicate' => 0, 'error' => 0],
            collect($imported)->only(['new', 'update', 'duplicate', 'error'])->all());
        $targetId = (int) DB::table('link_pool_targets')->value('id');
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $this->campaign('primary', [$targetId]), 1);
        app(LinkCampaignService::class)->publish($campaignId, 1);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $this->assertNotNull(app(LinkPoolRecommendationService::class)->forSiteArticle('primary', $source));
        $this->assertLessThanOrEqual(16, count(DB::connection()->getQueryLog()));
        DB::connection()->disableQueryLog();

        $response = $this->get('https://primary.test/article/source')->assertOk()
            ->assertSee('推荐内容')->assertSee('href="https://primary.test/article/target"', false)
            ->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame(1, (int) DB::table('link_metrics_daily')->sum('html_served'));
        $deliveryId = (string) Str::uuid();
        $placementId = (int) DB::table('link_placements')->value('id');
        app(LinkPoolMetricsService::class)->recordHtmlServed([$placementId], $deliveryId);
        app(LinkPoolMetricsService::class)->recordHtmlServed([$placementId], $deliveryId);
        $this->assertSame(2, (int) DB::table('link_metrics_daily')->sum('html_served'));
        $this->assertSame($source->updated_at->toDateTimeString(), $source->fresh()->updated_at->toDateTimeString());
        DB::table('link_pool_targets')->where('id', $targetId)->update(['status' => 'paused']);
        $withoutRecommendations = $this->get('https://primary.test/article/source')->assertOk()
            ->assertDontSee('href="https://primary.test/article/target"', false);
        $this->assertStringNotContainsString('no-store', $withoutRecommendations->headers->get('Cache-Control', ''));
        $target->update(['status' => 'draft']);
        $this->assertNull(app(LinkPoolSiteService::class)->article('primary', (int) $target->id));
    }

    public function test_hosted_private_assignment_can_be_recommended_then_noindex_hides_it(): void
    {
        $profile = $this->hosted('alpha');
        $source = $this->article('Hosted source', 'hosted-source', 'distribution_only', 'private', 'hosted-source-category');
        $target = $this->article('Hosted target', 'hosted-target', 'distribution_only', 'private', 'hosted-target-category');
        $this->assign($profile, $source);
        $this->assign($profile, $target);
        $siteKey = 'hosted:'.$profile->id;
        $rows = app(LinkPoolImportService::class)->preflight('https://alpha.sites.test/article/hosted-target,Hosted target');
        $this->assertSame($siteKey, $rows[0]['site_key']);
        app(LinkPoolImportService::class)->import($rows, 1, true);
        $targetId = (int) DB::table('link_pool_targets')->value('id');
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $this->campaign($siteKey, [$targetId]), 1);
        app(LinkCampaignService::class)->publish($campaignId, 1);
        app(LinkPoolRecommendationService::class)->refresh($siteKey, (int) $source->id);

        $this->get('https://alpha.sites.test/article/hosted-source')->assertOk()
            ->assertSee('href="https://alpha.sites.test/article/hosted-target"', false)
            ->assertSee('/assets/css/link-pool.css', false)
            ->assertSee('/js/link-pool.js', false);
        $this->get('https://alpha.sites.test/assets/css/link-pool.css')->assertOk();
        $this->get('https://alpha.sites.test/js/link-pool.js')->assertOk();
        $placementId = (int) DB::table('link_placements')->value('id');
        $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome/128.0')
            ->postJson('https://alpha.sites.test/link-pool/events', [
                'placement_id' => $placementId, 'event_uuid' => (string) Str::uuid(), 'event_type' => 'viewable',
            ])->assertOk()->assertJsonPath('accepted', true);
        $servedBeforeDeleting = (int) DB::table('link_metrics_daily')->sum('html_served');
        $profile->channel->update(['status' => DistributionChannel::STATUS_DELETING]);
        app(LinkPoolMetricsService::class)->recordHtmlServed([$placementId], (string) Str::uuid());
        $this->assertSame($servedBeforeDeleting, (int) DB::table('link_metrics_daily')->sum('html_served'));
        $profile->channel->update(['status' => DistributionChannel::STATUS_ACTIVE]);
        $beforeVersion = (int) $profile->settings_version;
        $admin = Admin::query()->create(['username' => 'site-setting-admin', 'password' => 'Password123!', 'email' => 'site-setting-admin@example.test', 'role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($admin, 'admin')->post('https://primary.test'.route('admin.link-pool.settings.update', [], false), [
            'site_key' => $siteKey, 'heading' => '推荐内容', 'default_limit' => 4, 'rotation_hours' => 24,
            'revision' => $beforeVersion,
        ])->assertRedirect();
        $this->assertSame($beforeVersion + 1, (int) $profile->fresh()->settings_version);
        $this->post('https://primary.test'.route('admin.link-pool.settings.update', [], false), [
            'site_key' => $siteKey, 'heading' => '过期配置', 'default_limit' => 4, 'rotation_hours' => 24,
            'revision' => $beforeVersion,
        ])->assertSessionHasErrors('revision');
        $this->assertSame($beforeVersion + 1, (int) $profile->fresh()->settings_version);
        $this->get('https://alpha.sites.test/article/hosted-source')->assertOk()
            ->assertDontSee('href="https://alpha.sites.test/article/hosted-target"', false);
        $settings = $profile->channel->fresh()->site_settings;
        $settings['article_recommendations']['enabled'] = true;
        $profile->channel->fresh()->update(['site_settings' => $settings]);
        $profile->update(['indexing_status' => HostedSiteProfile::INDEXING_NOINDEX]);
        $this->get('https://alpha.sites.test/article/hosted-source')->assertOk()
            ->assertDontSee('href="https://alpha.sites.test/article/hosted-target"', false)
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_cross_site_requires_manual_reason_review_and_rechecks_target_site(): void
    {
        $source = $this->article('Primary source', 'primary-source', 'local_and_distribution', 'published', 'primary-category');
        $profile = $this->hosted('beta');
        $target = $this->article('Hosted cross target', 'cross-target', 'distribution_only', 'private', 'cross-category');
        $this->assign($profile, $target);
        $rows = app(LinkPoolImportService::class)->preflight('https://beta.sites.test/article/cross-target,Hosted cross target');
        app(LinkPoolImportService::class)->import($rows, 1, true);
        $targetId = (int) DB::table('link_pool_targets')->value('id');
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $this->campaign('primary', [$targetId]), 1);
        try {
            app(LinkCampaignService::class)->publish($campaignId, 1);
            $this->fail('Expected cross-site review gate.');
        } catch (ValidationException) {
            $this->assertDatabaseMissing('link_campaign_targets', ['campaign_id' => $campaignId]);
        }
        $approved = $this->campaign('primary', [$targetId]);
        $approved['approved_cross_site_ids'] = [$targetId];
        $approved['review_reasons'] = [$targetId => '相关专题的原始解释文章'];
        app(LinkCampaignService::class)->saveDraft($campaignId, $approved, 1);
        app(LinkCampaignService::class)->publish($campaignId, 1);
        $changedReason = [...$approved, 'base_revision' => 1, 'review_reasons' => [$targetId => '更新后的编辑理由']];
        app(LinkCampaignService::class)->saveDraft($campaignId, $changedReason, 1);
        $pendingReview = json_decode((string) DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->value('payload_json'), true);
        $this->assertSame([], $pendingReview['approved_cross_site_ids']);
        app(LinkCampaignService::class)->saveDraft($campaignId, $changedReason, 1);
        $reapproved = json_decode((string) DB::table('link_campaign_drafts')->where('campaign_id', $campaignId)->value('payload_json'), true);
        $this->assertSame([$targetId], $reapproved['approved_cross_site_ids']);
        $copiedSite = $this->hosted('gamma');
        $copyId = app(LinkCampaignService::class)->copyToSite($campaignId, 'hosted:'.$copiedSite->id, 1);
        $this->assertSame('draft', DB::table('link_campaigns')->where('id', $copyId)->value('status'));
        $copiedPayload = json_decode((string) DB::table('link_campaign_drafts')->where('campaign_id', $copyId)->value('payload_json'), true);
        $this->assertSame([], $copiedPayload['approved_cross_site_ids']);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
        $this->get('https://primary.test/article/primary-source')->assertOk()->assertSee('https://beta.sites.test/article/cross-target', false);
        $profile->update(['hostname' => 'beta-new.sites.test']);
        $this->get('https://primary.test/article/primary-source')->assertOk()
            ->assertSee('https://beta-new.sites.test/article/cross-target', false)
            ->assertDontSee('https://beta.sites.test/article/cross-target', false);
        $profile->update(['serving_status' => HostedSiteProfile::SERVING_ARCHIVED]);
        $this->get('https://primary.test/article/primary-source')->assertOk()->assertDontSee('https://beta.sites.test/article/cross-target', false);
    }

    public function test_draft_publish_conflict_and_read_only_admin_write_boundary(): void
    {
        $target = $this->article('Target', 'target-2', 'local_and_distribution', 'published', 'target-2-category');
        $this->article('Source', 'source-2', 'local_and_distribution', 'published', 'source-2-category');
        $rows = app(LinkPoolImportService::class)->preflight('https://primary.test/article/target-2,Target');
        app(LinkPoolImportService::class)->import($rows, 1, true);
        $targetId = (int) DB::table('link_pool_targets')->value('id');
        $service = app(LinkCampaignService::class);
        $campaignId = $service->saveDraft(null, $this->campaign('primary', [$targetId]), 1);
        $service->publish($campaignId, 1);
        try {
            $service->saveDraft($campaignId, $this->campaign('primary', [$targetId]), 1);
            $this->fail('Expected stale revision to be rejected.');
        } catch (ValidationException) {
            $this->assertSame(1, (int) DB::table('link_campaigns')->where('id', $campaignId)->value('revision'));
        }
        $admin = Admin::query()->create(['username' => 'readonly', 'password' => 'Password123!', 'email' => 'readonly@example.test', 'role' => 'admin', 'status' => 'active']);
        $this->actingAs($admin, 'admin')->get('https://primary.test'.route('admin.link-pool.overview', [], false))->assertOk()
            ->assertSee('/assets/css/admin-link-pool.css', false);
        foreach (['targets', 'campaigns.index', 'dashboard', 'settings', 'import.form', 'preview'] as $page) {
            $this->get('https://primary.test'.route('admin.link-pool.'.$page, [], false))->assertOk();
        }
        $this->actingAs($admin, 'admin')->post('https://primary.test'.route('admin.link-pool.emergency', [], false), ['disabled' => '1'])->assertForbidden();
        $super = Admin::query()->create(['username' => 'super-link-pool', 'password' => 'Password123!', 'email' => 'super-link@example.test', 'role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($super, 'admin')->get('https://primary.test'.route('admin.link-pool.campaigns.edit', $campaignId, false))->assertOk();
        $this->get('https://primary.test'.route('admin.link-pool.import.form', ['site' => 'primary'], false))
            ->assertOk()->assertSee('data-link-pool-upload-zone', false)
            ->assertSee('admin-link-pool-import.js', false)
            ->assertSee('导入目标站点按 CSV 中的文章或外部网页 URL 自动识别。')
            ->assertSee('value="primary" selected', false);
        $this->get('https://primary.test'.route('admin.link-pool.targets', [
            'site' => 'primary', 'target_site' => 'primary', 'status' => 'active', 'q' => 'Target',
        ], false))->assertOk()->assertSee('近 7 天点击')->assertSee('Target');
        $this->get('https://primary.test'.route('admin.link-pool.campaigns.index', [
            'site' => 'primary', 'status' => 'active', 'q' => 'Test',
        ], false))->assertOk()->assertSee('每页上限');
        $this->get('https://primary.test'.route('admin.link-pool.dashboard', ['site' => 'primary'], false))
            ->assertOk()->assertSee('按日趋势图');
        $this->get('https://primary.test'.route('admin.link-pool.campaigns.create', ['site' => 'primary'], false))->assertOk();
        $this->post('https://primary.test'.route('admin.link-pool.import.preflight', [], false), [
            'links' => 'https://primary.test/article/target-2,Target',
        ])->assertOk()->assertSee('预检结果');
        $this->assertNotNull($target);
    }

    public function test_event_endpoint_accepts_current_human_placement_and_rejects_stale_or_bot_events(): void
    {
        $source = $this->article('Event source', 'event-source', 'local_and_distribution', 'published', 'event-source-category');
        $this->article('Event target', 'event-target', 'local_and_distribution', 'published', 'event-target-category');
        $rows = app(LinkPoolImportService::class)->preflight('https://primary.test/article/event-target,Event target');
        app(LinkPoolImportService::class)->import($rows, 1, true);
        $id = (int) DB::table('link_pool_targets')->value('id');
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $this->campaign('primary', [$id]), 1);
        app(LinkCampaignService::class)->publish($campaignId, 1);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
        $placementId = (int) DB::table('link_placements')->value('id');

        $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome/128.0')
            ->postJson('https://primary.test/link-pool/events', [
                'placement_id' => $placementId, 'event_uuid' => (string) Str::uuid(), 'event_type' => 'viewable',
            ])->assertOk()->assertJsonPath('accepted', true);
        $this->assertSame(1, (int) DB::table('link_metrics_daily')->sum('viewable'));
        $this->withHeader('User-Agent', 'Googlebot')
            ->postJson('https://primary.test/link-pool/events', [
                'placement_id' => $placementId, 'event_uuid' => (string) Str::uuid(), 'event_type' => 'click',
            ])->assertOk()->assertJsonPath('accepted', false);
        $source->update(['status' => 'draft']);
        $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome/128.0')
            ->postJson('https://primary.test/link-pool/events', [
                'placement_id' => $placementId, 'event_uuid' => (string) Str::uuid(), 'event_type' => 'click',
            ])->assertOk()->assertJsonPath('accepted', false);
        $source->update(['status' => 'published']);
        DB::table('link_selection_heads')->where('source_site_key', 'primary')->delete();
        $this->withHeader('User-Agent', 'Mozilla/5.0 Chrome/128.0')
            ->postJson('https://primary.test/link-pool/events', [
                'placement_id' => $placementId, 'event_uuid' => (string) Str::uuid(), 'event_type' => 'click',
            ])->assertOk()->assertJsonPath('accepted', false);
    }

    public function test_running_campaign_draft_preview_does_not_change_public_batch(): void
    {
        $source = $this->article('Preview source', 'preview-source', 'local_and_distribution', 'published', 'preview-source-category');
        $this->article('First target', 'first-target', 'local_and_distribution', 'published', 'first-target-category');
        $this->article('Second target', 'second-target', 'local_and_distribution', 'published', 'second-target-category');
        $imports = app(LinkPoolImportService::class);
        $imports->import($imports->preflight("https://primary.test/article/first-target,First target\nhttps://primary.test/article/second-target,Second target"), 1, true);
        $first = (int) DB::table('link_pool_targets')->where('article_id', '<>', $source->id)->orderBy('id')->value('id');
        $second = (int) DB::table('link_pool_targets')->orderByDesc('id')->value('id');
        $service = app(LinkCampaignService::class);
        $campaignId = $service->saveDraft(null, $this->campaign('primary', [$first]), 1);
        $service->publish($campaignId, 1);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
        $newDraft = $this->campaign('primary', [$second]);
        $newDraft['base_revision'] = 1;
        $service->saveDraft($campaignId, $newDraft, 1);
        $preview = app(LinkPoolRecommendationService::class)->previewDraft('primary', $source, $campaignId);
        $this->assertSame('Second target', $preview['items'][0]['title']);
        $this->assertSame($campaignId, $preview['items'][0]['campaign_id']);
        $this->get('https://primary.test/article/preview-source')->assertOk()
            ->assertSee('href="https://primary.test/article/first-target"', false)
            ->assertDontSee('href="https://primary.test/article/second-target"', false);
    }

    public function test_hosted_channel_deletion_includes_link_pool_impact_and_cleans_cross_site_edges(): void
    {
        $this->article('Source before delete', 'source-delete', 'local_and_distribution', 'published', 'source-delete-category');
        $profile = $this->hosted('delete');
        $target = $this->article('Target before delete', 'target-delete', 'distribution_only', 'private', 'target-delete-category');
        $this->assign($profile, $target);
        $imports = app(LinkPoolImportService::class);
        $imports->import($imports->preflight('https://delete.sites.test/article/target-delete,Target before delete'), 1, true);
        $targetId = (int) DB::table('link_pool_targets')->value('id');
        $profile->update(['serving_status' => HostedSiteProfile::SERVING_ARCHIVED]);
        $service = app(DistributionChannelDeletionService::class);
        $channel = $profile->channel;
        $service->prepare($channel);
        $impact = $service->inspect($channel->fresh());
        $this->assertContains($targetId, $impact['link_pool']['target_ids']);
        $admin = Admin::query()->create(['username' => 'delete-admin', 'password' => 'Password123!', 'email' => 'delete-admin@example.test', 'role' => 'super_admin', 'status' => 'active']);
        $service->delete($channel, $admin, new DistributionChannelDeletionConfirmation(
            $impact['impact_fingerprint'], true, true, true, true,
        ));
        $this->assertDatabaseMissing('link_pool_targets', ['id' => $targetId]);
    }

    public function test_batch_refresh_keeps_stable_pointer_until_rotation(): void
    {
        $source = $this->article('Random source', 'random-source', 'local_and_distribution', 'published', 'random-source-category');
        $this->article('Random target A', 'random-target-a', 'local_and_distribution', 'published', 'random-target-a-category');
        $this->article('Random target B', 'random-target-b', 'local_and_distribution', 'published', 'random-target-b-category');
        $imports = app(LinkPoolImportService::class);
        $imports->import($imports->preflight("https://primary.test/article/random-target-a,Random target A\nhttps://primary.test/article/random-target-b,Random target B"), 1, true);
        $draft = $this->campaign('primary', []);
        $draft['mode'] = 'random';
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $draft, 1);
        app(LinkCampaignService::class)->publish($campaignId, 1);
        $recommendations = app(LinkPoolRecommendationService::class);
        $recommendations->refresh('primary', (int) $source->id);
        $first = DB::table('link_selection_heads')->where('source_article_id', $source->id)->first();
        $this->assertSame(1, (int) $first->revision);
        $recommendations->refresh('primary', (int) $source->id);
        $this->assertSame(1, DB::table('link_selection_batches')->count());
        DB::table('link_selection_batches')->where('id', $first->batch_id)->update(['rotate_after' => now()->subMinute()]);
        $currentPreview = $recommendations->preview('primary', $source);
        $this->assertSame((int) $first->batch_id, $currentPreview['batch_id']);
        $this->assertCount(
            DB::table('link_placements')->where('batch_id', $first->batch_id)->count(),
            $currentPreview['items'],
        );
        $this->assertSame(1, DB::table('link_selection_batches')->count());
        $recommendations->refresh('primary', (int) $source->id);
        $this->assertSame(2, DB::table('link_selection_batches')->count());
        $this->assertSame(2, (int) DB::table('link_selection_heads')->where('source_article_id', $source->id)->value('revision'));
    }

    public function test_empty_pool_omits_module_and_source_withdrawal_removes_article(): void
    {
        $source = $this->article('Empty source', 'empty-source', 'local_and_distribution', 'published', 'empty-source-category');
        $this->get('https://primary.test/article/empty-source')->assertOk()->assertDontSee('data-link-pool', false);
        $source->update(['status' => 'draft']);
        $this->get('https://primary.test/article/empty-source')->assertNotFound();
    }

    public function test_future_target_expiry_allows_publish_and_expired_target_blocks_it(): void
    {
        $this->article('Expiring target', 'expiring-target', 'local_and_distribution', 'published', 'expiry-target-category');
        $imports = app(LinkPoolImportService::class);
        $futureDate = now()->addDays(7)->toDateString();
        $rows = $imports->preflight("https://primary.test/article/expiring-target,Expiring target,,,{$futureDate}");
        $this->assertSame('new', $rows[0]['status']);
        $imports->import($rows, 1, true);
        $targetId = (int) DB::table('link_pool_targets')->value('id');
        $campaigns = app(LinkCampaignService::class);
        $campaignId = $campaigns->saveDraft(null, $this->campaign('primary', [$targetId]), 1);
        $campaigns->publish($campaignId, 1);
        $this->assertSame('active', DB::table('link_campaigns')->where('id', $campaignId)->value('status'));

        DB::table('link_pool_targets')->where('id', $targetId)->update(['expires_at' => now()->subMinute()]);
        $draft = $this->campaign('primary', [$targetId]);
        $draft['base_revision'] = 1;
        $campaigns->saveDraft($campaignId, $draft, 1);
        $this->expectException(ValidationException::class);
        $campaigns->publish($campaignId, 1);
    }

    public function test_related_mode_breaks_equal_scores_by_newer_publication_then_article_id(): void
    {
        $source = $this->article('Ranking source', 'ranking-source', 'local_and_distribution', 'published', 'ranking-source-category');
        $older = $this->article('Older target', 'ranking-older', 'local_and_distribution', 'published', 'ranking-older-category');
        $newer = $this->article('Newer target', 'ranking-newer', 'local_and_distribution', 'published', 'ranking-newer-category');
        $source->update(['keywords' => 'shared']);
        $older->update(['keywords' => 'shared', 'published_at' => now()->subDays(5)]);
        $newer->update(['keywords' => 'shared', 'published_at' => now()->subDay()]);
        $imports = app(LinkPoolImportService::class);
        $imports->import($imports->preflight("https://primary.test/article/ranking-older,Older target\nhttps://primary.test/article/ranking-newer,Newer target"), 1, true);
        $targetIds = DB::table('link_pool_targets')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $draft = $this->campaign('primary', $targetIds);
        $draft['mode'] = 'related';
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $draft, 1);
        app(LinkCampaignService::class)->publish($campaignId, 1);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);

        $this->assertSame([(int) $newer->id, (int) $older->id], DB::table('link_placements')
            ->orderBy('slot')->pluck('target_article_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_mixed_mode_preserves_editorial_pin_order_before_relevance(): void
    {
        $source = $this->article('Mixed source', 'mixed-source', 'local_and_distribution', 'published', 'mixed-source-category');
        $low = $this->article('Pinned first', 'mixed-low', 'local_and_distribution', 'published', 'mixed-low-category');
        $high = $this->article('Pinned second', 'mixed-high', 'local_and_distribution', 'published', 'mixed-high-category');
        $source->update(['keywords' => 'shared']);
        $high->update(['keywords' => 'shared']);
        $imports = app(LinkPoolImportService::class);
        $imports->import($imports->preflight("https://primary.test/article/mixed-low,Pinned first\nhttps://primary.test/article/mixed-high,Pinned second"), 1, true);
        $targetIds = DB::table('link_pool_targets')->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $draft = $this->campaign('primary', $targetIds);
        $draft['mode'] = 'mixed';
        $draft['pinned_ids'] = $targetIds;
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $draft, 1);
        app(LinkCampaignService::class)->publish($campaignId, 1);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);

        $this->assertSame([(int) $low->id, (int) $high->id], DB::table('link_placements')
            ->orderBy('slot')->pluck('target_article_id')->map(fn ($id): int => (int) $id)->all());
    }

    public function test_rule_mode_can_reach_valid_targets_after_two_thousand_stale_rows(): void
    {
        $source = $this->article('Large pool source', 'large-pool-source', 'local_and_distribution', 'published', 'large-pool-source-category');
        $target = $this->article('Zeta large pool target', 'large-pool-target', 'local_and_distribution', 'published', 'large-pool-target-category');
        foreach (array_chunk(range(100000, 101999), 500) as $ids) {
            DB::table('link_pool_targets')->insert(array_map(static fn (int $id): array => [
                'target_site_key' => 'primary', 'article_id' => $id,
                'input_url' => 'https://primary.test/article/stale-'.$id,
                'input_title' => 'Stale '.$id, 'display_title' => 'Stale '.$id,
                'topic' => '', 'priority' => 1, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ], $ids));
        }
        $imports = app(LinkPoolImportService::class);
        $imports->import($imports->preflight('https://primary.test/article/large-pool-target,Zeta large pool target'), 1, true);
        $targetId = (int) DB::table('link_pool_targets')->where('article_id', $target->id)->value('id');
        $admin = Admin::query()->create([
            'username' => 'large-pool-admin', 'password' => 'Password123!',
            'email' => 'large-pool@example.test', 'role' => 'super_admin', 'status' => 'active',
        ]);
        $this->actingAs($admin, 'admin')->get('https://primary.test'.route('admin.link-pool.campaigns.create', [
            'site' => 'primary', 'target_q' => 'Zeta large pool target',
        ], false))->assertOk()->assertSee('value="'.$targetId.'"', false);
        $manualId = app(LinkCampaignService::class)->saveDraft(null, $this->campaign('primary', [$targetId]), (int) $admin->id);
        $this->get('https://primary.test'.route('admin.link-pool.campaigns.edit', [
            'campaignId' => $manualId, 'target_q' => 'no-such-target',
        ], false))->assertOk()->assertSee('value="'.$targetId.'"', false);
        $draft = $this->campaign('primary', []);
        $draft['mode'] = 'random';
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $draft, 1);
        app(LinkCampaignService::class)->publish($campaignId, 1);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
        $this->assertSame((int) $target->id, (int) DB::table('link_placements')->value('target_article_id'));
    }

    public function test_dashboard_effective_edges_follow_public_target_withdrawal(): void
    {
        $source = $this->article('Dashboard source', 'dashboard-source', 'local_and_distribution', 'published', 'dashboard-source-category');
        $target = $this->article('Dashboard target', 'dashboard-target', 'local_and_distribution', 'published', 'dashboard-target-category');
        $imports = app(LinkPoolImportService::class);
        $imports->import($imports->preflight('https://primary.test/article/dashboard-target,Dashboard target'), 1, true);
        $targetId = (int) DB::table('link_pool_targets')->value('id');
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $this->campaign('primary', [$targetId]), 1);
        app(LinkCampaignService::class)->publish($campaignId, 1);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);
        $admin = Admin::query()->create(['username' => 'link-dashboard-admin', 'password' => 'Password123!', 'email' => 'link-dashboard@example.test', 'role' => 'super_admin', 'status' => 'active']);
        $url = 'https://primary.test'.route('admin.link-pool.dashboard', ['site' => 'primary'], false);
        $this->actingAs($admin, 'admin')->get($url)->assertOk()->assertViewHas('edgeCount', 1)->assertViewHas('zeroInbound', 0);
        $target->update(['status' => 'draft']);
        $this->get('https://primary.test/article/dashboard-source')->assertOk()
            ->assertDontSee('href="https://primary.test/article/dashboard-target"', false);
        $this->get($url)->assertOk()->assertViewHas('edgeCount', 0)->assertViewHas('zeroInbound', 0)
            ->assertViewHas('targetRows', fn ($rows) => $rows->isEmpty());
        $this->get('https://primary.test'.route('admin.link-pool.overview', ['site' => 'primary'], false))
            ->assertOk()->assertViewHas('summary', fn ($summary) => $summary['target_count'] === 0 && $summary['zero_inbound'] === 0);
    }

    public function test_hosted_dashboard_drops_edge_when_published_assignment_is_withdrawn(): void
    {
        $profile = $this->hosted('graph-hosted');
        $source = $this->article('Hosted graph source', 'graph-source', 'distribution_only', 'private', 'graph-source-category');
        $target = $this->article('Hosted graph target', 'graph-target', 'distribution_only', 'private', 'graph-target-category');
        $this->assign($profile, $source);
        $this->assign($profile, $target);
        $siteKey = 'hosted:'.$profile->id;
        $imports = app(LinkPoolImportService::class);
        $imports->import($imports->preflight('https://graph-hosted.sites.test/article/graph-target,Hosted graph target'), 1, true);
        $targetId = (int) DB::table('link_pool_targets')->value('id');
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $this->campaign($siteKey, [$targetId]), 1);
        app(LinkCampaignService::class)->publish($campaignId, 1);
        app(LinkPoolRecommendationService::class)->refresh($siteKey, (int) $source->id);
        $admin = Admin::query()->create(['username' => 'hosted-graph-admin', 'password' => 'Password123!', 'email' => 'hosted-graph@example.test', 'role' => 'super_admin', 'status' => 'active']);
        $url = 'https://primary.test'.route('admin.link-pool.dashboard', ['site' => $siteKey], false);
        $this->actingAs($admin, 'admin')->get($url)->assertOk()->assertViewHas('edgeCount', 1);
        HostedSiteArticleAssignment::query()->where('article_id', $target->id)->update([
            'status' => HostedSiteArticleAssignment::STATUS_WITHDRAWN,
        ]);
        $this->get('https://graph-hosted.sites.test/article/graph-source')->assertOk()
            ->assertDontSee('href="https://graph-hosted.sites.test/article/graph-target"', false);
        $this->get($url)->assertOk()->assertViewHas('edgeCount', 0)->assertViewHas('zeroInbound', 0);
    }

    public function test_unadapted_theme_cannot_enable_recommendations_or_publish(): void
    {
        $themeId = 'link-pool-test-'.bin2hex(random_bytes(6));
        $directory = resource_path('views/theme/'.$themeId);
        mkdir($directory, 0700);
        file_put_contents($directory.'/article.blade.php', '<article>Theme without recommendations</article>');
        try {
            SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $themeId]);
            SiteSetting::query()->where('setting_key', 'article_recommendations')->update([
                'setting_value' => json_encode(['enabled' => false, 'revision' => 0]),
            ]);
            $this->assertFalse(app(LinkPoolSiteService::class)->themeSupportsRecommendations('primary'));

            $admin = Admin::query()->create([
                'username' => 'theme-guard-admin', 'password' => 'Password123!',
                'email' => 'theme-guard@example.test', 'role' => 'super_admin', 'status' => 'active',
            ]);
            $this->actingAs($admin, 'admin')->post('https://primary.test'.route('admin.link-pool.settings.update', [], false), [
                'site_key' => 'primary', 'heading' => '推荐内容', 'default_limit' => 4,
                'rotation_hours' => 24, 'revision' => 0, 'enabled' => '1',
            ])->assertSessionHasErrors('enabled');
            $this->assertFalse(app(LinkPoolSiteService::class)->settings('primary')['enabled']);
        } finally {
            unlink($directory.'/article.blade.php');
            rmdir($directory);
        }
    }

    public function test_primary_settings_reject_stale_revision(): void
    {
        $admin = Admin::query()->create([
            'username' => 'settings-version-admin', 'password' => 'Password123!',
            'email' => 'settings-version@example.test', 'role' => 'super_admin', 'status' => 'active',
        ]);
        $url = 'https://primary.test'.route('admin.link-pool.settings.update', [], false);
        $payload = [
            'site_key' => 'primary', 'heading' => '精选阅读', 'default_limit' => 4,
            'rotation_hours' => 24, 'revision' => 0, 'enabled' => '1',
        ];
        $this->actingAs($admin, 'admin')->post($url, $payload)->assertRedirect();
        $this->assertSame(1, app(LinkPoolSiteService::class)->settings('primary')['revision']);
        $this->post($url, [...$payload, 'heading' => '过期标题'])->assertSessionHasErrors('revision');
        $this->assertSame('精选阅读', app(LinkPoolSiteService::class)->settings('primary')['heading']);
    }

    public function test_history_pruning_keeps_the_current_batch(): void
    {
        $old = now()->subDays(91);
        $batch = static fn (string $site, int $articleId): int => DB::table('link_selection_batches')->insertGetId([
            'source_site_key' => $site, 'source_article_id' => $articleId,
            'rule_fingerprint' => str_repeat('a', 64), 'sequence' => 1,
            'generated_at' => $old, 'rotate_after' => $old,
        ]);
        $expiredBatch = $batch('primary', 1);
        $currentBatch = $batch('primary', 2);
        DB::table('link_selection_heads')->insert([
            'source_site_key' => 'primary', 'source_article_id' => 2,
            'batch_id' => $currentBatch, 'revision' => 1,
        ]);
        DB::table('link_placements')->insert([
            'batch_id' => $expiredBatch, 'source_site_key' => 'primary', 'source_article_id' => 1,
            'target_site_key' => 'primary', 'target_article_id' => 3, 'campaign_id' => 1,
            'slot' => 1, 'reason_code' => 'manual', 'score' => 1, 'selected_at' => $old,
        ]);
        DB::table('link_pool_events')->insert([
            'event_uuid' => (string) Str::uuid(), 'event_type' => 'click',
            'batch_id' => $expiredBatch, 'placement_id' => 1,
            'source_site_key' => 'primary', 'target_site_key' => 'primary',
            'source_article_id' => 1, 'target_article_id' => 3, 'campaign_id' => 1,
            'created_at' => $old,
        ]);
        DB::table('link_metrics_daily')->insert([
            'metric_key' => str_repeat('b', 64), 'date' => $old->toDateString(),
            'source_site_key' => 'primary', 'target_site_key' => 'primary',
            'source_article_id' => 1, 'target_article_id' => 3, 'campaign_id' => 1,
            'batch_id' => $expiredBatch, 'html_served' => 1, 'viewable' => 0, 'human_clicks' => 0,
        ]);
        DB::table('link_pool_html_deliveries')->insert(['id' => (string) Str::uuid(), 'created_at' => $old]);

        $this->artisan('geoflow:prune-link-pool')->assertSuccessful();
        $this->assertDatabaseMissing('link_selection_batches', ['id' => $expiredBatch]);
        $this->assertDatabaseHas('link_selection_batches', ['id' => $currentBatch]);
        $this->assertSame(0, DB::table('link_placements')->count());
        $this->assertSame(0, DB::table('link_pool_events')->count());
        $this->assertSame(0, DB::table('link_metrics_daily')->count());
        $this->assertSame(0, DB::table('link_pool_html_deliveries')->count());
    }

    public function test_import_undo_removes_only_new_targets_without_active_references(): void
    {
        $this->article('Undo source', 'undo-source', 'local_and_distribution', 'published', 'undo-source-category');
        $this->article('Removable', 'undo-removable', 'local_and_distribution', 'published', 'undo-target-category');
        $this->article('In use', 'undo-in-use', 'local_and_distribution', 'published', 'undo-target-category');
        $admin = Admin::query()->create([
            'username' => 'import-undo-admin', 'password' => 'Password123!',
            'email' => 'import-undo@example.test', 'role' => 'super_admin', 'status' => 'active',
        ]);
        $input = "https://primary.test/article/undo-removable,Removable\nhttps://primary.test/article/undo-in-use,In use";
        $this->actingAs($admin, 'admin')->post('https://primary.test'.route('admin.link-pool.import.store', [], false), [
            'links' => $input, 'ack_titles' => '1',
        ])->assertOk()->assertSee('撤销本批新增目标');
        $batchId = (string) DB::table('link_pool_import_batches')->value('id');
        $protectedId = (int) DB::table('link_pool_targets')->where('input_url', 'https://primary.test/article/undo-in-use')->value('id');
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $this->campaign('primary', [$protectedId]), $admin->id);
        app(LinkCampaignService::class)->publish($campaignId, $admin->id);

        $this->post('https://primary.test'.route('admin.link-pool.import.undo', $batchId, false))
            ->assertRedirect()->assertSessionHas('link_pool_message');
        $this->assertSame(1, DB::table('link_pool_targets')->count());
        $this->assertDatabaseHas('link_pool_targets', ['id' => $protectedId]);
        $this->assertNotNull(DB::table('link_pool_import_batches')->where('id', $batchId)->value('undone_at'));
        $this->post('https://primary.test'.route('admin.link-pool.import.undo', $batchId, false))
            ->assertSessionHasErrors('batch');
    }

    public function test_import_preflight_rejects_invalid_urls_and_upsert_preserves_identity(): void
    {
        $article = $this->article('Verified title', 'verified-target', 'local_and_distribution', 'published', 'verified-category');
        $imports = app(LinkPoolImportService::class);
        $rows = $imports->preflight("javascript:alert(1),Bad\nhttps://user@primary.test/article/verified-target,Bad\nhttps://primary.test:99999/article/verified-target,Bad\nhttps://primary.test/article/verified-target,Editorial title\nhttps://primary.test/article/verified-target,Duplicate");
        $this->assertSame(['error', 'error', 'error', 'new', 'duplicate'], array_column($rows, 'status'));
        try {
            $imports->import($rows, 1, false);
            $this->fail('Different editorial title requires confirmation.');
        } catch (ValidationException) {
            $this->assertSame(0, DB::table('link_pool_targets')->count());
        }
        $imports->import($rows, 1, false, [4]);
        $target = DB::table('link_pool_targets')->first();
        $this->assertSame((int) $article->id, (int) $target->article_id);
        $this->assertSame('Editorial title', $target->input_title);
        $this->assertSame('Verified title', $target->display_title);
        $imports->import($imports->preflight('https://primary.test/article/verified-target,Verified title'), 1, true);
        $this->assertSame(1, DB::table('link_pool_targets')->count());
        $this->assertSame((int) $target->id, (int) DB::table('link_pool_targets')->value('id'));
    }

    /** @return array<string,mixed> */
    private function campaign(string $siteKey, array $targetIds): array
    {
        return ['source_site_key' => $siteKey, 'name' => 'Manual recommendations', 'mode' => 'manual',
            'priority' => 10, 'limit' => 4, 'scope' => 'all', 'target_ids' => $targetIds, 'base_revision' => 0];
    }

    private function hosted(string $label): HostedSiteProfile
    {
        $hostname = $label.'.sites.test';
        $channel = DistributionChannel::query()->create([
            'name' => $label, 'domain' => $hostname, 'endpoint_url' => 'https://'.$hostname,
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
            'site_settings' => ['site_name' => $label, 'article_recommendations' => ['enabled' => true, 'heading' => '推荐内容', 'default_limit' => 4, 'rotation_hours' => 24]],
        ]);

        return HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id, 'hostname' => $hostname, 'root_domain' => 'sites.test',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE, 'indexing_status' => HostedSiteProfile::INDEXING_INDEX,
            'quality_status' => HostedSiteProfile::QUALITY_PASSED,
        ]);
    }

    private function assign(HostedSiteProfile $profile, Article $article): void
    {
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $article->id, 'hosted_site_profile_id' => $profile->id,
            'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
            'content_fingerprint' => hash('sha256', (string) $article->id),
            'capacity_date' => now()->toDateString(), 'assigned_at' => now(), 'published_at' => now(),
        ]);
    }

    private function article(string $title, string $slug, string $scope, string $status, string $categorySlug): Article
    {
        $task = Task::query()->create(['name' => $title.' task', 'status' => 'active', 'publish_scope' => $scope]);
        $category = Category::query()->firstOrCreate(['slug' => $categorySlug], ['name' => $categorySlug, 'description' => '', 'sort_order' => 0]);
        $author = Author::query()->firstOrCreate(['email' => 'link-pool@example.test'], ['name' => 'Author']);

        return Article::query()->create([
            'title' => $title, 'slug' => $slug, 'content' => $title.' body', 'category_id' => $category->id,
            'author_id' => $author->id, 'task_id' => $task->id, 'status' => $status,
            'review_status' => 'approved', 'published_at' => now(),
        ]);
    }
}
