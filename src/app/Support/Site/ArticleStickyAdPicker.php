<?php

namespace App\Support\Site;

/**
 * 从站点设置 JSON 中选取首个启用的文章详情页悬浮广告（与后台站点设置结构一致）。
 */
final class ArticleStickyAdPicker
{
    /**
     * @return array{id:string,badge:string,title:string,copy:string,button_text:string,button_url:string}|null
     */
    public static function firstEnabled(): ?array
    {
        $raw = SiteSettingsBag::get('article_detail_ads', '[]');
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $item) {
            if (! is_array($item) || empty($item['enabled'])) {
                continue;
            }

            $copy = trim((string) ($item['copy'] ?? ''));
            $buttonText = trim((string) ($item['button_text'] ?? ''));
            $buttonUrl = trim((string) ($item['button_url'] ?? ''));
            if ($copy === '' || $buttonText === '' || $buttonUrl === '') {
                continue;
            }

            return [
                'id' => trim((string) ($item['id'] ?? 'default')),
                'badge' => trim((string) ($item['badge'] ?? '')),
                'title' => trim((string) ($item['title'] ?? '')),
                'copy' => $copy,
                'button_text' => $buttonText,
                'button_url' => self::normalizeCtaTargetUrl($buttonUrl),
            ];
        }

        return null;
    }

    private static function normalizeCtaTargetUrl(string $url): string
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
