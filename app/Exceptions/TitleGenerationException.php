<?php

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class TitleGenerationException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, 0, $previous);
    }
}
