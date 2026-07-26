<?php

namespace App\Services\GeoFlow\AiVisibility;

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
    public function collect(string $keyword): array
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            throw new RuntimeException('AI 可见性关键词为空');
        }

        $provider = $this->configuration->searchProvider();
        $deepSeek = $this->configuration->deepSeekModel();
        if ($provider instanceof AiSourceProvider && $deepSeek instanceof AiModel) {
            return $this->visibility->runDoubaoSearchThenDeepSeekAnalysis(
                $provider,
                $deepSeek,
                $keyword,
            );
        }

        $ark = $this->configuration->arkModel();
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
}
