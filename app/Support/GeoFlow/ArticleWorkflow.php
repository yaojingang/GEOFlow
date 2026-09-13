<?php

namespace App\Support\GeoFlow;

use App\Services\GeoFlow\ArticleSlugRegistry;

final class ArticleWorkflow
{
    public const PUBLISHABLE_REVIEW_STATUSES = ['approved', 'auto_approved'];

    public static function isPublishableReviewStatus(mixed $status): bool
    {
        return in_array((string) $status, self::PUBLISHABLE_REVIEW_STATUSES, true);
    }

    public static function normalizeState(string $status, string $reviewStatus, ?string $publishedAt = null): array
    {
        $allowedStatuses = ['draft', 'published', 'private'];
        $allowedReviewStatuses = ['pending', 'approved', 'rejected', 'auto_approved'];

        if (! in_array($status, $allowedStatuses, true)) {
            $status = 'draft';
        }

        if (! in_array($reviewStatus, $allowedReviewStatuses, true)) {
            $reviewStatus = 'pending';
        }

        if (in_array($reviewStatus, ['pending', 'rejected'], true)) {
            $status = 'draft';
        }

        if ($status === 'published' && in_array($reviewStatus, ['pending', 'rejected'], true)) {
            $reviewStatus = 'approved';
        }

        if ($status !== 'published' && $reviewStatus === 'auto_approved') {
            $status = 'published';
        }

        if ($status === 'published' && $reviewStatus === 'pending') {
            $reviewStatus = 'approved';
        }

        if ($status === 'published') {
            $publishedAt = $publishedAt ?: date('Y-m-d H:i:s');
        } else {
            $publishedAt = null;
        }

        return [
            'status' => $status,
            'review_status' => $reviewStatus,
            'published_at' => $publishedAt,
        ];
    }

    /**
     * @param  array{status: string, review_status: string, published_at: mixed}  $workflowState
     * @return array{status: string, review_status: string, published_at: mixed}
     */
    public static function normalizeForPublishScope(array $workflowState, ?string $publishScope): array
    {
        if ((string) $publishScope !== 'distribution_only' || $workflowState['status'] !== 'published') {
            return $workflowState;
        }

        $workflowState['status'] = 'private';
        $workflowState['published_at'] = null;

        return $workflowState;
    }

    public static function generateUniqueSlug(string $title, ?int $excludeArticleId = null): string
    {
        return app(ArticleSlugRegistry::class)->generate($excludeArticleId);
    }
}
