<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;

class BrandProfileContextFormatter
{
    /**
     * @return list<string>
     */
    public static function promptLines(BrandProfile $brandProfile): array
    {
        return collect(self::extendedLines($brandProfile))
            ->map(static fn (string $line): string => $line)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    public static function markdownBullets(?BrandProfile $brandProfile): array
    {
        if (! $brandProfile instanceof BrandProfile) {
            return [];
        }

        return collect(self::extendedLines($brandProfile))
            ->map(static fn (string $line): string => '- '.$line)
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private static function extendedLines(BrandProfile $brandProfile): array
    {
        $profile = (array) ($brandProfile->extended_profile ?? []);
        $lines = [];

        self::appendTextLine($lines, '品牌简称', $profile['short_name'] ?? '');
        self::appendTextLine($lines, '写作方向', $profile['writing_directions'] ?? '');
        self::appendListLine($lines, '文案类型', $profile['copy_types'] ?? []);
        self::appendListLine($lines, '产品特点', $profile['product_features'] ?? []);
        self::appendTextLine($lines, '品牌故事', $profile['brand_story'] ?? '');
        self::appendListLine($lines, '信任背书', $profile['trust_proofs'] ?? []);
        self::appendListLine($lines, '推广区域', $profile['promotion_regions'] ?? []);
        self::appendListLine($lines, '禁用表达', $profile['forbidden_claims'] ?? []);

        return $lines;
    }

    /**
     * @param  list<string>  $lines
     */
    private static function appendTextLine(array &$lines, string $label, mixed $value): void
    {
        $text = trim((string) $value);
        if ($text !== '') {
            $lines[] = $label.'：'.$text;
        }
    }

    /**
     * @param  list<string>  $lines
     */
    private static function appendListLine(array &$lines, string $label, mixed $value): void
    {
        $items = collect((array) $value)
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->values()
            ->all();

        if ($items !== []) {
            $lines[] = $label.'：'.implode('、', $items);
        }
    }
}
