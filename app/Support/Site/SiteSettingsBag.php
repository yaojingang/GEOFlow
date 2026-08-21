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
        if (app()->bound(CurrentSite::class)) {
            $currentSite = app(CurrentSite::class);
            if ($currentSite->isResolved() && $currentSite->isHosted()) {
                return self::hosted($currentSite);
            }
        }

        return self::primaryAll();
    }

    /** @return array<string, string> */
    public static function primaryAll(): array
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

    /** @return array<string,string> */
    private static function hosted(CurrentSite $currentSite): array
    {
        $profile = $currentSite->profile();
        if ($profile === null) {
            return [];
        }

        $cacheKey = sprintf(
            'geoflow.hosted_sites.settings.%d.v%d',
            (int) $profile->id,
            (int) $profile->settings_version,
        );

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, static function () use ($profile): array {
            $channel = $profile->channel;
            if ($channel === null) {
                return [];
            }

            $stored = is_array($channel->site_settings) ? $channel->site_settings : [];
            $siteName = trim((string) ($stored['site_name'] ?? $channel->name));
            $settings = [
                'site_name' => $siteName !== '' ? $siteName : (string) $channel->name,
                'site_subtitle' => '',
                'site_description' => '',
                'site_keywords' => '',
                'copyright_info' => '',
                'site_logo' => '',
                'site_favicon' => '',
                'filing_info' => '',
                'filing_url' => '',
                'about_title' => '',
                'about_content' => '',
                'featured_limit' => '5',
                'per_page' => (string) config('geoflow.items_per_page', 12),
                'lead_form_slugs' => '[]',
                'homepage_style' => '{}',
                'homepage_modules' => '[]',
                'home_carousel_slides' => '[]',
                'article_detail_text_ads' => '[]',
                'article_detail_ads' => '[]',
            ];

            foreach ([
                'site_name', 'site_subtitle', 'site_description', 'site_keywords', 'copyright_info',
                'site_logo', 'site_favicon', 'filing_info', 'filing_url', 'about_title', 'about_content',
                'featured_limit', 'per_page', 'lead_form_slugs', 'homepage_style', 'homepage_modules',
                'home_carousel_slides', 'article_detail_text_ads', 'article_detail_ads',
            ] as $key) {
                if (array_key_exists($key, $stored)) {
                    $settings[$key] = self::stringValue($stored[$key]);
                }
            }

            $settings['active_theme'] = trim((string) ($stored['theme_id'] ?? $channel->template_key ?? ''));
            $settings['analytics_code'] = '';
            $settings['custom_html'] = '';
            $settings['custom_header_html'] = '';
            $settings['custom_footer_html'] = '';

            return array_map(static fn (mixed $value): string => self::stringValue($value), $settings);
        });
    }

    private static function stringValue(mixed $value): string
    {
        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        return (string) $value;
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
