<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class DistributionPublishCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'distribution.publish';
    }
}
