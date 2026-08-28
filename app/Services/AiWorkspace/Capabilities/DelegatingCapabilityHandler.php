<?php

namespace App\Services\AiWorkspace\Capabilities;

use App\Ai\Workspace\AiCapabilityResult;
use App\Models\Admin;

abstract readonly class DelegatingCapabilityHandler implements AiCapabilityHandler
{
    public function __construct(private AiCapabilityActionDispatcher $actions) {}

    abstract protected function capabilityKey(): string;

    public function execute(array $parameters, Admin $admin, ?string $executionKey = null): AiCapabilityResult
    {
        return $this->actions->execute($this->capabilityKey(), $parameters, $admin, $executionKey);
    }
}
