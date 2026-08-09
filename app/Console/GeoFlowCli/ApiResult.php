<?php

namespace App\Console\GeoFlowCli;

class ApiResult
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $raw,
        public readonly array $payload,
        public readonly int $status,
    ) {}
}
