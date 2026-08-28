<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class VisibilityDiagnoseCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'visibility.diagnose';
    }
}
