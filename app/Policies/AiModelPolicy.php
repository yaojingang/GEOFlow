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
        return $this->accessResolver->canView($admin, $model);
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
        return $this->accessResolver->canManage($admin, $model);
    }

    public function test(Admin $admin, AiModel $model): bool
    {
        return $this->accessResolver->canManage($admin, $model);
    }

    public function disable(Admin $admin, AiModel $model): bool
    {
        return $this->accessResolver->canManage($admin, $model);
    }

    public function archive(Admin $admin, AiModel $model): bool
    {
        return $this->accessResolver->canManage($admin, $model);
    }

    public function delete(Admin $admin, AiModel $model): bool
    {
        return $this->accessResolver->canManage($admin, $model);
    }

    public function viewApiKey(Admin $admin, AiModel $model): bool
    {
        return $this->accessResolver->canManage($admin, $model);
    }

    private function isActive(Admin $admin): bool
    {
        return (string) $admin->status === 'active';
    }
}
