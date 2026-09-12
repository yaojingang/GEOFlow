<?php

namespace App\Services\GeoFlow\AiVisibility;

final class AiVisibilityKeywordNormalizer
{
    public static function normalize(string $keyword): string
    {
        return (string) preg_replace('/[\s\p{P}]+/u', '', mb_strtolower(trim($keyword), 'UTF-8'));
    }

    public static function hash(string $keyword): string
    {
        return hash('sha256', self::normalize($keyword));
    }
}
