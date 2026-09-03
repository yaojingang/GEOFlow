<?php

namespace App\Contracts;

use App\Models\AiModel;
use App\Services\Admin\AiModelProviderUsageSession;

interface ProviderAttemptAwareArticleAiOptimizationRefiner extends ArticleAiOptimizationRefiner
{
    /** @return array{result:array<string,mixed>,usage:array<string,mixed>,model:array<string,mixed>,mode:string} */
    public function refineTrackingProviderAttempts(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        int $quotaReserve,
        AiModelProviderUsageSession $usageSession,
    ): array;
}
