<?php

namespace App\Services\GeoFlow;

final class AiUsageReservation
{
    public function __construct(
        public readonly string $resourceType,
        public readonly int $resourceId,
        public readonly string $usageDate,
    ) {}

    private bool $finalizing = false;

    private bool $finalized = false;

    public function claimFinalization(): bool
    {
        if ($this->finalizing || $this->finalized) {
            return false;
        }

        $this->finalizing = true;

        return true;
    }

    public function completeFinalization(): void
    {
        $this->finalizing = false;
        $this->finalized = true;
    }

    public function cancelFinalization(): void
    {
        if (! $this->finalized) {
            $this->finalizing = false;
        }
    }
}
