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
        $publicPath = $withoutLeadingSlash;

        if (str_starts_with($withoutLeadingSlash, 'storage/app/public/')) {
            $publicPath = 'storage/'.substr($withoutLeadingSlash, strlen('storage/app/public/'));
        } elseif (str_starts_with($withoutLeadingSlash, 'public/storage/')) {
            $publicPath = substr($withoutLeadingSlash, strlen('public/'));
        } elseif (str_starts_with($withoutLeadingSlash, 'storage/')) {
            $publicPath = $withoutLeadingSlash;
        } elseif (str_starts_with($withoutLeadingSlash, 'uploads/')) {
            $publicPath = 'storage/'.$withoutLeadingSlash;
        }

        return self::withConfiguredBasePath($publicPath);
    }

    public static function readableAlt(string $alt): string
    {
        $alt = trim($alt);

        return preg_match('/^[^\/\\\\]+\.(?:png|jpe?g|gif|webp|svg|avif)$/iu', $alt) === 1 ? '' : $alt;
    }

    private static function withConfiguredBasePath(string $path): string
    {
        $path = '/'.ltrim($path, '/');
        $appUrlPath = parse_url((string) config('app.url'), PHP_URL_PATH);
        $basePath = is_string($appUrlPath) ? trim($appUrlPath, '/') : '';

        if ($basePath === '') {
            return $path;
        }

        $basePrefix = '/'.$basePath;
        if ($path === $basePrefix || str_starts_with($path, $basePrefix.'/')) {
            return $path;
        }

        return $basePrefix.$path;
    }
}
