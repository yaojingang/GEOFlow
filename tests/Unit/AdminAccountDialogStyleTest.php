<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminAccountDialogStyleTest extends TestCase
{
    public function test_account_avatar_keeps_centered_flex_layout_inside_summary(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        self::assertMatchesRegularExpression(
            '/\.gf-account-avatar\s*\{[^}]*display:\s*inline-flex;[^}]*\}/',
            $css,
        );
        self::assertStringContainsString(
            '.gf-account-summary > div strong, .gf-account-summary > div span { display: block; }',
            $css,
        );
        self::assertStringNotContainsString(
            '.gf-account-summary strong, .gf-account-summary span { display: block; }',
            $css,
        );
    }
}
