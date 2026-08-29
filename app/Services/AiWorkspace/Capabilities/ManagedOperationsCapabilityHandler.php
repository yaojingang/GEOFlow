<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class ManagedOperationsCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'managed.operations';
    }
}
