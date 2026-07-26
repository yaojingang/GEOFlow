<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use App\Models\SiteSetting;
use RuntimeException;

final class AiVisibilityCollectionService
{
    private const ARK_MODEL_SETTING_KEY = 'ai_visibility_ark_model_id';

    private const DEEPSEEK_MODEL_SETTING_KEY = 'ai_visibility_deepseek_analysis_model_id';

    public function __construct(
        private readonly AiVisibilityService $visibility,
        private readonly AiProviderEndpointPolicy $endpointPolicy,
    ) {}

    /**
     * @return array<string, AiVisibilityRun>
     */
    public function collect(string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            throw new RuntimeException('AI 可见性关键词为空');
        }

        $provider = $this->searchProvider();
        $deepSeek = $this->configuredModel(self::DEEPSEEK_MODEL_SETTING_KEY, 'deepseek');
        if ($provider instanceof AiSourceProvider && $deepSeek instanceof AiModel) {
            return $this->visibility->runDoubaoSearchThenDeepSeekAnalysis(
                $provider,
                $deepSeek,
                $keyword,
            );
        }

        $ark = $this->configuredModel(self::ARK_MODEL_SETTING_KEY, 'ark');
        if ($ark instanceof AiModel) {
            return [
                'ark_run' => $this->visibility->runDoubaoArkResponses($ark, $keyword),
            ];
        }

        if ($provider instanceof AiSourceProvider) {
            return [
                'search_run' => $this->visibility->runDoubaoSearchCustom($provider, $keyword),
            ];
        }

        if ($deepSeek instanceof AiModel) {
            return [
                'analysis_run' => $this->visibility->runDeepSeekAnalysis(
                    $deepSeek,
                    $keyword,
                    sprintf('请分析关键词「%s」的 GEO/AI 可见性，并给出可执行建议。', $keyword),
                ),
            ];
        }

        throw new RuntimeException('没有可用的 AI 可见性模型或搜索源');
    }

    private function searchProvider(): ?AiSourceProvider
    {
        $providers = AiSourceProvider::query()
            ->where('provider_key', AiSourceProvider::PROVIDER_DOUBAO_SEARCH_CUSTOM)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        return $providers->first(function (AiSourceProvider $provider): bool {
            return $this->endpointPolicy->acceptsSearchApi((string) $provider->endpoint_url)
                && trim((string) ($provider->getRawOriginal('api_key') ?? '')) !== '';
        });
    }

    private function configuredModel(string $settingKey, string $bindingType): ?AiModel
    {
        $modelId = (int) (SiteSetting::query()
            ->where('setting_key', $settingKey)
            ->value('setting_value') ?? 0);
        if ($modelId <= 0) {
            return null;
        }

        $model = AiModel::query()->whereKey($modelId)->first();
        if (! $model instanceof AiModel) {
            return null;
        }

        $modelType = trim((string) ($model->model_type ?? ''));
        if ((string) ($model->status ?? 'inactive') !== 'active'
            || ($modelType !== '' && $modelType !== 'chat')
            || trim((string) ($model->getRawOriginal('api_key') ?? '')) === ''
            || ! $this->endpointPolicy->acceptsModelApi($bindingType, (string) ($model->api_url ?? ''))) {
            return null;
        }

        return $model;
    }
}
