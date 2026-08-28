<?php

namespace App\Ai\Workspace\CapabilityPackages;

final class SystemCapabilityPackage extends ConfiguredCapabilityPackage
{
    public function key(): string
    {
        return 'system';
    }

    protected function capabilityKeys(): array
    {
        return ['system.capabilities.explain', 'admin.governance', 'managed.operations'];
    }
}
