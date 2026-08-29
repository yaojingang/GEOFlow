<?php

namespace App\Exceptions;

use RuntimeException;

final class ArticleAiQualityCauseException extends RuntimeException
{
    public function __construct(public readonly string $causeType)
    {
        parent::__construct('AI quality failure cause metadata retained.');
    }
}
