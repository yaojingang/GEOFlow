<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\EnterpriseKnowledgeProject;
use App\Services\Admin\AiModelUsageAttempt;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\AiWorkspace\AiModelInvocationLock;
use Illuminate\Contracts\Cache\Lock;
use Throwable;

final class EnterpriseKnowledgeDraftUsageDelivery
{
    /** @var array<int,array{attempt:AiModelUsageAttempt,reservation:AiUsageReservation,usage:mixed}> */
    private array $pending = [];

    /** @var array<string,int> */
    private array $providerOrdinals = [];

    private bool $finalized = false;

    public function __construct(
        private readonly EnterpriseKnowledgeProject $project,
        private readonly EnterpriseKnowledgeExecutionFence $executionFence,
        private readonly AiModel $model,
        private readonly int $candidateOrdinal,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly AiModelUsageAttemptFactory $usageAttempts,
        private readonly AiModelInvocationLock $invocationLocks,
        private readonly Lock $invocationLock,
    ) {}

    public function begin(string $stage, string $requestPayload): AiModelUsageAttempt
    {
        $providerOrdinal = ($this->providerOrdinals[$stage] ?? 0) + 1;
        $this->providerOrdinals[$stage] = $providerOrdinal;

        return $this->usageAttempts->beginForAdmin(
            model: $this->model,
            executionAdminId: (int) $this->project->model_access_admin_id,
            accessVersion: (int) $this->project->ai_config_access_version,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
            modelSource: $this->usageAttempts->sourceFor(
                $this->model,
                (int) $this->project->model_access_admin_id,
            ),
            requestId: $this->executionFence->leaseToken,
            requestPayload: $requestPayload,
            callKey: sprintf(
                'a%d.c%d.%s.p%d',
                $this->executionFence->executionAttempt,
                $this->candidateOrdinal,
                $stage,
                $providerOrdinal,
            ),
            operation: 'enterprise_knowledge.generate',
            businessSource: 'enterprise_knowledge_draft',
            sourceType: EnterpriseKnowledgeProject::class,
            sourceId: (int) $this->project->id,
        );
    }

    public function providerReturned(
        AiModelUsageAttempt $attempt,
        AiUsageReservation $reservation,
        mixed $usage = null,
    ): void {
        $this->pending[spl_object_id($attempt)] = compact('attempt', 'reservation', 'usage');
    }

    public function providerFailed(
        AiModelUsageAttempt $attempt,
        AiUsageReservation $reservation,
        string $errorCode = 'ai_provider_request_failed',
    ): void {
        $this->recordAttempt($reservation);
        $attempt->failed($errorCode);
    }

    public function discardPending(string $errorCode = 'ai_result_not_committed'): void
    {
        foreach ($this->drain() as $pending) {
            $this->recordAttempt($pending['reservation']);
            $pending['attempt']->discarded($errorCode, $pending['usage']);
        }
    }

    public function succeeded(): void
    {
        if (! $this->claimFinalization()) {
            return;
        }

        foreach ($this->drain() as $pending) {
            $this->recordSuccess($pending['reservation']);
            $pending['attempt']->succeeded($pending['usage']);
        }
        $this->releaseLock();
    }

    public function revoked(string $errorCode = 'ai_config_access_revoked'): void
    {
        if (! $this->claimFinalization()) {
            return;
        }

        foreach ($this->drain() as $pending) {
            $this->recordAttempt($pending['reservation']);
            $pending['attempt']->revoked($errorCode, $pending['usage']);
        }
        $this->releaseLock();
    }

    public function discarded(string $errorCode = 'ai_result_not_committed'): void
    {
        if (! $this->claimFinalization()) {
            return;
        }

        $this->discardPending($errorCode);
        $this->releaseLock();
    }

    private function claimFinalization(): bool
    {
        if ($this->finalized) {
            return false;
        }

        $this->finalized = true;

        return true;
    }

    /** @return array<int,array{attempt:AiModelUsageAttempt,reservation:AiUsageReservation,usage:mixed}> */
    private function drain(): array
    {
        $pending = $this->pending;
        $this->pending = [];

        return $pending;
    }

    private function recordSuccess(AiUsageReservation $reservation): void
    {
        try {
            $this->usageQuota->recordModelSuccess($reservation);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function recordAttempt(AiUsageReservation $reservation): void
    {
        try {
            $this->usageQuota->recordModelAttempt($reservation);
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
