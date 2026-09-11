<?php

namespace Tests\Unit;

use App\Support\Site\CtaTargetUrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CtaTargetUrlNormalizerTest extends TestCase
{
    #[DataProvider('safeUrls')]
    public function test_safe_cta_urls_are_normalized(string $input, string $expected): void
    {
        $this->assertSame($expected, CtaTargetUrlNormalizer::normalize($input));
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_cta_urls_are_rejected(string $input): void
    {
        $this->assertSame('', CtaTargetUrlNormalizer::normalize($input));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function safeUrls(): array
    {
        return [
            'root-relative path' => ['/offers/demo', '/offers/demo'],
            'trimmed root-relative path' => ['  /offers/demo  ', '/offers/demo'],
            'plain relative path' => ['offers/demo', '/offers/demo'],
            'relative path with query and fragment' => ['offers/demo?utm_source=cta#pricing', '/offers/demo?utm_source=cta#pricing'],
            'https URL' => ['https://partner.example/demo?utm_source=cta#pricing', 'https://partner.example/demo?utm_source=cta#pricing'],
            'http URL' => ['http://partner.example/demo', 'http://partner.example/demo'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unsafeUrls(): array
    {
        return [
            'empty string' => [''],
            'blank string' => ['   '],
            'protocol-relative URL' => ['//evil.example/demo'],
            'multiple leading slashes' => ['///evil.example/demo'],
            'authority with backslashes' => ['\\\\evil.example/demo'],
            'root path with backslashes' => ['/\\\\evil.example/demo'],
            'relative path with a backslash' => ['offers\\demo'],
            'malformed https URL' => ['https:///evil.example/demo'],
            'malformed http URL' => ['http:/evil.example/demo'],
            'javascript scheme' => ['javascript:alert(1)'],
            'data scheme' => ['data:text/html,unsafe'],
            'mailto scheme' => ['mailto:security@example.com'],
            'embedded control character' => ["https://safe.example/\nunsafe"],
        ];
    }
}
