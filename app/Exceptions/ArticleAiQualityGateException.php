<?php

namespace App\Exceptions;

use App\Models\ArticleAiQualityCheck;
use RuntimeException;

class ArticleAiQualityGateException extends RuntimeException
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly ?ArticleAiQualityCheck $check = null,
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getCheck(): ?ArticleAiQualityCheck
    {
        return $this->check;
    }
}
