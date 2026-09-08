<?php

namespace App\Support\Site;

use Illuminate\Support\Facades\View;
use Illuminate\View\View as ViewContract;

/**
 * 前台主题视图链：优先 {@see resources/views/theme/{themeId}/} 下模板，不存在则回退 {@see resources/views/site/}。
 */
final class SiteThemeViewResolver
{
    /**
     * 当前启用的主题目录名（与 site_settings.active_theme 一致，仅允许安全字符）。
     */
    public static function activeThemeId(): string
    {
        $preview = app(SiteThemePreviewContext::class)->themeId();
        if ($preview !== null) {
            return $preview;
        }
        $id = trim(SiteSettingsBag::get('active_theme', ''));
        if ($id === '') {
            $id = trim((string) config('geoflow.default_theme', ''));
        }

        return preg_match('/^[a-zA-Z0-9_-]+$/', $id) === 1 ? $id : '';
    }

    /**
     * 渲染第一个存在的视图：theme.{themeId}.{template} → site.{template}。
     *
     * @param  array<string, mixed>  $data
     */
    public static function first(string $template, array $data = []): ViewContract
    {
        self::registerInstalledTheme();

        return View::first(self::candidateViews($template), $data);
    }

    private static function registerInstalledTheme(): void
    {
        $id = self::activeThemeId();
        if ($id === '' || is_dir(resource_path('views/theme/'.$id))) {
            return;
        }
        $theme = app(InstalledSiteThemeRepository::class)->find($id);
        if ($theme !== null && ! in_array($theme['view_root'], View::getFinder()->getPaths(), true)) {
            View::addLocation($theme['view_root']);
        }
    }

    /**
     * @return list<string>
     */
    public static function candidateViews(string $template): array
    {
        $views = [];
        $themeId = self::activeThemeId();
        if ($themeId !== '') {
            $views[] = 'theme.'.$themeId.'.'.$template;
        }
        $views[] = 'site.'.$template;

        return $views;
    }
}
