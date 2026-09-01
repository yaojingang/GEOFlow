<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminContextNavigationStyleTest extends TestCase
{
    public function test_context_navigation_keeps_its_height_when_page_content_exceeds_the_viewport(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        self::assertStringContainsString(
            'flex: 0 0 auto;',
            $this->declarationsFor($css, '.gf-context-nav'),
        );
    }

    private function declarationsFor(string $css, string $selector): string
    {
        $matches = [];
        $matched = preg_match('/(?:^|})\s*'.preg_quote($selector, '/').'\s*\{(?<declarations>[^}]*)\}/', $css, $matches);

        self::assertSame(1, $matched, "Missing CSS selector: {$selector}");

        return (string) $matches['declarations'];
    }
}
