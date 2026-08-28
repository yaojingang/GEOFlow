<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class HostedSitePreflightCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'hosted_site.preflight';
    }
}
