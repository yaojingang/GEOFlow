<?php

namespace App\Services\AiWorkspace\Capabilities;

use App\Ai\Workspace\AiCapabilityResult;
use App\Models\Admin;

interface AiCapabilityHandler
{
    /** @param array<string,mixed> $parameters */
    public function execute(array $parameters, Admin $admin, ?string $executionKey = null): AiCapabilityResult;
}
