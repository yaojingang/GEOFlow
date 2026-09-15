<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Models\SiteSetting;

class ManagementSiteQuery
{
    public const PUBLIC_FIELDS = ['site_name', 'site_subtitle', 'site_description', 'site_keywords', 'copyright_info', 'filing_info', 'filing_url', 'site_logo', 'site_favicon', 'seo_title_template', 'seo_description_template', 'featured_limit', 'per_page', 'active_theme', 'home_carousel_slides', 'homepage_modules', 'homepage_style', 'article_detail_ads', 'article_detail_text_ads', 'friend_links'];

    /** @return array<string, mixed> */
    public function show(string $site): array
    {
        if ($site !== 'primary') {
            throw new ApiException('site_not_found', '站点不存在或尚未开放远程管理', 404);
        }
        $settings = SiteSetting::query()->useWritePdo()->whereIn('setting_key', self::PUBLIC_FIELDS)->orderBy('setting_key')->pluck('setting_value', 'setting_key')->all();

        return [
            'site_key' => 'primary', 'kind' => 'primary',
            'name' => $settings['site_name'] ?? config('app.name', 'GEOFlow'),
            'active_theme' => $settings['active_theme'] ?? config('geoflow.default_theme'),
            'settings' => $settings,
            'settings_revision' => hash('sha256', json_encode($settings, JSON_THROW_ON_ERROR)),
        ];
    }
}
