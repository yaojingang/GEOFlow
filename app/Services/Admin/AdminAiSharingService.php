<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiSharingChangeResult;
use App\Exceptions\AdminAiSharingException;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class AdminAiSharingService
{
    public function __construct(private readonly AdminAiDependencyInspector $dependencyInspector) {}

    /** @param array<string, mixed> $attributes */
    public function createOrdinaryAdmin(Admin $actor, array $attributes, string $mode): Admin
    {
        return DB::transaction(function () use ($actor, $attributes, $mode): Admin {
            $lockedActor = Admin::query()
                ->whereKey($actor->getKey())
                ->lockForUpdate()
                ->first();
            $this->assertActiveSuperAdmin($lockedActor);

            if (! in_array($mode, ['independent', 'shared_current_super'], true)) {
                throw AdminAiSharingException::providerInvalid((int) $lockedActor->getKey());
            }

            $admin = new Admin;
            $admin->forceFill([
                'username' => (string) $attributes['username'],
                'display_name' => (string) ($attributes['display_name'] ?? ''),
                'email' => (string) ($attributes['email'] ?? ''),
                'password' => (string) $attributes['password'],
                'role' => 'admin',
                'status' => 'active',
                'created_by' => (int) $lockedActor->getKey(),
                'shared_ai_config_owner_id' => $mode === 'shared_current_super'
                    ? (int) $lockedActor->getKey()
                    : null,
                'ai_config_access_version' => 1,
            ])->save();

            return $admin->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function updateAdmin(
        Admin $actor,
        Admin $target,
        array $attributes,
        ?string $mode,
        ?int $expectedAccessVersion,
        ?int $expectedProviderAdminId = null,
        bool $switchSharedProvider = false,
    ): AdminAiSharingChangeResult {
        return DB::transaction(function () use (
            $actor,
            $target,
            $attributes,
            $mode,
            $expectedAccessVersion,
            $expectedProviderAdminId,
            $switchSharedProvider,
        ): AdminAiSharingChangeResult {
            $lockedAdmins = $this->lockAdmins($actor, $target);
            $lockedActor = $lockedAdmins->firstWhere('id', (int) $actor->getKey());
            $lockedTarget = $lockedAdmins->firstWhere('id', (int) $target->getKey());
            $this->assertActiveSuperAdmin($lockedActor);
            if (! $lockedTarget instanceof Admin) {
                throw AdminAiSharingException::targetInvalid((int) $target->getKey());
            }

            if ($lockedTarget->isSuperAdmin()) {
                return $this->updateSuperAdminSelf(
                    $lockedActor,
                    $lockedTarget,
                    $attributes,
                    $mode,
                    $expectedAccessVersion,
                    $expectedProviderAdminId,
                    $switchSharedProvider,
                );
            }

            return $this->updateOrdinaryAdmin(
                $lockedActor,
                $lockedTarget,
                $attributes,
                $mode,
                $expectedAccessVersion,
                $expectedProviderAdminId,
                $switchSharedProvider,
            );
        }, 3);
    }

    public function changeOrdinaryStatus(
        Admin $actor,
        Admin $target,
        string $newStatus,
    ): AdminAiSharingChangeResult {
        return DB::transaction(function () use ($actor, $target, $newStatus): AdminAiSharingChangeResult {
            $lockedAdmins = $this->lockAdmins($actor, $target);
            $lockedActor = $lockedAdmins->firstWhere('id', (int) $actor->getKey());
            $lockedTarget = $lockedAdmins->firstWhere('id', (int) $target->getKey());
            $this->assertActiveSuperAdmin($lockedActor);
            if (! $lockedTarget instanceof Admin || $lockedTarget->isSuperAdmin()) {
                throw AdminAiSharingException::targetInvalid((int) $target->getKey());
            }
            if (! in_array($newStatus, ['active', 'inactive'], true)) {
                throw AdminAiSharingException::targetInvalid((int) $target->getKey());
            }

            $oldStatus = (string) $lockedTarget->status;
            $oldVersion = max(1, (int) $lockedTarget->ai_config_access_version);
            $statusChanged = $oldStatus !== $newStatus;
            $newVersion = $statusChanged ? $oldVersion + 1 : $oldVersion;
            $providerId = $lockedTarget->shared_ai_config_owner_id === null
                ? null
                : (int) $lockedTarget->shared_ai_config_owner_id;

            $lockedTarget->forceFill([
                'status' => $newStatus,
                'ai_config_access_version' => $newVersion,
            ])->save();
            if ($statusChanged) {
                $lockedTarget->revokeAuthenticationCredentials();
            }

            return new AdminAiSharingChangeResult(
                admin: $lockedTarget->refresh(),
                oldProviderAdminId: $providerId,
                newProviderAdminId: $providerId,
                oldAccessVersion: $oldVersion,
                newAccessVersion: $newVersion,
                clearedDefaultModelIds: [],
                clearedChatDefaultModelId: null,
                clearedEmbeddingDefaultModelId: null,
                pendingImpactCounts: $this->dependencyInspector->pendingTaskCounts($lockedTarget),
            );
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    private function updateOrdinaryAdmin(
        Admin $actor,
        Admin $target,
        array $attributes,
        ?string $mode,
        ?int $expectedAccessVersion,
        ?int $expectedProviderAdminId,
        bool $switchSharedProvider,
    ): AdminAiSharingChangeResult {
        if (! in_array($mode, ['independent', 'shared_current_super'], true)) {
            throw AdminAiSharingException::providerInvalid((int) $target->getKey());
        }

        $oldVersion = max(1, (int) $target->ai_config_access_version);
        if ($expectedAccessVersion !== $oldVersion) {
            throw AdminAiSharingException::accessConflict((int) $target->getKey());
        }

        $oldProviderId = $target->shared_ai_config_owner_id === null
            ? null
            : (int) $target->shared_ai_config_owner_id;
        if ($expectedProviderAdminId !== $oldProviderId) {
            throw AdminAiSharingException::accessConflict((int) $target->getKey());
        }
        if ($switchSharedProvider && (
            $mode !== 'shared_current_super'
            || $oldProviderId === null
            || $oldProviderId === (int) $actor->getKey()
        )) {
            throw AdminAiSharingException::providerInvalid((int) $target->getKey());
        }
        $newProviderId = match (true) {
            $mode === 'independent' => null,
            $oldProviderId === null => (int) $actor->getKey(),
            $oldProviderId === (int) $actor->getKey() => $oldProviderId,
            $switchSharedProvider => (int) $actor->getKey(),
            default => $oldProviderId,
        };
        $providerChanged = $oldProviderId !== $newProviderId;
        $oldStatus = (string) $target->status;
        $newStatus = (string) $attributes['status'];
        $statusChanged = $oldStatus !== $newStatus;
        $versionChanged = $providerChanged || $statusChanged;
        $clearedDefaults = $providerChanged && $oldProviderId !== null
            ? $this->clearDefaultsOwnedBy($target, $oldProviderId, $actor)
            : ['model_ids' => [], 'chat' => null, 'embedding' => null];

        $target->forceFill([
            'username' => (string) $attributes['username'],
            'display_name' => (string) ($attributes['display_name'] ?? ''),
            'email' => (string) ($attributes['email'] ?? ''),
            'status' => $newStatus,
            'shared_ai_config_owner_id' => $newProviderId,
            'ai_config_access_version' => $versionChanged ? $oldVersion + 1 : $oldVersion,
        ]);
        if (filled($attributes['password'] ?? null)) {
            $target->password = (string) $attributes['password'];
        }
        $passwordChanged = $target->isDirty('password');
        $statusChanged = $target->isDirty('status');
        $target->save();

        if ($passwordChanged || $statusChanged) {
            $target->revokeAuthenticationCredentials();
        }

        return new AdminAiSharingChangeResult(
            admin: $target->refresh(),
            oldProviderAdminId: $oldProviderId,
            newProviderAdminId: $newProviderId,
            oldAccessVersion: $oldVersion,
            newAccessVersion: $versionChanged ? $oldVersion + 1 : $oldVersion,
            clearedDefaultModelIds: $clearedDefaults['model_ids'],
            clearedChatDefaultModelId: $clearedDefaults['chat'],
            clearedEmbeddingDefaultModelId: $clearedDefaults['embedding'],
            pendingImpactCounts: $this->dependencyInspector->pendingTaskCounts($target),
        );
    }

    /** @param array<string, mixed> $attributes */
    private function updateSuperAdminSelf(
        Admin $actor,
        Admin $target,
        array $attributes,
        ?string $mode,
        ?int $expectedAccessVersion,
        ?int $expectedProviderAdminId,
        bool $switchSharedProvider,
    ): AdminAiSharingChangeResult {
        if (! $actor->is($target)) {
            throw AdminAiSharingException::targetInvalid((int) $target->getKey());
        }
        if ($mode !== null
            || $expectedAccessVersion !== null
            || $expectedProviderAdminId !== null
            || $switchSharedProvider) {
            throw AdminAiSharingException::providerInvalid((int) $target->getKey());
        }

        $oldVersion = max(1, (int) $target->ai_config_access_version);
        $oldProviderId = $target->shared_ai_config_owner_id === null
            ? null
            : (int) $target->shared_ai_config_owner_id;
        $clearedDefaults = $oldProviderId === null
            ? ['model_ids' => [], 'chat' => null, 'embedding' => null]
            : $this->clearDefaultsOwnedBy($target, $oldProviderId, $actor);
        $newVersion = $oldProviderId === null ? $oldVersion : $oldVersion + 1;
        $target->forceFill([
            'username' => (string) $attributes['username'],
            'display_name' => (string) ($attributes['display_name'] ?? ''),
            'email' => (string) ($attributes['email'] ?? ''),
            'status' => (string) $target->status,
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => $newVersion,
        ]);
        if (filled($attributes['password'] ?? null)) {
            $target->password = (string) $attributes['password'];
        }
        $passwordChanged = $target->isDirty('password');
        $target->save();
        if ($passwordChanged) {
            $target->revokeAuthenticationCredentials();
        }

        return new AdminAiSharingChangeResult(
            admin: $target->refresh(),
            oldProviderAdminId: $oldProviderId,
            newProviderAdminId: null,
            oldAccessVersion: $oldVersion,
            newAccessVersion: $newVersion,
            clearedDefaultModelIds: $clearedDefaults['model_ids'],
            clearedChatDefaultModelId: $clearedDefaults['chat'],
            clearedEmbeddingDefaultModelId: $clearedDefaults['embedding'],
            pendingImpactCounts: $this->dependencyInspector->pendingTaskCounts($target),
        );
    }

    /** @return array{model_ids: list<int>, chat: ?int, embedding: ?int} */
    private function clearDefaultsOwnedBy(Admin $target, int $ownerId, Admin $updatedBy): array
    {
        $setting = AdminAiSetting::query()
            ->where('admin_id', $target->getKey())
            ->lockForUpdate()
            ->first();
        if (! $setting instanceof AdminAiSetting) {
            return ['model_ids' => [], 'chat' => null, 'embedding' => null];
        }

        $defaultIds = array_values(array_unique(array_filter([
            $setting->default_chat_model_id,
            $setting->default_embedding_model_id,
        ], static fn (mixed $id): bool => $id !== null)));
        sort($defaultIds);
        $ownedIds = AiModel::query()
            ->whereIn('id', $defaultIds)
            ->where('owner_admin_id', $ownerId)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $ownedLookup = array_fill_keys($ownedIds, true);
        $attributes = ['updated_by_admin_id' => $updatedBy->getKey()];
        $clearedChatId = isset($ownedLookup[(int) $setting->default_chat_model_id])
            ? (int) $setting->default_chat_model_id
            : null;
        $clearedEmbeddingId = isset($ownedLookup[(int) $setting->default_embedding_model_id])
            ? (int) $setting->default_embedding_model_id
            : null;
        if ($clearedChatId !== null) {
            $attributes['default_chat_model_id'] = null;
        }
        if ($clearedEmbeddingId !== null) {
            $attributes['default_embedding_model_id'] = null;
        }
        if (count($attributes) > 1) {
            $setting->forceFill($attributes)->save();
        }

        return [
            'model_ids' => $ownedIds,
            'chat' => $clearedChatId,
            'embedding' => $clearedEmbeddingId,
        ];
    }

    /** @return Collection<int, Admin> */
    private function lockAdmins(Admin $actor, Admin $target): Collection
    {
        $ids = array_values(array_unique(array_filter([
            (int) $actor->getKey(),
            (int) $target->getKey(),
            $target->shared_ai_config_owner_id === null
                ? null
                : (int) $target->shared_ai_config_owner_id,
        ], static fn (mixed $id): bool => $id !== null)));
        sort($ids);

        $locked = Admin::query()
            ->whereIn('id', $ids)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $currentTarget = $locked->firstWhere('id', (int) $target->getKey());
        $currentProviderId = $currentTarget?->shared_ai_config_owner_id;
        if ($currentTarget instanceof Admin
            && $currentProviderId !== null
            && ! in_array((int) $currentProviderId, $ids, true)) {
            throw AdminAiSharingException::accessConflict((int) $target->getKey());
        }

        return $locked;
    }

    private function assertActiveSuperAdmin(?Admin $actor): void
    {
        if (! $actor instanceof Admin || ! $actor->isSuperAdmin() || (string) $actor->status !== 'active') {
            throw AdminAiSharingException::providerInvalid((int) ($actor?->getKey() ?? 0));
        }
    }
}
