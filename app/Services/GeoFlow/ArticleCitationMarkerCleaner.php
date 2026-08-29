<?php

namespace App\Services\GeoFlow;

final class ArticleCitationMarkerCleaner
{
    private const EVIDENCE_ID = '[KＫ](?:\s|\\x{200B}|\\x{2060}|\\x{FEFF})*(?:[0-9０-９]|&#(?:48|49|50|51|52|53|54|55|56|57|x3[0-9]);){1,6}';

    private const NUMERIC_CITATION_ID = '[1-9１-９][0-9０-９]{0,2}';

    private const CITATION_ID = '(?:'.self::EVIDENCE_ID.'|'.self::NUMERIC_CITATION_ID.')';

    private const EVIDENCE_ID_SEPARATOR = '(?:\\s*(?:[,，、;；/]|[-–—\\~～])\\s*|\\s+)';

    private const EVIDENCE_ID_GROUP = self::EVIDENCE_ID.'(?:'.self::EVIDENCE_ID_SEPARATOR.self::EVIDENCE_ID.')*+';

    private const CITATION_ID_GROUP = self::CITATION_ID.'(?:'.self::EVIDENCE_ID_SEPARATOR.self::CITATION_ID.')*+';

    private const SIMPLE_SQUARE_MARKER_BODY = '\\[\\s*\\^?\\s*'.self::CITATION_ID_GROUP.'\\s*\\]';

    private const BRACKETED_MARKER_BODY = '(?:'
        .'\\[\\s*\\^?\\s*'.self::CITATION_ID_GROUP.'\\s*\\]'
        .'|\\\\\\[\\s*\\^?\\s*'.self::CITATION_ID_GROUP.'\\s*\\\\\\]'
        .'|(?:&#(?:0*91|x0*5b);|&lbrack;|&lsqb;)\\s*\\^?\\s*'.self::CITATION_ID_GROUP.'\\s*(?:&#(?:0*93|x0*5d);|&rbrack;|&rsqb;)'
        .'|(?:&#(?:0*91|x0*5b);|&lbrack;|&lsqb;)\\s*\\^?\\s*'.self::CITATION_ID_GROUP.'\\s*\\]'
        .'|\\[\\s*\\^?\\s*'.self::CITATION_ID_GROUP.'\\s*(?:&#(?:0*93|x0*5d);|&rbrack;|&rsqb;)'
        .'|【\\s*(?:证据\\s*)?'.self::EVIDENCE_ID_GROUP.'\\s*】'
        .'|［\\s*'.self::EVIDENCE_ID_GROUP.'\\s*］'
        .'|\\(\\s*'.self::EVIDENCE_ID_GROUP.'\\s*\\)'
        .'|（\\s*'.self::EVIDENCE_ID_GROUP.'\\s*）'
        .'|〔\\s*'.self::EVIDENCE_ID_GROUP.'\\s*〕'
        .'|〖\\s*'.self::EVIDENCE_ID_GROUP.'\\s*〗'
        .')';

    private const BARE_MARKER_BODY = '(?<![\\p{L}\\p{N}_/\\-])(?:`\\s*)?'.self::EVIDENCE_ID.'(?:\\s*`)?(?![\\p{L}\\p{N}_/\\-])';

    public function contains(string $content): bool
    {
        return $this->cleanContent($content) !== $content;
    }

    public function cleanContent(string $content): string
    {
        return $this->removeContextualBareMarkers($this->removeBracketedMarkers($content));
    }

    public function cleanGeneratedContent(string $content, string $sourceContext = ''): string
    {
        return $this->cleanContent($content);
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    public function cleanArticleFields(array $fields): array
    {
        $sourceContent = (string) ($fields['content'] ?? '');
        if (array_key_exists('content', $fields)) {
            $fields['content'] = $this->cleanContent($sourceContent);
        }
        foreach (['excerpt', 'meta_description'] as $field) {
            if (array_key_exists($field, $fields)) {
                $fields[$field] = $this->cleanGeneratedSummary((string) $fields[$field], $sourceContent);
            }
        }

        return $fields;
    }

    public function cleanGeneratedSummary(string $summary, string $sourceContent = ''): string
    {
        $cleaned = $this->cleanGeneratedContent($summary, $sourceContent);
        if ($cleaned === $summary) {
            return $summary;
        }
        $cleaned = preg_replace('/\\s+/u', ' ', trim($cleaned)) ?? trim($cleaned);

        return $cleaned;
    }

    private function removeBracketedMarkers(string $content): string
    {
        $markerCluster = self::SIMPLE_SQUARE_MARKER_BODY
            .'(?:\\s*(?:(?:[,，]\\s*(?:and|&)|[,，、;；/]|and|&)\\s*)?'.self::SIMPLE_SQUARE_MARKER_BODY.')+';
        $patterns = [
            '(?m)^[ \\t]*\\[\\s*\\^\\s*'.self::CITATION_ID.'\\s*\\]\\s*:[^\\r\\n]*(?:\\R|$)',
            '<sup\\b[^>]*>\\s*'.self::CITATION_ID.'\\s*</sup>',
            '<sup\\b[^>]*>\\s*'.self::BRACKETED_MARKER_BODY.'\\s*</sup>',
            '(?:\\(\\s*'.$markerCluster.'\\s*\\)|（\\s*'.$markerCluster.'\\s*）)',
            $markerCluster,
            '(?:\\(\\s*'.self::BRACKETED_MARKER_BODY.'\\s*\\)|（\\s*'.self::BRACKETED_MARKER_BODY.'\\s*）)',
            self::BRACKETED_MARKER_BODY.'\\(\\s*(?:(?:https?|ftp)://|mailto:|#|/)[^\\r\\n)]{0,2048}\\)',
            self::BRACKETED_MARKER_BODY.'\\s*\\[[^]\\r\\n]{1,200}\\]',
            '`\\s*'.self::BRACKETED_MARKER_BODY.'\\s*`',
            self::BRACKETED_MARKER_BODY,
        ];

        $cleaned = $content;
        foreach ($patterns as $pattern) {
            $cleaned = $this->removePattern($cleaned, $pattern);
        }

        return $cleaned;
    }

    private function removeContextualBareMarkers(string $content): string
    {
        $cue = '(?:资料显示|材料显示|证据(?:编号|标注|占位符)?|引用(?:编号|标注|占位符)?|来源(?:编号|标注|占位符)?|参考(?:编号|标注|占位符)?|evidence(?:\\s+(?:id|marker|label))?|citation(?:\\s+(?:id|marker|label))?|source(?:\\s+(?:id|marker|label))?)';

        return preg_replace(
            '~('.$cue.')\\s*[:：]?\\s*'.self::BARE_MARKER_BODY.'(?=\\s|[，。！？；：,.!?;:]|$)~iu',
            '$1',
            $content,
        ) ?? $content;
    }

    private function removePattern(string $content, string $markerBody): string
    {
        return preg_replace_callback(
            '~([\\p{L}\\p{N}]|[-+*>|.:：])?([ \\t])?(?:'.$markerBody.')([ \\t])?([\\p{L}\\p{N}]|[，。！？；：,.!?;:])?~iu',
            static function (array $matches): string {
                $before = (string) ($matches[1] ?? '');
                $after = (string) ($matches[4] ?? '');
                $hadWhitespace = ($matches[2] ?? '') !== '' || ($matches[3] ?? '') !== '';
                if ($after !== '' && preg_match('/[，。！？；：,.!?;:]/u', $after) === 1) {
                    return $before.$after;
                }
                $joinsAsciiWords = $before !== ''
                    && $after !== ''
                    && (preg_match('/[A-Za-z0-9]/', $before) === 1 || preg_match('/[A-Za-z0-9]/', $after) === 1);
                $preservesTrailingSeparation = $before !== '' && $after === '' && $hadWhitespace;

                return $before.(($joinsAsciiWords || $preservesTrailingSeparation) ? ' ' : '').$after;
            },
            $content,
        ) ?? $content;
    }
}
