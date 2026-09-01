<?php

namespace App\Policies;

use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Services\Admin\AdminAiModelAccessResolver;

final class AiModelPolicy
{
    public function __construct(
        private readonly AdminAiModelAccessResolver $accessResolver,
    ) {}

    public function viewAny(Admin $admin): bool
    {
        return $this->isActive($admin);
    }

    public function view(Admin $admin, AiModel $model): bool
    {
        if (! $this->isActive($admin)) {
            return false;
        }

        if ($admin->isSuperAdmin()) {
            return true;
        }

        return $this->accessResolver
            ->visibleQuery($admin)
            ->whereKey($model->getKey())
            ->exists();
    }

    public function create(Admin $admin): bool
    {
        return $this->isActive($admin);
    }

    public function useModel(Admin $admin, AiModel $model): bool
    {
        try {
            $this->accessResolver->assertUsable($admin, $model);

            return true;
        } catch (AiModelAccessException) {
            return false;
        }
    }

    public function update(Admin $admin, AiModel $model): bool
    {
        return $this->canManageOwnedModel($admin, $model);
    }

    public function test(Admin $admin, AiModel $model): bool
    {
        return $this->canManageOwnedModel($admin, $model);
    }

    public function disable(Admin $admin, AiModel $model): bool
    {
        return $this->isActive($admin)
            && ($admin->isSuperAdmin() || $this->isOwner($admin, $model));
    }

    public function archive(Admin $admin, AiModel $model): bool
    {
        return $this->disable($admin, $model);
    }

    public function delete(Admin $admin, AiModel $model): bool
    {
        return $this->canManageOwnedModel($admin, $model);
    }

    public function viewApiKey(Admin $admin, AiModel $model): bool
    {
        return false;
    }

    private function canManageOwnedModel(Admin $admin, AiModel $model): bool
    {
        return $this->isActive($admin)
            && $this->isOwner($admin, $model)
            && ($admin->isSuperAdmin() || $model->access_scope === AiModel::ACCESS_SCOPE_USER_CONTENT);
    }

    private function isOwner(Admin $admin, AiModel $model): bool
    {
        return (int) $model->owner_admin_id === (int) $admin->getKey();
    }

    private function isActive(Admin $admin): bool
    {
        return (string) $admin->status === 'active';
    }
}
