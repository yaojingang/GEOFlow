<?php

namespace Tests\Unit;

use App\Contracts\Outbound\HostResolver;
use App\Services\Outbound\FinalOutboundSecurityPolicy;
use App\Services\Outbound\LaravelPinnedOutboundTransport;
use App\Services\Outbound\OutboundProxyOptions;
use App\Services\Outbound\OutboundProxyPolicy;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Tests\TestCase;

class OutboundProxyRoutingTest extends TestCase
{
    #[Test]
    public function it_routes_an_opted_in_host_through_the_configured_proxy(): void
    {
        $options = $this->capturedRequestOptions('public.test', 'public.test', 'http://proxy.test:8080');

        $this->assertSame('http://proxy.test:8080', $options['proxy'] ?? null);
        $this->assertArrayNotHasKey(CURLOPT_PROXY, $options['curl'] ?? []);
        $this->assertSame('', $options['curl'][CURLOPT_NOPROXY] ?? null);
        $this->assertSame(['public.test:443:93.184.216.34:443'], $options['curl'][CURLOPT_CONNECT_TO] ?? null);
        $this->assertTrue($options['curl'][CURLOPT_HTTPPROXYTUNNEL] ?? false);
    }

    #[Test]
    public function it_keeps_direct_connections_for_hosts_outside_the_proxy_allowlist(): void
    {
        $options = $this->capturedRequestOptions('other.test', 'public.test', 'http://proxy.test:8080');

        $this->assertSame('', $options['proxy'] ?? null);
        $this->assertSame('', $options['curl'][CURLOPT_PROXY] ?? null);
        $this->assertSame('*', $options['curl'][CURLOPT_NOPROXY] ?? null);
    }

    #[Test]
    public function it_ignores_a_caller_supplied_proxy_option(): void
    {
        $options = $this->capturedRequestOptions(
            'other.test',
            'public.test',
            'http://proxy.test:8080',
            'http://evil-proxy.test:8080',
        );

        $this->assertSame('', $options['proxy'] ?? null);
        $this->assertSame('*', $options['curl'][CURLOPT_NOPROXY] ?? null);
    }

    #[Test]
    public function it_does_not_let_a_caller_supplied_proxy_replace_the_policy_proxy(): void
    {
        $options = $this->capturedRequestOptions(
            'public.test',
            'public.test',
            'http://proxy.test:8080',
            'http://evil-proxy.test:8080',
        );

        $this->assertSame('http://proxy.test:8080', $options['proxy'] ?? null);
    }

    #[Test]
    public function it_rejects_a_proxy_url_with_an_unsupported_scheme(): void
    {
        $this->expectExceptionObject(new OutboundRequestBlockedException('outbound_proxy_scheme_forbidden'));

        OutboundProxyOptions::resolve('file:///etc/passwd');
    }

    #[Test]
    public function it_rejects_a_proxy_url_without_a_host(): void
    {
        $this->expectExceptionObject(new OutboundRequestBlockedException('outbound_proxy_invalid'));

        OutboundProxyOptions::resolve('http://');
    }

    #[Test]
    public function it_preserves_proxy_credentials_when_normalizing_socks5h(): void
    {
        $this->assertSame('socks5://user%40name:pass%3Aword@[::1]:1080',
            OutboundProxyOptions::resolve('SOCKS5H://user%40name:pass%3Aword@[::1]:1080'));
    }

    #[Test]
    public function it_accepts_an_empty_proxy_configuration(): void
    {
        $this->assertNull(OutboundProxyOptions::resolve(null));
        $this->assertNull(OutboundProxyOptions::resolve('   '));
    }

    #[Test]
    public function it_keeps_allowlisted_private_targets_direct_even_with_a_global_proxy(): void
    {
        config()->set('geoflow.outbound_private_targets', ['internal.test:443']);
        $options = $this->capturedRequestOptions('internal.test', '*', 'http://proxy.test:8080', selectedIp: '10.0.0.8');
        $this->assertSame('', $options['proxy']);
        $this->assertSame('*', $options['curl'][CURLOPT_NOPROXY]);
        $this->assertArrayNotHasKey(CURLOPT_CONNECT_TO, $options['curl']);
    }

    #[Test]
    public function it_does_not_apply_the_target_ip_family_to_the_proxy(): void
    {
        $options = $this->capturedRequestOptions('public.test', '*', 'http://proxy.test:8080', selectedIp: '2606:4700:4700::1111');
        $this->assertSame(['public.test:443:[2606:4700:4700::1111]:443'], $options['curl'][CURLOPT_CONNECT_TO] ?? null);
        $this->assertArrayNotHasKey('force_ip_resolve', $options);
        $this->assertArrayNotHasKey(CURLOPT_IPRESOLVE, $options['curl']);
    }

    #[Test]
    public function it_discards_caller_connection_mapping_and_proxy_bypass_overrides(): void
    {
        $options = $this->capturedRequestOptions('public.test', '*', 'http://proxy.test:8080', callerCurl: [
            CURLOPT_CONNECT_TO => ['public.test:443:127.0.0.1:80'],
            CURLOPT_HTTPPROXYTUNNEL => false,
            CURLOPT_NOPROXY => '*',
        ]);
        $this->assertSame(['public.test:443:93.184.216.34:443'], $options['curl'][CURLOPT_CONNECT_TO] ?? null);
        $this->assertTrue($options['curl'][CURLOPT_HTTPPROXYTUNNEL] ?? false);
        $this->assertSame('', $options['curl'][CURLOPT_NOPROXY]);
    }

    #[Test]
    #[DataProvider('dynamicTargets')]
    public function it_applies_the_same_proxy_policy_to_dynamic_http_requests(string $host, string $ip, bool $privateAllowed, string $expectedProxy): void
    {
        config()->set('geoflow.outbound_private_targets', $privateAllowed ? [$host.':443'] : []);
        $capability = new \stdClass;
        $resolver = new class($ip) implements HostResolver
        {
            public function __construct(private readonly string $ip) {}

            public function resolve(string $host): array
            {
                return [$this->ip];
            }
        };
        $client = new SafeOutboundHttpClient($resolver, new LaravelPinnedOutboundTransport($capability),
            OutboundProxyPolicy::fromConfig('http://proxy.test:8080', 'public.test,internal.test'));
        $captured = [];
        $factory = new SecureHttpFactory(app('events'), new FinalOutboundSecurityPolicy,
            fn (string $url) => $client->resolveTarget($url),
            function (RequestInterface $request, array $options) use (&$captured) {
                $captured = $options;

                return new FulfilledPromise(new PsrResponse(200, [], '{}'));
            }, $capability);
        app()->instance(HttpFactory::class, $factory);
        Http::clearResolvedInstances();

        Http::withOptions(['proxy' => 'http://evil.test:8080'])->get('https://'.$host.'/probe')->throw();

        $this->assertNotSame([], $captured);
        $this->assertSame($expectedProxy, $captured['proxy']);
        $this->assertSame($expectedProxy === '' ? '*' : '', $captured['curl'][CURLOPT_NOPROXY]);
    }

    public static function dynamicTargets(): array
    {
        return [
            'matched provider' => ['public.test', '93.184.216.34', false, 'http://proxy.test:8080'],
            'unmatched provider' => ['other.test', '93.184.216.34', false, ''],
            'private exception' => ['internal.test', '10.0.0.8', true, ''],
        ];
    }

    /**
     * Runs one outbound request through the hardened gateway and returns the
     * options that reached the terminal transport handler.
     *
     * @return array<string, mixed>
     */
    private function capturedRequestOptions(
        string $host,
        string $allowlistedHost,
        string $proxyUrl,
        ?string $callerProxyUrl = null,
        string $selectedIp = '93.184.216.34',
        array $callerCurl = [],
    ): array {
        $capability = new \stdClass;
        $captured = [];
        $terminal = function (RequestInterface $request, array $options) use (&$captured) {
            $captured = $options;

            return new FulfilledPromise(new PsrResponse(200, [], '{}'));
        };

        $factory = new SecureHttpFactory(
            app('events'),
            new FinalOutboundSecurityPolicy,
            static fn (string $url) => throw new \LogicException('dynamic target resolution is not used here'),
            $terminal,
            $capability,
        );
        app()->instance(HttpFactory::class, $factory);
        Http::clearResolvedInstances();

        $resolver = new class($selectedIp) implements HostResolver
        {
            public function __construct(private readonly string $ip) {}

            public function resolve(string $host): array
            {
                return [$this->ip];
            }
        };
        $transport = new LaravelPinnedOutboundTransport($capability);
        $client = new SafeOutboundHttpClient(
            $resolver,
            $transport,
            OutboundProxyPolicy::fromConfig($proxyUrl, $allowlistedHost),
        );

        $request = Http::timeout(2)->withOptions(['curl' => $callerCurl]);
        if ($callerProxyUrl !== null) {
            $request = $request->withOptions(['proxy' => $callerProxyUrl]);
        }

        $client->get($request, 'https://'.$host.'/probe', 1024);

        $this->assertNotSame([], $captured, 'the request must reach the transport handler');

        return $captured;
    }
}
