<?php

namespace App\Support\Site;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * 前台读取 {@see SiteSetting} 键值（与后台站点设置对齐），带短 TTL 缓存减轻重复查询。
 */
final class SiteSettingsBag
{
    private const CACHE_KEY = 'geoflow.site_settings.public_map';

    private const CACHE_TTL_SECONDS = 60;

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        if (! Schema::hasTable('site_settings')) {
            return [];
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, static function (): array {
            /** @var array<string, string> $map */
            $map = SiteSetting::query()
                ->pluck('setting_value', 'setting_key')
                ->all();

            return $map;
        });
    }

    public static function get(string $key, string $default = ''): string
    {
        $map = self::all();

        return isset($map[$key]) ? (string) $map[$key] : $default;
    }

    /**
     * 站点设置变更后由后台调用，避免前台读到旧缓存。
     */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
