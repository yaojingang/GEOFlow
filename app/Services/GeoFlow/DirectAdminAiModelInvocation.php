<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\DirectAdminAiExecutionContext;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Services\Admin\AiModelUsageAttempt;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\AiWorkspace\AiModelInvocationLock;
use Illuminate\Contracts\Cache\Lock;

final class DirectAdminAiModelInvocation
{
    private bool $closed = false;

    private ?AiModelUsageAttempt $usageAttempt = null;

    public function __construct(
        public readonly AiModel $model,
        public readonly string $source,
        public readonly AiUsageReservation $reservation,
        private readonly Lock $lock,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly AiModelInvocationLock $invocationLocks,
        private readonly AiModelUsageAttemptFactory $usageAttempts,
        private readonly DirectAdminAiExecutionContext $context,
    ) {}

    public function beginUsageAttempt(
        string $requestPayload,
        string $operation,
        string $businessSource,
        ?string $sourceType = null,
        int|string|null $sourceId = null,
        string $callKey = 'provider-1',
    ): void {
        if ($this->usageAttempt instanceof AiModelUsageAttempt) {
            return;
        }
        $this->usageAttempt = $this->usageAttempts->beginForAdmin(
            model: $this->model,
            executionAdminId: $this->context->adminId,
            accessVersion: $this->context->accessVersion,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN,
            modelSource: $this->source,
            requestId: $this->context->requestId,
            requestPayload: $requestPayload,
            callKey: $callKey,
            operation: $operation,
            businessSource: $businessSource,
            sourceType: $sourceType,
            sourceId: $sourceId,
        );
    }

    public function newProviderUsageAttempt(
        string $requestPayload,
        string $operation,
        string $businessSource,
        ?string $sourceType,
        int|string|null $sourceId,
        string $callKey,
    ): AiModelUsageAttempt {
        return $this->usageAttempts->beginForAdmin(
            model: $this->model,
            executionAdminId: $this->context->adminId,
            accessVersion: $this->context->accessVersion,
            executionScope: AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN,
            modelSource: $this->source,
            requestId: $this->context->requestId,
            requestPayload: $requestPayload,
            callKey: $callKey,
            operation: $operation,
            businessSource: $businessSource,
            sourceType: $sourceType,
            sourceId: $sourceId,
        );
    }

    public function recordSuccess(mixed $usage = null): void
    {
        $this->usageQuota->recordModelSuccess($this->reservation);
        $this->usageAttempt?->succeeded($usage);
    }

    public function recordDelivered(mixed $usage = null): void
    {
        $this->usageAttempt?->succeeded($usage);
    }

    public function recordProviderFailure(string $errorCode = 'ai_provider_request_failed'): void
    {
        $this->usageAttempt?->failed($errorCode);
    }

    public function recordRevoked(string $errorCode, mixed $usage = null): void
    {
        $this->usageAttempt?->revoked($errorCode, $usage);
    }

    public function recordDiscarded(string $errorCode = 'ai_result_discarded', mixed $usage = null): void
    {
        $this->usageAttempt?->discarded($errorCode, $usage);
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        try {
            $this->usageAttempt?->discarded('ai_result_not_delivered');
            $this->usageQuota->releaseModel($this->reservation);
        } finally {
            $this->invocationLocks->release($this->lock);
        }
    }
}
