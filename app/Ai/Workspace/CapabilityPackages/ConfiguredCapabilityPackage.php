<?php

namespace App\Ai\Workspace\CapabilityPackages;

use App\Ai\Workspace\AiCapabilityDefinition;
use LogicException;

abstract class ConfiguredCapabilityPackage implements AiDomainCapabilityPackage
{
    /** @return list<string> */
    abstract protected function capabilityKeys(): array;

    /** @return array<string,AiCapabilityDefinition> */
    final public function definitions(): array
    {
        $configured = CapabilityManifest::definitions();
        $definitions = [];
        foreach ($this->capabilityKeys() as $key) {
            $definition = $configured[$key] ?? null;
            if (! is_array($definition)) {
                throw new LogicException(sprintf('Capability package %s is missing definition %s.', $this->key(), $key));
            }
            $definitions[$key] = AiCapabilityDefinition::fromArray($key, $definition);
        }

        return $definitions;
    }

    public function featuredPrompts(): array
    {
        return [];
    }
}
