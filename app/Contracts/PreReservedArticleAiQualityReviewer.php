<?php

namespace App\Contracts;

use App\Models\AiModel;
use App\Services\GeoFlow\AiUsageReservation;

interface PreReservedArticleAiQualityReviewer extends VersionAwareArticleAiQualityReviewer
{
    /** @return array<string,mixed> */
    public function reviewWithinReservedVersion(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
        AiUsageReservation $reservation,
        bool $readinessConfirmed = false,
    ): array;
}
