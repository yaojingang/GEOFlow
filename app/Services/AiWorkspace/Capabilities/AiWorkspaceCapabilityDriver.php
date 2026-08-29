<?php

namespace App\Services\AiWorkspace\Capabilities;

use App\Ai\Workspace\AiCapabilityResult;
use App\Models\Admin;

interface AiWorkspaceCapabilityDriver
{
    /** @param array<string,mixed> $parameters */
    public function execute(string $capabilityKey, array $parameters, Admin $admin, ?string $executionKey = null): AiCapabilityResult;

    /** @param array<string,mixed> $parameters */
    public function executeRegisteredAction(
        string $capabilityKey,
        array $parameters,
        Admin $admin,
        ?string $executionKey = null,
    ): AiCapabilityResult;
}
