<?php

namespace App\Services\GeoFlow\AiVisibility;

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

    public function searchProvider(): ?AiSourceProvider
    {
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

    public function arkModel(): ?AiModel
    {
        return $this->configuredModel(self::ARK_MODEL_SETTING_KEY, 'ark');
    }

    public function deepSeekModel(): ?AiModel
    {
        return $this->configuredModel(self::DEEPSEEK_MODEL_SETTING_KEY, 'deepseek');
    }

    /** @return array{model:?AiModel,reason:?string} */
    public function modelResolution(string $bindingType): array
    {
        $settingKey = $bindingType === 'ark'
            ? self::ARK_MODEL_SETTING_KEY
            : self::DEEPSEEK_MODEL_SETTING_KEY;

        return $this->configuredModelResolution($settingKey, $bindingType);
    }

    public function isCallableModelId(int $modelId, string $bindingType, ?Admin $owner = null): bool
    {
        $model = AiModel::query()->whereKey($modelId)->first();

        return $model instanceof AiModel && $this->isCallableModel($model, $bindingType, $owner);
    }

    public function isCallableModel(AiModel $model, string $bindingType, ?Admin $owner = null): bool
    {
        $modelType = trim((string) ($model->model_type ?? ''));
        $modelOwner = $model->owner_admin_id === null
            ? null
            : Admin::query()->whereKey($model->owner_admin_id)->active()->first();

        return in_array($bindingType, ['ark', 'deepseek'], true)
            && $modelOwner instanceof Admin
            && $modelOwner->isSuperAdmin()
            && ($owner === null || (int) $modelOwner->getKey() === (int) $owner->getKey())
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
    public function status(): array
    {
        $doubaoSearchConfigured = $this->searchProvider() instanceof AiSourceProvider;
        $arkConfigured = $this->arkModel() instanceof AiModel;
        $deepSeekConfigured = $this->deepSeekModel() instanceof AiModel;

        return [
            'configured' => $doubaoSearchConfigured || $arkConfigured || $deepSeekConfigured,
            'doubao_search_configured' => $doubaoSearchConfigured,
            'ark_configured' => $arkConfigured,
            'deepseek_configured' => $deepSeekConfigured,
        ];
    }

    private function configuredModel(string $settingKey, string $bindingType): ?AiModel
    {
        return $this->configuredModelResolution($settingKey, $bindingType)['model'];
    }

    /** @return array{model:?AiModel,reason:?string} */
    private function configuredModelResolution(string $settingKey, string $bindingType): array
    {
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
        if (! $this->isCallableModel($model, $bindingType)) {
            return ['model' => null, 'reason' => 'ai_model_not_accessible'];
        }

        return ['model' => $model, 'reason' => null];
    }

    private function hasStoredApiKey(AiModel|AiSourceProvider $resource): bool
    {
        return trim((string) ($resource->getRawOriginal('api_key') ?? '')) !== '';
    }
}
