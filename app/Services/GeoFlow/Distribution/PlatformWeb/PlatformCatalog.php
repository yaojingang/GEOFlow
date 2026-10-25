<?php

namespace App\Services\GeoFlow\Distribution\PlatformWeb;

final class PlatformCatalog
{
    /** 默认编辑器 URL 随平台改版可能失效，渠道 config 里的 editor_url 覆盖之。 */
    private const CATALOG = [
        'toutiao' => [
            'label' => '头条号',
            'editor_url' => 'https://mp.toutiao.com/profile_v4/graph/articles/publish',
            'origin' => 'https://mp.toutiao.com',
        ],
        'sohu' => [
            'label' => '搜狐号',
            'editor_url' => 'https://mp.sohu.com/suc-ce/article/publish',
            'origin' => 'https://mp.sohu.com',
        ],
        'netease' => [
            'label' => '网易号',
            'editor_url' => 'https://mp.163.com/jizhe-mp/#/home/answer/publish',
            'origin' => 'https://mp.163.com',
        ],
    ];

    public static function all(): array
    {
        return self::CATALOG;
    }

    public static function isSupported(string $platform): bool
    {
        return array_key_exists($platform, self::CATALOG);
    }

    public static function label(string $platform): string
    {
        return self::CATALOG[$platform]['label'] ?? $platform;
    }

    public static function origin(string $platform): string
    {
        return self::CATALOG[$platform]['origin'] ?? '';
    }

    public static function editorUrl(string $platform, ?string $override): string
    {
        if ($override !== null && $override !== '' && self::acceptsEditorUrl($platform, $override)) {
            return $override;
        }

        return self::CATALOG[$platform]['editor_url'] ?? '';
    }

    public static function acceptsEditorUrl(string $platform, string $url): bool
    {
        $parts = parse_url($url);
        $origin = self::origin($platform);
        if ($origin === '' || ! is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || 'https://'.strtolower((string) ($parts['host'] ?? '')) !== $origin) {
            return false;
        }
        $path = (string) ($parts['path'] ?? '/');
        if (preg_match('~(?:^|/)\.{1,2}(?:/|$)|[\\\\\x00-\x20]~', rawurldecode($path))) {
            return false;
        }

        return match ($platform) {
            'toutiao' => str_starts_with($path, '/profile_v4/graph/articles/'),
            'sohu' => str_starts_with($path, '/suc-ce/article/'),
            'netease' => str_starts_with($path, '/jizhe-mp/'),
            default => false,
        };
    }
}
