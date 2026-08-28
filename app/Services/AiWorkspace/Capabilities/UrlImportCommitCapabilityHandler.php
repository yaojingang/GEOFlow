<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class UrlImportCommitCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'url_import.commit';
    }
}
