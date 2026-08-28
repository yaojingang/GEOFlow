<?php

namespace App\Contracts;

use App\Models\AiModel;

interface ArticleAiQualityReviewer
{
    /**
     * @return array{result:array<string,mixed>,usage:array<string,mixed>,model:array<string,mixed>,mode:string}
     */
    public function review(AiModel $model, string $instructions): array;
}
