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
            'root category and slug' => ['/{category}/{slug}', '/ai-news/hello%20world'],
            'root category and id' => ['/{category}/{id}', '/ai-news/42'],
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
            'dynamic root can match a reserved route' => ['/{slug}/{id}'],
            'dynamic root suffix can match a reserved file' => ['/{category}.xml/{slug}'],
        ];
    }

    public function test_the_configured_admin_prefix_is_reserved(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ArticlePermalinkPattern::compile('/control-room/{slug}.html', '/control-room/panel');
    }

    public function test_admin_remains_reserved_with_a_custom_admin_prefix(): void
    {
        $this->assertTrue(ArticlePermalinkPattern::isReservedFirstSegment('admin', '/geo_admin'));
        $compiled = ArticlePermalinkPattern::compile('/{category}/{slug}', '/geo_admin');
        $this->assertNull($compiled->match('/admin/article'));

        $this->expectException(InvalidArgumentException::class);

        ArticlePermalinkPattern::compile('/admin/{slug}', '/geo_admin');
    }

    #[DataProvider('patternsWithWhitespace')]
    public function test_it_explains_how_to_remove_whitespace_between_path_levels(string $pattern): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('模板中包含空白字符（空格、制表符或换行）。请删除多余空白，路径层级直接用 / 分隔；分类与文章两层地址请填写 /{category}/{slug}。');

        ArticlePermalinkPattern::compile($pattern);
    }

    /** @return array<string,array{string}> */
    public static function patternsWithWhitespace(): array
    {
        return [
            'space' => ['/{category} /{slug}'],
            'tab' => ["/{category}\t/{slug}"],
            'full-width space' => ['/{category}　/{slug}'],
        ];
    }

    public function test_it_explains_which_characters_fixed_text_supports(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('模板中的固定文字包含不支持的字符。令牌以外只允许小写英文字母（a-z）、数字（0-9）、短横线（-）、下划线（_）、点（.）和路径分隔符（/）。');

        ArticlePermalinkPattern::compile('/News/{slug}');
    }

    public function test_a_root_category_pattern_rejects_reserved_category_values(): void
    {
        $compiled = ArticlePermalinkPattern::compile('/{category}/{id}');

        $this->assertNull($compiled->match('/api/42'));

        $this->expectException(InvalidArgumentException::class);
        $compiled->render([
            'category' => 'api',
            'id' => 42,
        ]);
    }

    public function test_locator_equivalence_only_skips_provably_identical_single_token_segments(): void
    {
        $this->assertTrue(ArticlePermalinkPattern::compile('/{year}/{slug}.html')->preservesLocatorOf(ArticlePermalinkPattern::compile('/{category}/{slug}.html')));
        $this->assertFalse(ArticlePermalinkPattern::compile('/{id}/{slug}')->preservesLocatorOf(ArticlePermalinkPattern::compile('/{category}/{slug}')));
        $this->assertFalse(ArticlePermalinkPattern::compile('/entry/{slug}-{year}')->preservesLocatorOf(ArticlePermalinkPattern::compile('/entry/{slug}-{year}')));
        $this->assertFalse(ArticlePermalinkPattern::compile('/{category}/{slug}')->preservesLocatorOf(ArticlePermalinkPattern::compile('/{category}/x-{slug}')));
    }
}
