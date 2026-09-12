<?php

namespace App\Services\GeoFlow\AiVisibility;

use Normalizer;

final class AiVisibilityKeywordNormalizer
{
    public static function normalize(string $keyword): string
    {
        $lowered = mb_strtolower(trim($keyword), 'UTF-8');
        $folded = Normalizer::normalize(mb_convert_kana($lowered, 'as', 'UTF-8'), Normalizer::NFC);

        return (string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $folded);
    }

    public static function hash(string $keyword): string
    {
        return hash('sha256', self::normalize($keyword));
    }
}
