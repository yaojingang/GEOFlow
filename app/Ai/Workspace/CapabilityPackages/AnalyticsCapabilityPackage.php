<?php

namespace App\Ai\Workspace\CapabilityPackages;

final class AnalyticsCapabilityPackage extends ConfiguredCapabilityPackage
{
    public function key(): string
    {
        return 'analytics';
    }

    protected function capabilityKeys(): array
    {
        return ['analytics.daily_report', 'analytics.weekly_report'];
    }

    public function featuredPrompts(): array
    {
        return ['analytics.daily_report'];
    }
}
