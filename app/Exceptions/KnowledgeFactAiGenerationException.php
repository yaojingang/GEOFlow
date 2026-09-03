<?php

namespace App\Exceptions;

use RuntimeException;

final class KnowledgeFactAiGenerationException extends RuntimeException
{
    public function __construct(
        public readonly bool $retryable,
    ) {
        parent::__construct($retryable
            ? 'knowledge_fact_provider_transient_failure'
            : 'knowledge_fact_provider_permanent_failure');
    }
}
