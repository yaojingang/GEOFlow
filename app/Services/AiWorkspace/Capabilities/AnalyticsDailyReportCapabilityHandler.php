<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class AnalyticsDailyReportCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'analytics.daily_report';
    }
}
