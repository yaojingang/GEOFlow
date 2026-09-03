<?php

namespace App\Exceptions;

use RuntimeException;

final class AdminAiAccessBackfillException extends RuntimeException
{
    public function __construct(private readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
