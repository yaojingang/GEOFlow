<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\SiteSetting;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminAiSystemSettingsService
{
    public function updateDefaultEmbedding(Admin $actor, int $modelId): void
    {
        DB::transaction(function () use ($actor, $modelId): void {
            $lockedActor = $this->lockActiveSuperAdmin($actor);
            if ($modelId > 0 && ! $this->lockSystemModel($lockedActor, $modelId, 'embedding') instanceof AiModel) {
                throw ValidationException::withMessages([
                    'default_embedding_model_id' => __('admin.ai_models.error.embedding_unavailable'),
                ]);
            }

            $this->writeSetting('default_embedding_model_id', (string) $modelId);
        }, 3);
    }

    public function updateChunking(Admin $actor, string $strategy, int $modelId): void
    {
        DB::transaction(function () use ($actor, $strategy, $modelId): void {
            $lockedActor = $this->lockActiveSuperAdmin($actor);
            if ($strategy === 'semantic_llm' && $modelId <= 0) {
                throw ValidationException::withMessages([
                    'knowledge_chunking_model_id' => __('admin.ai_models.error.chunking_model_required'),
                ]);
            }
            if ($modelId > 0 && ! $this->lockSystemModel($lockedActor, $modelId, 'chat') instanceof AiModel) {
                throw ValidationException::withMessages([
                    'knowledge_chunking_model_id' => __('admin.ai_models.error.chunking_model_unavailable'),
                ]);
            }

            $this->writeSetting('knowledge_chunk_strategy', $strategy);
            $this->writeSetting('knowledge_chunking_model_id', (string) $modelId);
        }, 3);
    }

    /** @return array<int, array{id:int,name:string,model_id:string}> */
    public function modelOptions(Admin $actor, string $modelType): array
    {
        $currentActor = Admin::query()->whereKey($actor->getKey())->active()->first();
        if (! $currentActor instanceof Admin || ! $currentActor->isSuperAdmin()) {
            throw new AuthorizationException('ai_system_config_super_admin_only');
        }

        return $this->systemModelQuery($currentActor, $modelType)
            ->select(['id', 'name', 'model_id'])
            ->orderBy('name')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AiModel $model): array => [
                'id' => (int) $model->getKey(),
                'name' => (string) $model->name,
                'model_id' => (string) $model->model_id,
            ])
            ->all();
    }

    public function initializeDefaultEmbeddingForNewModel(Admin $lockedActor, AiModel $lockedModel): void
    {
        if (! $lockedActor->isSuperAdmin()
            || (int) $lockedModel->owner_admin_id !== (int) $lockedActor->getKey()
            || (string) $lockedModel->access_scope !== AiModel::ACCESS_SCOPE_SYSTEM_ONLY
            || (string) $lockedModel->status !== 'active'
            || $lockedModel->archived_at !== null
            || (string) $lockedModel->model_type !== 'embedding') {
            return;
        }

        $setting = SiteSetting::query()
            ->where('setting_key', 'default_embedding_model_id')
            ->lockForUpdate()
            ->first();
        if ($setting instanceof SiteSetting && (int) $setting->setting_value > 0) {
            return;
        }

        $this->writeSetting('default_embedding_model_id', (string) $lockedModel->getKey());
    }

    public function clearDefaultEmbeddingForModel(Admin $lockedActor, AiModel $lockedModel): void
    {
        if (! $lockedActor->isSuperAdmin()) {
            return;
        }

        $setting = SiteSetting::query()
            ->where('setting_key', 'default_embedding_model_id')
            ->lockForUpdate()
            ->first();
        if (! $setting instanceof SiteSetting || (int) $setting->setting_value !== (int) $lockedModel->getKey()) {
            return;
        }

        $setting->forceFill(['setting_value' => '0'])->save();
    }

    private function lockActiveSuperAdmin(Admin $actor): Admin
    {
        $lockedActor = Admin::query()->whereKey($actor->getKey())->lockForUpdate()->first();
        if (! $lockedActor instanceof Admin
            || (string) $lockedActor->status !== 'active'
            || ! $lockedActor->isSuperAdmin()) {
            throw new AuthorizationException('ai_system_config_super_admin_only');
        }

        return $lockedActor;
    }

    private function lockSystemModel(Admin $lockedActor, int $modelId, string $modelType): ?AiModel
    {
        return $this->systemModelQuery($lockedActor, $modelType)
            ->whereKey($modelId)
            ->lockForUpdate()
            ->first();
    }

    private function systemModelQuery(Admin $actor, string $modelType): Builder
    {
        return AiModel::query()
            ->ownedBy($actor)
            ->systemOnly()
            ->active()
            ->unarchived()
            ->where(function (Builder $query) use ($modelType): void {
                if ($modelType === 'chat') {
                    $query->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');

                    return;
                }

                $query->where('model_type', 'embedding');
            });
    }

    private function writeSetting(string $key, string $value): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => $key],
            ['setting_value' => $value],
        );
    }
}
