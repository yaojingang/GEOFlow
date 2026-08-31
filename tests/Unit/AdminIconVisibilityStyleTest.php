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
}
