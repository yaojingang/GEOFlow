<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class ArticleAiQualityRuntimeException extends RuntimeException
{
    public function __construct(
        private readonly string $safeCode,
        private readonly bool $retryable = false,
        ?Throwable $previous = null,
        private readonly ?int $httpStatus = null,
        private readonly ?string $providerCode = null,
    ) {
        $safePrevious = $previous instanceof ArticleAiQualityCauseException
            ? $previous
            : ($previous ? new ArticleAiQualityCauseException($previous::class) : null);

        parent::__construct($safeCode, 0, $safePrevious);
    }

    public function safeCode(): string
    {
        return $this->safeCode;
    }

    public function retryable(): bool
    {
        return $this->retryable;
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }

    public function providerCode(): ?string
    {
        return $this->providerCode;
    }
}
