<?php

namespace App\Support\GeoFlow;

use App\Models\Article;

final class ArticleWorkflow
{
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

    public static function generateUniqueSlug(string $title, ?int $excludeArticleId = null): string
    {
        $slug = self::randomSlug(8);

        while (true) {
            try {
                $q = Article::withTrashed()->where('slug', $slug);
                if ($excludeArticleId !== null) {
                    $q->where('id', '!=', $excludeArticleId);
                }

                if (! $q->exists()) {
                    return $slug;
                }

                $slug = self::randomSlug(8);
            } catch (\Throwable) {
                return self::randomSlug(8);
            }
        }
    }

    private static function randomSlug(int $length): string
    {
        $characters = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $slug = '';
        for ($i = 0; $i < $length; $i++) {
            $slug .= $characters[random_int(0, strlen($characters) - 1)];
        }

        return $slug;
    }
}
