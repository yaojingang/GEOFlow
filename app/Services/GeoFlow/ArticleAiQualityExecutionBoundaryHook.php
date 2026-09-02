<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleAiQualitySegment;

class ArticleAiQualityExecutionBoundaryHook
{
    public function beforeSampledOutbound(ArticleAiQualityCheck $check, AiModel $model): void {}

    public function beforeFullSegmentCommit(
        ArticleAiQualityCheck $check,
        ArticleAiQualitySegment $segment,
        AiModel $model,
    ): void {}

    public function beforeSampledCommit(ArticleAiQualityCheck $check, AiModel $model): void {}
}
