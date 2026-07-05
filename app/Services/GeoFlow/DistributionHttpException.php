<?php

namespace App\Services\GeoFlow;

use RuntimeException;

class DistributionHttpException extends RuntimeException
{
    public function __construct(
        string $message,
        private readonly int $status,
        private readonly string $endpoint,
    ) {
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }
}
