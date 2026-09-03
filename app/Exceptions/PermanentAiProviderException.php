<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class PermanentAiProviderException extends RuntimeException
{
    public const ERROR_CODE = 'ai_provider_request_rejected';

    private function __construct(Throwable $previous)
    {
        parent::__construct(self::ERROR_CODE, 0, $previous);
    }

    public static function fromProviderFailure(Throwable $previous): self
    {
        return new self($previous);
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }
}
