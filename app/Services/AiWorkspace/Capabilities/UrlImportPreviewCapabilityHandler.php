<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class UrlImportPreviewCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'url_import.preview';
    }
}
