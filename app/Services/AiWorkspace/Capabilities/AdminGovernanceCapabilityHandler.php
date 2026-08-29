<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class AdminGovernanceCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'admin.governance';
    }
}
