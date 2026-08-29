<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminAiWorkspaceComposerStyleTest extends TestCase
{
    public function test_help_home_places_the_primary_composer_between_the_intro_and_suggestions(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $welcome = $this->declarationsFor($css, '.gf-ai-help__welcome');
        $composer = $this->declarationsFor($css, '.gf-ai-help__composer');
        $homeComposer = $this->declarationsFor($css, '.gf-ai-help__welcome:not([hidden]) ~ .gf-ai-help__composer');
        $homeTextarea = $this->declarationsFor($css, '.gf-ai-help__welcome:not([hidden]) ~ .gf-ai-help__composer textarea');
        $hiddenStarters = $this->declarationsFor($css, '.gf-ai-help__welcome[hidden] ~ .gf-ai-help__starters');
        $showcaseFrame = $this->declarationsFor($css, '.gf-ai-help__showcase-frame');

        self::assertStringContainsString('width: 100%;', $welcome);
        self::assertStringContainsString('position: sticky;', $composer);
        self::assertStringContainsString('position: relative;', $homeComposer);
        self::assertStringContainsString('overflow: hidden;', $homeComposer);
        self::assertStringContainsString('min-height: 74px;', $homeTextarea);
        self::assertStringContainsString('min-height: 136px;', $homeComposer);
        self::assertStringContainsString('display: none;', $hiddenStarters);
        self::assertStringContainsString('min-height: 170px;', $showcaseFrame);
    }

    public function test_help_composer_has_a_visible_focus_state_and_mobile_layout(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $focus = $this->declarationsFor($css, '.gf-ai-help__composer:focus-within');

        self::assertStringContainsString('border-color:', $focus);
        self::assertStringContainsString('box-shadow:', $focus);
        self::assertStringContainsString('.gf-ai-help__starter-actions button { flex: 1 1 calc(33.333% - 7px);', $css);
        self::assertStringContainsString('.gf-ai-help__composer footer > span { display: none; }', $css);
    }

    public function test_help_surface_supports_reduced_motion_and_narrow_answer_reading(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $css);
        self::assertStringContainsString('.gf-ai-help__dots i { animation: none;', $css);
        self::assertStringContainsString('max-width: 780px;', $this->declarationsFor($css, '.gf-ai-help__messages'));
        self::assertStringContainsString('max-width: min(82%, 590px);', $this->declarationsFor($css, '.gf-ai-help__user-bubble'));
    }

    public function test_related_features_use_a_three_column_borderless_layout(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');
        $sections = $this->declarationsFor($css, '.gf-ai-help__related, .gf-ai-help__followups');
        $grid = $this->declarationsFor($css, '.gf-ai-help__related > div');

        self::assertStringContainsString('border-top: 0;', $sections);
        self::assertStringContainsString('padding-top: 0;', $sections);
        self::assertStringContainsString('grid-template-columns: repeat(3, minmax(0, 1fr));', $grid);
        self::assertStringContainsString('.gf-ai-help__related > div { grid-template-columns: 1fr; }', $css);
    }

    private function declarationsFor(string $css, string $selector): string
    {
        $matches = [];
        $matched = preg_match('/(?:^|})\s*'.preg_quote($selector, '/').'\s*\{(?<declarations>[^}]*)\}/', $css, $matches);

        self::assertSame(1, $matched, "Missing CSS selector: {$selector}");

        return (string) $matches['declarations'];
    }
}
