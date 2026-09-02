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
            ->whereIn('status', KnowledgeFactGenerationRun::ACTIVE_STATUSES)
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
                    'cancelled_at' => now(),
                ])->save();
                $library->forceFill(['workflow_status' => 'idle'])->save();

                return null;
            }
            if (! (bool) $run->retryable_failure) {
                $this->failPermanently($run, $library, self::PERMANENT_FAILURE);

                return null;
            }
            if ($this->recoveryAttemptsExhausted($run)) {
                $this->failPermanently($run, $library, self::ATTEMPTS_EXHAUSTED);

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
                    'completed_at' => now(),
                ])->save();
                $library->forceFill(['workflow_status' => 'review_required'])->save();

                return null;
            }

            $nextAttempt = (int) $run->execution_attempt + 1;
            $finalizerToken = (string) Str::uuid7();
            $oldClaims = (array) $run->batch_claims_json;
            if ($oldClaims !== [] && $this->claimsAreTerminal($oldClaims)) {
                $claims = collect($oldClaims)
                    ->map(function (mixed $claim) use ($nextAttempt): array {
                        $normalized = (array) $claim;
                        $normalized['execution_attempt'] = $nextAttempt;
                        $normalized['dispatch_token'] = null;
                        $normalized['lease_token'] = null;
                        $normalized['lease_expires_at'] = null;

                        return $normalized;
                    })
                    ->all();
                $this->freezeAttempt($run, $library, $nextAttempt, $claims, $finalizerToken);

                return new KnowledgeFactGenerationRecoveryDispatch(
                    (int) $run->id,
                    $nextAttempt,
                    $finalizerToken,
                    [],
                );
            }

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
                if (in_array((string) ($oldClaim['status'] ?? ''), ['completed', 'failed'], true)
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

            return $batch !== null && ! $batch->finished() && ! $batch->cancelled();
        } catch (Throwable) {
            return true;
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
    private function claimsAreTerminal(array $claims): bool
    {
        return collect($claims)->every(
            static fn (mixed $claim): bool => in_array(
                (string) data_get($claim, 'status'),
                ['completed', 'failed'],
                true,
            ),
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
}
