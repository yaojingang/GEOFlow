<?php

namespace App\Contracts;

use App\Models\AiModel;

interface ArticleAiOptimizationRefiner
{
    /**
     * @return array{result:array<string,mixed>,usage:array<string,mixed>,model:array<string,mixed>,mode:string}
     */
    public function refine(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        int $quotaReserve = 0,
    ): array;
}
