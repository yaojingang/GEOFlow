<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Support\AdminBasePathManager;
use App\Support\AdminWeb;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * 网站设置控制器。
 *
 * 对齐 bak/admin/site-settings.php 的核心能力：
 * 1. 读取并展示网站基础设置；
 * 2. 保存基础信息、SEO模板与统计代码；
 * 3. 维持键值对存储结构（site_settings）。
 */
class SiteSettingsController extends Controller
{
    /**
     * 网站设置页面。
     */
    public function index(): View
    {
        $settings = $this->loadSettings();

        return view('admin.site-settings.index', [
            'pageTitle' => __('admin.site_settings.page_title'),
            'activeMenu' => 'site_settings',
            'adminSiteName' => AdminWeb::siteName(),
            'settings' => $settings,
            'availableThemes' => $this->discoverThemes(),
            'homeCarouselSlides' => $this->parseHomeCarouselSlides((string) ($settings['home_carousel_slides'] ?? '[]')),
            'articleDetailAds' => $this->parseArticleDetailAds((string) ($settings['article_detail_ads'] ?? '[]')),
        ]);
    }

    /**
     * 保存网站基础设置。
     */
    public function update(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'site_name' => ['required', 'string', 'max:120'],
            'site_subtitle' => ['nullable', 'string', 'max:255'],
            'site_description' => ['nullable', 'string'],
            'site_keywords' => ['nullable', 'string', 'max:500'],
            'copyright_info' => ['nullable', 'string', 'max:500'],
            'site_logo' => ['nullable', 'url', 'max:500'],
            'site_favicon' => ['nullable', 'url', 'max:500'],
            'analytics_code' => ['nullable', 'string'],
            'seo_title_template' => ['nullable', 'string', 'max:255'],
            'seo_description_template' => ['nullable', 'string', 'max:255'],
            'featured_limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
            'home_carousel_slides' => ['nullable', 'array', 'max:3'],
            'home_carousel_slides.*.image_url' => ['nullable', 'string', 'max:500'],
            'home_carousel_slides.*.title' => ['nullable', 'string', 'max:120'],
            'home_carousel_slides.*.link_url' => ['nullable', 'string', 'max:500'],
            'home_carousel_slides.*.enabled' => ['nullable'],
            'admin_base_path' => [
                'required',
                'string',
                'min:3',
                'max:48',
                'regex:/^[a-z0-9][a-z0-9_-]*[a-z0-9]$/',
                Rule::notIn(AdminBasePathManager::reservedSegments()),
            ],
        ], [
            'site_name.required' => __('admin.site_settings.error.site_name_required'),
            'admin_base_path.required' => __('admin.site_settings.error.admin_base_path_required'),
            'admin_base_path.min' => __('admin.site_settings.error.admin_base_path_invalid'),
            'admin_base_path.max' => __('admin.site_settings.error.admin_base_path_invalid'),
            'admin_base_path.regex' => __('admin.site_settings.error.admin_base_path_invalid'),
            'admin_base_path.not_in' => __('admin.site_settings.error.admin_base_path_reserved'),
        ]);

        try {
            $newAdminBasePath = AdminBasePathManager::normalize((string) $payload['admin_base_path']);
        } catch (\Throwable) {
            return back()->withErrors(['admin_base_path' => __('admin.site_settings.error.admin_base_path_invalid')])->withInput();
        }

        $currentAdminBasePath = AdminWeb::basePath();

        $settings = [
            'site_name' => trim((string) $payload['site_name']),
            'site_title' => trim((string) $payload['site_name']),
            'site_subtitle' => trim((string) ($payload['site_subtitle'] ?? '')),
            'site_description' => trim((string) ($payload['site_description'] ?? '')),
            'site_keywords' => trim((string) ($payload['site_keywords'] ?? '')),
            'copyright_info' => trim((string) ($payload['copyright_info'] ?? '')),
            'site_logo' => trim((string) ($payload['site_logo'] ?? '')),
            'site_favicon' => trim((string) ($payload['site_favicon'] ?? '')),
            'analytics_code' => trim((string) ($payload['analytics_code'] ?? '')),
            'seo_title_template' => trim((string) ($payload['seo_title_template'] ?? '')),
            'seo_description_template' => trim((string) ($payload['seo_description_template'] ?? '')),
            'featured_limit' => (string) ((int) ($payload['featured_limit'] ?? 6)),
            'per_page' => (string) ((int) ($payload['per_page'] ?? 12)),
            'home_carousel_slides' => (string) json_encode($this->normalizeHomeCarouselSlides($payload['home_carousel_slides'] ?? []), JSON_UNESCAPED_UNICODE),
            'admin_base_path' => $newAdminBasePath,
        ];

        foreach ($settings as $settingKey => $settingValue) {
            SiteSetting::query()->updateOrCreate(
                ['setting_key' => $settingKey],
                ['setting_value' => $settingValue]
            );
        }

        SiteSettingsBag::forget();

        if ($newAdminBasePath !== $currentAdminBasePath) {
            try {
                AdminBasePathManager::persist($newAdminBasePath);
            } catch (\Throwable $e) {
                return back()->withErrors([
                    'admin_base_path' => __('admin.site_settings.error.admin_base_path_save_failed', ['message' => $e->getMessage()]),
                ])->withInput();
            }

            $newAdminUrl = url('/'.$newAdminBasePath.'/site-settings');

            return redirect()->to($newAdminUrl)->with('message', __('admin.site_settings.message.saved_admin_base_path', ['url' => $newAdminUrl]));
        }

        return redirect()->route('admin.site-settings.index')->with('message', __('admin.site_settings.message.saved'));
    }

    /**
     * 保存模板设置。
     */
    public function updateTheme(Request $request): RedirectResponse
    {
        $selectedTheme = trim((string) $request->input('active_theme', ''));
        $availableThemeIds = array_map(
            static fn (array $theme): string => (string) $theme['id'],
            $this->discoverThemes()
        );

        if ($selectedTheme !== '' && ! in_array($selectedTheme, $availableThemeIds, true)) {
            return back()->withErrors(__('admin.site_settings.theme.invalid_selection'));
        }

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'active_theme'],
            ['setting_value' => $selectedTheme]
        );

        SiteSettingsBag::forget();

        if ($selectedTheme === '') {
            return redirect()->route('admin.site-settings.index')->with('message', __('admin.site_settings.theme.message.default_enabled'));
        }

        return redirect()->route('admin.site-settings.index')->with('message', __('admin.site_settings.theme.message.activated', ['name' => $selectedTheme]));
    }

    /**
     * 保存文章详情页广告位设置。
     */
    public function updateArticleDetailAds(Request $request): RedirectResponse
    {
        $postedAds = $request->input('ads', []);
        if (! is_array($postedAds)) {
            $postedAds = [];
        }

        $ads = [];
        foreach ($postedAds as $index => $postedAd) {
            if (! is_array($postedAd)) {
                continue;
            }

            $name = trim((string) ($postedAd['name'] ?? ''));
            $badge = trim((string) ($postedAd['badge'] ?? ''));
            $title = trim((string) ($postedAd['title'] ?? ''));
            $copy = trim((string) ($postedAd['copy'] ?? ''));
            $buttonText = trim((string) ($postedAd['button_text'] ?? ''));
            $buttonUrl = $this->normalizeCtaTargetUrl((string) ($postedAd['button_url'] ?? ''));
            $enabled = ! empty($postedAd['enabled']);
            $id = trim((string) ($postedAd['id'] ?? ''));

            if ($name === '' && $badge === '' && $title === '' && $copy === '' && $buttonText === '' && $buttonUrl === '') {
                continue;
            }

            if ($copy === '' || $buttonText === '' || $buttonUrl === '') {
                return back()->withErrors(__('admin.site_settings.ads.validation_required', ['index' => ((int) $index + 1)]));
            }

            $ads[] = [
                'id' => $id !== '' ? $id : uniqid('article_ad_', true),
                'name' => $name !== '' ? $name : __('admin.site_settings.ads.default_name', ['index' => (count($ads) + 1)]),
                'badge' => $badge,
                'title' => $title,
                'copy' => $copy,
                'button_text' => $buttonText,
                'button_url' => $buttonUrl,
                'enabled' => $enabled,
            ];
        }

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'article_detail_ads'],
            ['setting_value' => (string) json_encode($ads, JSON_UNESCAPED_UNICODE)]
        );

        SiteSettingsBag::forget();

        return redirect()->route('admin.site-settings.index')->with('message', __('admin.site_settings.ads.saved'));
    }

    /**
     * @return array{
     *   site_name:string,
     *   site_subtitle:string,
     *   site_description:string,
     *   site_keywords:string,
     *   copyright_info:string,
     *   site_logo:string,
     *   site_favicon:string,
     *   analytics_code:string,
     *   seo_title_template:string,
     *   seo_description_template:string,
     *   featured_limit:string,
     *   per_page:string,
     *   admin_base_path:string,
     *   active_theme:string,
     *   home_carousel_slides:string,
     *   article_detail_ads:string
     * }
     */
    private function loadSettings(): array
    {
        $defaults = [
            'site_name' => 'GEOFlow',
            'site_subtitle' => '',
            'site_description' => '基于AI的智能内容生成与发布平台',
            'site_keywords' => 'AI内容生成,GEO优化,智能发布,内容管理',
            'copyright_info' => '© 2026 GEOFlow. All rights reserved.',
            'site_logo' => '',
            'site_favicon' => '',
            'analytics_code' => '',
            'seo_title_template' => '{title} - {site_name}',
            'seo_description_template' => '{description}',
            'featured_limit' => '6',
            'per_page' => '12',
            'admin_base_path' => AdminWeb::basePath(),
            'active_theme' => (string) config('geoflow.default_theme', ''),
            'home_carousel_slides' => '[]',
            'article_detail_ads' => '[]',
        ];

        $stored = SiteSetting::query()
            ->select(['setting_key', 'setting_value'])
            ->whereIn('setting_key', array_keys($defaults))
            ->get()
            ->pluck('setting_value', 'setting_key')
            ->all();

        foreach ($defaults as $key => $defaultValue) {
            if (! array_key_exists($key, $stored)) {
                $stored[$key] = $defaultValue;
            }
        }

        return [
            'site_name' => (string) $stored['site_name'],
            'site_subtitle' => (string) $stored['site_subtitle'],
            'site_description' => (string) $stored['site_description'],
            'site_keywords' => (string) $stored['site_keywords'],
            'copyright_info' => (string) $stored['copyright_info'],
            'site_logo' => (string) $stored['site_logo'],
            'site_favicon' => (string) $stored['site_favicon'],
            'analytics_code' => (string) $stored['analytics_code'],
            'seo_title_template' => (string) $stored['seo_title_template'],
            'seo_description_template' => (string) $stored['seo_description_template'],
            'featured_limit' => (string) $stored['featured_limit'],
            'per_page' => (string) $stored['per_page'],
            'admin_base_path' => AdminWeb::basePath(),
            'active_theme' => (string) ($stored['active_theme'] !== '' ? $stored['active_theme'] : config('geoflow.default_theme', '')),
            'home_carousel_slides' => (string) $stored['home_carousel_slides'],
            'article_detail_ads' => (string) $stored['article_detail_ads'],
        ];
    }

    /**
     * @return array<int, array{id:string,name:string,version:string,description:string}>
     */
    private function discoverThemes(): array
    {
        $themesRoot = resource_path('views/theme');
        if (! is_dir($themesRoot)) {
            return [];
        }

        $themes = [];
        $entries = scandir($themesRoot);
        if (! is_array($entries)) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (! preg_match('/^[a-zA-Z0-9_-]+$/', $entry)) {
                continue;
            }

            $themeDir = $themesRoot.DIRECTORY_SEPARATOR.$entry;
            if (! is_dir($themeDir)) {
                continue;
            }

            $manifestPath = $themeDir.DIRECTORY_SEPARATOR.'manifest.json';
            if (is_file($manifestPath)) {
                $manifestRaw = file_get_contents($manifestPath);
                if (! is_string($manifestRaw) || $manifestRaw === '') {
                    continue;
                }

                $manifest = json_decode($manifestRaw, true);
                if (! is_array($manifest)) {
                    continue;
                }

                $themes[] = [
                    'id' => (string) $entry,
                    'name' => (string) ($manifest['name'] ?? $entry),
                    'version' => (string) ($manifest['version'] ?? ''),
                    'description' => (string) ($manifest['description'] ?? ''),
                ];

                continue;
            }

            if (! is_file($themeDir.DIRECTORY_SEPARATOR.'home.blade.php')) {
                continue;
            }

            $themes[] = [
                'id' => (string) $entry,
                'name' => ucfirst(str_replace(['-', '_'], ' ', $entry)),
                'version' => '',
                'description' => '',
            ];
        }

        return $themes;
    }

    /**
     * @return array<int, array{
     *   id:string,
     *   name:string,
     *   badge:string,
     *   title:string,
     *   copy:string,
     *   button_text:string,
     *   button_url:string,
     *   enabled:bool
     * }>
     */
    private function parseArticleDetailAds(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $ads = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $ads[] = [
                'id' => trim((string) ($item['id'] ?? '')),
                'name' => trim((string) ($item['name'] ?? '')),
                'badge' => trim((string) ($item['badge'] ?? '')),
                'title' => trim((string) ($item['title'] ?? '')),
                'copy' => trim((string) ($item['copy'] ?? '')),
                'button_text' => trim((string) ($item['button_text'] ?? '')),
                'button_url' => trim((string) ($item['button_url'] ?? '')),
                'enabled' => ! empty($item['enabled']),
            ];
        }

        return $ads;
    }

    /**
     * @return array<int, array{image_url:string,title:string,link_url:string,enabled:bool}>
     */
    private function parseHomeCarouselSlides(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $slides = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }

            $slides[] = [
                'image_url' => trim((string) ($item['image_url'] ?? '')),
                'title' => trim((string) ($item['title'] ?? '')),
                'link_url' => trim((string) ($item['link_url'] ?? '')),
                'enabled' => ! empty($item['enabled']),
            ];

            if (count($slides) >= 3) {
                break;
            }
        }

        return $slides;
    }

    /**
     * @param mixed $postedSlides
     * @return array<int, array{image_url:string,title:string,link_url:string,enabled:bool}>
     */
    private function normalizeHomeCarouselSlides(mixed $postedSlides): array
    {
        if (! is_array($postedSlides)) {
            return [];
        }

        $slides = [];
        foreach ($postedSlides as $postedSlide) {
            if (! is_array($postedSlide)) {
                continue;
            }

            $imageUrl = $this->normalizePublicImageUrl((string) ($postedSlide['image_url'] ?? ''));
            $title = trim((string) ($postedSlide['title'] ?? ''));
            $linkUrl = $this->normalizeCtaTargetUrl((string) ($postedSlide['link_url'] ?? ''));
            $enabled = ! empty($postedSlide['enabled']);

            if ($imageUrl === '' && $title === '' && $linkUrl === '') {
                continue;
            }

            if ($imageUrl === '') {
                continue;
            }

            $slides[] = [
                'image_url' => $imageUrl,
                'title' => $title,
                'link_url' => $linkUrl,
                'enabled' => $enabled,
            ];

            if (count($slides) >= 3) {
                break;
            }
        }

        return $slides;
    }

    /**
     * 首页海报图允许站内相对路径与 http(s) 图片地址；其它协议直接忽略，避免把无效资源写入前台。
     */
    private function normalizePublicImageUrl(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, '/') && ! str_starts_with($normalized, '//')) {
            return $normalized;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        return '';
    }

    /**
     * 归一化广告按钮链接，兼容相对路径与完整 URL。
     */
    private function normalizeCtaTargetUrl(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '') {
            return '';
        }

        if (str_starts_with($normalized, '/')) {
            return $normalized;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        return '/'.ltrim($normalized, '/');
    }
}
