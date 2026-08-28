<?php

namespace App\Services\AiWorkspace\Capabilities;

final readonly class TaskStatusChangeCapabilityHandler extends DelegatingCapabilityHandler
{
    protected function capabilityKey(): string
    {
        return 'task.status.change';
    }
}
