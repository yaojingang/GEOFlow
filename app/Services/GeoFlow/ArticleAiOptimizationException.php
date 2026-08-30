<?php

namespace App\Services\GeoFlow;

use RuntimeException;
use Throwable;

class ArticleAiOptimizationException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        ?string $message = null,
        private readonly int $httpStatus = 409,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message ?? $errorCode, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }
}
