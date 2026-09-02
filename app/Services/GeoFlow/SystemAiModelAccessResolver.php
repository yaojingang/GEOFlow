<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\SystemAiIdentity;
use App\Exceptions\AiModelAccessException;
use App\Models\AiModel;
use App\Models\SiteSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class SystemAiModelAccessResolver
{
    public function resolveEmbedding(SystemAiIdentity $identity): ?AiModel
    {
        return $this->resolveBound($identity, 'default_embedding_model_id', 'embedding');
    }

    public function resolveSemanticChunking(SystemAiIdentity $identity): ?AiModel
    {
        return $this->resolveBound($identity, 'knowledge_chunking_model_id', 'chat');
    }

    public function assertEmbeddingCurrent(SystemAiIdentity $identity, AiModel $model): AiModel
    {
        return $this->assertBoundCurrent($identity, $model, 'default_embedding_model_id', 'embedding');
    }

    public function assertSemanticChunkingCurrent(SystemAiIdentity $identity, AiModel $model): AiModel
    {
        return $this->assertBoundCurrent($identity, $model, 'knowledge_chunking_model_id', 'chat');
    }

    private function resolveBound(SystemAiIdentity $identity, string $settingKey, string $modelType): ?AiModel
    {
        $identity->assertCanBuildKnowledgeIndex();
        $modelId = (int) (SiteSetting::query()->where('setting_key', $settingKey)->value('setting_value') ?? 0);

        if ($modelId <= 0) {
            return null;
        }

        $modelQuery = $this->systemModelQuery($modelType)->whereKey($modelId);
        if (DB::transactionLevel() > 0) {
            $modelQuery->lockForUpdate();
        }
        $model = $modelQuery->first();
        if (DB::transactionLevel() > 0) {
            $lockedModelId = (int) (SiteSetting::query()
                ->where('setting_key', $settingKey)
                ->lockForUpdate()
                ->value('setting_value') ?? 0);
            if ($lockedModelId !== $modelId) {
                return null;
            }
        }

        return $model;
    }

    private function assertBoundCurrent(
        SystemAiIdentity $identity,
        AiModel $model,
        string $settingKey,
        string $modelType,
    ): AiModel {
        $current = $this->resolveBound($identity, $settingKey, $modelType);
        if (! $current instanceof AiModel || (int) $current->getKey() !== (int) $model->getKey()) {
            throw AiModelAccessException::configAccessRevokedForAdminId((int) $model->owner_admin_id);
        }

        return $current;
    }

    private function systemModelQuery(string $modelType): Builder
    {
        return AiModel::query()
            ->systemOnly()
            ->active()
            ->unarchived()
            ->whereHas('owner', static function (Builder $owner): void {
                $owner->active()->superAdmins();
            })
            ->where(function (Builder $query) use ($modelType): void {
                if ($modelType === 'chat') {
                    $query->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');

                    return;
                }

                $query->where('model_type', 'embedding');
            });
    }
}
