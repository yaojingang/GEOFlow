<?php

namespace App\Support\GeoFlow;

final class PromptTestAnchors
{
    public static function normalize(string $text): string
    {
        return str_replace(["\r\n", "\r"], "\n", $text);
    }

    /** @return list<array{id:string,text:string,line:int,start:int,end:int,section:string}> */
    public static function segments(string $prompt): array
    {
        $segments = [];
        $offset = 0;
        $section = '';
        foreach (explode("\n", $prompt) as $index => $line) {
            if (preg_match('/^\s*#{1,6}\s+(.+)$/u', $line, $matches)) {
                $section = mb_substr($matches[1], 0, 100);
            }
            if (trim($line) !== '') {
                $segments[] = [
                    'id' => 'p'.($index + 1), 'text' => $line, 'line' => $index + 1,
                    'start' => $offset, 'end' => $offset + strlen($line), 'section' => $section,
                ];
            }
            $offset += strlen($line) + 1;
        }

        return $segments;
    }

    /** @return array<string,int|string>|null */
    public static function resolve(string $prompt, string $segmentId, string $quote, string $action): ?array
    {
        if ($quote === '' || ! in_array($action, ['replace', 'insert_after'], true)) {
            return null;
        }
        foreach (self::segments($prompt) as $segment) {
            if ($segment['id'] !== $segmentId) {
                continue;
            }
            $offset = strpos($segment['text'], $quote);
            if ($offset === false || strpos($segment['text'], $quote, $offset + 1) !== false) {
                continue;
            }
            $start = $segment['start'] + $offset;
            $end = $start + strlen($quote);

            return [
                'segment_id' => $segmentId, 'quote' => $quote, 'action' => $action,
                'line' => $segment['line'], 'section' => $segment['section'],
                'start' => self::utf16Length(substr($prompt, 0, $start)),
                'end' => self::utf16Length(substr($prompt, 0, $end)),
                'before' => mb_substr(substr($prompt, 0, $start), -40),
                'after' => mb_substr(substr($prompt, $end), 0, 40),
                'prompt_hash' => hash('sha256', $prompt),
            ];
        }

        return null;
    }

    private static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
