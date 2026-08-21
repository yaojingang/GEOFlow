<?php

namespace App\Console\GeoFlowCli;

use RuntimeException;

class ApiException extends RuntimeException
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        string $message,
        public readonly int $httpStatus,
        public readonly array $payload = [],
        public readonly string $raw = '',
    ) {
        parent::__construct($message);
    }
}
