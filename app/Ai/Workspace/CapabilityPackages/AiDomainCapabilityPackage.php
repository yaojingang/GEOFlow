<?php

namespace App\Ai\Workspace\CapabilityPackages;

use App\Ai\Workspace\AiCapabilityDefinition;

interface AiDomainCapabilityPackage
{
    public function key(): string;

    /** @return array<string,AiCapabilityDefinition> */
    public function definitions(): array;

    /** @return list<string> */
    public function featuredPrompts(): array;
}
