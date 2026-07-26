<?php

namespace App\Services\GeoFlow;

final readonly class AiUsageReservation
{
    public function __construct(
        public string $resourceType,
        public int $resourceId,
        public string $usageDate,
    ) {}
}
