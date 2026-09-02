<?php

namespace App\Contracts;

use App\Models\AiModel;
use App\Services\GeoFlow\AiUsageReservation;
use App\Services\GeoFlow\ArticleAiQualityProviderUsageSession;

interface ProviderAttemptAwareArticleAiQualityReviewer extends PreReservedArticleAiQualityReviewer
{
    /** @return array<string,mixed> */
    public function reviewWithinVersionTrackingProviderAttempts(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
        ArticleAiQualityProviderUsageSession $usageSession,
    ): array;

    /** @return array<string,mixed> */
    public function reviewWithinReservedVersionTrackingProviderAttempts(
        AiModel $model,
        string $instructions,
        int $timeoutSeconds,
        string $executionVersion,
        AiUsageReservation $reservation,
        ArticleAiQualityProviderUsageSession $usageSession,
        bool $readinessConfirmed = false,
    ): array;
}
