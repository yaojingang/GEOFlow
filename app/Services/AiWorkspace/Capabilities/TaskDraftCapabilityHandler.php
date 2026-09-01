<?php

namespace App\Services\AiWorkspace\Capabilities;

use App\Ai\Workspace\AiCapabilityResult;
use App\Models\Admin;
use App\Services\GeoFlow\TaskLifecycleService;

final readonly class TaskDraftCapabilityHandler implements AiCapabilityHandler
{
    public function __construct(private TaskLifecycleService $tasks) {}

    public function execute(array $parameters, Admin $admin, ?string $executionKey = null): AiCapabilityResult
    {
        $task = $this->tasks->createDraftTask($parameters, $admin);

        return new AiCapabilityResult(
            summary: sprintf('任务草稿“%s”已创建，当前保持暂停。', $task['name']),
            payload: ['task_id' => (int) $task['id'], 'status' => (string) $task['status']],
            artifactType: 'task_draft',
            artifactName: (string) $task['name'],
            sourceRoute: 'admin.tasks.edit',
            sourceUrl: route('admin.tasks.edit', ['taskId' => $task['id']]),
        );
    }
}
