<?php

namespace App\Services\GeoFlow\Distribution\PlatformWeb;

final class PlatformWebContentFormatter
{
    /** 编辑器只接受纯文本：块级标签转换行，剥其余标签，实体解码，压缩三连空行。 */
    public static function plainText(string $html): string
    {
        $text = preg_replace('#<\s*(br|/p|/div|/h[1-6]|/li)\s*/?>#i', "\n", $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
