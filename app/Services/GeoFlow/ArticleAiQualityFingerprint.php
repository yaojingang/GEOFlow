<?php

namespace App\Services\GeoFlow;

use JsonException;

class ArticleAiQualityFingerprint
{
    public const ALGORITHM_VERSION = 'ai-quality-1.0.0';

    /** @param array<string, mixed> $input */
    public function make(array $input, ?string $algorithmVersion = null): string
    {
        $input['algorithm_version'] = $algorithmVersion ?: self::ALGORITHM_VERSION;

        try {
            return hash('sha256', json_encode(
                $this->canonicalize($input),
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            ));
        } catch (JsonException $exception) {
            throw new \InvalidArgumentException('AI quality fingerprint input could not be encoded.', previous: $exception);
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }

        ksort($value, SORT_STRING);

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize($item);
        }

        return $value;
    }
}
