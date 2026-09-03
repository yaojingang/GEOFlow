<?php

namespace App\Exceptions;

use RuntimeException;

final class KnowledgeFactFinalizationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('knowledge_fact_generation_finalize_failed');
    }
}
