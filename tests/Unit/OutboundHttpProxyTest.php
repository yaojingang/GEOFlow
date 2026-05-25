<?php

namespace Tests\Unit;

use App\Support\GeoFlow\OutboundHttpProxy;
use Illuminate\Support\Facades\Http;
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

    public function test_laravel_http_requests_receive_global_proxy_options(): void
    {
        config([
            'geoflow.outbound_http_proxy' => 'http://host.docker.internal:9999',
            'geoflow.outbound_https_proxy' => 'http://host.docker.internal:9999',
            'geoflow.outbound_no_proxy' => 'localhost,127.0.0.1',
        ]);

        $capturedOptions = null;

        Http::fake(function ($request, array $options) use (&$capturedOptions) {
            $capturedOptions = $options;

            return Http::response(['ok' => true]);
        });

        Http::get('https://generativelanguage.googleapis.com/v1beta/models');

        $this->assertSame([
            'http' => 'http://host.docker.internal:9999',
            'https' => 'http://host.docker.internal:9999',
            'no' => ['localhost', '127.0.0.1'],
        ], $capturedOptions['proxy'] ?? null);
    }
}
