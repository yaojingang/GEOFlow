<?php

namespace App\Services\SystemUpdater;

/** Stable evidence encoding across database JSON object key orders. */
final class RecoveryEvidence
{
    public static function json(array $value): string
    {
        return json_encode(self::canonical($value), JSON_THROW_ON_ERROR);
    }

    private static function canonical(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$item) {
            if (is_array($item)) {
                $item = self::canonical($item);
            }
        }

        return $value;
    }
}
