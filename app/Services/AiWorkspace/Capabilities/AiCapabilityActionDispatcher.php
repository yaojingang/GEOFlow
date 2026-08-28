<?php

namespace App\Services\AiWorkspace\Capabilities;

use App\Ai\Workspace\AiCapabilityResult;
use App\Models\Admin;

final readonly class AiCapabilityActionDispatcher
{
    public function __construct(private AiWorkspaceCapabilityDriver $driver) {}

    /** @param array<string,mixed> $parameters */
    public function execute(string $capabilityKey, array $parameters, Admin $admin, ?string $executionKey): AiCapabilityResult
    {
        return $this->driver->executeRegisteredAction(
            $capabilityKey,
            $parameters,
            $admin,
            $executionKey,
        );
    }
}
