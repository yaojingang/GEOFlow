<?php

namespace App\Exceptions;

use RuntimeException;

final class KnowledgeFactModelRateLimitExceeded extends RuntimeException
{
    public const ERROR_CODE = 'knowledge_fact_generation_model_rate_limited';

    public function __construct(
        private readonly int $retryAfterSeconds,
    ) {
        parent::__construct(self::ERROR_CODE);
    }

    public function retryAfterSeconds(): int
    {
        return max(1, $this->retryAfterSeconds);
    }
}
