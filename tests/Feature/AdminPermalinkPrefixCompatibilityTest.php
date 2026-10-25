<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Services\GeoFlow\CategorySlugRegistry;
use App\Services\Site\ArticlePermalinkService;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPermalinkPrefixCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('categoryPolicies')]
    public function test_admin_prefix_change_preserves_historical_category_article_urls(bool $retired): void
    {
        $category = Category::query()->create(['name' => 'Original', 'slug' => 'control-room']);
        $author = Author::query()->create(['name' => 'Author']);
        $article = Article::query()->create([
            'title' => 'Category history article', 'slug' => 'history-article', 'content' => 'Body',
            'category_id' => $category->id, 'author_id' => $author->id,
            'status' => 'published', 'review_status' => 'approved', 'published_at' => now(),
        ]);
        app(CategorySlugRegistry::class)->change($category, 'current-news');
        $policy = ArticlePermalinkPolicy::defaults()->activate('/{category}/{id}');
        if ($retired) {
            $policy = $policy->activate('/article/{id}.html');
        }
        SiteSetting::query()->create([
            'setting_key' => ArticlePermalinkPolicy::SETTING_KEY,
            'setting_value' => json_encode($policy->toArray(), JSON_THROW_ON_ERROR),
        ]);
        SiteSettingsBag::forget();
        $articlePath = '/control-room/'.$article->id;
        $this->get($articlePath)->assertStatus(301);
        $this->get('/category/control-room')->assertStatus(301);

        $this->assertPrefixRejected('control-room');

        $this->get($articlePath)->assertStatus(301);
        $this->get('/category/control-room')->assertStatus(301);
    }

    public static function categoryPolicies(): array
    {
        return ['current category rule' => [false], 'retired category rule' => [true]];
    }

    #[DataProvider('hostedPolicies')]
    public function test_admin_prefix_change_preserves_saved_hosted_rules(string $servingStatus, bool $retired): void
    {
        $policy = ArticlePermalinkPolicy::defaults()->activate('/control-room/{id}');
        if ($retired) {
            $policy = $policy->activate('/article/{id}.html');
        }
        $this->hostedChannel($policy, $servingStatus);

        $this->assertPrefixRejected('control-room');
    }

    public static function hostedPolicies(): array
    {
        return [
            'online current rule' => [HostedSiteProfile::SERVING_ONLINE, false],
            'online retired rule' => [HostedSiteProfile::SERVING_ONLINE, true],
            'archived current rule' => [HostedSiteProfile::SERVING_ARCHIVED, false],
            'archived retired rule' => [HostedSiteProfile::SERVING_ARCHIVED, true],
        ];
    }

    public function test_hosted_category_rules_protect_historical_category_roots_when_primary_uses_default(): void
    {
        $category = Category::query()->create(['name' => 'Original', 'slug' => 'control-room']);
        app(CategorySlugRegistry::class)->change($category, 'current-news');
        $this->hostedChannel(ArticlePermalinkPolicy::defaults()->activate('/{category}/{id}'));

        $this->assertPrefixRejected('control-room');
    }

    public function test_category_history_does_not_block_an_unrelated_prefix_when_no_rule_uses_root_categories(): void
    {
        $category = Category::query()->create(['name' => 'Original', 'slug' => 'control-room']);
        app(CategorySlugRegistry::class)->change($category, 'current-news');

        app(ArticlePermalinkService::class)->assertAdminBasePathCompatible('control-room');

        $this->assertSame('/article/{slug}', app(ArticlePermalinkService::class)->policy()->currentPattern);
    }

    public function test_independent_agent_rules_do_not_restrict_the_local_admin_prefix(): void
    {
        DistributionChannel::query()->create([
            'name' => 'Remote', 'domain' => 'remote.example.test', 'endpoint_url' => 'https://remote.example.test',
            'channel_type' => DistributionChannel::TYPE_GEOFLOW_AGENT, 'status' => DistributionChannel::STATUS_ACTIVE,
            'site_settings' => [ArticlePermalinkPolicy::SETTING_KEY => ArticlePermalinkPolicy::defaults()->activate('/control-room/{id}')->toArray()],
        ]);

        app(ArticlePermalinkService::class)->assertAdminBasePathCompatible('control-room');

        $this->assertSame('/geo_admin', config('geoflow.admin_base_path'));
    }

    private function assertPrefixRejected(string $prefix): void
    {
        $originalPrefix = (string) config('geoflow.admin_base_path');
        SiteSetting::query()->create(['setting_key' => 'admin_base_path', 'setting_value' => trim($originalPrefix, '/')]);
        $admin = Admin::query()->create([
            'username' => 'prefix-editor', 'password' => 'Password123!', 'role' => 'admin', 'status' => 'active',
        ]);
        File::partialMock()->shouldReceive('exists')->with(base_path('.env'))->andReturn(false);

        $this->actingAs($admin, 'admin')->post(route('admin.site-settings.update'), [
            'site_name' => 'GEOFlow', 'admin_base_path' => $prefix,
        ])->assertSessionHasErrors(['admin_base_path' => __('article_permalink.errors.admin_path_conflict')]);

        $this->assertSame($originalPrefix, config('geoflow.admin_base_path'));
        $this->assertDatabaseHas('site_settings', ['setting_key' => 'admin_base_path', 'setting_value' => trim($originalPrefix, '/')]);
    }

    private function hostedChannel(ArticlePermalinkPolicy $policy, string $servingStatus = HostedSiteProfile::SERVING_ONLINE): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Hosted', 'domain' => 'prefix.sites.test', 'endpoint_url' => 'https://prefix.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
            'site_settings' => [ArticlePermalinkPolicy::SETTING_KEY => $policy->toArray()],
        ]);
        HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id, 'hostname' => 'prefix.sites.test',
            'root_domain' => 'sites.test', 'topic' => 'News', 'serving_status' => $servingStatus,
        ]);
    }
}
