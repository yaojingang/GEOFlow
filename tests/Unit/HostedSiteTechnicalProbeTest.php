<?php

namespace Tests\Unit;

use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Services\HostedSites\HostedSiteTechnicalProbe;
use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\Factory;
use Tests\Support\FakeHostResolver;
use Tests\Support\HostedSiteProbeTransport;
use Tests\TestCase;

class HostedSiteTechnicalProbeTest extends TestCase
{
    public function test_online_probe_checks_dns_tls_public_pages_discovery_and_forms(): void
    {
        config()->set('geoflow.hosted_sites.network_preflight_enabled', true);
        $transport = new HostedSiteProbeTransport;
        $probe = new HostedSiteTechnicalProbe(
            new SafeOutboundHttpClient(new FakeHostResolver, $transport),
            app(Factory::class),
        );
        $profile = new HostedSiteProfile([
            'hostname' => 'alpha.sites.test',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
        ]);
        $profile->setRelation('channel', new DistributionChannel([
            'template_key' => 'geoflow-template-01-ink-editorial',
        ]));

        $checks = $probe->check($profile, ['contact']);

        $this->assertNotEmpty($checks);
        $this->assertNotContains(false, $checks);
        $this->assertArrayHasKey('dns_public', $checks);
        $this->assertArrayHasKey('tls_https', $checks);
        $this->assertArrayHasKey('canonical', $checks);
        $this->assertArrayHasKey('json_ld', $checks);
        $this->assertArrayHasKey('about_page', $checks);
        $this->assertArrayHasKey('robots', $checks);
        $this->assertArrayHasKey('sitemap', $checks);
        $this->assertArrayHasKey('form_'.hash('sha256', 'contact'), $checks);
        $this->assertTrue($checks['theme_css']);
        $this->assertTrue($checks['theme_js']);

        $transport->healthy = false;
        $failedChecks = $probe->check($profile, []);
        $this->assertFalse($failedChecks['homepage_status']);
        $this->assertFalse($failedChecks['homepage_no_5xx']);
    }

    public function test_maintenance_probe_requires_service_unavailable_and_noindex(): void
    {
        config()->set('geoflow.hosted_sites.network_preflight_enabled', true);
        $transport = new HostedSiteProbeTransport;
        $transport->maintenance = true;
        $probe = new HostedSiteTechnicalProbe(
            new SafeOutboundHttpClient(new FakeHostResolver, $transport),
            app(Factory::class),
        );
        $profile = new HostedSiteProfile([
            'hostname' => 'alpha.sites.test',
            'serving_status' => HostedSiteProfile::SERVING_MAINTENANCE,
        ]);

        $checks = $probe->check($profile, []);

        $this->assertTrue($checks['homepage_status']);
        $this->assertTrue($checks['maintenance_noindex']);
        $this->assertArrayNotHasKey('canonical', $checks);
    }
}
