<?php

namespace App\Exceptions;

use RuntimeException;

final class AiQualityComparisonCheckpointException extends RuntimeException
{
    public static function busy(): self
    {
        return new self('ai_quality_comparison_checkpoint_busy');
    }

    public static function mismatch(): self
    {
        return new self('ai_quality_comparison_checkpoint_mismatch');
    }
}
