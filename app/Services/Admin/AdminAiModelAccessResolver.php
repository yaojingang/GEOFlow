<?php

namespace App\Services\Admin;

use App\Data\Admin\SharedAiModelData;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class AdminAiModelAccessResolver
{
    private const SANITIZED_COLUMNS = [
        'id',
        'owner_admin_id',
        'name',
        'version',
        'model_type',
        'status',
        'failover_priority',
        'access_scope',
        'archived_at',
    ];

    public function sanitizedFor(Admin $actor, AiModel $model): SharedAiModelData
    {
        $visibleModel = $this->visibleQuery($actor)
            ->whereKey($model->getKey())
            ->first();

        if (! $visibleModel instanceof AiModel) {
            throw AiModelAccessException::modelNotAccessible($actor, $model);
        }

        return SharedAiModelData::fromModel(
            $visibleModel,
            (int) $visibleModel->owner_admin_id !== (int) $actor->getKey(),
        );
    }

    public function managementQuery(Admin $actor): Builder
    {
        $actor = $this->activeActor($actor);
        $query = AiModel::query()->ownedBy($actor);

        return $actor->isSuperAdmin() ? $query : $query->userContent();
    }

    public function manageableBy(Admin $actor): Builder
    {
        return $this->managementQuery($actor);
    }

    public function visibleQuery(Admin $actor): Builder
    {
        $actor = $this->activeActor($actor);
        $query = AiModel::query()
            ->select(self::SANITIZED_COLUMNS)
            ->whereIn('owner_admin_id', $this->visibleOwnerIds($actor));

        return $actor->isSuperAdmin() ? $query : $query->userContent();
    }

    public function visibleTo(Admin $actor): Builder
    {
        return $this->visibleQuery($actor);
    }

    public function usableQuery(Admin $actor): Builder
    {
        $actor = $this->activeActor($actor);
        $ownerColumn = (new AiModel)->qualifyColumn('owner_admin_id');

        return AiModel::query()
            ->whereIn('owner_admin_id', $this->visibleOwnerIds($actor))
            ->userContent()
            ->active()
            ->unarchived()
            ->orderByRaw("CASE WHEN {$ownerColumn} = ? THEN 0 ELSE 1 END", [(int) $actor->getKey()])
            ->inFailoverOrder();
    }

    public function usableBy(Admin $actor): Builder
    {
        return $this->usableQuery($actor);
    }

    public function assertUsable(Admin $actor, AiModel $model): void
    {
        $actor = $this->activeActor($actor);
        $currentModel = AiModel::query()->find($model->getKey());

        if (! $currentModel instanceof AiModel) {
            throw AiModelAccessException::modelUnavailable($actor, $model);
        }

        if ((string) $currentModel->access_scope !== AiModel::ACCESS_SCOPE_USER_CONTENT) {
            throw AiModelAccessException::modelNotAccessible($actor, $currentModel);
        }

        $isPersonal = (int) $currentModel->owner_admin_id === (int) $actor->getKey();
        if (! $isPersonal) {
            $sharedProviderId = $actor->isSuperAdmin()
                ? null
                : $actor->shared_ai_config_owner_id;

            if ($sharedProviderId === null || (int) $currentModel->owner_admin_id !== (int) $sharedProviderId) {
                throw AiModelAccessException::modelNotAccessible($actor, $currentModel);
            }

            if (! $this->activeSharedProvider($actor) instanceof Admin) {
                throw AiModelAccessException::configOwnerInactive($actor, (int) $sharedProviderId);
            }
        }

        if ((string) $currentModel->status !== 'active' || $currentModel->archived_at !== null) {
            throw AiModelAccessException::modelUnavailable($actor, $currentModel);
        }
    }

    /** @return Collection<int, AiModel> */
    public function resolveCandidates(Admin $actor, ?string $modelType = null): Collection
    {
        $actor = $this->activeActor($actor);
        $personal = $this->candidatePool($actor, $modelType)->get();

        if ($actor->isSuperAdmin() || $actor->shared_ai_config_owner_id === null) {
            return $this->requireCandidates($actor, $personal);
        }

        $provider = $this->activeSharedProvider($actor);

        if (! $provider instanceof Admin) {
            if ($personal->isEmpty()) {
                throw AiModelAccessException::configOwnerInactive(
                    $actor,
                    (int) $actor->shared_ai_config_owner_id,
                );
            }

            return $personal;
        }

        return $this->requireCandidates(
            $actor,
            $personal->concat($this->candidatePool($provider, $modelType)->get()),
        );
    }

    private function candidatePool(Admin $owner, ?string $modelType): Builder
    {
        return AiModel::query()
            ->ownedBy($owner)
            ->userContent()
            ->active()
            ->unarchived()
            ->when($modelType !== null, static fn (Builder $query): Builder => $query->where('model_type', $modelType))
            ->inFailoverOrder();
    }

    private function activeActor(Admin $actor): Admin
    {
        $activeActor = Admin::query()
            ->whereKey($actor->getKey())
            ->active()
            ->first();

        if (! $activeActor instanceof Admin) {
            throw AiModelAccessException::executionAdminInactive($actor);
        }

        return $activeActor;
    }

    private function activeSharedProvider(Admin $actor): ?Admin
    {
        if ($actor->isSuperAdmin() || $actor->shared_ai_config_owner_id === null) {
            return null;
        }

        $provider = Admin::query()
            ->whereKey($actor->shared_ai_config_owner_id)
            ->active()
            ->first();

        return $provider?->isSuperAdmin() === true ? $provider : null;
    }

    /** @return list<int> */
    private function visibleOwnerIds(Admin $actor): array
    {
        $ownerIds = [(int) $actor->getKey()];
        $provider = $this->activeSharedProvider($actor);

        if ($provider instanceof Admin) {
            $ownerIds[] = (int) $provider->getKey();
        }

        return $ownerIds;
    }

    /**
     * @param  Collection<int, AiModel>  $candidates
     * @return Collection<int, AiModel>
     */
    private function requireCandidates(Admin $actor, Collection $candidates): Collection
    {
        if ($candidates->isEmpty()) {
            throw AiModelAccessException::modelUnavailable($actor);
        }

        return $candidates;
    }
}
