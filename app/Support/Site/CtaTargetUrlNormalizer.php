<?php

namespace App\Support\Site;

use Illuminate\Support\Str;

final class CtaTargetUrlNormalizer
{
    public static function normalize(string $url): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            return '';
        }

        $normalized = trim($url);

        if ($normalized === '' || str_contains($normalized, '\\')) {
            return '';
        }

        if (str_starts_with($normalized, '//')) {
            return '';
        }

        if (str_starts_with($normalized, '/')) {
            return $normalized;
        }

        if (Str::isUrl($normalized, ['http', 'https'])) {
            return $normalized;
        }

        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $normalized) === 1) {
            return '';
        }

        return '/'.ltrim($normalized, '/');
    }
}
