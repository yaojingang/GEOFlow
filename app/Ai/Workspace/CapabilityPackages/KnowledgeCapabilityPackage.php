<?php

namespace App\Ai\Workspace\CapabilityPackages;

final class KnowledgeCapabilityPackage extends ConfiguredCapabilityPackage
{
    public function key(): string
    {
        return 'knowledge';
    }

    protected function capabilityKeys(): array
    {
        return ['knowledge.draft', 'url_import.preview', 'url_import.commit'];
    }

    public function featuredPrompts(): array
    {
        return ['knowledge.draft'];
    }
}
