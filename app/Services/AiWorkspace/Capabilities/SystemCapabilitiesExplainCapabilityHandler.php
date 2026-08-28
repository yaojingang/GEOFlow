<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class SystemCapabilitiesExplainCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'system.capabilities.explain';
    }
}
