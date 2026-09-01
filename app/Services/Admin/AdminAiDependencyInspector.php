<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiDependencySummary;
use App\Data\Admin\AdminAiSharingImpact;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;

final class AdminAiDependencyInspector
{
    public function sharingImpact(Admin $admin, ?int $providerAdminId = null): AdminAiSharingImpact
    {
        $ownerId = $providerAdminId ?? $admin->shared_ai_config_owner_id;
        if ($ownerId === null) {
            return new AdminAiSharingImpact([], $this->pendingTaskCounts($admin));
        }

        $setting = AdminAiSetting::query()
            ->where('admin_id', $admin->getKey())
            ->first(['default_chat_model_id', 'default_embedding_model_id']);
        $defaultIds = array_values(array_unique(array_filter([
            $setting?->default_chat_model_id,
            $setting?->default_embedding_model_id,
        ], static fn (mixed $id): bool => $id !== null)));
        sort($defaultIds);

        $sharedDefaultIds = AiModel::query()
            ->whereIn('id', $defaultIds)
            ->where('owner_admin_id', $ownerId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return new AdminAiSharingImpact($sharedDefaultIds, $this->pendingTaskCounts($admin));
    }

    public function deletionDependencies(Admin $admin): AdminAiDependencySummary
    {
        return new AdminAiDependencySummary(
            ownedModelCount: AiModel::query()->where('owner_admin_id', $admin->getKey())->count(),
            dependentAdminCount: Admin::query()
                ->where('shared_ai_config_owner_id', $admin->getKey())
                ->count(),
            aiSettingCount: AdminAiSetting::query()->where('admin_id', $admin->getKey())->count(),
            pendingTaskCounts: $this->pendingTaskCounts($admin),
        );
    }

    /** @return array{queued: int, active: int, total: int} */
    public function pendingTaskCounts(Admin $admin): array
    {
        return [
            'queued' => 0,
            'active' => 0,
            'total' => 0,
        ];
    }
}
