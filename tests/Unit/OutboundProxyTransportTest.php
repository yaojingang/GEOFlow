<?php

namespace Tests\Unit;

use App\Contracts\Outbound\HostResolver;
use App\Services\Outbound\FinalOutboundSecurityPolicy;
use App\Services\Outbound\LaravelPinnedOutboundTransport;
use App\Services\Outbound\OutboundProxyPolicy;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class OutboundProxyTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        foreach (['curl', 'pcntl', 'openssl'] as $extension) {
            if (! extension_loaded($extension)) {
                $this->markTestSkipped('Real proxy transport tests require '.$extension.'.');
            }
        }
    }

    /** @return array<string, array{string, string}> */
    public static function httpProxyTargets(): array
    {
        return [
            'IPv4 target' => ['93.184.216.34', '93.184.216.34:80'],
            'IPv6 target through an IPv4-only proxy' => ['2606:4700:4700::1111', '[2606:4700:4700::1111]:80'],
        ];
    }

    #[Test]
    #[DataProvider('httpProxyTargets')]
    public function it_tunnels_http_to_the_checked_ip_through_an_ipv4_only_proxy(string $ip, string $authority): void
    {
        $capture = $this->withProxy(
            fn ($connection): array => $this->serveHttpTunnel($connection),
            function (string $address) use ($ip): void {
                $response = $this->requestThroughProxy('http://localhost:'.$address, 'http://public.test/probe', $ip);

                $this->assertSame(200, $response->status());
                $this->assertSame('local proxy response', $response->body());
            },
        );

        $this->assertSame('CONNECT '.$authority.' HTTP/1.1', strtok($capture['connect'], "\r\n"));
        $this->assertTargetRequest($capture['request']);
    }

    #[Test]
    public function it_uses_the_configured_proxy_even_when_the_environment_bypasses_all_proxies(): void
    {
        $capture = $this->withProxy(
            fn ($connection): array => $this->serveHttpTunnel($connection),
            function (string $address): void {
                $response = $this->requestThroughProxy('http://127.0.0.1:'.$address, 'http://public.test/probe');

                $this->assertSame(200, $response->status());
            },
            noProxy: '*',
        );

        $this->assertStringStartsWith('CONNECT 93.184.216.34:80 HTTP/1.1', $capture['connect']);
        $this->assertTargetRequest($capture['request']);
    }

    #[Test]
    public function it_preserves_target_sni_and_certificate_hostname_verification_through_an_http_proxy(): void
    {
        $this->withCertificates(['public.test', 'other.test'], function (array $certificates, string $ca): void {
            $capture = $this->withProxy(
                fn ($connection): array => $this->serveHttpTunnel($connection, $certificates['other.test'], $certificates['public.test']),
                function (string $address) use ($ca): void {
                    $response = $this->requestThroughProxy('http://127.0.0.1:'.$address, 'https://public.test/probe', ca: $ca);

                    $this->assertSame(200, $response->status());
                    $this->assertSame('local proxy response', $response->body());
                },
            );

            $this->assertStringStartsWith('CONNECT 93.184.216.34:443 HTTP/1.1', $capture['connect']);
            $this->assertTrue($capture['tls']);
            $this->assertTargetRequest($capture['request']);
        });
    }

    /** @return array<string, array{string, bool}> */
    public static function invalidTargetCertificates(): array
    {
        return [
            'trusted certificate with a different hostname' => ['other.test', true],
            'matching hostname with an untrusted issuer' => ['public.test', false],
        ];
    }

    #[Test]
    #[DataProvider('invalidTargetCertificates')]
    public function it_rejects_unverified_target_certificates_through_an_http_proxy(string $host, bool $trustIssuer): void
    {
        $this->withCertificates([$host], function (array $certificates, string $ca) use ($host, $trustIssuer): void {
            $capture = $this->withProxy(
                fn ($connection): array => $this->serveHttpTunnel($connection, $certificates[$host], expectTlsRejection: true),
                function (string $address) use ($ca, $trustIssuer): void {
                    try {
                        $this->requestThroughProxy('http://127.0.0.1:'.$address, 'https://public.test/probe', ca: $trustIssuer ? $ca : null);
                        $this->fail('The target certificate must have a trusted issuer and match the original hostname.');
                    } catch (OutboundRequestFailedException $exception) {
                        $this->assertSame('tls', $exception->transportCategory);
                    }
                },
            );

            $this->assertStringStartsWith('CONNECT 93.184.216.34:443 HTTP/1.1', $capture['connect']);
            $this->assertSame('', $capture['request']);
        });
    }

    /** @return array<string, array{string, string, int}> */
    public static function socksTargets(): array
    {
        return [
            'SOCKS5 IPv4' => ['socks5', '93.184.216.34', 1],
            'SOCKS5H IPv4' => ['socks5h', '93.184.216.34', 1],
            'SOCKS5 IPv6' => ['socks5', '2606:4700:4700::1111', 4],
            'SOCKS5H IPv6' => ['socks5h', '2606:4700:4700::1111', 4],
        ];
    }

    #[Test]
    #[DataProvider('socksTargets')]
    public function it_sends_the_checked_numeric_address_to_socks_without_proxy_dns(string $scheme, string $ip, int $addressType): void
    {
        $capture = $this->withProxy(
            fn ($connection): array => $this->serveSocksTunnel($connection),
            function (string $address) use ($scheme, $ip): void {
                $response = $this->requestThroughProxy($scheme.'://127.0.0.1:'.$address, 'http://public.test/probe', $ip);

                $this->assertSame(200, $response->status());
                $this->assertSame('local proxy response', $response->body());
            },
        );

        $this->assertSame($addressType, $capture['address_type'], json_encode($capture));
        $this->assertSame($ip, $capture['address']);
        $this->assertSame(80, $capture['port']);
        $this->assertTargetRequest($capture['request']);
    }

    #[Test]
    public function it_tunnels_the_checked_address_through_a_verified_https_proxy(): void
    {
        if (! (curl_version()['features'] & CURL_VERSION_HTTPS_PROXY)) {
            $this->markTestSkipped('libcurl does not support HTTPS proxies.');
        }

        $this->withCertificates(['127.0.0.1'], function (array $certificates, string $ca): void {
            $capture = $this->withProxy(
                function ($connection) use ($certificates): array {
                    $this->enableServerTls($connection, $certificates['127.0.0.1']);

                    return $this->serveHttpTunnel($connection);
                },
                function (string $address) use ($ca): void {
                    $response = $this->requestThroughProxy('https://127.0.0.1:'.$address, 'http://public.test/probe', ca: $ca);

                    $this->assertSame(200, $response->status());
                    $this->assertSame('local proxy response', $response->body());
                },
            );

            $this->assertStringStartsWith('CONNECT 93.184.216.34:80 HTTP/1.1', $capture['connect']);
            $this->assertTargetRequest($capture['request']);
        });
    }

    private function requestThroughProxy(string $proxy, string $url, string $ip = '93.184.216.34', ?string $ca = null): Response
    {
        $capability = new \stdClass;
        $curl = new CurlHandler;
        $terminal = static function (RequestInterface $request, array $options) use ($curl, $proxy, $ca) {
            $bypass = $options['curl'][CURLOPT_NOPROXY] ?? null;
            if (! in_array($options['proxy'] ?? null, [$proxy, preg_replace('/^socks5h:/', 'socks5:', $proxy)], true) || isset($options['curl'][CURLOPT_PROXY])
                || ($bypass !== null && $bypass !== '')
                || ($bypass === null && (getenv('NO_PROXY') !== '' || getenv('no_proxy') !== ''))) {
                throw new RuntimeException('The local proxy fixture requires all connections to use its listener.');
            }
            if ($ca !== null) {
                $options['curl'][CURLOPT_CAINFO] = $ca;
                $options['curl'][CURLOPT_PROXY_CAINFO] = $ca;
            }

            return $curl($request, $options);
        };
        $factory = new SecureHttpFactory(
            app('events'),
            new FinalOutboundSecurityPolicy,
            static fn (string $url) => throw new RuntimeException('This test uses the fixed transport context.'),
            $terminal,
            $capability,
        );
        app()->instance(HttpFactory::class, $factory);
        Http::clearResolvedInstances();
        $resolver = new class($ip) implements HostResolver
        {
            public function __construct(private readonly string $ip) {}

            public function resolve(string $host): array
            {
                return [$this->ip];
            }
        };
        $client = new SafeOutboundHttpClient(
            $resolver,
            new LaravelPinnedOutboundTransport($capability),
            OutboundProxyPolicy::fromConfig($proxy, 'public.test'),
        );

        return $client->get(Http::timeout(3)->connectTimeout(3), $url, 1024);
    }

    /** @return array<string, mixed> */
    private function withProxy(callable $serve, callable $request, string $noProxy = ''): array
    {
        $capturePath = tempnam(sys_get_temp_dir(), 'geoflow-proxy-');
        $server = null;
        $pid = null;
        $environment = ['NO_PROXY' => getenv('NO_PROXY'), 'no_proxy' => getenv('no_proxy')];
        try {
            $server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
            $this->assertIsResource($server, $errorMessage);
            $port = substr(strrchr(stream_socket_get_name($server, false), ':'), 1);
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);

            if ($pid === 0) {
                $connection = null;
                try {
                    $connection = stream_socket_accept($server, 4);
                    if (! is_resource($connection)) {
                        throw new RuntimeException('The local proxy received no connection.');
                    }
                    stream_set_timeout($connection, 3);
                    $capture = $serve($connection);
                } catch (Throwable $exception) {
                    $capture = ['fixture_error' => $exception->getMessage()];
                } finally {
                    file_put_contents($capturePath, json_encode($capture, JSON_THROW_ON_ERROR));
                    if (is_resource($connection)) {
                        fclose($connection);
                    }
                    fclose($server);
                }
                exit(0);
            }

            foreach (array_keys($environment) as $name) {
                putenv($name.'='.$noProxy);
            }
            $request($port);
        } finally {
            if (is_resource($server)) {
                fclose($server);
            }
            if ($pid > 0) {
                pcntl_waitpid($pid, $status);
            }
            foreach ($environment as $name => $value) {
                putenv($value === false ? $name : $name.'='.$value);
            }
            try {
                $encodedCapture = file_get_contents($capturePath);
            } finally {
                unlink($capturePath);
            }
        }

        $capture = json_decode($encodedCapture, true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue(pcntl_wifexited($status));
        $this->assertSame(0, pcntl_wexitstatus($status));
        $this->assertArrayNotHasKey('fixture_error', $capture, $capture['fixture_error'] ?? '');

        return $capture;
    }

    /**
     * @param  resource  $connection
     * @return array<string, mixed>
     */
    private function serveHttpTunnel($connection, ?string $certificate = null, ?string $sniCertificate = null, bool $expectTlsRejection = false): array
    {
        $capture = ['connect' => $this->readHeaders($connection), 'request' => ''];
        if (! str_starts_with($capture['connect'], 'CONNECT ')) {
            fwrite($connection, "HTTP/1.1 400 CONNECT Required\r\nContent-Length: 0\r\n\r\n");

            return $capture;
        }
        fwrite($connection, "HTTP/1.1 200 Connection Established\r\n\r\n");
        if ($certificate !== null) {
            $capture['tls'] = $this->enableServerTls($connection, $certificate, $sniCertificate);
            if (! $capture['tls']) {
                return $capture;
            }
        }
        if ($expectTlsRejection) {
            $capture['request'] = @fread($connection, 16384) ?: '';
        } else {
            $capture['request'] = $this->readHeaders($connection);
        }
        if ($capture['request'] !== '') {
            $this->respond($connection);
        }

        return $capture;
    }

    /**
     * @param  resource  $connection
     * @return array<string, mixed>
     */
    private function serveSocksTunnel($connection): array
    {
        $greeting = $this->readBytes($connection, 2);
        $this->readBytes($connection, ord($greeting[1]));
        fwrite($connection, "\x05\x00");
        $header = $this->readBytes($connection, 4);
        $addressType = ord($header[3]);
        $address = match ($addressType) {
            1 => inet_ntop($this->readBytes($connection, 4)),
            4 => inet_ntop($this->readBytes($connection, 16)),
            3 => $this->readBytes($connection, ord($this->readBytes($connection, 1))),
            default => throw new RuntimeException('Unexpected SOCKS address type.'),
        };
        $port = unpack('n', $this->readBytes($connection, 2))[1];
        fwrite($connection, "\x05\x00\x00\x01\x00\x00\x00\x00\x00\x00");
        $request = $this->readHeaders($connection);
        $this->respond($connection);

        return ['address_type' => $addressType, 'address' => $address, 'port' => $port, 'request' => $request];
    }

    /** @param resource $connection */
    private function enableServerTls($connection, string $certificate, ?string $sniCertificate = null): bool
    {
        stream_context_set_option($connection, 'ssl', 'local_cert', $certificate);
        stream_context_set_option($connection, 'ssl', 'verify_peer', false);
        if ($sniCertificate !== null) {
            // The valid target certificate is selected only for the original target SNI.
            stream_context_set_option($connection, 'ssl', 'SNI_server_certs', ['public.test' => $sniCertificate]);
        }

        return @stream_socket_enable_crypto($connection, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
    }

    /** @param resource $connection */
    private function readHeaders($connection): string
    {
        $headers = '';
        while (! str_ends_with($headers, "\r\n\r\n") && strlen($headers) < 16384) {
            $headers .= $this->readBytes($connection, 1);
        }

        return $headers;
    }

    /** @param resource $connection */
    private function readBytes($connection, int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = fread($connection, $length - strlen($bytes));
            if (! is_string($chunk) || $chunk === '') {
                throw new RuntimeException('The local proxy connection ended before the expected bytes.');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    /** @param resource $connection */
    private function respond($connection): void
    {
        $body = 'local proxy response';
        fwrite($connection, "HTTP/1.1 200 OK\r\nConnection: close\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
    }

    private function assertTargetRequest(string $request): void
    {
        $this->assertStringStartsWith("GET /probe HTTP/1.1\r\n", $request);
        $this->assertStringContainsString("\r\nHost: public.test\r\n", $request);
    }

    /** @param list<string> $hosts */
    private function withCertificates(array $hosts, callable $test): void
    {
        $directory = sys_get_temp_dir().'/geoflow-proxy-tls-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        try {
            $certificates = [];
            $ca = $directory.'/ca.pem';
            foreach ($hosts as $host) {
                $config = $directory.'/'.$host.'.cnf';
                $san = filter_var($host, FILTER_VALIDATE_IP) ? 'IP:'.$host : 'DNS:'.$host;
                file_put_contents($config, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nbasicConstraints=critical,CA:TRUE\nsubjectAltName=".$san."\n");
                $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'x509_extensions' => 'v3'];
                $key = openssl_pkey_new($options);
                $csr = openssl_csr_new(['commonName' => $host], $key, $options);
                $certificate = openssl_csr_sign($csr, null, $key, 1, $options);
                openssl_x509_export($certificate, $public);
                openssl_pkey_export($key, $private);
                $certificates[$host] = $directory.'/'.$host.'.pem';
                file_put_contents($certificates[$host], $public.$private);
                file_put_contents($ca, $public, FILE_APPEND);
            }
            $test($certificates, $ca);
        } finally {
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }
}
