<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class JsonLdComponentTest extends TestCase
{
    use RefreshDatabase;

    private const RAW_JSON_LD_SCRIPT_PATTERN = '/<script\b[^>]*\btype\s*=\s*(?:(["\'])application\/ld\+json\1|application\/ld\+json)(?=\s|>)[^>]*>/i';

    public function test_theme_guard_detects_quoted_and_unquoted_json_ld_types(): void
    {
        $scriptTags = [
            '<script type="application/ld+json">',
            '<script type=application/ld+json>',
        ];

        foreach ($scriptTags as $scriptTag) {
            $this->assertMatchesRegularExpression(self::RAW_JSON_LD_SCRIPT_PATTERN, $scriptTag);
        }
    }

    public function test_theme_views_use_the_shared_json_ld_component(): void
    {
        $violations = [];
        $componentCount = 0;

        foreach (File::allFiles(resource_path('views/theme')) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = $file->getContents();
            $relativePath = $file->getRelativePathname();

            if (preg_match(self::RAW_JSON_LD_SCRIPT_PATTERN, $contents)) {
                $violations[] = $relativePath.' contains a raw JSON-LD script';
            }

            if (preg_match('/\{!!\s*json_encode\s*\(/i', $contents)) {
                $violations[] = $relativePath.' contains raw json_encode output';
            }

            $componentCount += substr_count($contents, '<x-json-ld ');
        }

        $this->assertSame([], $violations, implode(PHP_EOL, $violations));
        $this->assertSame(97, $componentCount, 'The theme JSON-LD block count changed.');
    }

    public function test_json_ld_component_prevents_script_breakout_and_preserves_data(): void
    {
        $data = [
            '@context' => 'https://schema.org',
            'name' => '危险值 </script><script>alert("xss")</script>',
            'url' => 'https://example.com/路径/页面',
        ];

        $html = Blade::render('<x-json-ld :data="$data" />', compact('data'));

        $this->assertStringNotContainsString('</script><script>', $html);
        $this->assertSame(1, substr_count($html, '<script type="application/ld+json">'));
        $this->assertMatchesRegularExpression(
            '/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s',
            $html,
        );

        preg_match('/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s', $html, $matches);

        $this->assertSame($data, json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_homepage_search_keeps_reflected_values_inside_json_ld(): void
    {
        $search = '</script><script>alert("homepage")</script>';

        $response = $this->get(route('site.home', ['search' => $search]));

        $response->assertOk();
        $response->assertDontSee('</script><script>', false);

        preg_match_all(
            '/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s',
            $response->getContent(),
            $matches,
        );

        $schemas = array_map(
            static fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR),
            $matches[1],
        );

        $this->assertNotEmpty($schemas);
        $this->assertTrue(collect($schemas)->contains(
            static fn (array $schema): bool => str_contains((string) ($schema['name'] ?? ''), $search)
                || str_contains((string) ($schema['description'] ?? ''), $search),
        ));
    }
}
