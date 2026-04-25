<?php

namespace App\Support\GeoFlow;

/**
 * 统一图片素材的公开访问路径，兼容历史数据中的 uploads/... 路径。
 */
final class ImageUrlNormalizer
{
    public static function toPublicUrl(string $path): string
    {
        $normalized = str_replace('\\', '/', trim($path));
        if ($normalized === '') {
            return '';
        }

        if (
            str_starts_with($normalized, 'http://')
            || str_starts_with($normalized, 'https://')
            || str_starts_with($normalized, '//')
            || str_starts_with($normalized, 'data:')
        ) {
            return $normalized;
        }

        $withoutLeadingSlash = ltrim($normalized, '/');

        if (str_starts_with($withoutLeadingSlash, 'storage/app/public/')) {
            $withoutLeadingSlash = substr($withoutLeadingSlash, strlen('storage/app/public/'));
        }

        if (str_starts_with($withoutLeadingSlash, 'public/storage/')) {
            $withoutLeadingSlash = substr($withoutLeadingSlash, strlen('public/storage/'));
        }

        if (str_starts_with($withoutLeadingSlash, 'storage/')) {
            return '/'.$withoutLeadingSlash;
        }

        if (str_starts_with($withoutLeadingSlash, 'uploads/')) {
            return '/storage/'.$withoutLeadingSlash;
        }

        return '/'.ltrim($withoutLeadingSlash, '/');
    }

    public static function readableAlt(string $alt): string
    {
        $alt = trim($alt);

        return preg_match('/^[^\/\\\\]+\.(?:png|jpe?g|gif|webp|svg|avif)$/iu', $alt) === 1 ? '' : $alt;
    }
}
