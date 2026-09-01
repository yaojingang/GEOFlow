<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiModelMutationResult;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\TitleGenerationRun;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class AdminAiModelMutationService
{
    public function __construct(
        private readonly AdminAiSettingsService $personalSettings,
        private readonly AdminAiSystemSettingsService $systemSettings,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(Admin $actor, array $attributes, string $requestedScope): AdminAiModelMutationResult
    {
        return DB::transaction(function () use ($actor, $attributes, $requestedScope): AdminAiModelMutationResult {
            $lockedActor = $this->lockActiveActor($actor);
            $scope = $this->authorizedScope($lockedActor, $requestedScope);
            $model = new AiModel($attributes);
            $model->forceFill([
                'owner_admin_id' => (int) $lockedActor->getKey(),
                'access_scope' => $scope,
            ])->save();

            if ($scope === AiModel::ACCESS_SCOPE_USER_CONTENT) {
                $this->personalSettings->setPersonalDefaultIfMissing($lockedActor, $model);
            } else {
                $this->systemSettings->initializeDefaultEmbeddingForNewModel($lockedActor, $model);
            }

            return new AdminAiModelMutationResult($model);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(
        Admin $actor,
        int $modelId,
        array $attributes,
        string $requestedScope,
    ): AdminAiModelMutationResult {
        return DB::transaction(function () use ($actor, $modelId, $attributes, $requestedScope): AdminAiModelMutationResult {
            $lockedActor = $this->lockActiveActor($actor);
            $lockedModel = $this->lockConfigurableModel($lockedActor, $modelId, 'update');
            $scope = $this->authorizedScope($lockedActor, $requestedScope);

            if ($this->activeTitleGenerationCount($modelId) > 0) {
                return new AdminAiModelMutationResult($lockedModel, 'title_generation');
            }

            $lockedModel->fill(Arr::except($attributes, ['access_scope']));
            $lockedModel->forceFill(['access_scope' => $scope])->save();
            $this->personalSettings->clearIncompatibleDefaultsForModel($lockedModel, $lockedActor);
            if (! $this->isUsableSystemEmbedding($lockedModel)) {
                $this->systemSettings->clearDefaultEmbeddingForModel($lockedActor, $lockedModel);
            }

            return new AdminAiModelMutationResult($lockedModel->refresh());
        }, 3);
    }

    public function delete(Admin $actor, int $modelId): AdminAiModelMutationResult
    {
        return DB::transaction(function () use ($actor, $modelId): AdminAiModelMutationResult {
            $lockedActor = $this->lockActiveActor($actor);
            $lockedModel = $this->lockConfigurableModel($lockedActor, $modelId, 'delete');
            if ($this->activeTitleGenerationCount($modelId) > 0) {
                return new AdminAiModelMutationResult($lockedModel, 'title_generation');
            }

            $taskCount = $lockedModel->tasks()->withTrashed()->count()
                + $lockedModel->qualityTasks()->withTrashed()->count();
            if ($taskCount > 0) {
                return new AdminAiModelMutationResult($lockedModel, 'task', $taskCount);
            }

            $this->personalSettings->clearAllDefaultsForModel($lockedModel, $lockedActor);
            $this->systemSettings->clearDefaultEmbeddingForModel($lockedActor, $lockedModel);
            $lockedModel->delete();

            return new AdminAiModelMutationResult($lockedModel);
        }, 3);
    }

    private function lockActiveActor(Admin $actor): Admin
    {
        $lockedActor = Admin::query()->whereKey($actor->getKey())->lockForUpdate()->first();
        abort_unless($lockedActor instanceof Admin && (string) $lockedActor->status === 'active', 404);

        return $lockedActor;
    }

    private function lockConfigurableModel(Admin $actor, int $modelId, string $ability): AiModel
    {
        $query = AiModel::query()->ownedBy($actor);
        if (! $actor->isSuperAdmin()) {
            $query->userContent();
        }

        $model = $query->whereKey($modelId)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize($ability, $model);

        return $model;
    }

    private function authorizedScope(Admin $actor, string $requestedScope): string
    {
        if ($requestedScope === AiModel::ACCESS_SCOPE_SYSTEM_ONLY && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages([
                'access_scope' => __('admin.ai_models.error.system_scope_super_admin_only'),
            ]);
        }

        return $actor->isSuperAdmin() ? $requestedScope : AiModel::ACCESS_SCOPE_USER_CONTENT;
    }

    private function activeTitleGenerationCount(int $modelId): int
    {
        return TitleGenerationRun::query()
            ->where('ai_model_id', $modelId)
            ->whereIn('status', [
                TitleGenerationRun::STATUS_QUEUED,
                TitleGenerationRun::STATUS_RUNNING,
            ])
            ->count();
    }

    private function isUsableSystemEmbedding(AiModel $model): bool
    {
        return (string) $model->access_scope === AiModel::ACCESS_SCOPE_SYSTEM_ONLY
            && (string) $model->model_type === 'embedding'
            && (string) $model->status === 'active'
            && $model->archived_at === null;
    }
}
