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
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
