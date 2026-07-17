<?php

namespace Tests\Unit;

use App\Contracts\Outbound\HostResolver;
use App\Contracts\Outbound\OutboundTransport;
use App\Services\Outbound\FinalOutboundSecurityPolicy;
use App\Services\Outbound\LaravelPinnedOutboundTransport;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\ResolvedOutboundTarget;
use App\Services\Outbound\ResponseSizeLimitedStream;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Services\Outbound\SecurePendingRequest;
use App\Services\Outbound\SystemHostResolver;
use Closure;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware as GuzzleMiddleware;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Request as LaravelRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use Tests\Support\FakeHostResolver;
use Tests\TestCase;

class SafeOutboundHttpClientTest extends TestCase
{
    #[Test]
    public function it_exposes_a_single_safe_outbound_gateway_and_resolver_contract(): void
    {
        $this->assertTrue(interface_exists(HostResolver::class));
        $this->assertTrue(interface_exists(OutboundTransport::class));
        $this->assertTrue(class_exists(SafeOutboundHttpClient::class));
    }

    #[Test]
    public function the_application_container_resolves_the_safe_gateway(): void
    {
        $this->assertInstanceOf(SafeOutboundHttpClient::class, app(SafeOutboundHttpClient::class));
    }

    /**
     * @return array<string, array{string, list<string>, string}>
     */
    public static function blockedTargets(): array
    {
        return [
            'loopback v4' => ['https://loopback.test/path', ['127.0.0.1'], 'unsafe_address'],
            'unspecified v4' => ['https://zero.test/path', ['0.0.0.0'], 'unsafe_address'],
            'private v4' => ['https://private.test/path', ['10.1.2.3'], 'unsafe_address'],
            'link local v4' => ['https://link.test/path', ['169.254.1.2'], 'unsafe_address'],
            'metadata' => ['https://metadata.test/latest', ['169.254.169.254'], 'unsafe_address'],
            'carrier grade nat' => ['https://cgnat.test/path', ['100.64.0.1'], 'unsafe_address'],
            'protocol assignment' => ['https://protocol.test/path', ['192.0.0.1'], 'unsafe_address'],
            'documentation v4' => ['https://documentation.test/path', ['192.0.2.1'], 'unsafe_address'],
            'benchmark v4' => ['https://benchmark.test/path', ['198.18.0.1'], 'unsafe_address'],
            'multicast v4' => ['https://multicast.test/path', ['224.0.0.1'], 'unsafe_address'],
            'loopback v6' => ['https://loopback6.test/path', ['::1'], 'unsafe_address'],
            'private v6' => ['https://private6.test/path', ['fd00::10'], 'unsafe_address'],
            'site local v6' => ['https://site-local6.test/path', ['fec0::10'], 'unsafe_address'],
            'well known nat64' => ['https://nat64.test/path', ['64:ff9b::7f00:1'], 'unsafe_address'],
            'local nat64' => ['https://nat64-local.test/path', ['64:ff9b:1::7f00:1'], 'unsafe_address'],
            'dummy v6' => ['https://dummy6.test/path', ['100:0:0:1::1'], 'unsafe_address'],
            'documentation v6' => ['https://documentation6.test/path', ['2001:db8::1'], 'unsafe_address'],
            'documentation v6 new block' => ['https://documentation6-new.test/path', ['3fff::1'], 'unsafe_address'],
            'segment routing v6' => ['https://segment-routing6.test/path', ['5f00::1'], 'unsafe_address'],
            'multicast v6' => ['https://multicast6.test/path', ['ff02::1'], 'unsafe_address'],
            'mapped v6' => ['https://mapped.test/path', ['::ffff:127.0.0.1'], 'mapped_address'],
            'expanded mapped v6' => ['https://mapped-expanded.test/path', ['0:0:0:0:0:ffff:7f00:1'], 'mapped_address'],
            'mixed dns' => ['https://mixed.test/path', ['93.184.216.34', '10.0.0.8'], 'unsafe_address'],
        ];
    }

    #[Test]
    #[DataProvider('blockedTargets')]
    public function it_rejects_every_unsafe_resolved_candidate(string $url, array $addresses, string $reason): void
    {
        $transport = new RecordingOutboundTransport;
        $client = $this->client([$this->host($url) => $addresses], $transport);

        try {
            $client->get(Http::timeout(2), $url, 1024);
            $this->fail('Expected the target to be blocked.');
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame($reason, $exception->reasonCode);
            $this->assertSame('Outbound request blocked by security policy.', $exception->getMessage());
        }

        $this->assertCount(0, $transport->targets);
    }

    #[Test]
    public function it_rejects_a_literal_nat64_address_that_embeds_ipv4_loopback(): void
    {
        $transport = new RecordingOutboundTransport;
        $client = $this->client([], $transport);

        $this->expectExceptionObject(new OutboundRequestBlockedException('unsafe_address'));
        try {
            $client->get(Http::timeout(2), 'https://[64:ff9b::7f00:1]/metadata', 1024);
        } finally {
            $this->assertCount(0, $transport->targets);
        }
    }

    #[Test]
    public function an_exact_private_target_allowlist_cannot_enable_a_nat64_loopback_target(): void
    {
        config(['geoflow.outbound_private_targets' => ['nat64-allowlisted.test:443']]);
        $transport = new RecordingOutboundTransport;
        $client = $this->client(['nat64-allowlisted.test' => ['64:ff9b::7f00:1']], $transport);

        $this->expectExceptionObject(new OutboundRequestBlockedException('unsafe_address'));
        try {
            $client->get(Http::timeout(2), 'https://nat64-allowlisted.test/metadata', 1024);
        } finally {
            $this->assertCount(0, $transport->targets);
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function malformedUrls(): array
    {
        return [
            'userinfo' => ['https://user:secret@example.com/path', 'userinfo_forbidden'],
            'fragment' => ['https://example.com/path#fragment', 'fragment_forbidden'],
            'control' => ["https://example.com/line\nfeed", 'control_character'],
            'encoded authority' => ['https://example%2ecom/path', 'ambiguous_authority'],
            'backslash authority' => ['https://example.com\\@127.0.0.1/path', 'ambiguous_authority'],
            'non ascii host' => ['https://例子.测试/path', 'invalid_host'],
            'integer ip' => ['https://2130706433/path', 'ambiguous_ip'],
            'hex ip' => ['https://0x7f000001/path', 'ambiguous_ip'],
            'octal ip' => ['https://0177.0.0.1/path', 'ambiguous_ip'],
            'short ip' => ['https://127.1/path', 'ambiguous_ip'],
            'invalid dotted numeric ip' => ['https://999.999.999.999/path', 'ambiguous_ip'],
            'unsupported scheme' => ['file:///etc/passwd', 'invalid_scheme'],
        ];
    }

    #[Test]
    #[DataProvider('malformedUrls')]
    public function it_fail_closes_malformed_or_ambiguous_urls(string $url, string $reason): void
    {
        $client = $this->client([], new RecordingOutboundTransport);

        try {
            $client->get(Http::timeout(2), $url, 1024);
            $this->fail('Expected malformed URL to be blocked.');
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame($reason, $exception->reasonCode);
        }
    }

    #[Test]
    public function it_normalizes_a_trailing_dot_and_pins_one_public_address(): void
    {
        $transport = new RecordingOutboundTransport([new Response(new PsrResponse(200, [], 'ok'))]);
        $client = $this->client(['example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']], $transport);

        $response = $client->get(Http::timeout(2), 'https://EXAMPLE.com./path?q=1', 1024);

        $this->assertSame('ok', $response->body());
        $this->assertSame('https://example.com/path?q=1', $transport->targets[0]->url);
        $this->assertSame('example.com', $transport->targets[0]->host);
        $this->assertSame(443, $transport->targets[0]->port);
        $this->assertSame('93.184.216.34', $transport->targets[0]->selectedIp);
    }

    #[Test]
    public function it_allows_private_addresses_only_for_an_exact_host_and_port(): void
    {
        config(['geoflow.outbound_private_targets' => ['internal.example:8443']]);
        $transport = new RecordingOutboundTransport([
            new Response(new PsrResponse(200, [], 'allowed')),
        ]);
        $client = $this->client(['internal.example' => ['10.20.30.40']], $transport);

        $this->assertSame('allowed', $client->get(Http::timeout(2), 'https://internal.example:8443/ping', 1024)->body());

        $this->expectException(OutboundRequestBlockedException::class);
        $client->get(Http::timeout(2), 'https://internal.example/ping', 1024);
    }

    #[Test]
    public function it_fail_closes_dns_errors(): void
    {
        $client = $this->client(['missing.test' => []], new RecordingOutboundTransport);

        $this->expectExceptionObject(new OutboundRequestBlockedException('dns_resolution_failed'));
        $client->get(Http::timeout(2), 'https://missing.test/path', 1024);
    }

    #[Test]
    public function it_revalidates_each_redirect_and_blocks_public_to_private_hops(): void
    {
        $transport = new RecordingOutboundTransport([
            new Response(new PsrResponse(302, ['Location' => 'https://internal.test/secret'])),
        ]);
        $client = $this->client([
            'public.test' => ['93.184.216.34'],
            'internal.test' => ['10.0.0.5'],
        ], $transport);

        $this->expectExceptionObject(new OutboundRequestBlockedException('unsafe_address'));
        $client->get(Http::timeout(2), 'https://public.test/start', 1024, 3);
    }

    #[Test]
    public function it_limits_redirects_to_the_explicit_policy(): void
    {
        $transport = new RecordingOutboundTransport([
            new Response(new PsrResponse(302, ['Location' => '/two'])),
            new Response(new PsrResponse(302, ['Location' => '/three'])),
        ]);
        $client = $this->client(['public.test' => ['93.184.216.34']], $transport);

        $this->expectExceptionObject(new OutboundRequestBlockedException('redirect_limit_exceeded'));
        $client->get(Http::timeout(2), 'https://public.test/one', 1024, 1);
    }

    #[Test]
    public function it_rejects_content_length_and_actual_bodies_over_the_cap(): void
    {
        $transport = new RecordingOutboundTransport([
            new Response(new PsrResponse(200, ['Content-Length' => '2048'], 'small')),
            new Response(new PsrResponse(200, [], str_repeat('x', 1025))),
        ]);
        $client = $this->client(['public.test' => ['93.184.216.34']], $transport);

        foreach (['/declared', '/actual'] as $path) {
            try {
                $client->get(Http::timeout(2), 'https://public.test'.$path, 1024);
                $this->fail('Expected body limit failure.');
            } catch (OutboundRequestBlockedException $exception) {
                $this->assertSame('response_too_large', $exception->reasonCode);
            }
        }
    }

    #[Test]
    public function the_system_resolver_follows_cnames_and_collects_a_and_aaaa_records(): void
    {
        $resolver = new SystemHostResolver(static fn (string $host): array => match ($host) {
            'alias.example' => [['type' => 'CNAME', 'target' => 'edge.example']],
            'edge.example' => [
                ['type' => 'A', 'ip' => '93.184.216.34'],
                ['type' => 'AAAA', 'ipv6' => '2606:2800:220:1:248:1893:25c8:1946'],
            ],
            default => [],
        });

        $this->assertSame(
            ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'],
            $resolver->resolve('alias.example')
        );
    }

    #[Test]
    public function the_production_transport_disables_redirects_and_proxies_and_pins_the_validated_ip(): void
    {
        $captured = [];
        $terminal = static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
            $captured = $options;

            return new FulfilledPromise(new PsrResponse(200, [], 'ok'));
        };
        $transport = $this->swapSecureHttpTransport($terminal);
        $request = Http::timeout(7)
            ->connectTimeout(3)
            ->withOptions([
                'allow_redirects' => true,
                'proxy' => 'http://evil-proxy.test:8080',
                'on_headers' => static fn (): null => null,
                'curl' => [
                    CURLOPT_RESOLVE => ['public.test:8443:10.0.0.9'],
                    CURLOPT_PROXY => 'http://evil-proxy.test:8080',
                    CURLOPT_NOPROXY => '',
                    CURLOPT_FOLLOWLOCATION => true,
                ],
            ]);
        $target = new ResolvedOutboundTarget(
            'https://public.test:8443/path',
            'https',
            'public.test',
            8443,
            ['93.184.216.34'],
            '93.184.216.34',
        );

        $response = $transport->send($request, 'GET', $target, [], 4096);

        $this->assertSame('ok', $response->body());
        $this->assertFalse($captured['allow_redirects']);
        $this->assertSame('', $captured['proxy']);
        $this->assertSame(['public.test:8443:93.184.216.34'], $captured['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $captured['curl'][CURLOPT_PROXY]);
        $this->assertSame('*', $captured['curl'][CURLOPT_NOPROXY]);
        $this->assertFalse($captured['curl'][CURLOPT_FOLLOWLOCATION]);
        $this->assertSame(7, $captured['timeout']);
        $this->assertSame(3, $captured['connect_timeout']);

        $this->expectExceptionObject(new OutboundRequestBlockedException('response_too_large'));
        $captured['on_headers'](new PsrResponse(200, ['Content-Length' => '8192']));
    }

    /** @return array<string, array{string}> */
    public static function finalSecurityHops(): array
    {
        return [
            'first hop' => ['first'],
            'same-origin redirect' => ['same'],
            'cross-origin redirect' => ['cross'],
        ];
    }

    #[Test]
    #[DataProvider('finalSecurityHops')]
    public function final_security_options_override_caller_request_and_option_attacks_on_every_hop(string $hop): void
    {
        $sent = [];
        $responses = match ($hop) {
            'first' => [new PsrResponse(200, [], 'ok')],
            'same' => [
                new PsrResponse(302, ['Location' => 'https://first.test:8443/end?public=second']),
                new PsrResponse(200, [], 'ok'),
            ],
            default => [
                new PsrResponse(302, ['Location' => 'https://second.test:9443/end?public=second']),
                new PsrResponse(200, [], 'ok'),
            ],
        };
        $dangerousCurl = $this->dangerousCurlOptions();
        $terminal = static function (RequestInterface $request, array $options) use (&$sent, &$responses): PromiseInterface {
            $sent[] = ['request' => $request, 'options' => $options];

            return new FulfilledPromise(array_shift($responses));
        };
        $transport = $this->swapSecureHttpTransport($terminal);
        $request = Http::timeout(12)
            ->connectTimeout(4)
            ->withRequestMiddleware(static fn (RequestInterface $request): RequestInterface => $request
                ->withUri($request->getUri()->withScheme('http')->withHost('127.0.0.1')->withPort(80))
                ->withHeader('Host', '127.0.0.1'))
            ->withOptions([
                'allow_redirects' => true,
                'decode_content' => true,
                'force_ip_resolve' => 'v6',
                'proxy' => 'http://evil-proxy.test:8080',
                'stream' => true,
                'stream_context' => ['socket' => ['bindto' => '127.0.0.1:31337']],
                'verify' => false,
                'auth' => ['attacker', 'secret', 'digest'],
                'handler' => static fn (): null => null,
                'curl' => $dangerousCurl,
            ]);
        $client = $this->client([
            'first.test' => ['93.184.216.34'],
            'second.test' => ['93.184.216.35'],
        ], $transport);
        $maxRedirects = $hop === 'first' ? 0 : 1;

        $this->assertSame('ok', $client->get($request, 'https://first.test:8443/start?public=first', 4096, $maxRedirects)->body());

        $final = $sent[$hop === 'first' ? 0 : 1];
        $expectedUrl = $hop === 'cross'
            ? 'https://second.test:9443/end?public=second'
            : ($hop === 'same' ? 'https://first.test:8443/end?public=second' : 'https://first.test:8443/start?public=first');
        $expectedHost = $hop === 'cross' ? 'second.test:9443' : 'first.test:8443';
        $expectedPort = $hop === 'cross' ? 9443 : 8443;
        $expectedIp = $hop === 'cross' ? '93.184.216.35' : '93.184.216.34';

        $this->assertSame($expectedUrl, (string) $final['request']->getUri());
        $this->assertSame($expectedHost, $final['request']->getHeaderLine('Host'));
        $this->assertSame('identity', $final['request']->getHeaderLine('Accept-Encoding'));
        $this->assertFalse($final['options']['allow_redirects']);
        $this->assertFalse($final['options']['decode_content']);
        $this->assertFalse($final['options']['stream']);
        $this->assertTrue($final['options']['verify']);
        $this->assertSame('', $final['options']['proxy']);
        $this->assertSame('v4', $final['options']['force_ip_resolve']);
        $this->assertSame(12, $final['options']['timeout']);
        $this->assertSame(4, $final['options']['connect_timeout']);
        foreach (['auth', 'cert', 'cookies', 'handler', 'ssl_key', 'stream_context'] as $unsafeOption) {
            $this->assertArrayNotHasKey($unsafeOption, $final['options']);
        }
        $this->assertSame($expectedUrl, $final['options']['curl'][CURLOPT_URL]);
        $this->assertSame($expectedPort, $final['options']['curl'][CURLOPT_PORT]);
        $this->assertSame([$expectedHost.':'.$expectedIp], $final['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $final['options']['curl'][CURLOPT_PROXY]);
        $this->assertSame('*', $final['options']['curl'][CURLOPT_NOPROXY]);
        $this->assertFalse($final['options']['curl'][CURLOPT_FOLLOWLOCATION]);
        $this->assertSame('identity', $final['options']['curl'][CURLOPT_ENCODING]);
        $this->assertSame($this->safeCurlOptionKeys(true), $this->sortedKeys($final['options']['curl']));
    }

    #[Test]
    public function final_security_preserves_business_payload_timeouts_and_response_callbacks(): void
    {
        $captured = [];
        $onHeadersCalled = false;
        $progress = [];
        $sink = fopen('php://temp', 'w+');
        $dangerousCurl = $this->dangerousCurlOptions();
        $terminal = static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
            $captured = ['request' => $request, 'options' => $options];

            return new FulfilledPromise(new PsrResponse(200, [], 'ok'));
        };
        $transport = $this->swapSecureHttpTransport($terminal);
        $request = Http::timeout(12)
            ->connectTimeout(4)
            ->withHeaders(['X-Business-Header' => 'business-value'])
            ->withBody('business-body', 'application/json')
            ->withOptions([
                'sink' => $sink,
                'on_headers' => static function () use (&$onHeadersCalled): void {
                    $onHeadersCalled = true;
                },
                'progress' => static function ($downloadTotal, $downloaded, $uploadTotal, $uploaded) use (&$progress): void {
                    $progress = [$downloadTotal, $downloaded, $uploadTotal, $uploaded];
                },
                'curl' => $dangerousCurl,
            ]);
        $client = $this->client(
            ['public.test' => ['93.184.216.34']],
            $transport,
        );

        $response = $client->send($request, 'POST', 'https://public.test/path?public=1', [], 1024);

        $this->assertSame('ok', $response->body());
        $this->assertSame('POST', $captured['request']->getMethod());
        $this->assertSame('business-body', (string) $captured['request']->getBody());
        $this->assertSame('business-value', $captured['request']->getHeaderLine('X-Business-Header'));
        $this->assertSame('public=1', $captured['request']->getUri()->getQuery());
        $this->assertSame(12, $captured['options']['timeout']);
        $this->assertSame(4, $captured['options']['connect_timeout']);
        $this->assertSame($sink, $captured['options']['sink']);
        $this->assertArrayNotHasKey(CURLOPT_POSTFIELDS, $captured['options']['curl']);
        $this->assertArrayNotHasKey(CURLOPT_CUSTOMREQUEST, $captured['options']['curl']);

        $captured['options']['on_headers'](new PsrResponse(200, ['Content-Length' => '100']));
        $this->assertTrue($onHeadersCalled);
        $this->assertSame(0, $captured['options']['curl'][CURLOPT_XFERINFOFUNCTION](null, 100, 50, 20, 10));
        $this->assertSame([100.0, 50.0, 20.0, 10.0], $progress);
        $this->assertSame(1, $captured['options']['curl'][CURLOPT_XFERINFOFUNCTION](null, 2048, 1025, 20, 10));
    }

    #[Test]
    public function final_security_keeps_public_ip_literals_fixed_without_resolve(): void
    {
        $captured = [];
        $terminal = static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
            $captured = ['request' => $request, 'options' => $options];

            return new FulfilledPromise(new PsrResponse(200, [], 'ok'));
        };
        $transport = $this->swapSecureHttpTransport($terminal);
        $request = Http::timeout(3);
        $client = $this->client([], $transport);

        $this->assertSame('ok', $client->get($request, 'https://93.184.216.34/path', 1024)->body());
        $this->assertSame('https://93.184.216.34/path', (string) $captured['request']->getUri());
        $this->assertSame('v4', $captured['options']['force_ip_resolve']);
        $this->assertArrayNotHasKey(CURLOPT_RESOLVE, $captured['options']['curl']);
        $this->assertSame($this->safeCurlOptionKeys(false), $this->sortedKeys($captured['options']['curl']));
    }

    #[Test]
    public function final_security_fails_closed_when_the_handler_stack_throws(): void
    {
        $baseHandlerCalled = false;
        $terminal = static function () use (&$baseHandlerCalled): PromiseInterface {
            $baseHandlerCalled = true;

            return new FulfilledPromise(new PsrResponse(200, [], 'unexpected'));
        };
        $transport = $this->swapSecureHttpTransport($terminal);
        $request = Http::timeout(3)
            ->withRequestMiddleware(static function (): never {
                throw new \RuntimeException('caller stack failed before the secure base handler');
            });
        $client = $this->client(
            ['public.test' => ['93.184.216.34']],
            $transport,
        );

        try {
            $client->get($request, 'https://public.test/path', 1024);
            $this->fail('Expected the handler stack to fail closed.');
        } catch (OutboundRequestFailedException $exception) {
            $this->assertSame('outbound_request_failed', $exception->reasonCode);
        } finally {
            $this->assertFalse($baseHandlerCalled);
        }
    }

    #[Test]
    public function direct_http_facade_applies_final_security_after_caller_hooks(): void
    {
        $captured = [];
        $dangerousCurl = $this->dangerousCurlOptions();
        $terminal = static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
            $captured = ['request' => $request, 'options' => $options];

            return new FulfilledPromise(new PsrResponse(200, [], 'ok'));
        };
        $this->swapSecureHttpFactory($terminal);
        $resolver = app(HostResolver::class);
        $this->assertInstanceOf(FakeHostResolver::class, $resolver);
        $resolver->set('direct.test', ['93.184.216.34']);

        $response = Http::timeout(9)
            ->connectTimeout(3)
            ->withRequestMiddleware(static fn (RequestInterface $request): RequestInterface => $request->withHeader('Host', '127.0.0.1'))
            ->withOptions([
                'allow_redirects' => true,
                'decode_content' => true,
                'proxy' => 'http://evil-proxy.test:8080',
                'stream' => true,
                'verify' => false,
                'curl' => $dangerousCurl,
            ])
            ->beforeSending(static fn (LaravelRequest $request): RequestInterface => $request->toPsrRequest()->withHeader('Host', 'metadata.internal'))
            ->get('https://direct.test/path?public=1');

        $this->assertSame('ok', $response->body());
        $this->assertSame('https://direct.test/path?public=1', (string) $captured['request']->getUri());
        $this->assertSame('direct.test', $captured['request']->getHeaderLine('Host'));
        $this->assertSame('identity', $captured['request']->getHeaderLine('Accept-Encoding'));
        $this->assertFalse($captured['options']['allow_redirects']);
        $this->assertFalse($captured['options']['decode_content']);
        $this->assertFalse($captured['options']['stream']);
        $this->assertTrue($captured['options']['verify']);
        $this->assertSame('', $captured['options']['proxy']);
        $this->assertSame(9, $captured['options']['timeout']);
        $this->assertSame(3, $captured['options']['connect_timeout']);
        $this->assertSame('https://direct.test/path?public=1', $captured['options']['curl'][CURLOPT_URL]);
        $this->assertSame(['direct.test:443:93.184.216.34'], $captured['options']['curl'][CURLOPT_RESOLVE]);
        $this->assertSame($this->safeCurlOptionKeys(true), $this->sortedKeys($captured['options']['curl']));
    }

    #[Test]
    public function direct_http_facade_revalidates_the_request_after_caller_middleware(): void
    {
        $terminalCalled = false;
        $terminal = static function () use (&$terminalCalled): PromiseInterface {
            $terminalCalled = true;

            return new FulfilledPromise(new PsrResponse(200, [], 'unexpected'));
        };
        $this->swapSecureHttpFactory($terminal);
        $resolver = app(HostResolver::class);
        $this->assertInstanceOf(FakeHostResolver::class, $resolver);
        $resolver->set('private.test', ['10.0.0.9']);

        try {
            Http::withRequestMiddleware(static fn (RequestInterface $request): RequestInterface => $request->withUri($request->getUri()->withHost('private.test')))
                ->get('https://public.test/path');
            $this->fail('Expected the final boundary to reject the rewritten target.');
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame('unsafe_address', $exception->reasonCode);
        } finally {
            $this->assertFalse($terminalCalled);
        }
    }

    /** @return array<string, array{object|null}> */
    public static function unauthorizedFixedContextCapabilities(): array
    {
        return [
            'missing capability' => [null],
            'forged capability' => [new \stdClass],
            'former terminal argument' => [static fn (): null => null],
        ];
    }

    #[Test]
    #[DataProvider('unauthorizedFixedContextCapabilities')]
    public function callers_cannot_enable_a_forged_fixed_context_or_replace_the_terminal(?object $capability): void
    {
        $trustedCalls = 0;
        $dangerousCalls = 0;
        $trustedTerminal = static function () use (&$trustedCalls): PromiseInterface {
            $trustedCalls++;

            return new FulfilledPromise(new PsrResponse(200, [], 'trusted'));
        };
        $dangerousTerminal = static function () use (&$dangerousCalls): PromiseInterface {
            $dangerousCalls++;

            return new FulfilledPromise(new PsrResponse(200, [], 'dangerous'));
        };
        $dangerousStack = new HandlerStack($dangerousTerminal);
        $factory = $this->swapSecureHttpFactory($trustedTerminal);
        $request = $factory->createPendingRequest()
            ->setHandler($dangerousStack)
            ->setClient(new GuzzleClient(['handler' => $dangerousStack]))
            ->withOptions(['handler' => $dangerousStack]);
        $originalOptions = $request->getOptions();
        $forgedTarget = new ResolvedOutboundTarget(
            'http://169.254.169.254/latest/meta-data',
            'http',
            '169.254.169.254',
            80,
            ['169.254.169.254'],
            '169.254.169.254',
        );

        try {
            $request->withFixedSecurityContext(
                fixedContextCapability: $capability,
                target: $forgedTarget,
                method: 'GET',
                crossOrigin: false,
                maxBytes: 1024,
                streamingRequested: false,
            );
            $this->fail('Expected the forged fixed context to be rejected.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(OutboundRequestBlockedException::class, $exception);
            $this->assertSame('fixed_context_unauthorized', $exception->reasonCode);
        }

        $this->assertSame($originalOptions, $request->getOptions());
        $this->assertSame('trusted', $request->get('https://public.test/path')->body());
        $this->assertSame(1, $trustedCalls);
        $this->assertSame(0, $dangerousCalls);
    }

    #[Test]
    public function omitting_the_fixed_context_capability_fails_with_a_normalized_security_reason(): void
    {
        $factory = $this->swapSecureHttpFactory(
            static fn (): PromiseInterface => new FulfilledPromise(new PsrResponse(200, [], 'trusted'))
        );
        $request = $factory->createPendingRequest();
        $forgedTarget = new ResolvedOutboundTarget(
            'http://169.254.169.254/latest/meta-data',
            'http',
            '169.254.169.254',
            80,
            ['169.254.169.254'],
            '169.254.169.254',
        );

        try {
            $request->withFixedSecurityContext(
                target: $forgedTarget,
                method: 'GET',
                crossOrigin: false,
                maxBytes: 1024,
                streamingRequested: false,
            );
            $this->fail('Expected the missing fixed context capability to be rejected.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(OutboundRequestBlockedException::class, $exception);
            $this->assertSame('fixed_context_unauthorized', $exception->reasonCode);
        }
    }

    #[Test]
    public function the_secure_pending_request_terminal_is_constructor_immutable(): void
    {
        $terminal = new \ReflectionProperty(SecurePendingRequest::class, 'trustedTerminalHandler');

        $this->assertTrue($terminal->isReadOnly());
    }

    #[Test]
    public function a_forged_transport_capability_cannot_replace_the_secure_pending_terminal(): void
    {
        $trustedCalls = 0;
        $dangerousCalls = 0;
        $trustedTerminal = static function () use (&$trustedCalls): PromiseInterface {
            $trustedCalls++;

            return new FulfilledPromise(new PsrResponse(200, [], 'trusted'));
        };
        $dangerousTerminal = static function () use (&$dangerousCalls): PromiseInterface {
            $dangerousCalls++;

            return new FulfilledPromise(new PsrResponse(200, [], 'dangerous'));
        };
        $factory = $this->swapSecureHttpFactory($trustedTerminal);
        $request = $factory->createPendingRequest()
            ->setHandler($dangerousTerminal)
            ->withOptions(['handler' => $dangerousTerminal]);
        $forgedTarget = new ResolvedOutboundTarget(
            'http://169.254.169.254/latest/meta-data',
            'http',
            '169.254.169.254',
            80,
            ['169.254.169.254'],
            '169.254.169.254',
        );
        $forgedTransport = new LaravelPinnedOutboundTransport(new \stdClass);

        try {
            $forgedTransport->send($request, 'GET', $forgedTarget, [], 1024);
            $this->fail('Expected the forged transport capability to be rejected.');
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame('fixed_context_unauthorized', $exception->reasonCode);
        }

        $this->assertSame(0, $trustedCalls);
        $this->assertSame(0, $dangerousCalls);
        $this->assertSame('trusted', $request->get('https://public.test/path')->body());
        $this->assertSame(1, $trustedCalls);
        $this->assertSame(0, $dangerousCalls);
    }

    #[Test]
    public function the_application_transport_capability_enables_a_validated_fixed_target_without_network_access(): void
    {
        Http::preventStrayRequests();
        Http::fake(static fn (): PromiseInterface => Http::response('fixed-ok'));
        $target = app(SafeOutboundHttpClient::class)->resolveTarget('https://public.test/fixed');
        $firstRequest = Http::withRequestMiddleware(
            static fn (RequestInterface $request): RequestInterface => $request
                ->withUri($request->getUri()->withScheme('http')->withHost('169.254.169.254')->withPort(80))
                ->withHeader('Host', '169.254.169.254')
        );
        $secondRequest = Http::withRequestMiddleware(
            static fn (RequestInterface $request): RequestInterface => $request->withHeader('Host', 'forged.internal')
        );
        $firstTransport = app(OutboundTransport::class);
        $secondTransport = app(OutboundTransport::class);

        $firstResponse = $firstTransport->send($firstRequest, 'GET', $target, [], 1024);
        $secondResponse = $secondTransport->send($secondRequest, 'GET', $target, [], 1024);

        $this->assertNotSame($firstTransport, $secondTransport);
        $this->assertSame('fixed-ok', $firstResponse->body());
        $this->assertSame('fixed-ok', $secondResponse->body());
        Http::assertSentCount(2);
        Http::assertSent(static fn (LaravelRequest $request): bool => $request->url() === 'https://public.test/fixed'
            && $request->header('Host') === ['public.test']);
    }

    #[Test]
    public function direct_http_facade_ignores_nested_handlers_and_clients_for_sync_async_and_pool_requests(): void
    {
        $trustedRequests = [];
        $untrustedCalled = false;
        $trustedTerminal = static function (RequestInterface $request) use (&$trustedRequests): PromiseInterface {
            $trustedRequests[] = $request;

            return new FulfilledPromise(new PsrResponse(200, [], 'ok'));
        };
        $untrustedTerminal = static function () use (&$untrustedCalled): PromiseInterface {
            $untrustedCalled = true;

            return new FulfilledPromise(new PsrResponse(200, [], 'untrusted'));
        };
        $nestedStack = new HandlerStack($untrustedTerminal);
        $nestedStack->push(static function (callable $handler): callable {
            return static fn (RequestInterface $request, array $options) => $handler(
                $request->withUri($request->getUri()->withHost('private.test')),
                ['curl' => [CURLOPT_URL => 'http://127.0.0.1/private']] + $options,
            );
        });
        $this->swapSecureHttpFactory($trustedTerminal);

        $sync = Http::timeout(3)
            ->setHandler($nestedStack)
            ->setClient(new GuzzleClient(['handler' => $nestedStack]))
            ->get('https://public.test/sync');
        $async = Http::timeout(3)
            ->setHandler($nestedStack)
            ->setClient(new GuzzleClient(['handler' => $nestedStack]))
            ->async()
            ->get('https://public.test/async')
            ->wait();
        $optionHandler = Http::timeout(3)
            ->withOptions(['handler' => $nestedStack])
            ->get('https://public.test/option-handler');
        $pooled = Http::pool(static fn (Pool $pool): array => [
            $pool->get('https://public.test/pool'),
        ]);

        $this->assertSame('ok', $sync->body());
        $this->assertSame('ok', $async->body());
        $this->assertSame('ok', $optionHandler->body());
        $this->assertSame('ok', $pooled[0]->body());
        $this->assertFalse($untrustedCalled);
        $this->assertCount(4, $trustedRequests);
    }

    /** @return array<string, array{string}> */
    public static function directRequestModes(): array
    {
        return [
            'sync' => ['sync'],
            'async' => ['async'],
            'pool' => ['pool'],
        ];
    }

    /** @return array<string, array{string, callable}> */
    public static function forbiddenGenericMiddlewareModes(): array
    {
        $middleware = [
            'redirect middleware' => GuzzleMiddleware::redirect(),
            'http errors middleware' => GuzzleMiddleware::httpErrors(),
        ];
        $cases = [];

        foreach (array_keys(self::directRequestModes()) as $mode) {
            foreach ($middleware as $label => $callback) {
                $cases[$mode.' '.$label] = [$mode, $callback];
            }
        }

        return $cases;
    }

    #[Test]
    #[DataProvider('forbiddenGenericMiddlewareModes')]
    public function secure_pending_requests_reject_generic_middleware_before_sync_async_or_pool_dispatch(
        string $mode,
        callable $middleware,
    ): void {
        $terminalCalls = 0;
        $middlewareCalls = 0;
        $this->swapSecureHttpFactory(static function () use (&$terminalCalls): PromiseInterface {
            $terminalCalls++;

            return new FulfilledPromise(new PsrResponse(200, [], 'unexpected'));
        });
        Http::fake();
        $wrapped = static function (callable $handler) use ($middleware, &$middlewareCalls): callable {
            $middlewareCalls++;

            return $middleware($handler);
        };

        try {
            match ($mode) {
                'sync' => Http::timeout(3)->withMiddleware($wrapped)->get('https://public.test/sync'),
                'async' => Http::timeout(3)->async()->withMiddleware($wrapped)->get('https://public.test/async')->wait(),
                default => Http::pool(static fn (Pool $pool): array => [
                    $pool->timeout(3)->withMiddleware($wrapped)->get('https://public.test/pool'),
                ]),
            };
            $this->fail('Expected generic request middleware to be rejected.');
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame('generic_http_middleware_forbidden', $exception->reasonCode);
        }

        $this->assertSame(0, $middlewareCalls);
        $this->assertSame(0, $terminalCalls);
        Http::assertNothingSent();
    }

    #[Test]
    public function rejecting_generic_pending_middleware_does_not_mutate_the_request_collection(): void
    {
        $request = Http::timeout(3);
        $middlewareProperty = new \ReflectionProperty(PendingRequest::class, 'middleware');
        $before = $middlewareProperty->getValue($request)->all();

        try {
            $request->withMiddleware(GuzzleMiddleware::redirect());
            $this->fail('Expected generic request middleware to be rejected.');
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame('generic_http_middleware_forbidden', $exception->reasonCode);
        }

        $this->assertSame($before, $middlewareProperty->getValue($request)->all());
    }

    #[Test]
    public function rejecting_generic_middleware_does_not_disable_secure_factory_macros(): void
    {
        $captured = null;
        $factory = $this->swapSecureHttpFactory(
            static function (RequestInterface $request) use (&$captured): PromiseInterface {
                $captured = $request;

                return new FulfilledPromise(new PsrResponse(200, [], 'macro-ok'));
            }
        );
        SecureHttpFactory::macro(
            'securityTestRequest',
            fn (): PendingRequest => $this->createPendingRequest()->withHeader('X-Factory-Macro', 'kept'),
        );

        try {
            $response = $factory->securityTestRequest()->get('https://public.test/macro');
        } finally {
            SecureHttpFactory::flushMacros();
        }

        $this->assertSame('macro-ok', $response->body());
        $this->assertSame('kept', $captured->getHeaderLine('X-Factory-Macro'));
    }

    #[Test]
    #[DataProvider('forbiddenGenericMiddlewareModes')]
    public function secure_factory_rejects_generic_global_middleware_before_sync_async_or_pool_dispatch(
        string $mode,
        callable $middleware,
    ): void {
        $terminalCalls = 0;
        $middlewareCalls = 0;
        $factory = $this->swapSecureHttpFactory(static function () use (&$terminalCalls): PromiseInterface {
            $terminalCalls++;

            return new FulfilledPromise(new PsrResponse(200, [], 'unexpected'));
        });
        Http::fake();
        $before = $factory->getGlobalMiddleware();
        $wrapped = static function (callable $handler) use ($middleware, &$middlewareCalls): callable {
            $middlewareCalls++;

            return $middleware($handler);
        };

        try {
            Http::globalMiddleware($wrapped);
            match ($mode) {
                'sync' => Http::get('https://public.test/sync'),
                'async' => Http::async()->get('https://public.test/async')->wait(),
                default => Http::pool(static fn (Pool $pool): array => [
                    $pool->get('https://public.test/pool'),
                ]),
            };
            $this->fail('Expected generic global middleware to be rejected.');
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame('generic_http_middleware_forbidden', $exception->reasonCode);
        }

        $this->assertSame($before, $factory->getGlobalMiddleware());
        $this->assertSame(0, $middlewareCalls);
        $this->assertSame(0, $terminalCalls);
        Http::assertNothingSent();
    }

    /** @return array<string, array{string, string}> */
    public static function responseMiddlewarePolicyViolations(): array
    {
        return [
            'request encoded response' => ['request', 'encoded_response_forbidden'],
            'global encoded response' => ['global', 'encoded_response_forbidden'],
            'request oversized response' => ['request', 'response_too_large'],
            'global oversized response' => ['global', 'response_too_large'],
        ];
    }

    #[Test]
    #[DataProvider('responseMiddlewarePolicyViolations')]
    public function final_security_guards_responses_replaced_by_allowed_response_middleware(
        string $scope,
        string $reason,
    ): void {
        config(['geoflow.outbound_ai_max_bytes' => 1024]);
        $terminalCalls = 0;
        $factory = $this->swapSecureHttpFactory(static function () use (&$terminalCalls): PromiseInterface {
            $terminalCalls++;

            return new FulfilledPromise(new PsrResponse(200, [], 'safe'));
        });
        $replacement = $reason === 'encoded_response_forbidden'
            ? new PsrResponse(200, ['Content-Encoding' => 'gzip'], 'encoded')
            : new PsrResponse(200, [], str_repeat('x', 1025));
        $middleware = static fn (): PsrResponse => $replacement;

        $request = $scope === 'global'
            ? $factory->globalResponseMiddleware($middleware)->createPendingRequest()
            : $factory->createPendingRequest()->withResponseMiddleware($middleware);

        try {
            $request->get('https://public.test/v1/models');
            $this->fail('Expected the transformed response to be rejected.');
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame($reason, $exception->reasonCode);
        }

        $this->assertSame(1, $terminalCalls);
    }

    #[Test]
    public function allowed_global_and_request_hooks_remain_compatible_behind_the_final_boundary(): void
    {
        $calls = ['global_request' => 0, 'request' => 0, 'before' => 0, 'global_response' => 0, 'response' => 0];
        $captured = [];
        $transport = $this->swapSecureHttpTransport(
            static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
                $captured = ['request' => $request, 'options' => $options];

                return new FulfilledPromise(new PsrResponse(200, [], 'ok'));
            }
        );
        Http::globalRequestMiddleware(static function (RequestInterface $request) use (&$calls): RequestInterface {
            $calls['global_request']++;

            return $request->withUri($request->getUri()->withHost('global-private.test'));
        });
        Http::globalResponseMiddleware(static function (PsrResponse $response) use (&$calls): PsrResponse {
            $calls['global_response']++;

            return $response->withHeader('X-Global-Response', 'kept');
        });
        $this->assertCount(2, Http::getGlobalMiddleware());
        $request = Http::withRequestMiddleware(static function (RequestInterface $request) use (&$calls): RequestInterface {
            $calls['request']++;

            return $request->withUri($request->getUri()->withHost('request-private.test'));
        })->beforeSending(static function (LaravelRequest $request) use (&$calls): RequestInterface {
            $calls['before']++;

            return $request->toPsrRequest()->withUri($request->toPsrRequest()->getUri()->withHost('callback-private.test'));
        })->withResponseMiddleware(static function (PsrResponse $response) use (&$calls): PsrResponse {
            $calls['response']++;

            return $response->withHeader('X-Request-Response', 'kept');
        })->withOptions([
            'allow_redirects' => true,
            'http_errors' => true,
            'proxy' => 'http://evil-proxy.test:8080',
            'verify' => false,
        ]);
        $client = $this->client(['public.test' => ['93.184.216.34']], $transport);

        $response = $client->get($request, 'https://public.test/path', 1024);

        $this->assertSame('ok', $response->body());
        $this->assertSame('kept', $response->header('X-Global-Response'));
        $this->assertSame('kept', $response->header('X-Request-Response'));
        $this->assertSame(array_fill_keys(array_keys($calls), 1), $calls);
        $this->assertSame('https://public.test/path', (string) $captured['request']->getUri());
        $this->assertSame('public.test', $captured['request']->getHeaderLine('Host'));
        $this->assertFalse($captured['options']['allow_redirects']);
        $this->assertFalse($captured['options']['http_errors']);
        $this->assertSame('', $captured['options']['proxy']);
        $this->assertTrue($captured['options']['verify']);
    }

    #[Test]
    #[DataProvider('directRequestModes')]
    public function direct_http_never_auto_follows_redirects_or_forwards_api_keys(string $mode): void
    {
        $terminalRequests = [];
        $terminal = static function (RequestInterface $request) use (&$terminalRequests): PromiseInterface {
            $terminalRequests[] = $request;

            return new FulfilledPromise(
                $request->getUri()->getHost() === 'first.test'
                    ? new PsrResponse(302, ['Location' => 'https://second.test/collect'])
                    : new PsrResponse(200, [], 'followed')
            );
        };
        $this->swapSecureHttpFactory($terminal);
        $send = static fn (PendingRequest $request) => $request
            ->withHeader('x-goog-api-key', 'caller-secret')
            ->withOptions(['allow_redirects' => true])
            ->get('https://first.test/start');

        $response = match ($mode) {
            'sync' => $send(Http::timeout(3)),
            'async' => $send(Http::timeout(3)->async())->wait(),
            default => Http::pool(static fn (Pool $pool): array => [
                $send($pool->timeout(3)),
            ])[0],
        };

        $secondOriginRequests = array_values(array_filter(
            $terminalRequests,
            static fn (RequestInterface $request): bool => $request->getUri()->getHost() === 'second.test'
        ));
        $this->assertCount(1, $terminalRequests);
        $this->assertSame([], $secondOriginRequests);
        $this->assertSame(302, $response->status());
    }

    #[Test]
    public function direct_http_ignores_http_errors_and_returns_the_remote_error_response_to_the_business_layer(): void
    {
        $secretBody = 'synthetic-remote-secret-marker';
        $this->swapSecureHttpFactory(
            static fn (): PromiseInterface => new FulfilledPromise(new PsrResponse(500, [], $secretBody))
        );

        try {
            $response = Http::withOptions(['http_errors' => true])->get('https://public.test/failure');
        } catch (\Throwable $exception) {
            $this->assertStringNotContainsString($secretBody, $exception->getMessage());
            $this->fail('The default http_errors middleware escaped the final security policy.');
        }

        $this->assertSame(500, $response->status());
        $this->assertSame($secretBody, $response->body());
    }

    #[Test]
    public function explicit_transport_replaces_a_nested_caller_handler_with_its_trusted_terminal(): void
    {
        $captured = [];
        $nestedCalled = false;
        $trustedTerminal = static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
            $captured = ['request' => $request, 'options' => $options];

            return new FulfilledPromise(new PsrResponse(200, [], 'ok'));
        };
        $nestedStack = new HandlerStack(static function () use (&$nestedCalled): PromiseInterface {
            $nestedCalled = true;

            return new FulfilledPromise(new PsrResponse(200, [], 'untrusted'));
        });
        $nestedStack->push(static function (callable $handler): callable {
            return static fn (RequestInterface $request, array $options) => $handler(
                $request->withUri($request->getUri()->withHost('private.test')),
                ['curl' => [CURLOPT_URL => 'http://127.0.0.1/private']] + $options,
            );
        });
        $transport = $this->swapSecureHttpTransport($trustedTerminal);
        $request = Http::timeout(3)
            ->setHandler($nestedStack)
            ->withOptions(['handler' => $nestedStack]);
        $client = $this->client(['public.test' => ['93.184.216.34']], $transport);

        $this->assertSame('ok', $client->get($request, 'https://public.test/path', 1024)->body());
        $this->assertFalse($nestedCalled);
        $this->assertSame('https://public.test/path', (string) $captured['request']->getUri());
        $this->assertSame(['public.test:443:93.184.216.34'], $captured['options']['curl'][CURLOPT_RESOLVE]);
    }

    #[Test]
    public function explicit_transport_rejects_standard_pending_requests_before_any_handler_or_callback_runs(): void
    {
        $calls = [
            'middleware' => 0,
            'before' => 0,
            'stub' => 0,
            'nested' => 0,
            'terminal' => 0,
        ];
        $terminalHandler = static function () use (&$calls): PromiseInterface {
            $calls['terminal']++;

            return new FulfilledPromise(new PsrResponse(200, [], 'terminal'));
        };
        $nestedHandler = new HandlerStack($terminalHandler);
        $nestedHandler->push(static function (callable $handler) use (&$calls): callable {
            return static function (RequestInterface $request, array $options) use ($handler, &$calls) {
                $calls['nested']++;

                return $handler($request, $options);
            };
        });
        $request = (new PendingRequest)
            ->withMiddleware(static function (callable $handler) use (&$calls): callable {
                return static function (RequestInterface $request, array $options) use ($handler, &$calls) {
                    $calls['middleware']++;

                    return $handler($request, $options);
                };
            })
            ->beforeSending(static function () use (&$calls): void {
                $calls['before']++;
            })
            ->stub(static function () use (&$calls): PromiseInterface {
                $calls['stub']++;

                return new FulfilledPromise(new PsrResponse(200, [], 'stubbed'));
            })
            ->setHandler($nestedHandler)
            ->withOptions(['handler' => $nestedHandler]);
        $originalOptions = $request->getOptions();
        $target = new ResolvedOutboundTarget(
            'https://public.test/path',
            'https',
            'public.test',
            443,
            ['93.184.216.34'],
            '93.184.216.34',
        );
        $transport = new LaravelPinnedOutboundTransport(new \stdClass);

        try {
            $transport->send($request, 'GET', $target, [], 1024);
            $this->fail('Expected a standard PendingRequest to be rejected.');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(OutboundRequestBlockedException::class, $exception);
            $this->assertSame('secure_pending_request_required', $exception->reasonCode);
            $this->assertSame('Outbound request blocked by security policy.', $exception->getMessage());
        }

        $this->assertSame($originalOptions, $request->getOptions());
        $this->assertSame([
            'middleware' => 0,
            'before' => 0,
            'stub' => 0,
            'nested' => 0,
            'terminal' => 0,
        ], $calls);
    }

    #[Test]
    public function secure_http_factory_keeps_fake_recording_and_stray_request_controls_compatible(): void
    {
        $terminalCalled = false;
        $this->swapSecureHttpFactory(static function () use (&$terminalCalled): PromiseInterface {
            $terminalCalled = true;

            return new FulfilledPromise(new PsrResponse(200, [], 'unexpected'));
        });
        Http::preventStrayRequests();
        Http::fake(['https://public.test/fake' => Http::response('fake-ok')]);

        $this->assertSame('fake-ok', Http::get('https://public.test/fake')->body());
        Http::assertSentCount(1);
        Http::assertSent(static fn (LaravelRequest $request): bool => $request->url() === 'https://public.test/fake');
        $this->assertFalse($terminalCalled);
    }

    #[Test]
    public function the_hardened_default_stack_keeps_cookie_and_request_body_preparation(): void
    {
        $captured = [];
        $this->swapSecureHttpFactory(
            static function (RequestInterface $request) use (&$captured): PromiseInterface {
                $captured = $request;

                return new FulfilledPromise(new PsrResponse(200, [], 'ok'));
            }
        );

        $response = Http::withCookies(['session' => 'cookie-value'], 'public.test')
            ->asJson()
            ->post('https://public.test/body', ['field' => 'value']);

        $this->assertSame('ok', $response->body());
        $this->assertSame('session=cookie-value', $captured->getHeaderLine('Cookie'));
        $this->assertSame('application/json', $captured->getHeaderLine('Content-Type'));
        $this->assertSame('{"field":"value"}', (string) $captured->getBody());
    }

    #[Test]
    public function direct_http_facade_rejects_encoded_responses_after_the_terminal_handler(): void
    {
        $this->swapSecureHttpFactory(
            static fn (): PromiseInterface => new FulfilledPromise(
                new PsrResponse(200, ['Content-Encoding' => 'gzip'], 'encoded')
            )
        );

        $this->expectExceptionObject(new OutboundRequestBlockedException('encoded_response_forbidden'));
        Http::get('https://public.test/v1/models');
    }

    #[Test]
    public function direct_http_facade_rejects_oversized_responses_after_the_terminal_handler(): void
    {
        config(['geoflow.outbound_ai_max_bytes' => 1024]);
        $this->swapSecureHttpFactory(
            static fn (): PromiseInterface => new FulfilledPromise(
                new PsrResponse(200, [], str_repeat('x', 1025))
            )
        );

        $this->expectExceptionObject(new OutboundRequestBlockedException('response_too_large'));
        Http::get('https://public.test/v1/models');
    }

    #[Test]
    public function direct_http_facade_limits_unknown_size_streaming_responses_while_forcing_the_terminal_to_non_streaming_mode(): void
    {
        config(['geoflow.outbound_ai_max_bytes' => 1024]);
        $terminalStreamOption = null;
        $remaining = 1025;
        $stream = new PumpStream(static function (int $requested) use (&$remaining): string|false {
            if ($remaining === 0) {
                return false;
            }

            $length = min($requested, $remaining);
            $remaining -= $length;

            return str_repeat('x', $length);
        });
        $this->swapSecureHttpFactory(
            static function (RequestInterface $request, array $options) use (&$terminalStreamOption, $stream): PromiseInterface {
                $terminalStreamOption = $options['stream'] ?? null;

                return new FulfilledPromise(new PsrResponse(200, [], $stream));
            }
        );

        $response = Http::withOptions(['stream' => true])->get('https://public.test/v1/models');
        $body = $response->toPsrResponse()->getBody();

        $this->assertFalse($terminalStreamOption);
        $this->assertInstanceOf(ResponseSizeLimitedStream::class, $body);
        $this->assertSame(str_repeat('x', 1024), $body->read(1024));

        $this->expectExceptionObject(new OutboundRequestBlockedException('response_too_large'));
        $body->read(1);
    }

    #[Test]
    public function direct_http_facade_fails_closed_when_the_pinnable_transport_is_unavailable(): void
    {
        $terminalCalled = false;
        $policy = new FinalOutboundSecurityPolicy(static fn (bool $requiresDnsPin): bool => false);
        $this->swapSecureHttpFactory(
            static function () use (&$terminalCalled): PromiseInterface {
                $terminalCalled = true;

                return new FulfilledPromise(new PsrResponse(200, [], 'unexpected'));
            },
            $policy,
        );

        try {
            Http::get('https://public.test/v1/models');
            $this->fail('Expected the request to fail closed.');
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame('pinning_unavailable', $exception->reasonCode);
        } finally {
            $this->assertFalse($terminalCalled);
        }
    }

    #[Test]
    public function the_global_laravel_http_boundary_blocks_sdk_requests_that_bypass_the_explicit_gateway(): void
    {
        $resolver = app(HostResolver::class);
        $this->assertInstanceOf(FakeHostResolver::class, $resolver);
        $resolver->set('sdk-private.test', ['10.0.0.9']);
        Http::preventStrayRequests();
        Http::fake();

        try {
            Http::timeout(2)->connectTimeout(1)->get('https://sdk-private.test/v1/models');
            $this->fail('Expected the global boundary to reject a private SDK endpoint.');
        } catch (OutboundRequestBlockedException $exception) {
            $this->assertSame('unsafe_address', $exception->reasonCode);
        }

        Http::assertNothingSent();
    }

    #[Test]
    public function cross_origin_before_sending_callbacks_cannot_reintroduce_credentials(): void
    {
        $sent = [];
        $responses = [
            new PsrResponse(302, ['Location' => 'https://second.test/end']),
            new PsrResponse(200, [], 'ok'),
        ];
        $terminal = static function (RequestInterface $request) use (&$sent, &$responses): PromiseInterface {
            $sent[] = $request;

            return new FulfilledPromise(array_shift($responses));
        };
        $transport = $this->swapSecureHttpTransport($terminal);
        $request = Http::timeout(3)
            ->beforeSending(static function (LaravelRequest $request): ?RequestInterface {
                if ($request->url() !== 'https://second.test/end') {
                    return null;
                }

                return $request->toPsrRequest()
                    ->withHeader('Authorization', 'Bearer callback-secret')
                    ->withHeader('X-Signature', 'callback-signature')
                    ->withHeader('Accept', 'application/json');
            });
        $client = $this->client([
            'first.test' => ['93.184.216.34'],
            'second.test' => ['93.184.216.35'],
        ], $transport);

        $this->assertSame('ok', $client->get($request, 'https://first.test/start', 1024, 1)->body());
        $this->assertSame('', $sent[1]->getHeaderLine('Authorization'));
        $this->assertSame('', $sent[1]->getHeaderLine('X-Signature'));
        $this->assertSame('application/json', $sent[1]->getHeaderLine('Accept'));
    }

    #[Test]
    public function cross_origin_request_middleware_cannot_reintroduce_credentials(): void
    {
        $sent = [];
        $responses = [
            new PsrResponse(302, ['Location' => 'https://second.test/end']),
            new PsrResponse(200, [], 'ok'),
        ];
        $terminal = static function (RequestInterface $request) use (&$sent, &$responses): PromiseInterface {
            $sent[] = $request;

            return new FulfilledPromise(array_shift($responses));
        };
        $transport = $this->swapSecureHttpTransport($terminal);
        $request = Http::timeout(3)
            ->withRequestMiddleware(static function (RequestInterface $request): RequestInterface {
                if ((string) $request->getUri() !== 'https://second.test/end') {
                    return $request;
                }

                return $request
                    ->withUri($request->getUri()->withQuery('api_key=middleware-secret'))
                    ->withHeader('Authorization', 'Bearer middleware-secret')
                    ->withHeader('X-Signature', 'middleware-signature');
            });
        $client = $this->client([
            'first.test' => ['93.184.216.34'],
            'second.test' => ['93.184.216.35'],
        ], $transport);

        $this->assertSame('ok', $client->get($request, 'https://first.test/start', 1024, 1)->body());
        $this->assertSame('', $sent[1]->getHeaderLine('Authorization'));
        $this->assertSame('', $sent[1]->getHeaderLine('X-Signature'));
        $this->assertSame('', $sent[1]->getUri()->getQuery());
    }

    #[Test]
    public function cross_origin_redirects_do_not_forward_get_data_query(): void
    {
        $sent = [];
        $responses = [
            new PsrResponse(302, ['Location' => 'https://second.test/end?public=location']),
            new PsrResponse(200, [], 'ok'),
        ];
        $terminal = static function (RequestInterface $request) use (&$sent, &$responses): PromiseInterface {
            $sent[] = $request;

            return new FulfilledPromise(array_shift($responses));
        };
        $transport = $this->swapSecureHttpTransport($terminal);
        $request = Http::timeout(3);
        $client = $this->client([
            'first.test' => ['93.184.216.34'],
            'second.test' => ['93.184.216.35'],
        ], $transport);

        $response = $client->get($request, 'https://first.test/start', 1024, 1, [
            'api_key' => 'data-secret',
            'page' => 'private',
        ]);

        $this->assertSame('ok', $response->body());
        $this->assertSame('api_key=data-secret&page=private', $sent[0]->getUri()->getQuery());
        $this->assertSame('public=location', $sent[1]->getUri()->getQuery());
    }

    #[Test]
    public function cross_origin_redirects_do_not_forward_pending_request_query_options(): void
    {
        $sent = [];
        $responses = [
            new PsrResponse(302, ['Location' => 'https://second.test/end?public=location']),
            new PsrResponse(200, [], 'ok'),
        ];
        $terminal = static function (RequestInterface $request) use (&$sent, &$responses): PromiseInterface {
            $sent[] = $request;

            return new FulfilledPromise(array_shift($responses));
        };
        $transport = $this->swapSecureHttpTransport($terminal);
        $request = Http::timeout(3)
            ->withOptions(['query' => ['api_key' => 'option-secret', 'page' => 'private']]);
        $client = $this->client([
            'first.test' => ['93.184.216.34'],
            'second.test' => ['93.184.216.35'],
        ], $transport);

        $this->assertSame('ok', $client->get($request, 'https://first.test/start', 1024, 1)->body());
        $this->assertSame('api_key=option-secret&page=private', $sent[0]->getUri()->getQuery());
        $this->assertSame('public=location', $sent[1]->getUri()->getQuery());
    }

    #[Test]
    public function same_origin_redirects_keep_credentials_added_by_caller_hooks(): void
    {
        $sent = [];
        $responses = [
            new PsrResponse(302, ['Location' => '/end']),
            new PsrResponse(200, [], 'ok'),
        ];
        $terminal = static function (RequestInterface $request) use (&$sent, &$responses): PromiseInterface {
            $sent[] = $request;

            return new FulfilledPromise(array_shift($responses));
        };
        $transport = $this->swapSecureHttpTransport($terminal);
        $request = Http::timeout(3)
            ->withRequestMiddleware(static fn (RequestInterface $request): RequestInterface => $request->withHeader('X-Middleware-Key', 'middleware-secret'))
            ->beforeSending(static fn (LaravelRequest $request): RequestInterface => $request->toPsrRequest()->withHeader('X-Callback-Key', 'callback-secret'));
        $client = $this->client(
            ['public.test' => ['93.184.216.34']],
            $transport,
        );

        $this->assertSame('ok', $client->get($request, 'https://public.test/start', 1024, 1)->body());
        $this->assertSame('middleware-secret', $sent[1]->getHeaderLine('X-Middleware-Key'));
        $this->assertSame('callback-secret', $sent[1]->getHeaderLine('X-Callback-Key'));
    }

    #[Test]
    public function same_origin_redirects_keep_get_data_and_pending_request_queries(): void
    {
        $dataSent = [];
        $dataResponses = [
            new PsrResponse(302, ['Location' => '/end']),
            new PsrResponse(200, [], 'ok'),
        ];
        $dataTerminal = static function (RequestInterface $request) use (&$dataSent, &$dataResponses): PromiseInterface {
            $dataSent[] = $request;

            return new FulfilledPromise(array_shift($dataResponses));
        };
        $dataTransport = $this->swapSecureHttpTransport($dataTerminal);
        $dataRequest = Http::timeout(3);
        $client = $this->client(
            ['public.test' => ['93.184.216.34']],
            $dataTransport,
        );

        $this->assertSame('ok', $client->get($dataRequest, 'https://public.test/start', 1024, 1, ['api_key' => 'data-secret'])->body());
        $this->assertSame('api_key=data-secret', $dataSent[1]->getUri()->getQuery());

        $optionSent = [];
        $optionResponses = [
            new PsrResponse(302, ['Location' => '/end']),
            new PsrResponse(200, [], 'ok'),
        ];
        $optionTerminal = static function (RequestInterface $request) use (&$optionSent, &$optionResponses): PromiseInterface {
            $optionSent[] = $request;

            return new FulfilledPromise(array_shift($optionResponses));
        };
        $optionTransport = $this->swapSecureHttpTransport($optionTerminal);
        $optionRequest = Http::timeout(3)
            ->withOptions(['query' => ['api_key' => 'option-secret']]);

        $optionClient = $this->client(
            ['public.test' => ['93.184.216.34']],
            $optionTransport,
        );
        $this->assertSame('ok', $optionClient->get($optionRequest, 'https://public.test/start', 1024, 1)->body());
        $this->assertSame('api_key=option-secret', $optionSent[1]->getUri()->getQuery());
    }

    #[Test]
    public function cross_origin_redirects_drop_basic_auth_and_cookie_credentials(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://first.test/start' => Http::response('', 302, ['Location' => 'https://second.test/end']),
            'https://second.test/end' => Http::response('ok'),
        ]);
        $captured = [];
        $request = Http::timeout(3)
            ->connectTimeout(1)
            ->withBasicAuth('user', 'secret')
            ->withHeaders([
                'Accept' => 'application/json',
                'Accept-Language' => 'zh-CN',
                'User-Agent' => 'GEOFlow Test',
                'Cookie' => 'session=secret',
                'X-Api-Key' => 'api-secret',
                'X-Signature' => 'hmac-secret',
            ])
            ->beforeSending(static function ($request, array $options) use (&$captured): void {
                $captured[] = [
                    'options' => $options,
                    'cookie' => $request->header('Cookie'),
                    'api_key' => $request->header('X-Api-Key'),
                    'signature' => $request->header('X-Signature'),
                    'accept' => $request->header('Accept'),
                    'language' => $request->header('Accept-Language'),
                    'user_agent' => $request->header('User-Agent'),
                ];
            });
        $client = $this->client([
            'first.test' => ['93.184.216.34'],
            'second.test' => ['93.184.216.35'],
        ], app(OutboundTransport::class));

        $this->assertSame('ok', $client->get($request, 'https://first.test/start', 1024, 1)->body());
        $this->assertSame(['user', 'secret'], $captured[0]['options']['auth']);
        $this->assertNull($captured[1]['options']['auth'] ?? null);
        $this->assertSame([''], $captured[1]['cookie']);
        $this->assertSame([''], $captured[1]['api_key']);
        $this->assertSame([''], $captured[1]['signature']);
        $this->assertSame(['application/json'], $captured[1]['accept']);
        $this->assertSame(['zh-CN'], $captured[1]['language']);
        $this->assertSame(['GEOFlow Test'], $captured[1]['user_agent']);
    }

    #[Test]
    public function same_origin_redirects_keep_custom_authentication_headers(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://public.test/start' => Http::response('', 302, ['Location' => '/end']),
            'https://public.test/end' => Http::response('ok'),
        ]);
        $captured = [];
        $request = Http::timeout(3)
            ->withHeaders([
                'X-Api-Key' => 'api-secret',
                'X-Signature' => 'hmac-secret',
            ])
            ->beforeSending(static function ($request) use (&$captured): void {
                $captured[] = [
                    'api_key' => $request->header('X-Api-Key'),
                    'signature' => $request->header('X-Signature'),
                ];
            });
        $client = $this->client(['public.test' => ['93.184.216.34']], app(OutboundTransport::class));

        $this->assertSame('ok', $client->get($request, 'https://public.test/start', 1024, 1)->body());
        $this->assertSame(['api-secret'], $captured[1]['api_key']);
        $this->assertSame(['hmac-secret'], $captured[1]['signature']);
    }

    /** @return array<string, array{int}> */
    public static function redirectsConvertedToGet(): array
    {
        return [
            '301' => [301],
            '302' => [302],
            '303' => [303],
        ];
    }

    #[Test]
    #[DataProvider('redirectsConvertedToGet')]
    public function redirects_converted_to_get_clear_the_request_body_and_content_headers(int $status): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://first.test/start' => Http::response('', $status, ['Location' => 'https://second.test/end']),
            'https://second.test/end' => Http::response('ok'),
        ]);
        $captured = [];
        $request = Http::timeout(3)
            ->withHeaders(['X-Signature' => 'hmac-secret'])
            ->withBody('sensitive-body', 'application/json')
            ->beforeSending(static function ($request) use (&$captured): void {
                $captured[] = [
                    'method' => $request->method(),
                    'body' => $request->body(),
                    'content_type' => $request->header('Content-Type'),
                    'signature' => $request->header('X-Signature'),
                ];
            });
        $client = $this->client([
            'first.test' => ['93.184.216.34'],
            'second.test' => ['93.184.216.35'],
        ], app(OutboundTransport::class));

        $response = $client->send($request, 'POST', 'https://first.test/start', [], 1024, 1);

        $this->assertSame('ok', $response->body());
        $this->assertSame('GET', $captured[1]['method']);
        $this->assertSame('', $captured[1]['body']);
        $this->assertSame([''], $captured[1]['content_type']);
        $this->assertSame([''], $captured[1]['signature']);
    }

    /** @return array<string, array{int}> */
    public static function redirectsPreservingEntitySemantics(): array
    {
        return [
            '307' => [307],
            '308' => [308],
        ];
    }

    #[Test]
    #[DataProvider('redirectsPreservingEntitySemantics')]
    public function cross_origin_redirects_preserving_entity_semantics_clear_custom_signatures(int $status): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://first.test/start' => Http::response('', $status, ['Location' => 'https://second.test/end']),
            'https://second.test/end' => Http::response('ok'),
        ]);
        $captured = [];
        $request = Http::timeout(3)
            ->withHeaders(['X-Signature' => 'hmac-secret'])
            ->withBody('request-body', 'application/json')
            ->beforeSending(static function ($request) use (&$captured): void {
                $captured[] = [
                    'method' => $request->method(),
                    'body' => $request->body(),
                    'content_type' => $request->header('Content-Type'),
                    'signature' => $request->header('X-Signature'),
                ];
            });
        $client = $this->client([
            'first.test' => ['93.184.216.34'],
            'second.test' => ['93.184.216.35'],
        ], app(OutboundTransport::class));

        $response = $client->send($request, 'POST', 'https://first.test/start', [], 1024, 1);

        $this->assertSame('ok', $response->body());
        $this->assertSame('POST', $captured[1]['method']);
        $this->assertSame('request-body', $captured[1]['body']);
        $this->assertSame(['application/json'], $captured[1]['content_type']);
        $this->assertSame([''], $captured[1]['signature']);
    }

    #[Test]
    public function transport_runtime_failures_are_centrally_redacted(): void
    {
        $transport = new class implements OutboundTransport
        {
            public function send(
                PendingRequest $request,
                string $method,
                ResolvedOutboundTarget $target,
                array $data,
                int $maxBytes,
                bool $crossOrigin = false,
            ): Response {
                throw new \RuntimeException('TLS failed for https://secret.test via 10.0.0.9 token=super-secret');
            }
        };
        $client = $this->client(['secret.test' => ['93.184.216.34']], $transport);

        try {
            $client->get(Http::timeout(2), 'https://secret.test/path', 1024);
            $this->fail('Expected transport failure.');
        } catch (OutboundRequestFailedException $exception) {
            $this->assertSame('outbound_request_failed', $exception->reasonCode);
            $this->assertSame('Outbound request failed.', $exception->getMessage());
            $this->assertStringNotContainsString('super-secret', $exception->getMessage());
            $this->assertStringNotContainsString('10.0.0.9', $exception->getMessage());
            $this->assertStringNotContainsString('secret.test', $exception->getMessage());
        }
    }

    /** @return array<int, mixed> */
    private function dangerousCurlOptions(): array
    {
        $options = [
            CURLOPT_URL => 'http://127.0.0.1/private',
            CURLOPT_PORT => 80,
            CURLOPT_CONNECT_TO => ['public.test:443:127.0.0.1:80'],
            CURLOPT_RESOLVE => ['public.test:443:10.0.0.9'],
            CURLOPT_PROXY => 'http://evil-proxy.test:8080',
            CURLOPT_NOPROXY => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_ENCODING => 'gzip, deflate, br',
            CURLOPT_INTERFACE => 'lo',
            CURLOPT_LOCALPORT => 31337,
            CURLOPT_CUSTOMREQUEST => 'DELETE',
            CURLOPT_POSTFIELDS => 'middleware-body',
            CURLOPT_WRITEFUNCTION => static fn (): int => 0,
            CURLOPT_HEADERFUNCTION => static fn (): int => 0,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => 'attacker:secret',
            CURLOPT_XFERINFOFUNCTION => static fn (): int => 0,
        ];

        foreach ([
            'CURLOPT_ABSTRACT_UNIX_SOCKET' => 'geoflow-internal.sock',
            'CURLOPT_ALTSVC' => '/tmp/geoflow-altsvc',
            'CURLOPT_ALTSVC_CTRL' => 0,
            'CURLOPT_DNS_SERVERS' => '127.0.0.1',
            'CURLOPT_DOH_URL' => 'http://127.0.0.1/dns-query',
            'CURLOPT_HTTPHEADER' => ['Host: 127.0.0.1'],
            'CURLOPT_LOCALPORTRANGE' => 10,
            'CURLOPT_PROXYPORT' => 8080,
            'CURLOPT_PROXYUSERPWD' => 'attacker:secret',
            'CURLOPT_UNIX_SOCKET_PATH' => '/var/run/docker.sock',
        ] as $constant => $value) {
            if (defined($constant)) {
                $options[constant($constant)] = $value;
            }
        }

        if (defined('CURLOPT_PROTOCOLS')) {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_FILE;
        }
        if (defined('CURLOPT_REDIR_PROTOCOLS')) {
            $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_FILE;
        }

        return $options;
    }

    /** @return list<int> */
    private function safeCurlOptionKeys(bool $withResolve): array
    {
        $keys = [
            CURLOPT_ENCODING,
            CURLOPT_FOLLOWLOCATION,
            CURLOPT_IPRESOLVE,
            CURLOPT_MAXREDIRS,
            CURLOPT_NOPROGRESS,
            CURLOPT_NOPROXY,
            CURLOPT_PORT,
            CURLOPT_PROXY,
            CURLOPT_SSL_VERIFYHOST,
            CURLOPT_SSL_VERIFYPEER,
            CURLOPT_URL,
            CURLOPT_XFERINFOFUNCTION,
        ];
        if ($withResolve) {
            $keys[] = CURLOPT_RESOLVE;
        }
        foreach (['CURLOPT_PROTOCOLS', 'CURLOPT_REDIR_PROTOCOLS'] as $constant) {
            if (defined($constant)) {
                $keys[] = constant($constant);
            }
        }

        sort($keys);

        return $keys;
    }

    /** @param array<int|string, mixed> $items
     * @return list<int|string>
     */
    private function sortedKeys(array $items): array
    {
        $keys = array_keys($items);
        sort($keys);

        return $keys;
    }

    private function swapSecureHttpFactory(
        callable $trustedTerminal,
        ?FinalOutboundSecurityPolicy $securityPolicy = null,
        ?object $fixedContextCapability = null,
    ): SecureHttpFactory {
        $resolveTarget = Closure::fromCallable(
            fn (string $url): ResolvedOutboundTarget => app(SafeOutboundHttpClient::class)->resolveTarget($url)
        );
        $factory = new SecureHttpFactory(
            app('events'),
            $securityPolicy ?? app(FinalOutboundSecurityPolicy::class),
            $resolveTarget,
            Closure::fromCallable($trustedTerminal),
            $fixedContextCapability ?? new \stdClass,
        );
        Http::swap($factory);

        return $factory;
    }

    private function swapSecureHttpTransport(
        callable $trustedTerminal,
        ?FinalOutboundSecurityPolicy $securityPolicy = null,
    ): LaravelPinnedOutboundTransport {
        $fixedContextCapability = new \stdClass;
        $this->swapSecureHttpFactory($trustedTerminal, $securityPolicy, $fixedContextCapability);

        return new LaravelPinnedOutboundTransport($fixedContextCapability);
    }

    /**
     * @param  array<string, list<string>>  $records
     */
    private function client(array $records, OutboundTransport $transport): SafeOutboundHttpClient
    {
        $resolver = new class($records) implements HostResolver
        {
            /** @param array<string, list<string>> $records */
            public function __construct(private readonly array $records) {}

            public function resolve(string $host): array
            {
                return $this->records[$host] ?? [];
            }
        };

        return new SafeOutboundHttpClient($resolver, $transport);
    }

    private function host(string $url): string
    {
        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }
}

final class RecordingOutboundTransport implements OutboundTransport
{
    /** @var list<ResolvedOutboundTarget> */
    public array $targets = [];

    /** @param list<Response> $responses */
    public function __construct(private array $responses = []) {}

    public function send(
        PendingRequest $request,
        string $method,
        ResolvedOutboundTarget $target,
        array $data,
        int $maxBytes,
        bool $crossOrigin = false,
    ): Response {
        $this->targets[] = $target;

        return array_shift($this->responses) ?? new Response(new PsrResponse(200, [], '{}'));
    }
}
