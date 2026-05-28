<?php

namespace App\Services\GeoFlow;

use DateTimeInterface;
use Throwable;

class DistributionRetryPolicy
{
    public function shouldRetry(Throwable $exception, int $attemptCount, int $maxAttempts): bool
    {
        if ($attemptCount >= $maxAttempts) {
            return false;
        }

        $message = mb_strtolower($exception->getMessage(), 'UTF-8');

        if (
            str_contains($message, '401')
            || str_contains($message, '403')
            || str_contains($message, 'signature')
            || str_contains($message, '签名')
            || str_contains($message, '422')
        ) {
            return false;
        }

        return str_contains($message, 'timeout')
            || str_contains($message, 'connection')
            || str_contains($message, '429')
            || str_contains($message, '500')
            || str_contains($message, '502')
            || str_contains($message, '503')
            || str_contains($message, '504');
    }

    public function retryAt(int $attemptCount): DateTimeInterface
    {
        return now()->addSeconds(min(3600, 60 * (2 ** max(0, $attemptCount - 1))));
    }
}
