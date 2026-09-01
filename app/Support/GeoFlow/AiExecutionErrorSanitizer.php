<?php

namespace App\Support\GeoFlow;

use Throwable;

final class AiExecutionErrorSanitizer
{
    private const MAX_MESSAGE_LENGTH = 500;

    public function sanitize(Throwable|string|null $error, string $fallback = 'AI execution failed'): string
    {
        $message = $error instanceof Throwable ? $error->getMessage() : (string) $error;
        $message = strip_tags($message);
        $message = preg_replace('~https?://[^\s\"\'<>]+~iu', '[redacted-url]', $message) ?? '';
        $message = preg_replace(
            '/"(key|api[\s_-]*key|access[\s_-]*token|client[\s_-]*secret|token|secret|credential|password|base[\s_-]*url|api[\s_-]*url|endpoint|note)"\s*:\s*"(?:\\\\.|[^"\\\\])*"/iu',
            '"$1":"[redacted]"',
            $message,
        ) ?? '';
        $message = preg_replace(
            "/'(key|api[\\s_-]*key|access[\\s_-]*token|client[\\s_-]*secret|token|secret|credential|password|base[\\s_-]*url|api[\\s_-]*url|endpoint|note)'\\s*:\\s*'(?:\\\\.|[^'\\\\])*'/iu",
            "'$1':'[redacted]'",
            $message,
        ) ?? '';
        $message = preg_replace('/\bAuthorization\s*[:=]?\s*(?:Bearer\s+)?[^\s,;]+/iu', 'Authorization: [redacted]', $message) ?? '';
        $message = preg_replace('/\bBearer\s+[^\s,;]+/iu', 'Bearer [redacted]', $message) ?? '';
        $message = preg_replace(
            '/\b(key|api[\s_-]*key|access[\s_-]*token|client[\s_-]*secret|token|secret|credential|password|base[\s_-]*url|api[\s_-]*url|endpoint|note)\s*[:=]\s*[^\s,;]+/iu',
            '$1=[redacted]',
            $message,
        ) ?? '';
        $message = preg_replace('/\bsk-[a-z0-9_-]{8,}\b/iu', '[redacted-key]', $message) ?? '';
        $message = preg_replace('/\s+/u', ' ', trim($message)) ?? '';
        if ($message === '') {
            $message = $fallback;
        }

        return mb_substr($message, 0, self::MAX_MESSAGE_LENGTH, 'UTF-8');
    }

    /** @param array<string|int,mixed> $meta @return array<string|int,mixed> */
    public function sanitizeMeta(array $meta): array
    {
        $sanitized = [];
        foreach ($meta as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeMeta($value);
            } elseif (is_string($value)) {
                $sanitized[$key] = $this->sanitize($value, '');
            } elseif (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = preg_replace('/[^a-z0-9]+/', '', strtolower($key)) ?? '';

        return in_array($normalized, [
            'aiconfigaccessversion',
            'executionleasetoken',
            'modelaccessadminid',
            'modelaccessadminrole',
            'requestedaimodelid',
            'resolvedaimodelid',
            'resolvedmodelsource',
            'resolverpolicyversion',
        ], true)
            || $normalized === 'key'
            || str_contains($normalized, 'apikey')
            || str_contains($normalized, 'authorization')
            || str_contains($normalized, 'password')
            || str_contains($normalized, 'secret')
            || str_contains($normalized, 'token')
            || str_contains($normalized, 'bearer')
            || str_contains($normalized, 'credential')
            || str_contains($normalized, 'note')
            || str_contains($normalized, 'endpoint')
            || str_contains($normalized, 'baseurl')
            || str_contains($normalized, 'apiurl')
            || str_contains($normalized, 'prompt');
    }
}
