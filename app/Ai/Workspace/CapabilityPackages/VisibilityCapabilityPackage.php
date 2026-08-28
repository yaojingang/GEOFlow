<?php

namespace App\Ai\Workspace\CapabilityPackages;

final class VisibilityCapabilityPackage extends ConfiguredCapabilityPackage
{
    public function key(): string
    {
        return 'visibility';
    }

    protected function capabilityKeys(): array
    {
        return ['visibility.diagnose', 'content.opportunities'];
    }

    public function featuredPrompts(): array
    {
        return ['visibility.diagnose', 'content.opportunities'];
    }
}
