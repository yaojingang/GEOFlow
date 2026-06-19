<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SiteSetting;
use App\Services\Admin\SiteThemeReplicationService;
use App\Support\AdminBasePathManager;
use App\Support\AdminWeb;
use App\Support\Site\ArticleTextAdPicker;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
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
    public function __construct(
        private readonly SiteThemeCatalog $siteThemeCatalog,
        private readonly SiteThemeReplicationService $themeReplicationService
    ) {}

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
            'canEditAnalytics' => auth('admin')->user()?->isSuperAdmin() === true,
            'availableThemes' => $this->siteThemeCatalog->all(),
            'recentThemeReplications' => $this->themeReplicationService->recent(3),
            'themeReplicationDeployment' => $this->themeReplicationService->deploymentDiagnostics(),
            'homeCarouselSlides' => $this->parseHomeCarouselSlides((string) ($settings['home_carousel_slides'] ?? '[]')),
            'articleDetailAds' => $this->parseArticleDetailAds((string) ($settings['article_detail_ads'] ?? '[]')),
            'articleDetailTextAds' => $this->parseArticleDetailTextAds((string) ($settings['article_detail_text_ads'] ?? '[]')),
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
        $currentSettings = $this->loadSettings();
        $canEditAnalytics = auth('admin')->user()?->isSuperAdmin() === true;

        $settings = [
            'site_name' => trim((string) $payload['site_name']),
            'site_title' => trim((string) $payload['site_name']),
            'site_subtitle' => trim((string) ($payload['site_subtitle'] ?? '')),
            'site_description' => trim((string) ($payload['site_description'] ?? '')),
            'site_keywords' => trim((string) ($payload['site_keywords'] ?? '')),
            'copyright_info' => trim((string) ($payload['copyright_info'] ?? '')),
            'site_logo' => trim((string) ($payload['site_logo'] ?? '')),
            'site_favicon' => trim((string) ($payload['site_favicon'] ?? '')),
            'analytics_code' => $canEditAnalytics
                ? trim((string) ($payload['analytics_code'] ?? ''))
                : (string) ($currentSettings['analytics_code'] ?? ''),
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
            $this->siteThemeCatalog->all()
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
     * 保存文章正文顶部/底部文本广告设置。
     */
    public function updateArticleDetailTextAds(Request $request): RedirectResponse
    {
        $postedModules = $request->input('text_ad_modules');
        if (! is_array($postedModules)) {
            $postedModules = $this->legacyPostedTextAdsToModules($request->input('text_ads', []));
        }

        $modules = $this->normalizePostedArticleTextAdModules($postedModules);

        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'article_detail_text_ads'],
            ['setting_value' => (string) json_encode($modules, JSON_UNESCAPED_UNICODE)]
        );

        SiteSettingsBag::forget();

        return redirect()->route('admin.site-settings.index')->with('message', __('admin.site_settings.ads.text_saved'));
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
     *   article_detail_ads:string,
     *   article_detail_text_ads:string
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
            'article_detail_text_ads' => '[]',
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
            'article_detail_text_ads' => (string) $stored['article_detail_text_ads'],
        ];
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
     * @return array<int, array<string,mixed>>
     */
    private function parseArticleDetailTextAds(string $raw): array
    {
        $decoded = json_decode($raw, true);

        return ArticleTextAdPicker::normalizeModules($decoded, false, ArticleTextAdPicker::MAX_GLOBAL_MODULES);
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function normalizePostedArticleTextAdModules(mixed $postedModules): array
    {
        if (! is_array($postedModules)) {
            return [];
        }

        $modules = [];
        foreach (array_values($postedModules) as $moduleIndex => $postedModule) {
            if (! is_array($postedModule)) {
                continue;
            }

            if (count($modules) >= ArticleTextAdPicker::MAX_GLOBAL_MODULES) {
                throw ValidationException::withMessages([
                    'text_ad_modules' => __('admin.site_settings.ads.text_validation_max_modules', ['max' => ArticleTextAdPicker::MAX_GLOBAL_MODULES]),
                ]);
            }

            $moduleNumber = $moduleIndex + 1;
            $id = trim((string) ($postedModule['id'] ?? ''));
            $name = trim((string) ($postedModule['name'] ?? ''));
            $placement = trim((string) ($postedModule['placement'] ?? ArticleTextAdPicker::PLACEMENT_TOP));
            $rawLinks = is_array($postedModule['links'] ?? null) ? $postedModule['links'] : [];
            $links = $this->normalizePostedArticleTextAdLinks($rawLinks, $moduleNumber);
            $hasModuleData = $name !== '' || $id !== '' || $this->hasPostedArticleTextAdLinkData($rawLinks);

            if (! $hasModuleData && $links === []) {
                continue;
            }

            if (! in_array($placement, ArticleTextAdPicker::PLACEMENTS, true)) {
                throw ValidationException::withMessages([
                    'text_ad_modules' => __('admin.site_settings.ads.text_validation_position', ['index' => $moduleNumber]),
                ]);
            }

            if ($links === []) {
                throw ValidationException::withMessages([
                    'text_ad_modules' => __('admin.site_settings.ads.text_validation_module_required', ['index' => $moduleNumber]),
                ]);
            }

            $sortOrder = filter_var($postedModule['sort_order'] ?? null, FILTER_VALIDATE_INT);
            if ($sortOrder === false) {
                $sortOrder = (count($modules) + 1) * 10;
            }

            $modules[] = [
                'schema_version' => 2,
                'id' => $id !== '' ? $id : uniqid('article_text_module_', true),
                'name' => $name !== '' ? $name : __('admin.site_settings.ads.text_default_name', ['index' => count($modules) + 1]),
                'placement' => $placement,
                'enabled' => ! empty($postedModule['enabled']),
                'sort_order' => max(0, min(10000, (int) $sortOrder)),
                'links' => $links,
            ];
        }

        usort($modules, static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0)));

        return $modules;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normalizePostedArticleTextAdLinks(array $rawLinks, int $moduleNumber): array
    {
        $links = [];
        foreach (array_values($rawLinks) as $linkIndex => $postedLink) {
            if (! is_array($postedLink)) {
                continue;
            }

            if (count($links) >= ArticleTextAdPicker::MAX_LINKS_PER_MODULE) {
                throw ValidationException::withMessages([
                    'text_ad_modules' => __('admin.site_settings.ads.text_validation_max_links', [
                        'index' => $moduleNumber,
                        'max' => ArticleTextAdPicker::MAX_LINKS_PER_MODULE,
                    ]),
                ]);
            }

            $linkNumber = $moduleNumber.'.'.($linkIndex + 1);
            $text = trim((string) ($postedLink['text'] ?? ''));
            $rawUrl = trim((string) ($postedLink['url'] ?? ''));
            $trackingParam = trim((string) ($postedLink['tracking_param'] ?? ''));
            $color = trim((string) ($postedLink['text_color'] ?? ''));

            if ($text === '' && $rawUrl === '' && $trackingParam === '') {
                continue;
            }

            $url = $this->normalizeArticleTextAdUrl($rawUrl);
            if ($rawUrl !== '' && $url === '') {
                throw ValidationException::withMessages([
                    'text_ad_modules' => __('admin.site_settings.ads.text_validation_url', ['index' => $linkNumber]),
                ]);
            }

            if ($text === '' || $url === '') {
                throw ValidationException::withMessages([
                    'text_ad_modules' => __('admin.site_settings.ads.text_validation_required', ['index' => $linkNumber]),
                ]);
            }

            $normalizedColor = $color !== '' ? $this->normalizeHexColor($color) : '#2563eb';
            if ($color !== '' && $normalizedColor === '') {
                throw ValidationException::withMessages([
                    'text_ad_modules' => __('admin.site_settings.ads.text_validation_color', ['index' => $linkNumber]),
                ]);
            }

            $trackingEnabled = ! empty($postedLink['tracking_enabled']);
            if ($trackingEnabled && $trackingParam === '') {
                $trackingParam = 'utm_source=geoflow&utm_medium=article_text_ad';
            }
            $trackingParam = ltrim($trackingParam, "? \t\n\r\0\x0B");

            if ($trackingParam !== '' && ! $this->isValidTrackingParam($trackingParam)) {
                throw ValidationException::withMessages([
                    'text_ad_modules' => __('admin.site_settings.ads.text_validation_tracking', ['index' => $linkNumber]),
                ]);
            }

            $sortOrder = filter_var($postedLink['sort_order'] ?? null, FILTER_VALIDATE_INT);
            if ($sortOrder === false) {
                $sortOrder = (count($links) + 1) * 10;
            }

            $links[] = [
                'id' => trim((string) ($postedLink['id'] ?? '')) ?: uniqid('article_text_link_', true),
                'text' => $text,
                'url' => $url,
                'text_color' => $normalizedColor !== '' ? $normalizedColor : '#2563eb',
                'open_new_tab' => ! empty($postedLink['open_new_tab']),
                'tracking_enabled' => $trackingEnabled,
                'tracking_param' => $trackingParam,
                'enabled' => ! empty($postedLink['enabled']),
                'sort_order' => max(0, min(10000, (int) $sortOrder)),
            ];
        }

        usort($links, static fn (array $a, array $b): int => ((int) ($a['sort_order'] ?? 0)) <=> ((int) ($b['sort_order'] ?? 0)));

        return $links;
    }

    private function hasPostedArticleTextAdLinkData(array $rawLinks): bool
    {
        foreach ($rawLinks as $postedLink) {
            if (! is_array($postedLink)) {
                continue;
            }

            if (
                trim((string) ($postedLink['text'] ?? '')) !== ''
                || trim((string) ($postedLink['url'] ?? '')) !== ''
                || trim((string) ($postedLink['tracking_param'] ?? '')) !== ''
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function legacyPostedTextAdsToModules(mixed $postedAds): array
    {
        if (! is_array($postedAds)) {
            return [];
        }

        $modules = [];
        foreach ($postedAds as $postedAd) {
            if (! is_array($postedAd)) {
                continue;
            }

            $modules[] = [
                'id' => $postedAd['id'] ?? '',
                'name' => $postedAd['name'] ?? '',
                'placement' => $postedAd['placement'] ?? ArticleTextAdPicker::PLACEMENT_TOP,
                'enabled' => $postedAd['enabled'] ?? false,
                'sort_order' => $postedAd['sort_order'] ?? 0,
                'links' => [[
                    'id' => $postedAd['id'] ?? '',
                    'text' => $postedAd['text'] ?? '',
                    'url' => $postedAd['url'] ?? '',
                    'text_color' => $postedAd['text_color'] ?? '#2563eb',
                    'open_new_tab' => $postedAd['open_new_tab'] ?? false,
                    'tracking_enabled' => $postedAd['tracking_enabled'] ?? false,
                    'tracking_param' => $postedAd['tracking_param'] ?? '',
                    'enabled' => $postedAd['enabled'] ?? false,
                    'sort_order' => $postedAd['sort_order'] ?? 0,
                ]],
            ];
        }

        return $modules;
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

    /**
     * 正文文本广告链接只允许站内相对路径或 http(s) URL，不接受协议相对 URL 与脚本协议。
     */
    private function normalizeArticleTextAdUrl(string $url): string
    {
        $normalized = trim($url);
        if ($normalized === '' || str_starts_with($normalized, '//')) {
            return '';
        }

        if (str_starts_with($normalized, '/')) {
            return $normalized;
        }

        if (preg_match('#^https?://#i', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $normalized) === 1) {
            return '';
        }

        return '/'.ltrim($normalized, '/');
    }

    private function isValidHexColor(string $color): bool
    {
        return preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', trim($color)) === 1;
    }

    private function normalizeHexColor(string $color): string
    {
        $color = trim($color);
        if (! $this->isValidHexColor($color)) {
            return '';
        }

        $hex = ltrim(strtolower($color), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }

    private function isValidTrackingParam(string $trackingParam): bool
    {
        $trackingParam = trim($trackingParam);

        return $trackingParam !== ''
            && mb_strlen($trackingParam) <= 250
            && ! str_contains($trackingParam, '://')
            && ! str_starts_with($trackingParam, '/')
            && preg_match('/^[A-Za-z0-9._~%=&+;,:@-]+$/', $trackingParam) === 1;
    }
}
