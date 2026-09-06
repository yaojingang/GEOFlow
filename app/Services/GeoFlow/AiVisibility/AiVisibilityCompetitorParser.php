<?php

namespace App\Services\GeoFlow\AiVisibility;

use RuntimeException;

final class AiVisibilityCompetitorParser
{
    /** @return list<string> */
    public function parse(string $response): array
    {
        $raw = trim($response);
        if (preg_match('/\A```(?:json)?\s*(.*?)\s*```\z/is', $raw, $matches) === 1) {
            $raw = $matches[1];
        }
        try {
            $items = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('ai_competitor_response_invalid');
        }
        if (! str_starts_with($raw, '[') || ! is_array($items) || ! array_is_list($items) || count($items) > 50) {
            throw new RuntimeException('ai_competitor_response_invalid');
        }
        $names = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['name'] ?? null)) {
                throw new RuntimeException('ai_competitor_response_invalid');
            }
            $name = trim($item['name']);
            if ($name === '' || mb_strlen($name) > 120 || preg_match('/[\x00-\x1f]/u', $name) === 1) {
                throw new RuntimeException('ai_competitor_response_invalid');
            }
            $names[mb_strtolower($name)] = $name;
        }

        return array_values($names);
    }
}
