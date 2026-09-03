<?php

namespace App\Services\GeoFlow\AiVisibility;

final readonly class AiVisibilityPreparedModelRequest
{
    public function __construct(
        public mixed $providerPayload,
        public string $digestPayload,
    ) {}
}
