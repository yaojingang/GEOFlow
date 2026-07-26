<?php

namespace App\Services\GeoFlow\AiVisibility;

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

    public function isCallableModelId(int $modelId, string $bindingType): bool
    {
        $model = AiModel::query()->whereKey($modelId)->first();

        return $model instanceof AiModel && $this->isCallableModel($model, $bindingType);
    }

    public function isCallableModel(AiModel $model, string $bindingType): bool
    {
        $modelType = trim((string) ($model->model_type ?? ''));

        return in_array($bindingType, ['ark', 'deepseek'], true)
            && (string) ($model->status ?? 'inactive') === 'active'
            && ($modelType === '' || $modelType === 'chat')
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
        if (! Schema::hasTable('site_settings') || ! Schema::hasTable('ai_models')) {
            return null;
        }

        $modelId = (int) (SiteSetting::query()
            ->where('setting_key', $settingKey)
            ->value('setting_value') ?? 0);
        if ($modelId <= 0) {
            return null;
        }

        $model = AiModel::query()->whereKey($modelId)->first();

        return $model instanceof AiModel && $this->isCallableModel($model, $bindingType)
            ? $model
            : null;
    }

    private function hasStoredApiKey(AiModel|AiSourceProvider $resource): bool
    {
        return trim((string) ($resource->getRawOriginal('api_key') ?? '')) !== '';
    }
}
