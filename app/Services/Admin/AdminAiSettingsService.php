<?php

namespace App\Services\Admin;

use App\Contracts\Admin\AiModelWriteLock;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class AdminAiSettingsService
{
    public function __construct(
        private readonly AdminAiModelAccessResolver $accessResolver,
        private readonly AiModelWriteLock $modelWriteLock,
    ) {}

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
            $lockedModels = $this->lockedModels($chatModel, $embeddingModel);
            $currentChatModel = $this->assertCapability(
                $lockedAdmin,
                $this->selectedLockedModel($lockedAdmin, $chatModel, $lockedModels),
                'chat',
            );
            $currentEmbeddingModel = $this->assertCapability(
                $lockedAdmin,
                $this->selectedLockedModel($lockedAdmin, $embeddingModel, $lockedModels),
                'embedding',
            );
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

    public function setPersonalDefaultIfMissing(Admin $admin, AiModel $model): AdminAiSetting
    {
        return DB::transaction(function () use ($admin, $model): AdminAiSetting {
            $lockedAdmin = Admin::query()
                ->whereKey($admin->getKey())
                ->lockForUpdate()
                ->first();
            $this->assertActive($lockedAdmin, $admin);
            $lockedModel = $this->modelWriteLock
                ->lockByIds([(int) $model->getKey()])
                ->first();
            if (! $lockedModel instanceof AiModel
                || (int) $lockedModel->owner_admin_id !== (int) $lockedAdmin->getKey()) {
                throw AiModelAccessException::modelNotAccessible($lockedAdmin, $model);
            }
            $this->accessResolver->assertLockedUsable($lockedAdmin, $lockedModel);
            $setting = $this->lockedSetting($lockedAdmin);
            $attributes = [
                'admin_id' => $lockedAdmin->getKey(),
                'updated_by_admin_id' => $lockedAdmin->getKey(),
            ];
            $modelType = trim((string) $lockedModel->model_type) === 'embedding' ? 'embedding' : 'chat';
            $field = $modelType === 'embedding' ? 'default_embedding_model_id' : 'default_chat_model_id';
            if ($setting->getAttribute($field) === null) {
                $attributes[$field] = $lockedModel->getKey();
            }
            $setting->forceFill($attributes)->save();

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

    public function clearIncompatibleDefaultsForModel(AiModel $model, Admin $updatedBy): int
    {
        $clearChat = (string) $model->status !== 'active'
            || $model->archived_at !== null
            || (string) $model->access_scope !== AiModel::ACCESS_SCOPE_USER_CONTENT
            || (string) $model->model_type === 'embedding';
        $clearEmbedding = (string) $model->status !== 'active'
            || $model->archived_at !== null
            || (string) $model->access_scope !== AiModel::ACCESS_SCOPE_USER_CONTENT
            || (string) $model->model_type !== 'embedding';

        return $this->clearModelDefaults($model, $updatedBy, $clearChat, $clearEmbedding);
    }

    public function clearAllDefaultsForModel(AiModel $model, Admin $updatedBy): int
    {
        return $this->clearModelDefaults($model, $updatedBy, true, true);
    }

    private function assertCapability(Admin $admin, ?AiModel $model, string $capability): ?AiModel
    {
        if (! $model instanceof AiModel) {
            return null;
        }

        $this->accessResolver->assertLockedUsable($admin, $model);
        $modelType = trim((string) $model->model_type);
        if ($modelType === $capability || ($capability === 'chat' && $modelType === '')) {
            return $model;
        }

        if ($capability === 'embedding') {
            throw AiModelAccessException::embeddingIncompatible($admin, $model);
        }

        throw AiModelAccessException::modelNotAccessible($admin, $model);
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

    private function clearModelDefaults(
        AiModel $model,
        Admin $updatedBy,
        bool $clearChat,
        bool $clearEmbedding,
    ): int {
        if (! $clearChat && ! $clearEmbedding) {
            return 0;
        }

        return DB::transaction(function () use ($model, $updatedBy, $clearChat, $clearEmbedding): int {
            $modelId = (int) $model->getKey();
            $query = AdminAiSetting::query()->where(function ($query) use ($model, $clearChat, $clearEmbedding): void {
                if ($clearChat) {
                    $query->where('default_chat_model_id', $model->getKey());
                }
                if ($clearEmbedding) {
                    $method = $clearChat ? 'orWhere' : 'where';
                    $query->{$method}('default_embedding_model_id', $model->getKey());
                }
            });

            $attributes = ['updated_by_admin_id' => $updatedBy->getKey()];
            if ($clearChat) {
                $attributes['default_chat_model_id'] = $clearEmbedding
                    ? DB::raw("CASE WHEN default_chat_model_id = {$modelId} THEN NULL ELSE default_chat_model_id END")
                    : null;
            }
            if ($clearEmbedding) {
                $attributes['default_embedding_model_id'] = $clearChat
                    ? DB::raw("CASE WHEN default_embedding_model_id = {$modelId} THEN NULL ELSE default_embedding_model_id END")
                    : null;
            }

            return $query->update($attributes);
        }, 3);
    }

    /** @return Collection<int, AiModel> */
    private function lockedModels(?AiModel $chatModel, ?AiModel $embeddingModel): Collection
    {
        $ids = array_values(array_unique(array_filter([
            $chatModel?->getKey(),
            $embeddingModel?->getKey(),
        ], static fn (mixed $id): bool => $id !== null)));
        sort($ids);

        return $this->modelWriteLock->lockByIds($ids)->keyBy('id');
    }

    /** @param Collection<int, AiModel> $lockedModels */
    private function selectedLockedModel(
        Admin $admin,
        ?AiModel $requested,
        Collection $lockedModels,
    ): ?AiModel {
        if (! $requested instanceof AiModel) {
            return null;
        }

        $locked = $lockedModels->get((int) $requested->getKey());
        if ($locked instanceof AiModel) {
            return $locked;
        }

        throw AiModelAccessException::modelNotAccessible($admin, $requested);
    }
}
