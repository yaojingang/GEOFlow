<?php

namespace App\Ai\Workspace\CapabilityPackages;

final class ContentCapabilityPackage extends ConfiguredCapabilityPackage
{
    public function key(): string
    {
        return 'content';
    }

    protected function capabilityKeys(): array
    {
        return ['article.draft', 'content.catalog'];
    }

    public function featuredPrompts(): array
    {
        return ['article.draft'];
    }
}
