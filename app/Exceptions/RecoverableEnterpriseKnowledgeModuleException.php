<?php

namespace App\Exceptions;

use RuntimeException;

final class RecoverableEnterpriseKnowledgeModuleException extends RuntimeException
{
    public static function invalidOutput(string $message): self
    {
        return new self($message);
    }
}
