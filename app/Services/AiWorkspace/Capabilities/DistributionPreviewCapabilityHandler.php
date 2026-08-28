<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class DistributionPreviewCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'distribution.preview';
    }
}
