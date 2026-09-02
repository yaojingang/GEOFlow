<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Services\AiWorkspace\AiModelInvocationLock;
use Illuminate\Contracts\Cache\Lock;

final class DirectAdminAiModelInvocation
{
    private bool $closed = false;

    public function __construct(
        public readonly AiModel $model,
        public readonly string $source,
        public readonly AiUsageReservation $reservation,
        private readonly Lock $lock,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly AiModelInvocationLock $invocationLocks,
    ) {}

    public function recordSuccess(): void
    {
        $this->usageQuota->recordModelSuccess($this->reservation);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        try {
            $this->usageQuota->releaseModel($this->reservation);
        } finally {
            $this->invocationLocks->release($this->lock);
        }
    }
}
