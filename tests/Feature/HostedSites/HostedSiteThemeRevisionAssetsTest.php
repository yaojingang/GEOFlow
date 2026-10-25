<?php

namespace Tests\Feature\HostedSites;

use App\Models\Admin;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Services\Api\ThemeRevisionStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class HostedSiteThemeRevisionAssetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['geoflow.hosted_sites.enabled' => true, 'geoflow.hosted_sites.primary_hosts' => ['primary.test'], 'geoflow.hosted_sites.root_domains' => ['sites.test']]);
    }

    private function profile(string $label): HostedSiteProfile
    {
        $host = $label.'.sites.test';
        $channel = DistributionChannel::query()->create(['name' => $label, 'domain' => $host, 'endpoint_url' => 'https://'.$host, 'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE, 'site_settings' => ['site_name' => $label, 'theme_id' => 'default']]);

        return HostedSiteProfile::query()->create(['distribution_channel_id' => $channel->id, 'hostname' => $host, 'root_domain' => 'sites.test', 'serving_status' => HostedSiteProfile::SERVING_ONLINE]);
    }

    private function revision(): ThemeRevision
    {
        return app(ThemeRevisionStorage::class)->create((string) Str::uuid(), 'default', ['resources/views/site/home.blade.php' => '<link rel="stylesheet" href="{{ asset(\'themes/default/brand.css\') }}"><h1>Frozen hosted theme</h1>', 'public/themes/default/brand.css' => '/* scoped hosted revision */'], []);
    }

    private function release(ThemeRevision $revision, string $siteKey, ?string $previous = null): void
    {
        $admin = Admin::query()->firstOrCreate(['username' => 'revision-owner'], ['password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        ThemeRelease::query()->create(['id' => (string) Str::uuid(), 'site_key' => $siteKey, 'workspace_id' => $revision->workspace_id, 'revision_id' => $revision->id, 'previous_revision_id' => $previous, 'admin_id' => $admin->id, 'binding_version' => 1, 'kind' => 'topic_compatibility', 'changes' => [], 'plan_sha256' => str_repeat('a', 64)]);
    }

    public function test_hosted_frozen_home_assets_are_served_only_on_the_receipted_host(): void
    {
        $alpha = $this->profile('alpha');
        $this->profile('beta');
        $revision = $this->revision();
        $this->release($revision, 'hosted:'.$alpha->id);
        SiteThemeBinding::query()->create(['site_key' => 'hosted:'.$alpha->id, 'theme_id' => 'default', 'revision_id' => $revision->id, 'settings' => [], 'lock_version' => 1]);
        $path = '/theme-assets/'.$revision->id.'/brand.css';
        $this->get('http://alpha.sites.test/')->assertOk()->assertSee('Frozen hosted theme')->assertSee($path, false);
        $response = $this->get('http://alpha.sites.test'.$path)->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('/* scoped hosted revision */', file_get_contents($response->baseResponse->getFile()->getPathname()));
        $this->get('http://beta.sites.test'.$path)->assertNotFound();
        $this->get('http://primary.test'.$path)->assertNotFound();
    }

    public function test_hosted_previous_revision_receipt_does_not_expose_an_unpublished_revision(): void
    {
        $alpha = $this->profile('alpha');
        $previous = $this->revision();
        $current = $this->revision();
        $draft = $this->revision();
        $this->release($current, 'hosted:'.$alpha->id, $previous->id);
        $this->get('http://alpha.sites.test/theme-assets/'.$previous->id.'/brand.css')->assertOk();
        $this->get('http://alpha.sites.test/theme-assets/'.$draft->id.'/brand.css')->assertNotFound();
        $this->get('http://alpha.sites.test/theme-assets/'.$current->id.'/view.php')->assertNotFound();
    }

    public function test_hosted_asset_allowlist_keeps_serving_state_and_method_boundaries(): void
    {
        $alpha = $this->profile('alpha');
        $revision = $this->revision();
        $this->release($revision, 'hosted:'.$alpha->id);
        $url = 'http://alpha.sites.test/theme-assets/'.$revision->id.'/brand.css';
        $this->head($url)->assertOk();
        $this->post($url)->assertNotFound();
        $alpha->update(['serving_status' => HostedSiteProfile::SERVING_MAINTENANCE]);
        $this->get($url)->assertStatus(503)->assertHeader('Retry-After', '300');
        $alpha->update(['serving_status' => HostedSiteProfile::SERVING_ARCHIVED]);
        $this->get($url)->assertStatus(410);
    }
}
