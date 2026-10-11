<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Services\LinkPool\LinkCampaignService;
use App\Services\LinkPool\LinkPoolImportService;
use App\Services\Site\LinkPoolRecommendationService;
use App\Services\Site\LinkPoolSiteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class LinkPoolRenderingContractTest extends TestCase
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
        $this->settings(4);
    }

    public function test_console_reads_updated_hosted_settings_and_status_with_the_same_request(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Console site', 'domain' => 'console.sites.test', 'endpoint_url' => 'https://console.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
            'site_settings' => ['article_recommendations' => ['enabled' => true, 'default_limit' => 4, 'rotation_hours' => 24]],
        ]);
        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id, 'hostname' => 'console.sites.test', 'root_domain' => 'sites.test',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE, 'indexing_status' => HostedSiteProfile::INDEXING_INDEX,
            'quality_status' => HostedSiteProfile::QUALITY_PASSED,
        ]);
        $siteKey = 'hosted:'.$profile->id;
        $sites = app(LinkPoolSiteService::class);
        $request = request();
        $this->assertTrue(app()->runningInConsole());
        $this->assertSame(4, $sites->settings($siteKey)['default_limit']);
        $this->assertTrue($sites->canPublish($siteKey));

        $channel->update(['site_settings' => ['article_recommendations' => ['enabled' => true, 'default_limit' => 2, 'rotation_hours' => 12]]]);
        $profile->update(['indexing_status' => HostedSiteProfile::INDEXING_NOINDEX]);

        $this->assertSame($request, request());
        $this->assertSame(2, $sites->settings($siteKey)['default_limit']);
        $this->assertSame(12, $sites->settings($siteKey)['rotation_hours']);
        $this->assertFalse($sites->canPublish($siteKey));
    }

    public function test_dashboard_counts_displayed_links_and_keeps_reserved_slots(): void
    {
        [$source, $targets, $batchId] = $this->recommendations();

        $this->get('https://primary.test/article/'.$source->slug)->assertOk()
            ->assertViewHas('recommendationBlocks', fn ($blocks) => array_column($blocks[0]['items'], 'target_id') === array_slice($targets, 0, 4));
        $this->dashboard()->assertViewHas('edgeCount', 4)->assertViewHas('zeroInbound', 2);
        $this->assertSame(6, DB::table('link_placements')->where('batch_id', $batchId)->count());
    }

    public function test_withdrawn_target_refills_from_reserve_before_dashboard_applies_limit(): void
    {
        [$source, $targets, $batchId] = $this->recommendations();
        $articleId = (int) DB::table('link_pool_targets')->where('id', $targets[0])->value('article_id');

        Article::query()->whereKey($articleId)->update(['status' => 'draft']);

        $this->get('https://primary.test/article/'.$source->slug)->assertOk()
            ->assertViewHas('recommendationBlocks', fn ($blocks) => array_column($blocks[0]['items'], 'target_id') === array_slice($targets, 1, 4));
        $this->dashboard()->assertViewHas('edgeCount', 4)->assertViewHas('zeroInbound', 1);
        $this->get('https://primary.test'.route('admin.link-pool.targets', ['site' => 'primary'], false))
            ->assertOk()->assertViewHas('inbound', fn ($rows) => ! $rows->has($targets[0]) && $rows->has($targets[4]) && ! $rows->has($targets[5]));
        $this->assertSame($batchId, (int) DB::table('link_selection_heads')->where('source_article_id', $source->id)->value('batch_id'));
        $this->assertSame(6, DB::table('link_placements')->where('batch_id', $batchId)->count());
    }

    public function test_dashboard_uses_updated_limit_after_batch_refresh(): void
    {
        [$source, $targets] = $this->recommendations();

        $this->settings(2);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);

        $this->get('https://primary.test/article/'.$source->slug)->assertOk()
            ->assertViewHas('recommendationBlocks', fn ($blocks) => array_column($blocks[0]['items'], 'target_id') === array_slice($targets, 0, 2));
        $this->dashboard()->assertViewHas('edgeCount', 2)->assertViewHas('zeroInbound', 4);
        $batchId = (int) DB::table('link_selection_heads')->where('source_article_id', $source->id)->value('batch_id');
        $this->assertSame(6, DB::table('link_placements')->where('batch_id', $batchId)->count());
    }

    public function test_current_related_articles_are_excluded_before_dashboard_applies_limit(): void
    {
        [$source, $targets, $batchId] = $this->recommendations();
        $articleId = (int) DB::table('link_pool_targets')->where('id', $targets[0])->value('article_id');

        Article::query()->whereKey($articleId)->update(['category_id' => $source->category_id]);

        $this->get('https://primary.test/article/'.$source->slug)->assertOk()
            ->assertViewHas('relatedArticles', fn ($articles) => $articles->pluck('id')->all() === [$articleId])
            ->assertViewHas('recommendationBlocks', fn ($blocks) => array_column($blocks[0]['items'], 'target_id') === array_slice($targets, 1, 4));
        $this->dashboard()->assertViewHas('edgeCount', 4)->assertViewHas('zeroInbound', 2);
        $this->get('https://primary.test'.route('admin.link-pool.targets', ['site' => 'primary'], false))
            ->assertOk()->assertViewHas('inbound', fn ($rows) => ! $rows->has($targets[0]) && $rows->has($targets[4]) && ! $rows->has($targets[5]));
        $this->assertSame($batchId, (int) DB::table('link_selection_heads')->where('source_article_id', $source->id)->value('batch_id'));
    }

    public function test_displayed_placement_lookup_batches_long_article_metadata(): void
    {
        [$source, , $batchId] = $this->recommendations();
        DB::table('link_campaigns')->update(['source_rules_json' => json_encode(['scope' => 'all'])]);
        $recommendations = app(LinkPoolRecommendationService::class);
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        $this->assertCount(4, $recommendations->renderedPlacementIds(['primary']));
        $singleSourceQueries = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();
        $head = DB::table('link_selection_heads')->where('batch_id', $batchId)->first();
        $batch = (array) DB::table('link_selection_batches')->find($batchId);
        unset($batch['id']);
        $placements = DB::table('link_placements')->where('batch_id', $batchId)->get();
        $attributes = $source->getAttributes();
        unset($attributes['id']);
        $attributes['content'] = str_repeat('长文内容', 40000);
        DB::table('articles')->where('category_id', '!=', $source->category_id)->update(['content' => $attributes['content']]);
        foreach (range(1, 60) as $index) {
            $articleId = DB::table('articles')->insertGetId([...$attributes, 'slug' => 'long-source-'.$index]);
            $newBatchId = DB::table('link_selection_batches')->insertGetId([...$batch, 'source_article_id' => $articleId]);
            DB::table('link_selection_heads')->insert([
                'source_site_key' => 'primary', 'source_article_id' => $articleId, 'batch_id' => $newBatchId, 'revision' => $head->revision,
            ]);
            DB::table('link_placements')->insert($placements->map(static function ($placement) use ($articleId, $newBatchId): array {
                $row = (array) $placement;
                unset($row['id']);

                return [...$row, 'batch_id' => $newBatchId, 'source_article_id' => $articleId];
            })->all());
        }
        DB::connection()->enableQueryLog();
        DB::connection()->flushQueryLog();
        memory_reset_peak_usage();
        $before = memory_get_usage(true);

        $ids = $recommendations->renderedPlacementIds(['primary']);

        $this->assertCount(244, $ids);
        $this->assertLessThan(12 * 1024 * 1024, memory_get_peak_usage(true) - $before);
        $this->assertLessThanOrEqual($singleSourceQueries + 2, count(DB::connection()->getQueryLog()));
        DB::connection()->disableQueryLog();
    }

    public function test_preview_related_articles_and_recommendations_match_public_when_publish_times_tie(): void
    {
        [$source] = $this->recommendations();
        Article::query()->where('category_id', '!=', $source->category_id)->update(['category_id' => $source->category_id]);
        $this->article('Extra related', 'extra-related', 'source-category');
        $public = $this->get('https://primary.test/article/'.$source->slug)->assertOk();
        $publicRelated = $public->viewData('relatedArticles')->pluck('id')->all();
        $this->dashboard();

        $preview = $this->get('https://primary.test'.route('admin.link-pool.preview', ['site' => 'primary', 'article_id' => $source->id], false))->assertOk();

        $this->assertSame($publicRelated, $preview->viewData('related')->pluck('id')->all());
        $this->assertSame($public->viewData('recommendationBlocks')[0]['items'], $preview->viewData('block')['items']);
    }

    private function dashboard(): TestResponse
    {
        $admin = Admin::query()->firstOrCreate(['username' => 'rendering-contract-admin'], [
            'password' => 'Password123!', 'email' => 'rendering-contract@example.test', 'role' => 'super_admin', 'status' => 'active',
        ]);

        return $this->actingAs($admin, 'admin')->get('https://primary.test'.route('admin.link-pool.dashboard', ['site' => 'primary'], false))->assertOk();
    }

    /** @return array{Article,list<int>,int} */
    private function recommendations(): array
    {
        $this->freezeTime();
        $source = $this->article('Source', 'source', 'source-category');
        $lines = [];
        foreach (range(1, 6) as $index) {
            $target = $this->article('Target '.$index, 'target-'.$index, 'target-category');
            $lines[] = 'https://primary.test/article/'.$target->slug.','.$target->title;
        }
        $imports = app(LinkPoolImportService::class);
        $imports->import($imports->preflight(implode("\n", $lines)), 1, true);
        $targets = DB::table('link_pool_targets')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $campaigns = app(LinkCampaignService::class);
        $campaignId = $campaigns->saveDraft(null, [
            'source_site_key' => 'primary', 'name' => 'Reserved recommendations', 'mode' => 'manual',
            'priority' => 10, 'limit' => 6, 'scope' => 'articles', 'source_article_ids' => [$source->id],
            'target_ids' => $targets, 'base_revision' => 0,
        ], 1);
        $campaigns->publish($campaignId, 1);
        app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id);

        return [$source, $targets, (int) DB::table('link_selection_heads')->where('source_article_id', $source->id)->value('batch_id')];
    }

    private function settings(int $limit): void
    {
        SiteSetting::query()->updateOrCreate(['setting_key' => 'article_recommendations'], [
            'setting_value' => json_encode(['enabled' => true, 'heading' => '推荐内容', 'default_limit' => $limit, 'rotation_hours' => 24]),
        ]);
    }

    private function article(string $title, string $slug, string $categorySlug): Article
    {
        $task = Task::query()->create(['name' => $title.' task', 'status' => 'active', 'publish_scope' => 'local_and_distribution']);
        $category = Category::query()->firstOrCreate(['slug' => $categorySlug], ['name' => $categorySlug, 'description' => '', 'sort_order' => 0]);
        $author = Author::query()->firstOrCreate(['email' => 'rendering-contract@example.test'], ['name' => 'Author']);

        return Article::query()->create([
            'title' => $title, 'slug' => $slug, 'content' => $title.' body', 'category_id' => $category->id,
            'author_id' => $author->id, 'task_id' => $task->id, 'status' => 'published',
            'review_status' => 'approved', 'published_at' => now(),
        ]);
    }
}
