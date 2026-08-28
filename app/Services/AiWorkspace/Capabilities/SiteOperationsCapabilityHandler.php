<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class SiteOperationsCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'site.operations';
    }
}
