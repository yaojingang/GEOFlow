<?php

namespace App\Services\GeoFlow;

use App\Services\Admin\AiModelUsageAttempt;
use App\Services\AiWorkspace\AiModelInvocationLock;
use Illuminate\Contracts\Cache\Lock;
use Throwable;

final class KnowledgeIndexAiUsageSession
{
    /** @var array<int,array{attempt:AiModelUsageAttempt,reservation:AiUsageReservation,usage:mixed,terminal:bool}> */
    private array $attempts = [];

    private bool $closed = false;

    public function __construct(
        public readonly int $modelId,
        public readonly int $ownerAdminId,
        public readonly string $configurationRevision,
        private readonly AiUsageQuotaService $quota,
        private readonly AiModelInvocationLock $invocationLocks,
        private readonly Lock $lock,
    ) {}

    public function begin(AiModelUsageAttempt $attempt, AiUsageReservation $reservation): int
    {
        $ordinal = count($this->attempts);
        $this->attempts[$ordinal] = [
            'attempt' => $attempt,
            'reservation' => $reservation,
            'usage' => null,
            'terminal' => false,
        ];

        return $ordinal;
    }

    public function providerReturned(int $ordinal, mixed $usage): void
    {
        if (isset($this->attempts[$ordinal]) && ! $this->attempts[$ordinal]['terminal']) {
            $this->attempts[$ordinal]['usage'] = $usage;
        }
    }

    public function providerFailed(int $ordinal, string $errorCode = 'ai_provider_request_failed'): void
    {
        $this->finalizeOne($ordinal, 'failed', $errorCode);
    }

    public function providerDiscarded(int $ordinal, string $errorCode): void
    {
        $this->finalizeOne($ordinal, 'discarded', $errorCode);
    }

    public function succeeded(): void
    {
        $this->finalizeAll('succeeded', null);
    }

    public function revoked(string $errorCode = 'ai_config_access_revoked'): void
    {
        $this->finalizeAll('revoked', $errorCode);
    }

    public function discarded(string $errorCode = 'knowledge_index_result_not_committed'): void
    {
        $this->finalizeAll('discarded', $errorCode);
    }

    public function __destruct()
    {
        try {
            $this->discarded('knowledge_index_result_not_delivered');
        } catch (Throwable) {
            // The model lock and telemetry cleanup are best effort during destruction.
        }
    }

    private function finalizeAll(string $status, ?string $errorCode): void
    {
        if ($this->closed) {
            return;
        }

        try {
            foreach (array_keys($this->attempts) as $ordinal) {
                $this->finalizeOne($ordinal, $status, $errorCode);
            }
        } finally {
            $this->closed = true;
            $this->invocationLocks->release($this->lock);
        }
    }

    private function finalizeOne(
        int $ordinal,
        string $status,
        ?string $errorCode,
    ): void {
        if (! isset($this->attempts[$ordinal]) || $this->attempts[$ordinal]['terminal']) {
            return;
        }

        $entry = $this->attempts[$ordinal];
        $this->attempts[$ordinal]['terminal'] = true;

        try {
            $status === 'succeeded'
                ? $this->quota->recordModelSuccess($entry['reservation'])
                : $this->quota->releaseModel($entry['reservation']);
        } catch (Throwable $exception) {
            report($exception);
        }

        match ($status) {
            'succeeded' => $entry['attempt']->succeeded($entry['usage']),
            'failed' => $entry['attempt']->failed($errorCode ?? 'ai_provider_request_failed', $entry['usage']),
            'revoked' => $entry['attempt']->revoked($errorCode ?? 'ai_config_access_revoked', $entry['usage']),
            default => $entry['attempt']->discarded($errorCode ?? 'knowledge_index_result_not_committed', $entry['usage']),
        };

    }
}
