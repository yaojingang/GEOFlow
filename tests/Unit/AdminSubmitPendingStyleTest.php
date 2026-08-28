<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AdminSubmitPendingStyleTest extends TestCase
{
    public function test_pending_submit_controls_keep_visible_waiting_feedback_without_being_disabled(): void
    {
        $css = (string) file_get_contents(dirname(__DIR__, 2).'/resources/css/app.css');

        self::assertMatchesRegularExpression(
            '/\.gf-admin-v3 \[data-gf-submit-pending\]\s*\{[^}]*cursor:\s*wait;[^}]*opacity:\s*\.75;[^}]*\}/',
            $css,
        );
    }
}
