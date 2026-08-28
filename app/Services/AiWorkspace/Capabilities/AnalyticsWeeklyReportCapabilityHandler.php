<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class AnalyticsWeeklyReportCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'analytics.weekly_report';
    }
}
