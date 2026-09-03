<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Data\Ai\SystemAiIdentity;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\SiteSetting;
use Illuminate\Support\Facades\Schema;

final class AiVisibilityConfigurationResolver
{
    public const ARK_MODEL_SETTING_KEY = 'ai_visibility_ark_model_id';

    public const DEEPSEEK_MODEL_SETTING_KEY = 'ai_visibility_deepseek_analysis_model_id';

    public function __construct(
        private readonly AiProviderEndpointPolicy $endpointPolicy,
    ) {}

    public function searchProvider(SystemAiIdentity $identity): ?AiSourceProvider
    {
        $identity->assertCanResolveVisibilityConfiguration();
        if (! Schema::hasTable('ai_source_providers')) {
            return null;
        }

        return AiSourceProvider::query()
            ->where('provider_key', AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM)
            ->where('status', 'active')
            ->orderBy('id')
            ->get()
            ->first(fn (AiSourceProvider $provider): bool => $this->endpointPolicy
                ->acceptsSearchApi((string) ($provider->endpoint_url ?? ''))
                && $this->hasStoredApiKey($provider));
    }

    public function arkModel(SystemAiIdentity $identity): ?AiModel
    {
        return $this->configuredModel($identity, self::ARK_MODEL_SETTING_KEY, 'ark');
    }

    public function deepSeekModel(SystemAiIdentity $identity): ?AiModel
    {
        return $this->configuredModel($identity, self::DEEPSEEK_MODEL_SETTING_KEY, 'deepseek');
    }

    /** @return array{model:?AiModel,reason:?string} */
    public function modelResolution(SystemAiIdentity $identity, string $bindingType): array
    {
        $identity->assertCanResolveVisibilityConfiguration();
        $settingKey = $bindingType === 'ark'
            ? self::ARK_MODEL_SETTING_KEY
            : self::DEEPSEEK_MODEL_SETTING_KEY;

        return $this->configuredModelResolution($identity, $settingKey, $bindingType);
    }

    public function isCallableAdminOwnedModelId(int $modelId, string $bindingType, Admin $owner): bool
    {
        $model = AiModel::query()->whereKey($modelId)->first();

        return $model instanceof AiModel && $this->isCallableAdminOwnedModel($model, $bindingType, $owner);
    }

    public function isCallableAdminOwnedModel(AiModel $model, string $bindingType, Admin $owner): bool
    {
        return $this->isCallableSystemOnlyModel($model, $bindingType, (int) $owner->getKey());
    }

    public function isCallableSystemModel(
        SystemAiIdentity $identity,
        AiModel $model,
        string $bindingType,
    ): bool {
        $identity->assertCanResolveVisibilityConfiguration();

        return $this->isCallableSystemOnlyModel($model, $bindingType, null);
    }

    private function isCallableSystemOnlyModel(AiModel $model, string $bindingType, ?int $ownerAdminId): bool
    {
        $modelType = trim((string) ($model->model_type ?? ''));
        $modelOwner = $model->owner_admin_id === null
            ? null
            : Admin::query()->whereKey($model->owner_admin_id)->active()->first();

        return in_array($bindingType, ['ark', 'deepseek'], true)
            && $modelOwner instanceof Admin
            && $modelOwner->isSuperAdmin()
            && ($ownerAdminId === null || (int) $modelOwner->getKey() === $ownerAdminId)
            && (string) $model->access_scope === AiModel::ACCESS_SCOPE_SYSTEM_ONLY
            && (string) ($model->status ?? 'inactive') === 'active'
            && $model->archived_at === null
            && ($modelType === '' || $modelType === 'chat')
            && trim((string) $model->model_id) !== ''
            && $this->hasStoredApiKey($model)
            && $this->endpointPolicy->acceptsModelApi(
                $bindingType,
                (string) ($model->api_url ?? ''),
            );
    }

    /**
     * @return array{configured: bool, doubao_search_configured: bool, ark_configured: bool, deepseek_configured: bool}
     */
    public function status(SystemAiIdentity $identity): array
    {
        $identity->assertCanResolveVisibilityConfiguration();
        $doubaoSearchConfigured = $this->searchProvider($identity) instanceof AiSourceProvider;
        $arkConfigured = $this->arkModel($identity) instanceof AiModel;
        $deepSeekConfigured = $this->deepSeekModel($identity) instanceof AiModel;

        return [
            'configured' => $doubaoSearchConfigured || $arkConfigured || $deepSeekConfigured,
            'doubao_search_configured' => $doubaoSearchConfigured,
            'ark_configured' => $arkConfigured,
            'deepseek_configured' => $deepSeekConfigured,
        ];
    }

    private function configuredModel(
        SystemAiIdentity $identity,
        string $settingKey,
        string $bindingType,
    ): ?AiModel {
        return $this->configuredModelResolution($identity, $settingKey, $bindingType)['model'];
    }

    /** @return array{model:?AiModel,reason:?string} */
    private function configuredModelResolution(
        SystemAiIdentity $identity,
        string $settingKey,
        string $bindingType,
    ): array {
        $identity->assertCanResolveVisibilityConfiguration();
        if (! Schema::hasTable('site_settings') || ! Schema::hasTable('ai_models')) {
            return ['model' => null, 'reason' => 'ai_model_unavailable'];
        }

        $modelId = (int) (SiteSetting::query()
            ->where('setting_key', $settingKey)
            ->value('setting_value') ?? 0);
        if ($modelId <= 0) {
            return ['model' => null, 'reason' => 'ai_model_unavailable'];
        }

        $model = AiModel::query()->whereKey($modelId)->first();

        if (! $model instanceof AiModel) {
            return ['model' => null, 'reason' => 'ai_model_unavailable'];
        }
        if (! $this->isCallableSystemModel($identity, $model, $bindingType)) {
            return ['model' => null, 'reason' => 'ai_model_not_accessible'];
        }

        return ['model' => $model, 'reason' => null];
    }

    private function hasStoredApiKey(AiModel|AiSourceProvider $resource): bool
    {
        return trim((string) ($resource->getRawOriginal('api_key') ?? '')) !== '';
    }
}
