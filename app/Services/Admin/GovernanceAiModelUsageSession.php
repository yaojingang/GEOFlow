<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiModelTestSnapshot;
use App\Models\AiModel;
use App\Services\AiWorkspace\AiModelInvocationLock;
use Illuminate\Contracts\Cache\Lock;

final class GovernanceAiModelUsageSession
{
    /** @var array<string, array{attempt:AiModelUsageAttempt,usage:mixed,lock:Lock,terminal_status:?string,error_code:?string}> */
    private array $pending = [];

    private bool $providerAttemptStarted = false;

    public function __construct(
        private readonly AiModelUsageAttemptFactory $attempts,
        private readonly AiModelInvocationLock $invocationLocks,
        private readonly AdminAiModelTestPreparationService $testPreparation,
        private readonly AdminAiModelTestSnapshot $snapshot,
        public readonly string $requestId,
    ) {}

    public function begin(AiModel $model, string $callKey, string $requestPayload): void
    {
        $lock = $this->invocationLocks->acquireForInvocation((int) $model->getKey());
        try {
            $this->testPreparation->revalidateImmediatelyBeforeOutbound($this->snapshot);
            $this->pending[$callKey] = [
                'attempt' => $this->attempts->beginForGovernanceTest(
                    model: $model,
                    testSnapshot: $this->snapshot,
                    requestId: $this->requestId,
                    requestPayload: $requestPayload,
                    callKey: $callKey,
                ),
                'usage' => null,
                'lock' => $lock,
                'terminal_status' => null,
                'error_code' => null,
            ];
            $this->providerAttemptStarted = true;
        } catch (\Throwable $exception) {
            $this->invocationLocks->release($lock);

            throw $exception;
        }
    }

    public function hasStartedProviderAttempt(): bool
    {
        return $this->providerAttemptStarted;
    }

    public function retainUsage(string $callKey, mixed $usage): void
    {
        if (isset($this->pending[$callKey])) {
            $this->pending[$callKey]['usage'] = $usage;
        }
    }

    public function failed(string $callKey, string $errorCode = 'ai_provider_request_failed', mixed $usage = null): void
    {
        $this->markFailed($callKey, $errorCode, $usage);
        $this->finalizePendingOutcomes();
    }

    public function discarded(string $callKey, string $errorCode = 'ai_provider_response_invalid', mixed $usage = null): void
    {
        $this->markDiscarded($callKey, $errorCode, $usage);
        $this->finalizePendingOutcomes();
    }

    public function markFailed(string $callKey, string $errorCode = 'ai_provider_request_failed', mixed $usage = null): void
    {
        $this->mark($callKey, 'failed', $errorCode, $usage);
    }

    public function markDiscarded(string $callKey, string $errorCode = 'ai_provider_response_invalid', mixed $usage = null): void
    {
        $this->mark($callKey, 'discarded', $errorCode, $usage);
    }

    public function finalizePendingOutcomes(): void
    {
        foreach ($this->pending as $callKey => $pending) {
            if ($pending['terminal_status'] !== null) {
                $this->finalize($callKey, $pending['terminal_status'], $pending['error_code']);
            }
        }
    }

    public function succeededPending(): void
    {
        foreach ($this->pending as $callKey => $pending) {
            if ($pending['terminal_status'] !== null) {
                $this->finalize($callKey, $pending['terminal_status'], $pending['error_code']);

                continue;
            }
            $this->finalize($callKey, 'succeeded');
        }
    }

    public function revokedPending(string $errorCode = 'ai_config_access_revoked'): void
    {
        foreach (array_keys($this->pending) as $callKey) {
            $this->finalize($callKey, 'revoked', $errorCode);
        }
    }

    public function discardedPending(string $errorCode = 'ai_result_discarded'): void
    {
        foreach ($this->pending as $callKey => $pending) {
            if ($pending['terminal_status'] !== null) {
                $this->finalize($callKey, $pending['terminal_status'], $pending['error_code']);

                continue;
            }
            $this->finalize($callKey, 'discarded', $errorCode);
        }
    }

    private function finalize(
        string $callKey,
        string $status,
        ?string $errorCode = null,
        mixed $usage = null,
    ): void {
        $pending = $this->pending[$callKey] ?? null;
        if (! is_array($pending)) {
            return;
        }
        unset($this->pending[$callKey]);
        $usage ??= $pending['usage'];
        try {
            match ($status) {
                'succeeded' => $pending['attempt']->succeeded($usage),
                'failed' => $pending['attempt']->failed((string) $errorCode, $usage),
                'revoked' => $pending['attempt']->revoked((string) $errorCode, $usage),
                default => $pending['attempt']->discarded((string) $errorCode, $usage),
            };
        } finally {
            $this->invocationLocks->release($pending['lock']);
        }
    }

    private function mark(string $callKey, string $status, string $errorCode, mixed $usage): void
    {
        if (! isset($this->pending[$callKey])) {
            return;
        }
        $this->pending[$callKey]['terminal_status'] = $status;
        $this->pending[$callKey]['error_code'] = $errorCode;
        if ($usage !== null) {
            $this->pending[$callKey]['usage'] = $usage;
        }
    }
}
