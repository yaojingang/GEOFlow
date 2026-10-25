<?php

namespace App\Services\GeoFlow\Distribution\PlatformWeb;

use App\Support\Site\ArticleHtmlPresenter;

final class PlatformWebContentFormatter
{
    /** Use the immutable rendered article; legacy snapshots share the site's trusted Markdown renderer. */
    public static function fromSnapshot(array $snapshot): string
    {
        $html = is_string($snapshot['content_html'] ?? null)
            ? $snapshot['content_html']
            : ArticleHtmlPresenter::markdownToHtml(ArticleHtmlPresenter::stripLeadingTitleHeading(
                (string) ($snapshot['content'] ?? ''), (string) ($snapshot['title'] ?? ''),
            ));

        return self::plainText($html);
    }

    /** 编辑器只接受纯文本：块级开/闭标签均转换行（仅闭标签会把 <p>one<p>two 合并成一行），剥其余标签，实体解码，压缩三连空行。实体编码的标签（如 &lt;p&gt;）解码后成为字面文本注入，属无害纯文本。 */
    public static function plainText(string $html): string
    {
        $text = preg_replace('#<\s*/?\s*(br|p|div|h[1-6]|li|ul|ol|tr)\s*/?>#i', "\n", $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
