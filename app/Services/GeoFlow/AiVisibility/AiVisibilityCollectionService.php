<?php

namespace App\Services\GeoFlow\AiVisibility;

use App\Data\Ai\SystemAiIdentity;
use App\Models\AiModel;
use App\Models\AiSourceProvider;
use App\Models\AiVisibilityRun;
use RuntimeException;

final class AiVisibilityCollectionService
{
    public function __construct(
        private readonly AiVisibilityService $visibility,
        private readonly AiVisibilityConfigurationResolver $configuration,
    ) {}

    /**
     * @return array<string, AiVisibilityRun>
     */
    public function collect(SystemAiIdentity $identity, string $keyword): array
    {
        $identity->assertCanCollectVisibility();
        $keyword = trim($keyword);
        if ($keyword === '') {
            throw new RuntimeException('AI 可见性关键词为空');
        }

        $provider = $this->configuration->searchProvider($identity);
        $deepSeek = $this->configuration->deepSeekModel($identity);
        if ($provider instanceof AiSourceProvider && $deepSeek instanceof AiModel) {
            return $this->visibility->runDoubaoSearchThenDeepSeekAnalysis(
                $identity,
                $provider,
                $deepSeek,
                $keyword,
            );
        }

        $ark = $this->configuration->arkModel($identity);
        if ($ark instanceof AiModel) {
            return [
                'ark_run' => $this->visibility->runDoubaoArkResponses($identity, $ark, $keyword),
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
                    $identity,
                    $deepSeek,
                    $keyword,
                    sprintf('请分析关键词「%s」的 GEO/AI 可见性，并给出可执行建议。', $keyword),
                ),
            ];
        }

        throw new RuntimeException('没有可用的 AI 可见性模型或搜索源');
    }
}
