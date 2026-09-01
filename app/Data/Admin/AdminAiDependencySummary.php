<?php

namespace App\Data\Admin;

final readonly class AdminAiDependencySummary
{
    /** @param array{queued: int, active: int, total: int} $pendingTaskCounts */
    public function __construct(
        public int $ownedModelCount,
        public int $dependentAdminCount,
        public int $aiSettingCount,
        public array $pendingTaskCounts,
    ) {}

    public function blocksDeletion(): bool
    {
        return $this->ownedModelCount > 0
            || $this->dependentAdminCount > 0
            || $this->pendingTaskCounts['total'] > 0;
    }

    /** @return array<string, int> */
    public function counts(): array
    {
        return [
            'owned_model_count' => $this->ownedModelCount,
            'dependent_admin_count' => $this->dependentAdminCount,
            'ai_setting_count' => $this->aiSettingCount,
            'pending_task_count' => $this->pendingTaskCounts['total'],
        ];
    }
}
