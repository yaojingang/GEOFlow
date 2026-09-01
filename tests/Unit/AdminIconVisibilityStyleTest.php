<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminIconVisibilityStyleTest extends TestCase
{
    public function test_admin_svg_defaults_do_not_override_hidden_visibility_utility(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        self::assertMatchesRegularExpression(
            '/\.gf-admin-v3 svg\s*\{[^}]*stroke-width:\s*1\.8;[^}]*\}/',
            $css,
        );
        self::assertDoesNotMatchRegularExpression(
            '/\.gf-admin-v3 svg\s*\{[^}]*display:\s*block;[^}]*\}/',
            $css,
        );
    }

    public function test_ai_quality_help_triggers_keep_only_the_icon_chrome(): void
    {
        $view = (string) file_get_contents(
            dirname(__DIR__, 2).'/resources/views/components/admin/ai-quality-retrieval-selector.blade.php',
        );
        preg_match_all('/<button\s+.*?data-retrieval-mode-help-trigger.*?>/s', $view, $matches);

        self::assertCount(2, $matches[0]);
        foreach ($matches[0] as $trigger) {
            self::assertStringNotContainsString('rounded-full', $trigger);
            self::assertStringNotContainsString('border-gray-300', $trigger);
            self::assertStringNotContainsString('bg-white', $trigger);
            self::assertStringContainsString('hover:text-blue-700', $trigger);
            self::assertStringContainsString('focus-visible:outline-blue-500', $trigger);
        }
    }
}
