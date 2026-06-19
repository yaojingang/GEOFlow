<?php

namespace App\Support\Site;

/**
 * 读取并渲染文章正文顶部/底部文本广告。
 */
final class ArticleTextAdPicker
{
    /**
     * @return array<int, array{
     *   id:string,
     *   name:string,
     *   placement:string,
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
    public static function all(bool $enabledOnly = false): array
    {
        $raw = SiteSettingsBag::get('article_detail_text_ads', '[]');

        return self::normalizeMany(json_decode($raw, true), $enabledOnly);
    }

    /**
     * @return array<int, array{
     *   id:string,
     *   name:string,
     *   placement:string,
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
    public static function normalizeMany(mixed $value, bool $enabledOnly = false): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ads = [];
        foreach ($value as $item) {
            if (! is_array($item)) {
                continue;
            }

            $placement = (string) ($item['placement'] ?? 'content_top');
            if (! in_array($placement, ['content_top', 'content_bottom'], true)) {
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

            $ads[] = [
                'id' => trim((string) ($item['id'] ?? '')),
                'name' => trim((string) ($item['name'] ?? '')),
                'placement' => $placement,
                'text' => $text,
                'url' => $url,
                'text_color' => self::normalizeColor((string) ($item['text_color'] ?? '#2563eb')),
                'open_new_tab' => ! empty($item['open_new_tab']),
                'tracking_enabled' => ! empty($item['tracking_enabled']),
                'tracking_param' => self::normalizeTrackingParam((string) ($item['tracking_param'] ?? '')),
                'enabled' => $enabled,
                'sort_order' => (int) ($item['sort_order'] ?? 0),
            ];
        }

        usort($ads, static fn (array $a, array $b): int => ((int) $a['sort_order']) <=> ((int) $b['sort_order']));

        return $ads;
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

    public static function renderPlacement(string $placement, int $limit = 3, ?array $ads = null): string
    {
        if (! in_array($placement, ['content_top', 'content_bottom'], true)) {
            return '';
        }

        $sourceAds = $ads === null ? self::all(true) : self::normalizeMany($ads, true);
        $matchedAds = array_values(array_filter(
            $sourceAds,
            static fn (array $ad): bool => ($ad['placement'] ?? '') === $placement
        ));

        if ($matchedAds === []) {
            return '';
        }

        $placementClass = str_replace('_', '-', $placement);
        $html = '<div class="article-text-ads article-text-ads--'.e($placementClass).'" data-placement="'.e($placement).'">';
        foreach (array_slice($matchedAds, 0, max(1, $limit)) as $ad) {
            $url = self::withTrackingParam((string) $ad['url'], (bool) $ad['tracking_enabled'], (string) $ad['tracking_param']);
            $target = ! empty($ad['open_new_tab']) ? ' target="_blank"' : '';
            $style = '--article-text-ad-color: '.e((string) $ad['text_color']).';';

            $html .= '<a class="article-text-ad-link" href="'.e($url).'" rel="noopener sponsored nofollow"'.$target.' style="'.$style.'">';
            $html .= '<span class="article-text-ad-text">'.e((string) $ad['text']).'</span>';
            $html .= '</a>';
        }
        $html .= '</div>';

        return $html;
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
