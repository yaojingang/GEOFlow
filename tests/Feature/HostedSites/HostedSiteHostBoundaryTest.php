<?php

namespace Tests\Feature\HostedSites;

use App\Models\Admin;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class HostedSiteHostBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.primary_hosts', ['localhost', 'primary.test']);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
    }

    public function test_primary_host_keeps_the_existing_public_and_admin_surfaces(): void
    {
        $this->get('http://primary.test/')
            ->assertOk();

        $this->get('http://primary.test/geo_admin/login')
            ->assertOk();
    }

    public function test_known_online_hosted_site_can_render_only_public_allowlisted_routes(): void
    {
        $this->createOnlineProfile('alpha.sites.test');

        $this->get('http://alpha.sites.test/')
            ->assertOk();
        $this->get('http://alpha.sites.test/assets/css/style.css')->assertOk();

        foreach (['/geo_admin/login', '/api/v1/catalog', '/horizon', '/up', '/app', '/broadcasting/auth'] as $path) {
            $this->get('http://alpha.sites.test'.$path)
                ->assertNotFound();
        }
    }

    public function test_unknown_or_nested_hosted_hostname_never_falls_back_to_primary_site(): void
    {
        $this->get('http://unknown.sites.test/')
            ->assertNotFound();

        $this->get('http://deep.alpha.sites.test/')
            ->assertNotFound();
        $this->get('http://unknown.sites.test/assets/css/style.css')->assertNotFound();
        $this->get('http://unknown.sites.test/robots.txt')->assertNotFound();
        $this->get('http://unknown.sites.test/build/manifest.json')->assertNotFound();
    }

    public function test_certified_theme_assets_are_scoped_to_the_selected_known_site(): void
    {
        $themeId = 'geoflow-template-01-ink-editorial';
        config()->set('geoflow.hosted_sites.certified_themes', ['default', $themeId]);
        $profile = $this->createOnlineProfile('alpha.sites.test');
        $profile->channel->update(['template_key' => $themeId]);

        $this->get('http://alpha.sites.test/themes/'.$themeId.'/theme.css')->assertOk();
        $this->get('http://alpha.sites.test/themes/geoflow-template-02-market-briefing/theme.css')
            ->assertNotFound();
        $this->get('http://unknown.sites.test/themes/'.$themeId.'/theme.css')->assertNotFound();
    }

    public function test_unknown_host_api_request_stays_a_quiet_not_found(): void
    {
        Log::spy();

        $this->getJson('http://unknown.sites.test/api/v1/catalog')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->getJson('http://deep.alpha.sites.test/api/v1/catalog')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->getJson('http://evil.example/api/v1/catalog')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');

        Log::shouldNotHaveReceived('error');
    }

    public function test_a_hosted_hostname_cannot_be_promoted_to_the_primary_surface(): void
    {
        config()->set('geoflow.hosted_sites.primary_hosts', ['alpha.sites.test']);

        $this->get('http://alpha.sites.test/geo_admin/login')->assertNotFound();
    }

    public function test_forwarded_host_does_not_override_the_direct_primary_host_without_a_trusted_proxy(): void
    {
        $this->createOnlineProfile('alpha.sites.test');

        $this->withServerVariables([
            'HTTP_X_FORWARDED_HOST' => 'alpha.sites.test',
        ])->get('http://primary.test/geo_admin/login')->assertOk();
    }

    public function test_trusted_proxy_can_forward_a_known_hosted_hostname(): void
    {
        $this->createOnlineProfile('alpha.sites.test');
        config()->set('trustedproxy.proxies', ['127.0.0.1']);

        $this->withServerVariables([
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_X_FORWARDED_HOST' => 'alpha.sites.test',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ])->get('http://primary.test/')
            ->assertOk()
            ->assertSee('Alpha Site');
    }

    public function test_maintenance_and_archived_sites_have_explicit_responses(): void
    {
        $profile = $this->createOnlineProfile('alpha.sites.test');

        $profile->update(['serving_status' => HostedSiteProfile::SERVING_MAINTENANCE]);
        $this->get('http://alpha.sites.test/')
            ->assertStatus(503)
            ->assertHeader('Retry-After', '300')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get('http://alpha.sites.test/geo_admin/login')->assertNotFound();
        $this->get('http://alpha.sites.test/api/v1/catalog')->assertNotFound();

        $profile->update(['serving_status' => HostedSiteProfile::SERVING_ARCHIVED]);
        $this->get('http://alpha.sites.test/')
            ->assertGone()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function test_disabled_feature_rejects_hosted_public_and_admin_surfaces(): void
    {
        $profile = $this->createOnlineProfile('alpha.sites.test');
        config()->set('geoflow.hosted_sites.enabled', false);

        $this->get('http://alpha.sites.test/')->assertNotFound();
        $this->get('http://alpha.sites.test/assets/css/style.css')->assertNotFound();

        $admin = Admin::query()->create([
            'username' => 'disabled-hosted-admin',
            'password' => 'Password123!',
            'email' => 'disabled-hosted@example.test',
            'display_name' => 'Disabled hosted admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $this->actingAs($admin, 'admin')
            ->get('http://primary.test/geo_admin/distribution/hosted-sites')
            ->assertNotFound();
        $this->assertNotNull($profile->id);
    }

    private function createOnlineProfile(string $hostname): HostedSiteProfile
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Alpha',
            'domain' => $hostname,
            'endpoint_url' => 'https://'.$hostname,
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
            'template_key' => 'default',
            'site_settings' => ['site_name' => 'Alpha Site'],
        ]);

        return HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id,
            'hostname' => $hostname,
            'root_domain' => 'sites.test',
            'topic' => 'AI',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
        ]);
    }
}
