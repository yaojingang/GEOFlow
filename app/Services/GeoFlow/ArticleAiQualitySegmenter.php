<?php

namespace App\Services\GeoFlow;

use Illuminate\Support\Str;

class ArticleAiQualitySegmenter
{
    public function __construct(private readonly int $maxCharacters = 12000) {}

    /**
     * @return list<array{index:int,start_offset:int,end_offset:int,content:string,heading:string,input_hash:string}>
     */
    public function segment(string $content): array
    {
        $length = Str::length($content);
        if ($length === 0) {
            return [[
                'index' => 0,
                'start_offset' => 0,
                'end_offset' => 0,
                'content' => '',
                'heading' => '',
                'input_hash' => hash('sha256', ''),
            ]];
        }

        $maximum = max(500, $this->maxCharacters);
        if ($this->maxCharacters < 500) {
            $maximum = max(1, $this->maxCharacters);
        }
        $segments = [];
        $offset = 0;

        while ($offset < $length) {
            $remaining = $length - $offset;
            $take = min($maximum, $remaining);
            $window = Str::substr($content, $offset, $take);

            if ($take < $remaining) {
                $lineBreak = mb_strrpos($window, "\n", 0, 'UTF-8');
                if ($lineBreak !== false && $lineBreak >= (int) floor($maximum * 0.5)) {
                    $take = $lineBreak + 1;
                    $window = Str::substr($content, $offset, $take);
                }
            }

            $segments[] = [
                'index' => count($segments),
                'start_offset' => $offset,
                'end_offset' => $offset + $take,
                'content' => $window,
                'heading' => $this->headingBefore($content, $offset),
                'input_hash' => hash('sha256', $window),
            ];
            $offset += $take;
        }

        return $segments;
    }

    private function headingBefore(string $content, int $offset): string
    {
        $prefix = Str::substr($content, 0, $offset);
        preg_match_all('/^#{1,6}\s+(.+)$/mu', $prefix, $matches);
        $headings = $matches[1] ?? [];

        return $headings === [] ? '' : trim((string) end($headings));
    }
}
