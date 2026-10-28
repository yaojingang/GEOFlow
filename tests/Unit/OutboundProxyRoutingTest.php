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
        $this->assertArrayNotHasKey(CURLOPT_NOPROXY, $options['curl'] ?? []);
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
    public function it_accepts_an_empty_proxy_configuration(): void
    {
        $this->assertNull(OutboundProxyOptions::resolve(null));
        $this->assertNull(OutboundProxyOptions::resolve('   '));
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

        $resolver = new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return ['93.184.216.34'];
            }
        };
        $transport = new LaravelPinnedOutboundTransport($capability);
        $client = new SafeOutboundHttpClient(
            $resolver,
            $transport,
            OutboundProxyPolicy::fromConfig($proxyUrl, $allowlistedHost),
        );

        $request = Http::timeout(2);
        if ($callerProxyUrl !== null) {
            $request = $request->withOptions(['proxy' => $callerProxyUrl]);
        }

        $client->get($request, 'https://'.$host.'/probe', 1024);

        $this->assertNotSame([], $captured, 'the request must reach the transport handler');

        return $captured;
    }
}
