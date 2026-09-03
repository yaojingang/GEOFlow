<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Jobs\FinalizeKnowledgeFactGenerationJob;
use App\Models\KnowledgeFactGenerationRun;
use RuntimeException;
use Throwable;

final class KnowledgeFactGenerationFinalizerDispatcher
{
    public function dispatch(int $runId, int $executionAttempt, string $leaseToken): void
    {
        $marked = KnowledgeFactGenerationRun::query()
            ->whereKey($runId)
            ->whereIn('status', KnowledgeFactGenerationRun::ACTIVE_STATUSES)
            ->where('execution_attempt', $executionAttempt)
            ->where('finalizer_lease_token', $leaseToken)
            ->update([
                'finalizer_lease_expires_at' => now()->addSeconds(
                    $this->pendingSeconds(),
                ),
                'updated_at' => now(),
            ]);
        if ($marked !== 1) {
            throw new RuntimeException('knowledge_fact_generation_finalizer_dispatch_stale');
        }

        try {
            FinalizeKnowledgeFactGenerationJob::dispatch(
                $runId,
                $executionAttempt,
                $leaseToken,
            )->onQueue('knowledge')->afterCommit();
        } catch (Throwable $exception) {
            KnowledgeFactGenerationRun::query()
                ->whereKey($runId)
                ->whereIn('status', KnowledgeFactGenerationRun::ACTIVE_STATUSES)
                ->where('execution_attempt', $executionAttempt)
                ->where('finalizer_lease_token', $leaseToken)
                ->update([
                    'finalizer_lease_expires_at' => null,
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }

    private function pendingSeconds(): int
    {
        return max(60, min(3600, (int) config(
            'geoflow.knowledge_fact_generation_finalizer_pending_seconds',
            900,
        )));
    }
}
