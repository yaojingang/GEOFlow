<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class ContentCatalogCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'content.catalog';
    }
}
