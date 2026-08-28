<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class SystemUpdaterPreparationException extends RuntimeException
{
    private function __construct(
        private readonly string $failureReason,
        Throwable $previous,
    ) {
        parent::__construct($previous->getMessage(), (int) $previous->getCode(), $previous);
    }

    public static function verificationFailed(Throwable $previous): self
    {
        return new self('verification_failed', $previous);
    }

    public static function storageFailed(Throwable $previous): self
    {
        return new self('storage_failed', $previous);
    }

    public static function platformUnsupported(Throwable $previous): self
    {
        return new self('platform_unsupported', $previous);
    }

    public static function connectionFailed(Throwable $previous): self
    {
        return new self('connection_failed', $previous);
    }

    public function failureReason(): string
    {
        return $this->failureReason;
    }
}
