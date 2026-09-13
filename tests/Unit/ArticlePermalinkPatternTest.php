<?php

namespace Tests\Unit;

use App\Support\Site\ArticlePermalinkPattern;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArticlePermalinkPatternTest extends TestCase
{
    #[DataProvider('validPatterns')]
    public function test_it_compiles_and_renders_supported_patterns(string $pattern, string $expected): void
    {
        $compiled = ArticlePermalinkPattern::compile($pattern);

        $this->assertSame($pattern, $compiled->pattern());
        $this->assertSame($expected, $compiled->render([
            'slug' => 'hello world',
            'id' => 42,
            'category' => 'ai-news',
            'year' => '2026',
            'month' => '09',
            'day' => '12',
        ]));
        $this->assertNotNull($compiled->match($expected));
    }

    /** @return array<string,array{string,string}> */
    public static function validPatterns(): array
    {
        return [
            'default' => ['/article/{slug}', '/article/hello%20world'],
            'html' => ['/{slug}.html', '/hello%20world.html'],
            'id and slug' => ['/article/{id}-{slug}.html', '/article/42-hello%20world.html'],
            'category' => ['/article/{category}/{slug}.html', '/article/ai-news/hello%20world.html'],
            'date' => ['/article/{year}/{month}/{slug}.html', '/article/2026/09/hello%20world.html'],
            'id' => ['/article/{id}.html', '/article/42.html'],
            'custom' => ['/read/{year}/{month}/{day}/{id}-{slug}', '/read/2026/09/12/42-hello%20world'],
        ];
    }

    #[DataProvider('invalidPatterns')]
    public function test_it_rejects_unsafe_or_ambiguous_patterns(string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);

        ArticlePermalinkPattern::compile($pattern);
    }

    /** @return array<string,array{string}> */
    public static function invalidPatterns(): array
    {
        return [
            'missing locator' => ['/article/{year}/{month}'],
            'root slug without suffix' => ['/{slug}'],
            'unknown token' => ['/article/{title}'],
            'duplicate token' => ['/article/{slug}/{slug}'],
            'adjacent tokens' => ['/article/{id}{slug}'],
            'query string' => ['/article/{slug}?preview=1'],
            'protocol' => ['https://example.test/{slug}'],
            'traversal' => ['/article/../{slug}'],
            'reserved route' => ['/api/{slug}.html'],
            'dynamic root can match a reserved route' => ['/{category}/{slug}.html'],
            'dynamic root suffix can match a reserved file' => ['/{category}.xml/{slug}'],
        ];
    }

    public function test_the_configured_admin_prefix_is_reserved(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ArticlePermalinkPattern::compile('/control-room/{slug}.html', '/control-room/panel');
    }
}
