<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class AdminSidebarLayoutStyleTest extends TestCase
{
    public function test_primary_navigation_keeps_its_natural_height_before_recent_history_uses_the_remainder(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $stabilityCss = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/admin-ui-v3-stability.css');

        self::assertStringContainsString(
            '.gf-sidebar__primary { flex: 0 0 auto; min-height: auto; overflow: visible; }',
            $css,
        );
        self::assertStringContainsString(
            '.gf-sidebar__recent { display: flex; flex: 1 1 0;',
            $css,
        );
        self::assertStringContainsString('max-height: none; min-height: 34px;', $css);
        self::assertStringContainsString(
            '.gf-sidebar__recent.is-collapsed { flex: 0 0 34px; min-height: 34px; }',
            $css,
        );
        self::assertStringContainsString(
            '.gf-sidebar__recent-body { flex: 1 1 auto; min-height: 0; overflow: hidden; }',
            $css,
        );
        self::assertStringContainsString(
            '.gf-sidebar__recent-scroll { height: 100%; overflow-y: auto;',
            $css,
        );
        self::assertStringContainsString('scrollbar-gutter: stable;', $css);
        self::assertStringContainsString(
            '@media (max-height: 640px) { .gf-sidebar__recent { margin-top: 0; padding-top: 0; } }',
            $css,
        );
        self::assertStringContainsString(
            '@media (min-width: 768px) and (max-height: 594px) { .gf-sidebar__recent { display: none; } }',
            $css,
        );
        self::assertStringContainsString(
            "@media (max-width: 767px) and (max-height: 628px) {\n    .gf-sidebar__recent { display: none; }\n}",
            $css,
        );

        $mobileRecentRestore = strpos(
            $stabilityCss,
            "html[data-gf-sidebar-state='collapsed'] .gf-admin-v3 .gf-sidebar__recent {\n        display: flex;",
        );
        $mobileHeightFallback = strrpos(
            $stabilityCss,
            "html[data-gf-sidebar-state='collapsed'] .gf-admin-v3 .gf-sidebar__recent {\n        display: none;",
        );

        self::assertIsInt($mobileRecentRestore);
        self::assertIsInt($mobileHeightFallback);
        self::assertGreaterThan($mobileRecentRestore, $mobileHeightFallback);
    }
}
