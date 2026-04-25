<?php

namespace App\Support\Site;

use App\Models\Article;
use League\CommonMark\CommonMarkConverter;

/**
 * 文章正文 Markdown 渲染与摘要生成（对齐旧版前台展示习惯）。
 */
final class ArticleHtmlPresenter
{
    /**
     * 将 Markdown 转为 HTML（剥离不安全 HTML 输入）。
     */
    public static function markdownToHtml(string $markdown): string
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            return '';
        }

        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return $converter->convert($markdown)->getContent();
    }

    /**
     * 从正文中去掉与标题一致的首行 H1，避免详情页重复大标题。
     */
    public static function stripLeadingTitleHeading(string $content, string $title): string
    {
        $content = (string) $content;
        $title = trim($title);
        if ($title === '') {
            return $content;
        }

        $pattern = '/^\s*#\s*'.preg_quote($title, '/').'\s*(?:\r?\n)+/u';

        return (string) preg_replace($pattern, '', $content, 1);
    }

    /**
     * 列表卡片摘要：优先 excerpt，否则从正文抽纯文本片段。
     */
    public static function cardSummary(Article $article, int $limit = 120): string
    {
        $excerpt = trim((string) $article->excerpt);
        if ($excerpt !== '') {
            $excerpt = self::stripLeadingTitleHeading($excerpt, (string) $article->title);
            $excerpt = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', '', $excerpt) ?? $excerpt;
            $plain = self::toPlainLine($excerpt);

            return mb_strlen($plain) > $limit ? mb_substr($plain, 0, $limit).'…' : $plain;
        }

        $body = self::stripLeadingTitleHeading((string) $article->content, (string) $article->title);
        $body = preg_replace('/!\[[^\]]*\]\([^)]+\)/u', '', $body) ?? $body;
        $plain = self::toPlainLine($body);

        return mb_strlen($plain) > $limit ? mb_substr($plain, 0, $limit).'…' : $plain;
    }

    private static function toPlainLine(string $text): string
    {
        $text = preg_replace('/[#*_`>\[\]()]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
