<?php

namespace App\Ai\Workspace\CapabilityPackages;

final class DistributionCapabilityPackage extends ConfiguredCapabilityPackage
{
    public function key(): string
    {
        return 'distribution';
    }

    protected function capabilityKeys(): array
    {
        return [
            'distribution.preview', 'distribution.publish', 'distribution.site_settings_sync',
            'hosted_site.preflight', 'site.operations',
        ];
    }

    public function featuredPrompts(): array
    {
        return ['distribution.preview'];
    }
}
