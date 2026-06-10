<?php

namespace App\Support\Admin;

use App\Support\Site\ArticleHtmlPresenter;
use DOMDocument;
use DOMElement;
use DOMXPath;

final class WeChatArticleHtmlExporter
{
    /**
     * Render Markdown into HTML that can be pasted into rich-text editors such as WeChat.
     */
    public function toHtml(string $markdown): string
    {
        $body = ArticleHtmlPresenter::markdownToHtml($markdown);
        if (trim($body) === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><section data-geoflow-export="wechat-article">'.$body.'</section>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach ($dom->childNodes as $node) {
            if ($node->nodeType === XML_PI_NODE) {
                $dom->removeChild($node);
                break;
            }
        }

        $root = $dom->getElementsByTagName('section')->item(0);
        if (! $root instanceof DOMElement) {
            return '<section data-geoflow-export="wechat-article" style="'.$this->baseStyle().'">'.$body.'</section>';
        }

        $this->styleElements($dom);
        $root->setAttribute('style', $this->baseStyle());

        return trim($dom->saveHTML($root) ?: '');
    }

    public function toPlainText(string $html): string
    {
        $html = preg_replace('/<\/(h[1-6]|p|blockquote|li|tr|table|pre)>/iu', "</$1>\n", $html) ?? $html;
        $html = preg_replace('/<br\s*\/?>/iu', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function styleElements(DOMDocument $dom): void
    {
        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query('//*');
        if ($nodes === false) {
            return;
        }

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            $this->normalizeAttributes($node, $tag);

            $style = match ($tag) {
                'h1' => 'font-size:24px;line-height:1.35;font-weight:700;color:#111827;margin:28px 0 16px;padding-bottom:10px;border-bottom:1px solid #e5e7eb;',
                'h2' => 'font-size:21px;line-height:1.45;font-weight:700;color:#111827;margin:26px 0 14px;padding-left:12px;border-left:4px solid #2563eb;',
                'h3' => 'font-size:18px;line-height:1.55;font-weight:700;color:#111827;margin:22px 0 12px;',
                'h4' => 'font-size:16px;line-height:1.6;font-weight:700;color:#111827;margin:18px 0 10px;',
                'p' => 'font-size:16px;line-height:1.9;color:#374151;margin:0 0 16px;',
                'strong' => 'font-weight:700;color:#111827;',
                'em' => 'font-style:italic;color:#374151;',
                'a' => 'color:#2563eb;text-decoration:underline;text-underline-offset:3px;',
                'blockquote' => 'margin:18px 0;padding:12px 16px;border-left:4px solid #93c5fd;background:#f8fafc;color:#475569;',
                'ul', 'ol' => 'margin:0 0 16px 22px;padding:0;color:#374151;',
                'li' => 'font-size:16px;line-height:1.85;margin:6px 0;',
                'img' => 'display:block;max-width:100%;height:auto;margin:22px auto;border-radius:6px;',
                'pre' => 'overflow:auto;margin:18px 0;padding:14px 16px;border-radius:6px;background:#111827;color:#f9fafb;font-size:13px;line-height:1.7;',
                'code' => $node->parentNode instanceof DOMElement && strtolower($node->parentNode->tagName) === 'pre'
                    ? 'font-family:Menlo,Consolas,monospace;color:inherit;background:transparent;'
                    : 'font-family:Menlo,Consolas,monospace;background:#f3f4f6;color:#111827;border-radius:4px;padding:2px 5px;font-size:14px;',
                'table' => 'width:100%;border-collapse:collapse;margin:16px 0;background:#ffffff;',
                'th' => 'border:1px solid #e5e7eb;background:#f9fafb;color:#111827;padding:9px 10px;font-weight:700;text-align:left;font-size:14px;line-height:1.6;',
                'td' => 'border:1px solid #e5e7eb;color:#374151;padding:9px 10px;text-align:left;font-size:14px;line-height:1.6;',
                'hr' => 'border:none;border-top:1px solid #e5e7eb;margin:24px 0;',
                'div' => $this->isTableWrapper($node) ? 'overflow-x:auto;margin:16px 0;' : '',
                default => '',
            };

            if ($style !== '') {
                $node->setAttribute('style', $style);
            }
        }
    }

    private function normalizeAttributes(DOMElement $node, string $tag): void
    {
        $allowed = match ($tag) {
            'a' => ['href', 'title', 'target', 'rel'],
            'img' => ['src', 'alt', 'title', 'width', 'height'],
            'td', 'th' => ['colspan', 'rowspan'],
            'section' => ['data-geoflow-export'],
            default => [],
        };

        for ($i = $node->attributes->length - 1; $i >= 0; $i--) {
            $attribute = $node->attributes->item($i);
            if ($attribute === null) {
                continue;
            }

            $name = strtolower($attribute->name);
            if ($name === 'style' || in_array($name, $allowed, true)) {
                continue;
            }

            $node->removeAttribute($attribute->name);
        }

        if ($tag === 'a') {
            $node->setAttribute('target', '_blank');
            $node->setAttribute('rel', 'noopener noreferrer');
        }
    }

    private function isTableWrapper(DOMElement $node): bool
    {
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'table') {
                return true;
            }
        }

        return false;
    }

    private function baseStyle(): string
    {
        return 'display:block;max-width:677px;margin:0 auto;padding:0;color:#374151;font-size:16px;line-height:1.9;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Arial,"PingFang SC","Hiragino Sans GB","Microsoft YaHei",sans-serif;';
    }
}
