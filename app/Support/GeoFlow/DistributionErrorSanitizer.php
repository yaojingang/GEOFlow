<?php

namespace App\Support\GeoFlow;

use Throwable;

final class DistributionErrorSanitizer
{
    public static function from(Throwable $exception): string
    {
        $message = trim($exception->getMessage());
        $message = preg_replace('#https?://[^\s]+#iu', '[remote-url]', $message) ?? '';
        $message = preg_replace('/\b(bearer|token|secret|password|api[_-]?key)\b\s*[:=]\s*[^\s,;]+/iu', '$1=[redacted]', $message) ?? '';
        $message = preg_replace('/[\r\n\t]+/u', ' ', $message) ?? '';
        $message = trim($message);

        return mb_substr(
            class_basename($exception).($message !== '' ? ': '.$message : ''),
            0,
            1000,
        );
    }
}
