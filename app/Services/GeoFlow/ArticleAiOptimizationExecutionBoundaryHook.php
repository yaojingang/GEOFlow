<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiOptimizationStep;

class ArticleAiOptimizationExecutionBoundaryHook
{
    public function beforeCandidateCommit(
        ArticleAiOptimizationRun $run,
        ArticleAiOptimizationStep $step,
        AiModel $model,
    ): void {}
}
