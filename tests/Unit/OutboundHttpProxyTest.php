<?php

namespace Tests\Unit;

use App\Support\GeoFlow\OutboundHttpProxy;
use Tests\TestCase;

class OutboundHttpProxyTest extends TestCase
{
    public function test_it_does_not_set_proxy_options_when_proxy_is_empty(): void
    {
        config([
            'geoflow.outbound_http_proxy' => '',
            'geoflow.outbound_https_proxy' => '',
            'geoflow.outbound_no_proxy' => 'localhost,127.0.0.1',
        ]);

        $this->assertSame([], OutboundHttpProxy::httpClientOptions());
    }

    public function test_it_builds_guzzle_proxy_options_from_config(): void
    {
        config([
            'geoflow.outbound_http_proxy' => 'http://host.docker.internal:9999',
            'geoflow.outbound_https_proxy' => 'http://host.docker.internal:9999',
            'geoflow.outbound_no_proxy' => 'localhost, 127.0.0.1, postgres',
        ]);

        $this->assertSame([
            'proxy' => [
                'http' => 'http://host.docker.internal:9999',
                'https' => 'http://host.docker.internal:9999',
                'no' => ['localhost', '127.0.0.1', 'postgres'],
            ],
        ], OutboundHttpProxy::httpClientOptions());
    }

    public function test_it_only_applies_default_proxy_to_ai_hosts(): void
    {
        config([
            'geoflow.outbound_http_proxy' => 'http://host.docker.internal:9999',
            'geoflow.outbound_https_proxy' => 'http://host.docker.internal:9999',
            'geoflow.outbound_no_proxy' => 'localhost,127.0.0.1',
            'geoflow.outbound_proxy_hosts' => [
                'generativelanguage.googleapis.com',
                'api.openai.com',
            ],
        ]);

        $this->assertSame([
            'http' => 'http://host.docker.internal:9999',
            'https' => 'http://host.docker.internal:9999',
            'no' => ['localhost', '127.0.0.1'],
        ], OutboundHttpProxy::httpClientOptionsForUrl('https://generativelanguage.googleapis.com/v1beta/models')['proxy'] ?? null);

        $this->assertSame([], OutboundHttpProxy::httpClientOptionsForUrl('https://wp.example.com/wp-json/wp/v2/posts'));
        $this->assertSame([], OutboundHttpProxy::httpClientOptionsForUrl('https://example.com/geoflow-agent/v1/health'));
    }

    public function test_default_proxy_hosts_include_current_minimax_domain(): void
    {
        $this->assertContains('api.minimax.io', config('geoflow.outbound_proxy_hosts'));
        $this->assertContains('api.minimaxi.com', config('geoflow.outbound_proxy_hosts'));
    }

    public function test_it_can_apply_proxy_to_all_hosts_when_configured(): void
    {
        config([
            'geoflow.outbound_http_proxy' => 'http://host.docker.internal:9999',
            'geoflow.outbound_https_proxy' => 'http://host.docker.internal:9999',
            'geoflow.outbound_no_proxy' => 'localhost,127.0.0.1',
            'geoflow.outbound_proxy_hosts' => '*',
        ]);

        $this->assertSame([
            'http' => 'http://host.docker.internal:9999',
            'https' => 'http://host.docker.internal:9999',
            'no' => ['localhost', '127.0.0.1'],
        ], OutboundHttpProxy::httpClientOptionsForUrl('https://wp.example.com/wp-json/wp/v2/posts')['proxy'] ?? null);
    }
}
