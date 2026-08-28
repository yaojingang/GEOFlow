<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class DistributionSiteSettingsSyncCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'distribution.site_settings_sync';
    }
}
