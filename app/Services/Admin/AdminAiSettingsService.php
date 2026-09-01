<?php

namespace App\Services\Admin;

use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class AdminAiSettingsService
{
    public function __construct(private readonly AdminAiModelAccessResolver $accessResolver) {}

    public function setDefaults(
        Admin $admin,
        ?AiModel $chatModel,
        ?AiModel $embeddingModel,
        Admin $updatedBy,
    ): AdminAiSetting {
        return DB::transaction(function () use ($admin, $chatModel, $embeddingModel, $updatedBy): AdminAiSetting {
            [$lockedAdmin, $lockedUpdater] = $this->lockedAdmins($admin, $updatedBy);
            $this->assertActive($lockedAdmin, $admin);
            $this->assertActive($lockedUpdater, $updatedBy);
            $this->assertAuthorized($lockedAdmin, $lockedUpdater);
            $currentChatModel = $this->assertCapability($lockedAdmin, $chatModel, 'chat');
            $currentEmbeddingModel = $this->assertCapability($lockedAdmin, $embeddingModel, 'embedding');
            $setting = $this->lockedSetting($lockedAdmin);
            $setting->forceFill([
                'admin_id' => $lockedAdmin->getKey(),
                'default_chat_model_id' => $currentChatModel?->getKey(),
                'default_embedding_model_id' => $currentEmbeddingModel?->getKey(),
                'updated_by_admin_id' => $lockedUpdater->getKey(),
            ])->save();

            return $setting->refresh();
        }, 3);
    }

    public function clearDefaultsFromOwner(
        Admin $admin,
        Admin $owner,
        Admin $updatedBy,
    ): AdminAiSetting {
        return DB::transaction(function () use ($admin, $owner, $updatedBy): AdminAiSetting {
            [$lockedAdmin, $lockedUpdater] = $this->lockedAdmins($admin, $updatedBy);
            $this->assertActive($lockedUpdater, $updatedBy);
            $this->assertAuthorized($lockedAdmin, $lockedUpdater);
            $setting = $this->lockedSetting($lockedAdmin);
            $attributes = [
                'admin_id' => $lockedAdmin->getKey(),
                'updated_by_admin_id' => $lockedUpdater->getKey(),
            ];

            if ($this->modelIsOwnedBy($setting->default_chat_model_id, $owner)) {
                $attributes['default_chat_model_id'] = null;
            }
            if ($this->modelIsOwnedBy($setting->default_embedding_model_id, $owner)) {
                $attributes['default_embedding_model_id'] = null;
            }

            $setting->forceFill($attributes)->save();

            return $setting->refresh();
        }, 3);
    }

    private function assertCapability(Admin $admin, ?AiModel $model, string $capability): ?AiModel
    {
        if (! $model instanceof AiModel) {
            return null;
        }

        $this->accessResolver->assertUsable($admin, $model);
        $currentModel = AiModel::query()->findOrFail($model->getKey());
        $modelType = trim((string) $currentModel->model_type);
        if ($modelType === $capability || ($capability === 'chat' && $modelType === '')) {
            return $currentModel;
        }

        if ($capability === 'embedding') {
            throw AiModelAccessException::embeddingIncompatible($admin, $currentModel);
        }

        throw AiModelAccessException::modelNotAccessible($admin, $currentModel);
    }

    private function assertActive(?Admin $current, Admin $requested): void
    {
        if (! $current instanceof Admin || (string) $current->status !== 'active') {
            throw AiModelAccessException::executionAdminInactive($requested);
        }
    }

    private function assertAuthorized(?Admin $admin, ?Admin $updatedBy): void
    {
        if (! $admin instanceof Admin || ! $updatedBy instanceof Admin) {
            throw new AuthorizationException('ai_config_settings_not_manageable');
        }

        $isSelf = (int) $admin->getKey() === (int) $updatedBy->getKey();
        $canGovernOrdinaryAdmin = $updatedBy->isSuperAdmin() && ! $admin->isSuperAdmin();
        if (! $isSelf && ! $canGovernOrdinaryAdmin) {
            throw new AuthorizationException('ai_config_settings_not_manageable');
        }
    }

    /** @return array{?Admin, ?Admin} */
    private function lockedAdmins(Admin $admin, Admin $updatedBy): array
    {
        /** @var Collection<int, Admin> $locked */
        $locked = Admin::query()
            ->whereIn('id', array_values(array_unique([
                (int) $admin->getKey(),
                (int) $updatedBy->getKey(),
            ])))
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        return [
            $locked->firstWhere('id', (int) $admin->getKey()),
            $locked->firstWhere('id', (int) $updatedBy->getKey()),
        ];
    }

    private function lockedSetting(Admin $admin): AdminAiSetting
    {
        return AdminAiSetting::query()
            ->where('admin_id', $admin->getKey())
            ->lockForUpdate()
            ->first() ?? new AdminAiSetting;
    }

    private function modelIsOwnedBy(mixed $modelId, Admin $owner): bool
    {
        return $modelId !== null && AiModel::query()
            ->whereKey((int) $modelId)
            ->ownedBy($owner)
            ->exists();
    }
}
