<?php

namespace App\Ai\Workspace\CapabilityPackages;

final class TasksCapabilityPackage extends ConfiguredCapabilityPackage
{
    public function key(): string
    {
        return 'tasks';
    }

    protected function capabilityKeys(): array
    {
        return ['task.draft', 'task.status.change'];
    }

    public function featuredPrompts(): array
    {
        return ['task.draft'];
    }
}
