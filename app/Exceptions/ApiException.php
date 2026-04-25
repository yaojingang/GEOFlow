<?php

namespace App\Exceptions;

use RuntimeException;

class ApiException extends RuntimeException
{
    public function __construct(
        private string $errorCode,
        string $message,
        private int $httpStatus = 400,
        private array $details = []
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }

    public function getDetails(): array
    {
        return $this->details;
    }
}
