<?php

namespace App\Services\GeoFlow;

use App\Models\EnterpriseKnowledgeProject;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EnterpriseKnowledgeDraftRecoveryService
{
    private const DISPATCH_FAILED = 'enterprise_knowledge_recovery_dispatch_failed';

    public function __construct(
        private readonly EnterpriseKnowledgeDraftRecoveryDispatcher $dispatcher,
    ) {}

    /** @return array{recovered:int,dispatch_failed:int} */
    public function reconcile(int $limit = 50): array
    {
        $projectIds = EnterpriseKnowledgeProject::query()
            ->where('status', 'processing')
            ->where(function ($query): void {
                $query->whereNull('execution_lease_token')
                    ->orWhere('execution_lease_token', '')
                    ->orWhere(function ($expired): void {
                        $expired->whereNotNull('lease_expires_at')
                            ->where('lease_expires_at', '<=', now());
                    });
            })
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('id');

        $recovered = 0;
        $dispatchFailed = 0;
        foreach ($projectIds as $projectId) {
            if (! $this->reserveForRecovery((int) $projectId)) {
                continue;
            }

            try {
                $this->dispatcher->dispatch((int) $projectId);
                $recovered++;
            } catch (Throwable) {
                $dispatchFailed++;
                EnterpriseKnowledgeProject::query()
                    ->whereKey((int) $projectId)
                    ->where('status', 'queued')
                    ->whereNull('execution_lease_token')
                    ->update([
                        'status' => 'processing',
                        'error_code' => self::DISPATCH_FAILED,
                        'error_message' => self::DISPATCH_FAILED,
                        'retryable_failure' => true,
                        'lease_expires_at' => null,
                        'updated_at' => now(),
                    ]);
            }
        }

        return ['recovered' => $recovered, 'dispatch_failed' => $dispatchFailed];
    }

    private function reserveForRecovery(int $projectId): bool
    {
        return DB::transaction(function () use ($projectId): bool {
            $project = EnterpriseKnowledgeProject::query()
                ->whereKey($projectId)
                ->lockForUpdate()
                ->first();
            if (! $project instanceof EnterpriseKnowledgeProject || ! $this->isRecoverable($project)) {
                return false;
            }

            $project->forceFill([
                'status' => 'queued',
                'execution_lease_token' => null,
                'lease_expires_at' => null,
                'error_code' => null,
                'error_message' => null,
                'retryable_failure' => true,
            ])->save();

            return true;
        }, 3);
    }

    private function isRecoverable(EnterpriseKnowledgeProject $project): bool
    {
        if ((string) $project->status !== 'processing') {
            return false;
        }

        return trim((string) ($project->execution_lease_token ?? '')) === ''
            || ($project->lease_expires_at !== null && $project->lease_expires_at->isPast());
    }
}
