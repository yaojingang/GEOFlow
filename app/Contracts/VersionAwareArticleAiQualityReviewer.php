<?php

namespace App\Contracts;

use App\Models\AiModel;

interface VersionAwareArticleAiQualityReviewer extends DeadlineAwareArticleAiQualityReviewer
{
    /**
     * @return array{result:array<string,mixed>,usage:array<string,mixed>,model:array<string,mixed>,mode:string}
     */
    public function reviewWithinVersion(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
    ): array;
}
