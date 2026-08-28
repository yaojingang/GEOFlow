<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class ContentOpportunitiesCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'content.opportunities';
    }
}
