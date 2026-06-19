<?php

namespace App\Support\Site;

/**
 * 读取并渲染文章正文顶部/底部文本广告。
 */
final class ArticleTextAdPicker
{
    public const PLACEMENT_TOP = 'content_top';

    public const PLACEMENT_BOTTOM = 'content_bottom';

    public const MAX_GLOBAL_MODULES = 30;

    public const MAX_LINKS_PER_MODULE = 10;

    public const DEFAULT_MODULE_LIMIT_PER_PLACEMENT = 2;

    /**
     * @var list<string>
     */
    public const PLACEMENTS = [
        self::PLACEMENT_TOP,
        self::PLACEMENT_BOTTOM,
    ];

    /**
     * @return array<int, array{
     *   schema_version:int,
     *   id:string,
     *   name:string,
     *   placement:string,
     *   enabled:bool,
     *   sort_order:int,
     *   links:list<array<string,mixed>>
     * }>
     */
    public static function all(bool $enabledOnly = false): array
    {
        $raw = SiteSettingsBag::get('article_detail_text_ads', '[]');

        return self::normalizeModules(json_decode($raw, true), $enabledOnly, self::MAX_GLOBAL_MODULES);
    }

    /**
     * @return array<int, array{
     *   schema_version:int,
     *   id:string,
     *   name:string,
     *   placement:string,
     *   enabled:bool,
     *   sort_order:int,
     *   links:list<array<string,mixed>>
     * }>
     */
    public static function normalizeMany(mixed $value, bool $enabledOnly = false): array
    {
        return self::normalizeModules($value, $enabledOnly);
    }

    /**
     * @return array<int, array{
     *   schema_version:int,
     *   id:string,
     *   name:string,
     *   placement:string,
     *   enabled:bool,
     *   sort_order:int,
     *   links:list<array<string,mixed>>
     * }>
     */
    public static function normalizeModules(mixed $value, bool $enabledOnly = false, ?int $maxModules = null): array
    {
        if (! is_array($value)) {
            return [];
        }

        $modules = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $placement = (string) ($item['placement'] ?? self::PLACEMENT_TOP);
            if (! in_array($placement, self::PLACEMENTS, true)) {
                continue;
            }

            $enabled = ! empty($item['enabled']);
            if ($enabledOnly && ! $enabled) {
                continue;
            }

            $links = self::normalizeLinks(
                is_array($item['links'] ?? null) ? $item['links'] : [self::legacyItemToLink($item)],
                $enabledOnly,
                self::MAX_LINKS_PER_MODULE
            );
            if ($links === []) {
                continue;
            }

            $id = trim((string) ($item['id'] ?? ''));
            $name = trim((string) ($item['name'] ?? ''));
            $sortOrder = (int) ($item['sort_order'] ?? count($modules) * 10);

            $modules[] = [
                'schema_version' => 2,
                'id' => $id !== '' ? $id : 'article_text_module_'.md5($placement.'|'.$name.'|'.$sortOrder.'|'.json_encode($links)),
                'name' => $name !== '' ? $name : (string) ($links[0]['text'] ?? 'Text Ad Module'),
                'placement' => $placement,
                'enabled' => $enabled,
                'sort_order' => $sortOrder,
                'links' => $links,
            ];
        }

        usort($modules, static function (array $a, array $b): int {
            $order = ((int) $a['sort_order']) <=> ((int) $b['sort_order']);

            return $order !== 0 ? $order : strcmp((string) $a['name'], (string) $b['name']);
        });

        if ($maxModules !== null) {
            $modules = array_slice($modules, 0, max(0, $maxModules));
        }

        return array_values($modules);
    }

    /**
     * @return list<array{
     *   id:string,
     *   text:string,
     *   url:string,
     *   text_color:string,
     *   open_new_tab:bool,
     *   tracking_enabled:bool,
     *   tracking_param:string,
     *   enabled:bool,
     *   sort_order:int
     * }>
     */
    public static function normalizeLinks(mixed $value, bool $enabledOnly = false, ?int $maxLinks = null): array
    {
        if (! is_array($value)) {
            return [];
        }

        $links = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = trim((string) ($item['text'] ?? ''));
            $url = self::normalizeUrl((string) ($item['url'] ?? ''));
            if ($text === '' || $url === '') {
                continue;
            }

            $enabled = ! empty($item['enabled']);
            if ($enabledOnly && ! $enabled) {
                continue;
            }

            $links[] = [
                'id' => trim((string) ($item['id'] ?? '')),
                'text' => $text,
                'url' => $url,
                'text_color' => self::normalizeColor((string) ($item['text_color'] ?? '#2563eb')),
                'open_new_tab' => ! empty($item['open_new_tab']),
                'tracking_enabled' => ! empty($item['tracking_enabled']),
                'tracking_param' => self::normalizeTrackingParam((string) ($item['tracking_param'] ?? '')),
                'enabled' => $enabled,
                'sort_order' => (int) ($item['sort_order'] ?? count($links) * 10),
            ];
        }

        usort($links, static fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        if ($maxLinks !== null) {
            $links = array_slice($links, 0, max(0, $maxLinks));
        }

        return array_values($links);
    }

    public static function injectIntoContentHtml(string $contentHtml): string
    {
        $top = self::renderPlacement('content_top');
        $bottom = self::renderPlacement('content_bottom');

        if ($top === '' && $bottom === '') {
            return $contentHtml;
        }

        return $top.$contentHtml.$bottom;
    }

    public static function renderPlacement(string $placement, int $limit = self::DEFAULT_MODULE_LIMIT_PER_PLACEMENT, ?array $ads = null): string
    {
        if (! in_array($placement, self::PLACEMENTS, true)) {
            return '';
        }

        $sourceModules = $ads === null ? self::all(true) : self::normalizeModules($ads, true);
        $matchedModules = array_values(array_filter(
            $sourceModules,
            static fn (array $module): bool => ($module['placement'] ?? '') === $placement && ($module['links'] ?? []) !== []
        ));

        if ($matchedModules === []) {
            return '';
        }

        $placementClass = str_replace('_', '-', $placement);
        $html = '<div class="article-text-ads article-text-ads--'.e($placementClass).'" data-placement="'.e($placement).'">';
        foreach (array_slice($matchedModules, 0, max(1, $limit)) as $module) {
            $html .= '<div class="article-text-ad-module" data-module-id="'.e((string) $module['id']).'">';
            foreach ((array) ($module['links'] ?? []) as $link) {
                if (! is_array($link)) {
                    continue;
                }

                $url = self::withTrackingParam((string) $link['url'], (bool) $link['tracking_enabled'], (string) $link['tracking_param']);
                $target = ! empty($link['open_new_tab']) ? ' target="_blank"' : '';
                $style = '--article-text-ad-color: '.e((string) $link['text_color']).';';

                $html .= '<a class="article-text-ad-link" href="'.e($url).'" rel="noopener sponsored nofollow"'.$target.' style="'.$style.'">';
                $html .= '<span class="article-text-ad-text">'.e((string) $link['text']).'</span>';
                $html .= '</a>';
            }
            $html .= '</div>';
        }
        $html .= '</div>';

        return $html;
    }

    /**
     * @param  list<string>  $ids
     */
    public static function moduleOrLinkMatchesIds(array $module, array $ids): bool
    {
        $moduleId = (string) ($module['id'] ?? '');
        if ($moduleId !== '' && in_array($moduleId, $ids, true)) {
            return true;
        }

        foreach ((array) ($module['links'] ?? []) as $link) {
            if (is_array($link) && in_array((string) ($link['id'] ?? ''), $ids, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    private static function legacyItemToLink(array $item): array
    {
        return [
            'id' => $item['id'] ?? '',
            'text' => $item['text'] ?? '',
            'url' => $item['url'] ?? '',
            'text_color' => $item['text_color'] ?? '#2563eb',
            'open_new_tab' => $item['open_new_tab'] ?? false,
            'tracking_enabled' => $item['tracking_enabled'] ?? false,
            'tracking_param' => $item['tracking_param'] ?? '',
            'enabled' => $item['enabled'] ?? false,
            'sort_order' => $item['sort_order'] ?? 0,
        ];
    }

    private static function normalizeUrl(string $url): string
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

    private static function normalizeColor(string $color): string
    {
        $color = trim($color);
        if (preg_match('/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $color) !== 1) {
            return '#2563eb';
        }

        $hex = ltrim(strtolower($color), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        return '#'.$hex;
    }

    private static function normalizeTrackingParam(string $trackingParam): string
    {
        $trackingParam = ltrim(trim($trackingParam), "? \t\n\r\0\x0B");
        if (
            $trackingParam === ''
            || mb_strlen($trackingParam) > 250
            || str_contains($trackingParam, '://')
            || str_starts_with($trackingParam, '/')
            || preg_match('/^[A-Za-z0-9._~%=&+;,:@-]+$/', $trackingParam) !== 1
        ) {
            return '';
        }

        return $trackingParam;
    }

    private static function withTrackingParam(string $url, bool $trackingEnabled, string $trackingParam): string
    {
        if (! $trackingEnabled || $trackingParam === '') {
            return $url;
        }

        $fragment = '';
        $baseUrl = $url;
        $hashPosition = strpos($url, '#');
        if ($hashPosition !== false) {
            $fragment = substr($url, $hashPosition);
            $baseUrl = substr($url, 0, $hashPosition);
        }

        $separator = str_contains($baseUrl, '?')
            ? (str_ends_with($baseUrl, '?') || str_ends_with($baseUrl, '&') ? '' : '&')
            : '?';

        return $baseUrl.$separator.$trackingParam.$fragment;
    }
}
