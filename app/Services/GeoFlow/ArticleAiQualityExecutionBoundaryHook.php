<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\ArticleAiQualityCheck;

class ArticleAiQualityExecutionBoundaryHook
{
    public function beforeSampledOutbound(ArticleAiQualityCheck $check, AiModel $model): void {}
}
