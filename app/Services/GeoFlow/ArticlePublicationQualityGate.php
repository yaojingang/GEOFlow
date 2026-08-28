<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\ArticleAiQualityCheck;

class ArticlePublicationQualityGate
{
    public function __construct(
        private readonly ArticleRiskGate $riskGate,
        private readonly ArticleAiQualityGate $aiQualityGate,
    ) {}

    public function check(
        Article $article,
        string $trigger,
        ?int $adminId = null,
        ?string $overrideReason = null,
        bool $allowExistingOverride = true,
    ): ?ArticleAiQualityCheck {
        $this->riskGate->check($article, $trigger, $adminId, $overrideReason, $allowExistingOverride);

        // AI 质检人工放行必须通过独立操作留痕。普通风险放行原因只作用于确定性风险门禁。
        return $this->aiQualityGate->check($article, $trigger, allowExistingOverride: $allowExistingOverride);
    }
}
