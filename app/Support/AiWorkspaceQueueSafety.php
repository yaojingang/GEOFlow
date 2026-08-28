<?php

namespace App\Support;

use LogicException;

final class AiWorkspaceQueueSafety
{
    public static function assertVisibilityBudget(string $connection, int $visibilityTimeout): void
    {
        if ($connection === 'sqs' && $visibilityTimeout <= 930) {
            throw new LogicException('SQS visibility timeout must be greater than the 930-second AI workspace execution timeout.');
        }
    }
}
