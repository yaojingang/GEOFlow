<?php

namespace Tests\Unit\GeoFlowCli;

use App\Console\GeoFlowCli\BaseUrlPolicy;
use App\Console\GeoFlowCli\CliException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class BaseUrlPolicyTest extends TestCase
{
    #[Test]
    public function missing_scheme_defaults_to_https(): void
    {
        $this->assertSame('https://api.example.com', BaseUrlPolicy::validate('api.example.com', false));
    }

    #[Test]
    #[DataProvider('localHttpUrls')]
    public function local_http_targets_are_allowed(string $url): void
    {
        $this->assertSame($url, BaseUrlPolicy::validate($url, false));
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function localHttpUrls(): iterable
    {
        yield 'localhost' => ['http://localhost:8000'];
        yield 'localhost suffix' => ['http://demo.localhost'];
        yield 'loopback ipv4 range' => ['http://127.44.1.2'];
        yield 'loopback ipv6' => ['http://[::1]:8080'];
    }

    #[Test]
    #[DataProvider('unsafeUrls')]
    public function unsafe_url_components_are_rejected(string $url): void
    {
        $this->expectException(CliException::class);

        BaseUrlPolicy::validate($url, false);
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function unsafeUrls(): iterable
    {
        yield 'remote cleartext' => ['http://api.example.com'];
        yield 'userinfo' => ['https://token@example.com'];
        yield 'query' => ['https://example.com?token=x'];
        yield 'fragment' => ['https://example.com#x'];
        yield 'leading whitespace' => [' https://example.com'];
        yield 'trailing whitespace' => ['https://example.com '];
        yield 'whitespace' => ["https://example.com/path\nnext"];
        yield 'null byte' => ["https://example.com\0.evil"];
        yield 'escape sequence' => ["https://example.com\x1b[31m"];
        yield 'invalid UTF-8' => ["https://exa\xFFmple.com"];
        yield 'unsupported scheme' => ['ftp://example.com'];
    }

    #[Test]
    public function explicit_override_allows_remote_http(): void
    {
        $this->assertSame('http://api.example.com', BaseUrlPolicy::validate('http://api.example.com', true));
    }
}
