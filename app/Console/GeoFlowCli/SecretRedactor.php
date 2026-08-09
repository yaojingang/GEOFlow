<?php

namespace App\Console\GeoFlowCli;

class SecretRedactor
{
    public static function mask(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $length = strlen($value);
        if ($length <= 10) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 3).str_repeat('*', $length - 5).substr($value, -2);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public static function payload(array $payload, array $secrets = []): array
    {
        foreach ($payload as $key => $value) {
            if (preg_match('/(?:token|password|secret|api[_-]?key)/i', (string) $key) === 1) {
                $payload[$key] = is_string($value) ? self::mask($value) : '[redacted]';
            } elseif (is_array($value)) {
                $payload[$key] = self::payload($value, $secrets);
            } elseif (is_string($value)) {
                $payload[$key] = self::text($value, ...$secrets);
            }
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload @return list<string> */
    public static function sensitiveValues(array $payload): array
    {
        $values = [];
        foreach ($payload as $key => $value) {
            if (preg_match('/(?:token|password|secret|api[_-]?key)/i', (string) $key) === 1) {
                self::collectStrings($value, $values);
            } elseif (is_array($value)) {
                $values = array_merge($values, self::sensitiveValues($value));
            }
        }

        return array_values(array_unique(array_filter($values, static fn (string $value): bool => $value !== '')));
    }

    public static function text(string $message, ?string ...$secrets): string
    {
        $secrets = array_values(array_unique(array_filter(
            $secrets,
            static fn (?string $secret): bool => is_string($secret) && $secret !== '',
        )));
        usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $message = str_replace($secret, '[redacted]', $message);
            }
        }

        return preg_replace(
            '/((?:token|password|secret|api[_-]?key)\s*[=:]\s*)[^\s,&]+/i',
            '$1[redacted]',
            $message,
        ) ?? $message;
    }

    /** @param list<string> $values */
    private static function collectStrings(mixed $value, array &$values): void
    {
        if (is_string($value)) {
            $values[] = $value;

            return;
        }
        if (is_array($value)) {
            foreach ($value as $nested) {
                self::collectStrings($nested, $values);
            }
        }
    }
}
