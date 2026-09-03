<?php

namespace App\Services\GeoFlow;

use App\Services\Admin\AiModelUsageAttempt;
use App\Services\AiWorkspace\AiModelInvocationLock;
use Illuminate\Contracts\Cache\Lock;
use Throwable;

final class TitleGenerationUsageDelivery
{
    private bool $finalized = false;

    public function __construct(
        private readonly AiModelUsageAttempt $usageAttempt,
        private readonly mixed $providerUsage,
        private readonly AiUsageReservation $quotaReservation,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly AiModelInvocationLock $invocationLocks,
        private readonly Lock $invocationLock,
    ) {}

    public function succeeded(): void
    {
        if (! $this->claimFinalization()) {
            return;
        }

        $this->recordQuotaSuccess();
        $this->usageAttempt->succeeded($this->providerUsage);
        $this->releaseLock();
    }

    public function revoked(string $errorCode = 'ai_config_access_revoked'): void
    {
        if (! $this->claimFinalization()) {
            return;
        }

        $this->recordQuotaAttempt();
        $this->usageAttempt->revoked($errorCode, $this->providerUsage);
        $this->releaseLock();
    }

    public function discarded(string $errorCode = 'ai_result_not_committed'): void
    {
        if (! $this->claimFinalization()) {
            return;
        }

        $this->recordQuotaAttempt();
        $this->usageAttempt->discarded($errorCode, $this->providerUsage);
        $this->releaseLock();
    }

    public function isFinalized(): bool
    {
        return $this->finalized;
    }

    private function claimFinalization(): bool
    {
        if ($this->finalized) {
            return false;
        }

        $this->finalized = true;

        return true;
    }

    private function recordQuotaSuccess(): void
    {
        try {
            $this->usageQuota->recordModelSuccess($this->quotaReservation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function recordQuotaAttempt(): void
    {
        try {
            $this->usageQuota->recordModelAttempt($this->quotaReservation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function releaseLock(): void
    {
        $this->invocationLocks->release($this->invocationLock);
    }

    public function __destruct()
    {
        $this->discarded('ai_result_not_delivered');
    }
}
