<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Data\Ai\KnowledgeFactGenerationRecoveryDispatch;
use App\Exceptions\AiModelAccessException;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class KnowledgeFactGenerationRecoveryService
{
    private const DISPATCH_FAILED = 'knowledge_fact_generation_recovery_dispatch_failed';

    private const ATTEMPTS_EXHAUSTED = 'knowledge_fact_generation_recovery_attempts_exhausted';

    private const BATCH_ATTEMPTS_EXHAUSTED = 'knowledge_fact_generation_batch_attempts_exhausted';

    private const PERMANENT_FAILURE = 'knowledge_fact_generation_permanent_failure';

    public function __construct(
        private KnowledgeFactGenerationAiExecutionGuard $executionGuard,
        private KnowledgeFactGenerationRecoveryDispatcher $dispatcher,
    ) {}

    /** @return array{recovered:int,dispatch_failed:int} */
    public function reconcile(int $limit = 50): array
    {
        $candidateBefore = now()->subSeconds(min(
            $this->recoveryStaleSeconds(),
            (int) config('geoflow.knowledge_fact_generation_batch_lease_seconds', 210),
        ));
        $runIds = KnowledgeFactGenerationRun::query()
            ->where(function ($state): void {
                $state->whereIn('status', KnowledgeFactGenerationRun::ACTIVE_STATUSES)
                    ->orWhere(function ($retryable): void {
                        $retryable->whereIn('status', [
                            KnowledgeFactGenerationRun::STATUS_FAILED,
                            'partial',
                        ])->where('retryable_failure', true);
                    });
            })
            ->where('updated_at', '<=', $candidateBefore)
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('id');

        $recovered = 0;
        $dispatchFailed = 0;
        foreach ($runIds as $runId) {
            $dispatch = $this->reserve((int) $runId);
            if (! $dispatch instanceof KnowledgeFactGenerationRecoveryDispatch) {
                continue;
            }

            try {
                $jobBatchId = $this->dispatcher->dispatch($dispatch);
                $this->recordDispatched($dispatch, $jobBatchId);
                $recovered++;
            } catch (Throwable) {
                $this->recordDispatchFailure($dispatch);
                $dispatchFailed++;
            }
        }

        return [
            'recovered' => $recovered,
            'dispatch_failed' => $dispatchFailed,
        ];
    }

    private function reserve(int $runId): ?KnowledgeFactGenerationRecoveryDispatch
    {
        return DB::transaction(function () use ($runId): ?KnowledgeFactGenerationRecoveryDispatch {
            $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run instanceof KnowledgeFactGenerationRun || ! $this->isRecoverable($run)) {
                return null;
            }
            $library = KnowledgeFactLibrary::query()->whereKey($run->library_id)->lockForUpdate()->firstOrFail();
            if ($run->cancel_requested_at !== null) {
                $run->forceFill([
                    'status' => KnowledgeFactGenerationRun::STATUS_CANCELLED,
                    'active_key' => null,
                    'finalizer_lease_token' => null,
                    'finalizer_lease_expires_at' => null,
                    'retryable_failure' => false,
                    'cancelled_at' => now(),
                ])->save();
                $library->forceFill(['workflow_status' => 'idle'])->save();

                return null;
            }
            if ((string) $run->status === 'partial') {
                $run->forceFill([
                    'active_key' => null,
                    'job_batch_id' => null,
                    'finalizer_lease_token' => null,
                    'finalizer_lease_expires_at' => null,
                    'retryable_failure' => false,
                ])->save();
                $library->forceFill(['workflow_status' => 'review_required'])->save();

                return null;
            }
            if (! (bool) $run->retryable_failure) {
                $this->failPermanently($run, $library, self::PERMANENT_FAILURE);

                return null;
            }
            try {
                if (! $this->executionGuard->identityComplete($run)) {
                    throw AiModelAccessException::configAccessRevokedForAdminId(
                        (int) ($run->model_access_admin_id ?? 0),
                    );
                }
                $this->executionGuard->assertFrozenIdentityCurrent(
                    $run,
                    (int) $run->requested_ai_model_id,
                );
            } catch (AiModelAccessException $exception) {
                $this->failPermanently($run, $library, $exception->getErrorCode());

                return null;
            }

            $library->load('knowledgeBase');
            if (! hash_equals(
                (string) $run->source_hash,
                $library->knowledgeBase->servingChunkSourceHash(),
            ) || (int) $run->base_working_version !== (int) $library->working_version) {
                $run->forceFill([
                    'status' => KnowledgeFactGenerationRun::STATUS_OBSOLETE,
                    'active_key' => null,
                    'finalizer_lease_token' => null,
                    'finalizer_lease_expires_at' => null,
                    'retryable_failure' => false,
                    'completed_at' => now(),
                ])->save();
                $library->forceFill(['workflow_status' => 'review_required'])->save();

                return null;
            }

            $activeKey = 'knowledge-fact-library:'.$library->id;
            if (KnowledgeFactGenerationRun::query()
                ->where('active_key', $activeKey)
                ->whereKeyNot($run->id)
                ->exists()) {
                $this->failPermanently(
                    $run,
                    $library,
                    'knowledge_fact_generation_recovery_superseded',
                );

                return null;
            }

            $oldClaims = (array) $run->batch_claims_json;
            if ($oldClaims !== [] && $this->claimsAreCompleted($oldClaims)) {
                $executionAttempt = (int) $run->execution_attempt;
                $finalizerToken = trim((string) $run->finalizer_lease_token);
                if ($finalizerToken === '') {
                    $finalizerToken = (string) Str::uuid7();
                }
                $this->refreshFinalizerPending(
                    $run,
                    $library,
                    $executionAttempt,
                    $finalizerToken,
                );

                return new KnowledgeFactGenerationRecoveryDispatch(
                    (int) $run->id,
                    $executionAttempt,
                    $finalizerToken,
                    [],
                );
            }

            if ($this->recoveryAttemptsExhausted($run)) {
                $this->failPermanently($run, $library, self::ATTEMPTS_EXHAUSTED);

                return null;
            }

            $nextAttempt = (int) $run->execution_attempt + 1;
            $finalizerToken = (string) Str::uuid7();

            $groups = $this->evidenceGroups($run, $library);
            if ($groups === []) {
                $this->failPermanently($run, $library, 'knowledge_fact_generation_no_evidence');

                return null;
            }

            $claims = [];
            $batches = [];
            $result = (array) $run->result_json;
            foreach ($groups as $index => $evidence) {
                $sequence = $index + 1;
                $inputHash = hash('sha256', json_encode(
                    $evidence,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
                ));
                $oldClaim = (array) ($oldClaims[(string) $sequence] ?? []);
                if ((string) ($oldClaim['status'] ?? '') === 'completed'
                    && hash_equals((string) ($oldClaim['input_hash'] ?? ''), $inputHash)) {
                    $oldClaim['execution_attempt'] = $nextAttempt;
                    $oldClaim['dispatch_token'] = null;
                    $oldClaim['lease_token'] = null;
                    $oldClaim['lease_expires_at'] = null;
                    $claims[(string) $sequence] = $oldClaim;

                    continue;
                }
                $attemptCount = max(0, (int) ($oldClaim['attempt_count'] ?? 0));
                if ($attemptCount >= (int) config('geoflow.knowledge_fact_generation_max_batch_attempts', 3)) {
                    $this->failPermanently($run, $library, self::BATCH_ATTEMPTS_EXHAUSTED);

                    return null;
                }

                $claimToken = (string) Str::uuid7();
                $claims[(string) $sequence] = [
                    'input_hash' => $inputHash,
                    'status' => 'queued',
                    'dispatch_token' => $claimToken,
                    'execution_attempt' => $nextAttempt,
                    'attempt_count' => $attemptCount,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                ];
                unset($result['batches'][(string) $sequence]);
                $batches[] = [
                    'sequence' => $sequence,
                    'input_hash' => $inputHash,
                    'evidence' => $evidence,
                    'claim_token' => $claimToken,
                ];
            }
            if ($batches === []) {
                $this->freezeAttempt($run, $library, $nextAttempt, $claims, $finalizerToken);
                $this->markFinalizerPending($run);

                return new KnowledgeFactGenerationRecoveryDispatch(
                    (int) $run->id,
                    $nextAttempt,
                    $finalizerToken,
                    [],
                );
            }

            $run->forceFill(['result_json' => $result])->save();
            $this->freezeAttempt($run, $library, $nextAttempt, $claims, $finalizerToken);

            return new KnowledgeFactGenerationRecoveryDispatch(
                (int) $run->id,
                $nextAttempt,
                $finalizerToken,
                $batches,
            );
        }, 3);
    }

    private function isRecoverable(KnowledgeFactGenerationRun $run): bool
    {
        if (in_array((string) $run->status, [
            KnowledgeFactGenerationRun::STATUS_FAILED,
            'partial',
        ], true)) {
            return (bool) $run->retryable_failure
                && $run->updated_at?->lte(now()->subSeconds($this->recoveryStaleSeconds())) === true;
        }
        if (! $run->isActive()) {
            return false;
        }
        if ($run->finalizer_lease_expires_at?->isFuture()) {
            return false;
        }

        $claims = (array) $run->batch_claims_json;
        $runningClaims = collect($claims)->where('status', 'running');
        if ($runningClaims->isNotEmpty()) {
            return $runningClaims->every(
                fn (mixed $claim): bool => $this->leaseIsExpiredOrInvalid(
                    data_get($claim, 'lease_expires_at'),
                ),
            );
        }
        if (collect($claims)->contains('status', 'queued') && $this->hasLivePendingBatch($run)) {
            return false;
        }

        return $run->updated_at?->lte(now()->subSeconds($this->recoveryStaleSeconds())) === true;
    }

    private function hasLivePendingBatch(KnowledgeFactGenerationRun $run): bool
    {
        $jobBatchId = trim((string) ($run->job_batch_id ?? ''));
        if ($jobBatchId === '') {
            return false;
        }

        try {
            $batch = Bus::findBatch($jobBatchId);
            if ($batch === null || $batch->finished() || $batch->cancelled()) {
                return false;
            }
            if ($batch->createdAt->lte(now()->subSeconds($this->pendingBatchMaxAgeSeconds()))) {
                try {
                    $batch->cancel();
                } catch (Throwable) {
                    // The new execution attempt and claim token fence any stale queued payload.
                }

                return false;
            }

            return true;
        } catch (Throwable) {
            return $run->updated_at?->gt(
                now()->subSeconds($this->pendingBatchMaxAgeSeconds()),
            ) === true;
        }
    }

    private function leaseIsExpiredOrInvalid(mixed $expiresAt): bool
    {
        if (! is_string($expiresAt) || trim($expiresAt) === '') {
            return true;
        }

        try {
            return now()->parse($expiresAt)->isPast();
        } catch (Throwable) {
            return true;
        }
    }

    private function recoveryAttemptsExhausted(KnowledgeFactGenerationRun $run): bool
    {
        $usedRecoveryAttempts = max(0, (int) $run->execution_attempt - 1);

        return $usedRecoveryAttempts >= (int) config(
            'geoflow.knowledge_fact_generation_max_recovery_attempts',
            3,
        );
    }

    /**
     * @return list<list<array<string, string>>>
     */
    private function evidenceGroups(
        KnowledgeFactGenerationRun $run,
        KnowledgeFactLibrary $library,
    ): array {
        $servingGeneration = trim((string) $library->knowledgeBase->chunk_serving_generation);
        $evidence = $library->knowledgeBase->chunks()
            ->when(
                $servingGeneration !== '',
                fn ($query) => $query->where('generation_key', $servingGeneration),
                fn ($query) => $query->whereNull('generation_key'),
            )
            ->select(['id', 'knowledge_base_id', 'content_hash'])
            ->orderBy('id')
            ->get()
            ->map(static fn ($chunk): array => [
                'evidence_key' => 'chunk:'.$chunk->id.':'.substr((string) $chunk->content_hash, 0, 12),
                'chunk_id' => (string) $chunk->id,
                'content_hash' => (string) $chunk->content_hash,
            ])
            ->values()
            ->all();
        if ($evidence === []) {
            return [];
        }

        $batchSize = (int) config('geoflow.knowledge_fact_generation_batch_size', 25);
        $generationLimit = (int) data_get(
            $run->batch_meta_json,
            'generation_limit',
            $run->target_count,
        );
        $jobCount = min(8, max(1, (int) ceil($generationLimit / $batchSize)));

        return array_slice(
            array_chunk($evidence, max(1, (int) ceil(count($evidence) / $jobCount))),
            0,
            $jobCount,
        );
    }

    /** @param array<string, mixed> $claims */
    private function claimsAreCompleted(array $claims): bool
    {
        return collect($claims)->every(
            static fn (mixed $claim): bool => (string) data_get($claim, 'status') === 'completed',
        );
    }

    /** @param array<string, mixed> $claims */
    private function freezeAttempt(
        KnowledgeFactGenerationRun $run,
        KnowledgeFactLibrary $library,
        int $executionAttempt,
        array $claims,
        string $finalizerToken,
    ): void {
        $run->forceFill([
            'status' => KnowledgeFactGenerationRun::STATUS_RUNNING,
            'active_key' => 'knowledge-fact-library:'.$library->id,
            'execution_attempt' => $executionAttempt,
            'job_batch_id' => null,
            'batch_claims_json' => $claims,
            'finalizer_lease_token' => $finalizerToken,
            'finalizer_lease_expires_at' => null,
            'error_code' => null,
            'error_message' => null,
            'retryable_failure' => true,
            'failed_at' => null,
        ])->save();
        $library->forceFill(['workflow_status' => 'generating'])->save();
    }

    private function markFinalizerPending(KnowledgeFactGenerationRun $run): void
    {
        $run->forceFill([
            'finalizer_lease_expires_at' => now()->addSeconds(
                $this->finalizerPendingSeconds(),
            ),
        ])->save();
    }

    private function refreshFinalizerPending(
        KnowledgeFactGenerationRun $run,
        KnowledgeFactLibrary $library,
        int $executionAttempt,
        string $finalizerToken,
    ): void {
        $run->forceFill([
            'status' => KnowledgeFactGenerationRun::STATUS_RUNNING,
            'active_key' => 'knowledge-fact-library:'.$library->id,
            'execution_attempt' => $executionAttempt,
            'job_batch_id' => null,
            'finalizer_lease_token' => $finalizerToken,
            'finalizer_lease_expires_at' => now()->addSeconds(
                $this->finalizerPendingSeconds(),
            ),
            'error_code' => null,
            'error_message' => null,
            'retryable_failure' => true,
            'failed_at' => null,
        ])->save();
        $library->forceFill(['workflow_status' => 'generating'])->save();
    }

    private function failPermanently(
        KnowledgeFactGenerationRun $run,
        KnowledgeFactLibrary $library,
        string $errorCode,
    ): void {
        $run->forceFill([
            'status' => KnowledgeFactGenerationRun::STATUS_FAILED,
            'active_key' => null,
            'job_batch_id' => null,
            'finalizer_lease_token' => null,
            'finalizer_lease_expires_at' => null,
            'error_code' => $errorCode,
            'error_message' => $errorCode,
            'retryable_failure' => false,
            'failed_at' => now(),
        ])->save();
        $library->forceFill(['workflow_status' => 'failed'])->save();
    }

    private function recordDispatched(
        KnowledgeFactGenerationRecoveryDispatch $dispatch,
        ?string $jobBatchId,
    ): void {
        if ($jobBatchId === null) {
            return;
        }

        KnowledgeFactGenerationRun::query()
            ->whereKey($dispatch->runId)
            ->where('execution_attempt', $dispatch->executionAttempt)
            ->where('finalizer_lease_token', $dispatch->finalizerToken)
            ->whereIn('status', KnowledgeFactGenerationRun::ACTIVE_STATUSES)
            ->update([
                'job_batch_id' => $jobBatchId,
                'updated_at' => now(),
            ]);
    }

    private function recordDispatchFailure(
        KnowledgeFactGenerationRecoveryDispatch $dispatch,
    ): void {
        KnowledgeFactGenerationRun::query()
            ->whereKey($dispatch->runId)
            ->where('execution_attempt', $dispatch->executionAttempt)
            ->where('finalizer_lease_token', $dispatch->finalizerToken)
            ->whereIn('status', KnowledgeFactGenerationRun::ACTIVE_STATUSES)
            ->update([
                'job_batch_id' => null,
                'finalizer_lease_expires_at' => null,
                'error_code' => self::DISPATCH_FAILED,
                'error_message' => self::DISPATCH_FAILED,
                'retryable_failure' => true,
                'updated_at' => now(),
            ]);
    }

    private function recoveryStaleSeconds(): int
    {
        return max(1, min(3600, (int) config(
            'geoflow.knowledge_fact_generation_recovery_stale_seconds',
            300,
        )));
    }

    private function finalizerPendingSeconds(): int
    {
        return max(60, min(3600, (int) config(
            'geoflow.knowledge_fact_generation_finalizer_pending_seconds',
            900,
        )));
    }

    private function pendingBatchMaxAgeSeconds(): int
    {
        return max(60, min(86400, (int) config(
            'geoflow.knowledge_fact_generation_pending_batch_max_age_seconds',
            900,
        )));
    }
}
