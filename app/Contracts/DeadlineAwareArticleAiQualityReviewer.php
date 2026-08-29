<?php

namespace App\Contracts;

use App\Models\AiModel;

interface DeadlineAwareArticleAiQualityReviewer extends ArticleAiQualityReviewer
{
    /**
     * @return array{result:array<string,mixed>,usage:array<string,mixed>,model:array<string,mixed>,mode:string}
     */
    public function reviewWithin(AiModel $model, string $instructions, int $timeoutSeconds): array;
}
