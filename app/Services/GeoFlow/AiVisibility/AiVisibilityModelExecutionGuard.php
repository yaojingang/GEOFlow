<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Data\Ai\SystemAiIdentity;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiVisibilityRun;
use App\Models\SiteSetting;
use App\Support\GeoFlow\ApiKeyCrypto;

final readonly class AiVisibilityModelExecutionGuard
{
    public function __construct(
        private AiVisibilityConfigurationResolver $configuration,
        private ApiKeyCrypto $apiKeyCrypto,
    ) {}

    public function snapshotForRun(
        SystemAiIdentity $identity,
        AiVisibilityRun $run,
        AiModel $model,
        string $bindingType,
    ): AiVisibilityModelExecutionSnapshot {
        $identity->assertCanCollectVisibility();
        $settingKey = $this->settingKey($bindingType);
        $current = AiModel::query()->find((int) $model->getKey());
        if (! $current instanceof AiModel
            || ! $this->configuration->isCallableSystemModel($identity, $current, $bindingType)
            || (int) SiteSetting::query()->where('setting_key', $settingKey)->value('setting_value') !== (int) $current->id
            || ! $this->hasUsableCredential($current)) {
            throw new AiVisibilityModelAccessRevokedException('ai_config_access_revoked');
        }
        if ((int) $run->ai_model_id !== (int) $current->id
            || (string) $run->status !== AiVisibilityRun::STATUS_RUNNING) {
            throw new AiVisibilityRunDiscardedException('ai_result_discarded');
        }

        return new AiVisibilityModelExecutionSnapshot(
            runId: (int) $run->id,
            modelId: (int) $current->id,
            ownerAdminId: (int) $current->owner_admin_id,
            bindingType: $bindingType,
            settingKey: $settingKey,
            configurationRevision: $this->configurationRevision($current),
        );
    }

    public function assertCurrent(
        SystemAiIdentity $identity,
        AiVisibilityModelExecutionSnapshot $snapshot,
        bool $lockForUpdate = false,
    ): AiModel {
        $identity->assertCanCollectVisibility();
        $ownerQuery = Admin::query()->whereKey($snapshot->ownerAdminId);
        $settingQuery = SiteSetting::query()->where('setting_key', $snapshot->settingKey);
        $modelQuery = AiModel::query()->whereKey($snapshot->modelId);
        $runQuery = AiVisibilityRun::query()->whereKey($snapshot->runId);
        if ($lockForUpdate) {
            $ownerQuery->lockForUpdate();
            $settingQuery->lockForUpdate();
            $modelQuery->lockForUpdate();
            $runQuery->lockForUpdate();
        }

        $owner = $ownerQuery->first();
        $setting = $settingQuery->first();
        $model = $modelQuery->first();
        $run = $runQuery->first();

        if (! $owner instanceof Admin
            || ! $owner->isSuperAdmin()
            || (string) $owner->status !== 'active'
            || ! $model instanceof AiModel
            || (int) $model->owner_admin_id !== $snapshot->ownerAdminId
            || ! $this->configuration->isCallableSystemModel($identity, $model, $snapshot->bindingType)
            || ! $setting instanceof SiteSetting
            || (int) $setting->setting_value !== $snapshot->modelId
            || ! hash_equals($snapshot->configurationRevision, $this->configurationRevision($model))
            || ! $this->hasUsableCredential($model)) {
            throw new AiVisibilityModelAccessRevokedException('ai_config_access_revoked');
        }
        if (! $run instanceof AiVisibilityRun
            || (string) $run->status !== AiVisibilityRun::STATUS_RUNNING
            || (int) $run->ai_model_id !== $snapshot->modelId) {
            throw new AiVisibilityRunDiscardedException('ai_result_discarded');
        }

        return $model;
    }

    private function settingKey(string $bindingType): string
    {
        return match ($bindingType) {
            'ark' => AiVisibilityConfigurationResolver::ARK_MODEL_SETTING_KEY,
            'deepseek' => AiVisibilityConfigurationResolver::DEEPSEEK_MODEL_SETTING_KEY,
            default => throw new AiVisibilityModelAccessRevokedException('ai_model_not_accessible'),
        };
    }

    private function hasUsableCredential(AiModel $model): bool
    {
        try {
            return trim($this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''))) !== '';
        } catch (\Throwable) {
            return false;
        }
    }

    private function configurationRevision(AiModel $model): string
    {
        return hash('sha256', json_encode([
            'id' => (int) $model->id,
            'owner_admin_id' => (int) $model->owner_admin_id,
            'access_scope' => (string) $model->access_scope,
            'status' => (string) $model->status,
            'archived_at' => $model->archived_at?->format(DATE_ATOM),
            'model_type' => (string) $model->model_type,
            'model_id' => (string) $model->model_id,
            'api_url' => (string) $model->api_url,
            'api_key' => hash('sha256', (string) ($model->getRawOriginal('api_key') ?? '')),
            'max_tokens' => (int) ($model->max_tokens ?? 0),
            'daily_limit' => (int) ($model->daily_limit ?? 0),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
