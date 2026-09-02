<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Data\Ai\KnowledgeFactGenerationExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\KnowledgeFactAiGenerationException;
use App\Exceptions\KnowledgeFactFinalizationException;
use App\Exceptions\PermanentAiProviderException;
use App\Jobs\GenerateKnowledgeFactBatchJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\KnowledgeChunk;
use App\Models\KnowledgeFactGenerationRun;
use App\Models\KnowledgeFactLibrary;
use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use App\Support\GeoFlow\AiModelFailoverDecider;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

class KnowledgeFactGenerationCoordinator
{
    public function __construct(
        private readonly KnowledgeFactAiGenerator $generator,
        private readonly AiWorkspaceModelReadiness $readiness,
        private readonly KnowledgeFactValuePolicy $valuePolicy,
        private readonly KnowledgeFactGenerationAiExecutionGuard $executionGuard,
        private readonly KnowledgeFactGenerationModelRateLimiter $modelRateLimiter,
        private readonly AiModelFailoverDecider $failoverDecider,
        private readonly AiExecutionErrorSanitizer $errorSanitizer,
    ) {}

    public function start(KnowledgeFactLibrary $library, AiModel|int $model, Admin $admin, string $mode, int $targetCount, ?string $requestKey = null): KnowledgeFactGenerationRun
    {
        if (! in_array($mode, ['initial', 'supplement', 'refresh_stale'], true)) {
            throw ValidationException::withMessages(['mode' => 'knowledge_fact_generation_mode_invalid']);
        }
        $targetCount = max(1, min((int) config('geoflow.knowledge_fact_generation_max_per_run', 200), $targetCount));
        $requestKey ??= (string) Str::uuid();
        $requestedModelId = $model instanceof AiModel ? (int) $model->getKey() : $model;
        $run = DB::transaction(function () use ($library, $requestedModelId, $admin, $mode, $targetCount, $requestKey): KnowledgeFactGenerationRun {
            $locked = KnowledgeFactLibrary::query()->whereKey($library->id)->lockForUpdate()->firstOrFail();
            $locked->load('knowledgeBase');
            $existingRun = $locked->generationRuns()->where('request_key', $requestKey)->first();
            if ($existingRun instanceof KnowledgeFactGenerationRun) {
                if ($existingRun->mode !== $mode || (int) $existingRun->target_count !== $targetCount || (int) $existingRun->ai_model_id !== $requestedModelId) {
                    throw new ConflictHttpException('knowledge_fact_generation_idempotency_conflict');
                }

                return $existingRun;
            }
            $identity = $this->executionGuard->snapshotForCreation($admin, $requestedModelId);
            $servingSourceHash = $locked->knowledgeBase->servingChunkSourceHash();
            if ($locked->knowledgeBase->chunk_sync_status !== 'ready'
                || $servingSourceHash === '') {
                throw ValidationException::withMessages(['library' => 'knowledge_chunks_not_ready']);
            }
            if (KnowledgeFactGenerationRun::query()->where('active_key', $this->activeKey($locked->id))->exists()) {
                throw new ConflictHttpException('knowledge_fact_generation_active');
            }
            $lockedModel = AiModel::query()->whereKey($identity['requested_ai_model_id'])->lockForUpdate()->firstOrFail();
            if (! in_array((string) ($lockedModel->model_type ?? ''), ['', 'chat'], true)
                || ! $this->readiness->canAttempt($lockedModel)) {
                throw ValidationException::withMessages(['ai_model_id' => 'knowledge_fact_generation_model_unavailable']);
            }
            $existingCount = $locked->facts()->where('is_enabled', true)->count();
            if ($mode === 'initial' && $existingCount > 0) {
                throw new ConflictHttpException('knowledge_fact_generation_initial_requires_empty_library');
            }
            $generationLimit = $mode === 'supplement' ? max(0, $targetCount - $existingCount) : $targetCount;
            $run = $locked->generationRuns()->create([
                'mode' => $mode, 'target_count' => $targetCount, 'source_hash' => $servingSourceHash,
                'base_working_version' => $locked->working_version, 'status' => 'queued', 'ai_model_id' => $lockedModel->id,
                'created_by_admin_id' => $admin->id, 'request_key' => $requestKey, 'active_key' => $this->activeKey($locked->id),
                'result_json' => ['candidates' => [], 'conflicts' => [], 'batches' => []],
                'batch_meta_json' => ['generation_limit' => $generationLimit, 'existing_count' => $existingCount],
            ]);
            $run->forceFill([
                ...$identity,
                'execution_attempt' => 1,
                'retryable_failure' => true,
            ])->save();
            $locked->forceFill(['workflow_status' => 'generating'])->save();

            return $run;
        }, 3);

        if (! $run->isActive() || $run->job_batch_id !== null) {
            return $run;
        }
        if ((int) data_get($run->batch_meta_json, 'generation_limit', $run->target_count) === 0) {
            $this->completeWithoutGeneration($run->id);

            return $run->fresh();
        }

        $claimed = KnowledgeFactGenerationRun::query()
            ->whereKey($run->id)
            ->where('status', 'queued')
            ->whereNull('job_batch_id')
            ->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);
        if ($claimed !== 1) {
            return $run->fresh();
        }

        try {
            $this->dispatch($run->fresh());
        } catch (Throwable $exception) {
            $this->failRun($run->id, 'knowledge_fact_generation_dispatch_failed');

            throw $exception;
        }

        return $run->fresh();
    }

    public function dispatch(KnowledgeFactGenerationRun $run): void
    {
        if ($run->job_batch_id !== null) {
            return;
        }
        $library = $run->library()->with('knowledgeBase')->firstOrFail();
        $servingGeneration = trim((string) $library->knowledgeBase->chunk_serving_generation);
        $chunks = $library->knowledgeBase->chunks()
            ->when(
                $servingGeneration !== '',
                fn ($query) => $query->where('generation_key', $servingGeneration),
                fn ($query) => $query->whereNull('generation_key'),
            )
            ->select(['id', 'knowledge_base_id', 'content_hash'])
            ->orderBy('id')
            ->get();
        $evidence = $chunks->map(fn ($chunk) => [
            'evidence_key' => 'chunk:'.$chunk->id.':'.substr((string) $chunk->content_hash, 0, 12),
            'chunk_id' => (string) $chunk->id, 'content_hash' => (string) $chunk->content_hash,
        ])->values()->all();
        if ($evidence === []) {
            $this->failRun($run->id, 'knowledge_fact_generation_no_evidence');

            return;
        }
        $batchSize = (int) config('geoflow.knowledge_fact_generation_batch_size', 25);
        $generationLimit = (int) data_get($run->batch_meta_json, 'generation_limit', $run->target_count);
        $jobCount = min(8, max(1, (int) ceil($generationLimit / $batchSize)));
        $groups = array_chunk($evidence, max(1, (int) ceil(count($evidence) / $jobCount)));
        $jobs = [];
        $claims = [];
        foreach (array_slice($groups, 0, $jobCount) as $index => $group) {
            $sequence = $index + 1;
            $hash = hash('sha256', json_encode($group, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $claims[(string) $sequence] = [
                'input_hash' => $hash,
                'status' => 'queued',
                'dispatch_token' => (string) Str::uuid7(),
                'execution_attempt' => (int) $run->execution_attempt,
                'attempt_count' => 0,
            ];
        }
        $finalizerToken = (string) Str::uuid7();
        $registered = DB::transaction(function () use ($run, $claims, $finalizerToken): ?KnowledgeFactGenerationRun {
            $locked = KnowledgeFactGenerationRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if (! $locked instanceof KnowledgeFactGenerationRun
                || ! $locked->isActive()
                || $locked->cancel_requested_at !== null
                || $locked->job_batch_id !== null
                || (array) $locked->batch_claims_json !== []) {
                return null;
            }
            $locked->forceFill([
                'batch_claims_json' => $claims,
                'finalizer_lease_token' => $finalizerToken,
                'finalizer_lease_expires_at' => null,
            ])->save();

            return $locked;
        }, 3);
        if (! $registered instanceof KnowledgeFactGenerationRun) {
            return;
        }
        foreach (array_slice($groups, 0, $jobCount) as $index => $group) {
            $sequence = $index + 1;
            $claim = $claims[(string) $sequence];
            $job = new GenerateKnowledgeFactBatchJob(
                $run->id,
                $sequence,
                (string) $claim['input_hash'],
                $group,
                (int) $run->execution_attempt,
                (string) $claim['dispatch_token'],
            );
            $job->afterCommit();
            $jobs[] = $job;
        }
        $runId = $run->id;
        $executionAttempt = (int) $run->execution_attempt;
        $batch = Bus::batch($jobs)->name("knowledge-facts:{$runId}")->allowFailures()->finally(static function (Batch $batch) use ($runId, $executionAttempt, $finalizerToken): void {
            app(KnowledgeFactGenerationFinalizerDispatcher::class)->dispatch(
                $runId,
                $executionAttempt,
                $finalizerToken,
            );
        })->onQueue('knowledge')->dispatch();
        KnowledgeFactGenerationRun::query()
            ->whereKey($runId)
            ->whereIn('status', ['queued', 'running'])
            ->where('execution_attempt', $executionAttempt)
            ->where('finalizer_lease_token', $finalizerToken)
            ->update(['job_batch_id' => $batch->id, 'updated_at' => now()]);
    }

    /** @param list<array<string,string>> $evidence */
    public function processBatch(
        int $runId,
        int $sequence,
        string $inputHash,
        array $evidence,
        ?int $executionAttempt = null,
        ?string $claimToken = null,
    ): void {
        if ($executionAttempt === null || $claimToken === null || $claimToken === '') {
            return;
        }
        $context = $this->claimBatch(
            $runId,
            $sequence,
            $inputHash,
            $executionAttempt,
            $claimToken,
        );
        if (! $context instanceof KnowledgeFactGenerationExecutionContext) {
            return;
        }
        $this->processClaimedBatch($context, $evidence);
    }

    public function claimBatch(
        int $runId,
        int $sequence,
        string $inputHash,
        int $executionAttempt,
        string $claimToken,
        ?string $newLeaseToken = null,
    ): ?KnowledgeFactGenerationExecutionContext {
        return $this->executionGuard->claimBatch(
            $runId,
            $sequence,
            $inputHash,
            $executionAttempt,
            $claimToken,
            $newLeaseToken,
        );
    }

    /** @param list<array<string,string>> $evidence */
    public function processClaimedBatch(
        KnowledgeFactGenerationExecutionContext $context,
        array $evidence,
    ): void {
        $runId = $context->runId;
        $sequence = $context->batchSequence;
        $inputHash = $context->inputHash;
        $run = KnowledgeFactGenerationRun::query()->with(['library.knowledgeBase'])->findOrFail($runId);
        if (! hash_equals((string) $run->source_hash, $run->library->knowledgeBase->servingChunkSourceHash())
            || (int) $run->base_working_version !== (int) $run->library->working_version) {
            $this->markObsolete($context);

            return;
        }
        $chunkIds = array_map('intval', array_column($evidence, 'chunk_id'));
        $servingGeneration = trim((string) $run->library->knowledgeBase->chunk_serving_generation);
        $chunks = KnowledgeChunk::query()->where('knowledge_base_id', $run->library->knowledge_base_id)->whereIn('id', $chunkIds)
            ->when(
                $servingGeneration !== '',
                fn ($query) => $query->where('generation_key', $servingGeneration),
                fn ($query) => $query->whereNull('generation_key'),
            )
            ->get(['id', 'content_hash', 'source_hash', 'section_path', 'content'])->keyBy('id');
        $hydratedEvidence = [];
        foreach ($evidence as $descriptor) {
            $chunk = $chunks->get((int) $descriptor['chunk_id']);
            if (! $chunk || ! hash_equals((string) $descriptor['content_hash'], (string) $chunk->content_hash)) {
                $this->markObsolete($context);

                return;
            }
            $hydratedEvidence[] = [
                ...$descriptor,
                'source_hash' => (string) $chunk->source_hash,
                'section_path' => (string) $chunk->section_path,
                'content' => mb_substr((string) $chunk->content, 0, 6000),
            ];
        }
        $generationLimit = (int) data_get($run->batch_meta_json, 'generation_limit', $run->target_count);
        $candidates = $this->executionGuard->resolveCandidates($context);
        $facts = null;
        $resolvedModel = null;
        $usageRequestId = (string) Str::uuid();
        foreach ($candidates as $candidateIndex => $candidate) {
            $currentModel = $this->executionGuard->registerCandidate($context, $candidate);
            $this->modelRateLimiter->reserve($currentModel);
            try {
                $persistedModel = null;
                $facts = $this->generator->generate(
                    $currentModel,
                    $hydratedEvidence,
                    min((int) config('geoflow.knowledge_fact_generation_batch_size', 25), $generationLimit),
                    $context,
                    function (array $generatedFacts, AiModel $providerModel) use ($run, $context, $sequence, $inputHash, &$persistedModel): array {
                        $persistedModel = $providerModel;

                        return $this->persistBatchResult(
                            $run,
                            $context,
                            $sequence,
                            $inputHash,
                            $generatedFacts,
                            $providerModel,
                        );
                    },
                    $usageRequestId,
                    'candidate-'.($candidateIndex + 1).'-batch-'.$context->batchAttempt,
                );
                $resolvedModel = $persistedModel ?? $currentModel;

                break;
            } catch (Throwable $exception) {
                if ($exception instanceof AiModelAccessException) {
                    throw $exception;
                }
                $hasNextCandidate = $candidateIndex < $candidates->count() - 1;
                $retryable = $exception instanceof KnowledgeFactAiGenerationException
                    ? $exception->retryable
                    : $this->failoverDecider->shouldFailover($exception);
                if ($retryable && $hasNextCandidate) {
                    continue;
                }
                if (! $retryable) {
                    throw PermanentAiProviderException::fromProviderFailure($exception);
                }

                throw $exception;
            }
        }
        if (! is_array($facts) || ! $resolvedModel instanceof AiModel) {
            throw new \RuntimeException('knowledge_fact_generation_model_unavailable');
        }
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @return list<array<string,mixed>>
     */
    private function persistBatchResult(
        KnowledgeFactGenerationRun $run,
        KnowledgeFactGenerationExecutionContext $context,
        int $sequence,
        string $inputHash,
        array $facts,
        AiModel $resolvedModel,
    ): array {
        $existingKeys = $run->library->facts()->where('is_enabled', true)->pluck('stable_key')->all();
        if ($run->mode === 'supplement') {
            $facts = array_values(array_filter($facts, fn (array $fact): bool => ! in_array($fact['stable_key'], $existingKeys, true)));
        } elseif ($run->mode === 'refresh_stale') {
            $facts = array_values(array_filter($facts, fn (array $fact): bool => in_array($fact['stable_key'], $existingKeys, true)));
        }

        $admin = $this->executionGuard->assertCurrent($context, $resolvedModel);
        $locked = KnowledgeFactGenerationRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
        if (! $locked->isActive() || $locked->cancel_requested_at !== null) {
            throw AiModelAccessException::configAccessRevoked($admin);
        }
        $result = (array) $locked->result_json;
        $result['candidates'] = array_slice(array_merge((array) ($result['candidates'] ?? []), $facts), 0, 200);
        $source = (int) $resolvedModel->owner_admin_id === (int) $admin->getKey()
            ? 'personal'
            : 'shared';
        $result['batches'][(string) $sequence] = [
            'input_hash' => $inputHash,
            'status' => 'completed',
            'candidate_count' => count($facts),
            'resolved_ai_model_id' => (int) $resolvedModel->getKey(),
            'resolved_model_source' => $source,
        ];
        $claims = (array) $locked->batch_claims_json;
        $claim = (array) ($claims[(string) $sequence] ?? []);
        $claim['status'] = 'completed';
        $claim['candidate_count'] = count($facts);
        $claim['resolved_ai_model_id'] = (int) $resolvedModel->getKey();
        $claim['resolved_model_source'] = $source;
        $claim['lease_token'] = null;
        $claim['lease_expires_at'] = null;
        $claims[(string) $sequence] = $claim;
        $updates = [
            'result_json' => $result,
            'batch_claims_json' => $claims,
            'result_hash' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        ];
        if ($locked->resolved_ai_model_id === null) {
            $updates += [
                'resolved_ai_model_id' => (int) $resolvedModel->getKey(),
                'resolved_model_source' => $source,
                'model_resolved_at' => now(),
            ];
        }
        $locked->forceFill($updates)->save();

        return $facts;
    }

    public function releaseBatchForRetry(
        KnowledgeFactGenerationExecutionContext $context,
        bool $refundAttempt = false,
    ): void {
        $this->executionGuard->releaseBatchForRetry($context, $refundAttempt);
    }

    public function recordBatchFailure(
        int $runId,
        int $sequence,
        string $inputHash,
        ?Throwable $exception,
        ?int $executionAttempt = null,
        ?string $claimToken = null,
        bool $retryable = true,
        ?string $batchLeaseToken = null,
        ?int $batchAttempt = null,
    ): void {
        DB::transaction(function () use ($runId, $sequence, $inputHash, $exception, $executionAttempt, $claimToken, $retryable, $batchLeaseToken, $batchAttempt): void {
            $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || ! $run->isActive() || $executionAttempt === null || $claimToken === null
                || $batchAttempt === null || $batchAttempt < 0
                || (int) $run->execution_attempt !== $executionAttempt) {
                return;
            }
            $claims = (array) $run->batch_claims_json;
            $claim = (array) ($claims[(string) $sequence] ?? []);
            if (($claim['input_hash'] ?? null) !== $inputHash
                || (int) ($claim['execution_attempt'] ?? 0) !== $executionAttempt
                || (int) ($claim['attempt_count'] ?? 0) !== $batchAttempt
                || (string) ($claim['dispatch_token'] ?? '') === ''
                || $claimToken === ''
                || ! hash_equals((string) $claim['dispatch_token'], $claimToken)) {
                return;
            }
            if ($batchLeaseToken !== null) {
                if (($claim['status'] ?? null) !== 'running'
                    || (string) ($claim['lease_token'] ?? '') === ''
                    || ! hash_equals((string) $claim['lease_token'], $batchLeaseToken)) {
                    return;
                }
            } elseif (($claim['status'] ?? null) !== 'queued') {
                return;
            }
            $result = (array) $run->result_json;
            $result['batches'][(string) $sequence] = ['input_hash' => $inputHash, 'status' => 'failed'];
            $claim['status'] = 'failed';
            $claim['lease_token'] = null;
            $claim['lease_expires_at'] = null;
            $claim['dispatch_token'] = null;
            $claims[(string) $sequence] = $claim;
            $errorCode = match (true) {
                $exception instanceof AiModelAccessException => $exception->getErrorCode(),
                $exception instanceof PermanentAiProviderException => $exception->getErrorCode(),
                default => 'knowledge_fact_generation_batch_failed',
            };
            $updates = [
                'result_json' => $result,
                'batch_claims_json' => $claims,
                'error_code' => $errorCode,
                'error_message' => $this->errorSanitizer->sanitize($errorCode, 'knowledge_fact_generation_batch_failed'),
                'retryable_failure' => $retryable,
            ];
            if (! $retryable) {
                $updates += [
                    'status' => KnowledgeFactGenerationRun::STATUS_FAILED,
                    'active_key' => null,
                    'failed_at' => now(),
                ];
            }
            $run->forceFill($updates)->save();
            if (! $retryable) {
                $run->library()->update(['workflow_status' => 'failed']);
            }
        }, 3);
    }

    public function finalize(
        int $runId,
        ?int $executionAttempt = null,
        ?string $leaseToken = null,
    ): void {
        try {
            $this->finalizeTransaction($runId, $executionAttempt, $leaseToken);
        } catch (Throwable) {
            throw new KnowledgeFactFinalizationException;
        }
    }

    private function finalizeTransaction(
        int $runId,
        ?int $executionAttempt,
        ?string $leaseToken,
    ): void {
        DB::transaction(function () use ($runId, $executionAttempt, $leaseToken): void {
            $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            if (! $run->isActive()
                || $executionAttempt === null
                || $leaseToken === null
                || $leaseToken === ''
                || (int) $run->execution_attempt !== $executionAttempt
                || (string) $run->finalizer_lease_token === ''
                || ! hash_equals((string) $run->finalizer_lease_token, $leaseToken)) {
                return;
            }
            $claims = (array) $run->batch_claims_json;
            if (collect($claims)->contains(
                static fn (mixed $claim): bool => in_array(
                    (string) data_get($claim, 'status'),
                    ['queued', 'running'],
                    true,
                ),
            )) {
                throw new \RuntimeException('knowledge_fact_generation_batches_incomplete');
            }
            $run->forceFill([
                'finalizer_lease_expires_at' => now()->addSeconds(60),
            ])->save();
            $library = KnowledgeFactLibrary::query()->whereKey($run->library_id)->lockForUpdate()->firstOrFail();
            try {
                $this->executionGuard->assertFrozenIdentityCurrent($run);
                $resolvedModelIds = collect($claims)
                    ->where('status', 'completed')
                    ->pluck('resolved_ai_model_id')
                    ->filter(static fn (mixed $modelId): bool => (int) $modelId > 0)
                    ->map(static fn (mixed $modelId): int => (int) $modelId)
                    ->unique();
                if ($resolvedModelIds->isEmpty()) {
                    $resolvedModelIds = collect([(int) $run->requested_ai_model_id]);
                }
                foreach ($resolvedModelIds as $modelId) {
                    $this->executionGuard->assertFrozenIdentityCurrent($run, $modelId);
                }
            } catch (AiModelAccessException $exception) {
                $run->forceFill([
                    'status' => KnowledgeFactGenerationRun::STATUS_FAILED,
                    'active_key' => null,
                    'error_code' => $exception->getErrorCode(),
                    'error_message' => $exception->getErrorCode(),
                    'retryable_failure' => false,
                    'finalizer_lease_token' => null,
                    'finalizer_lease_expires_at' => null,
                    'failed_at' => now(),
                ])->save();
                $library->forceFill(['workflow_status' => 'failed'])->save();

                return;
            }
            if ($run->cancel_requested_at !== null) {
                $this->markCancelled($run, $library);

                return;
            }
            $library->load('knowledgeBase');
            if (! hash_equals((string) $run->source_hash, $library->knowledgeBase->servingChunkSourceHash())
                || (int) $run->base_working_version !== (int) $library->working_version) {
                $run->forceFill(['status' => 'obsolete', 'active_key' => null, 'completed_at' => now()])->save();
                $library->forceFill(['workflow_status' => 'review_required'])->save();

                return;
            }
            $result = (array) $run->result_json;
            $conflicts = [];
            $created = 0;
            $generationLimit = (int) data_get($run->batch_meta_json, 'generation_limit', $run->target_count);
            foreach (array_slice((array) ($result['candidates'] ?? []), 0, $generationLimit) as $candidate) {
                if (! is_array($candidate)) {
                    continue;
                }
                if ($library->facts()->where('stable_key', $candidate['stable_key'])->exists()) {
                    $candidate['_candidate_key'] = $this->candidateKey($candidate);
                    $conflicts[] = $candidate;

                    continue;
                }
                $fact = $library->facts()->create([
                    'stable_key' => $candidate['stable_key'], 'label' => $candidate['label'], 'subject' => $candidate['subject'], 'predicate' => $candidate['predicate'],
                    'value_type' => $candidate['value_type'], 'origin_generation_run_id' => $run->id, 'created_by_admin_id' => $run->created_by_admin_id, 'updated_by_admin_id' => $run->created_by_admin_id,
                ]);
                $value = $fact->values()->create([
                    'canonical_value_json' => ['value' => (string) $candidate['canonical_value'], 'unit' => (string) $candidate['unit']],
                    'canonical_answer' => $candidate['canonical_answer'],
                    ...$this->candidateValueMetadata($candidate),
                    'origin_generation_run_id' => $run->id,
                    'created_by_admin_id' => $run->created_by_admin_id, 'updated_by_admin_id' => $run->created_by_admin_id,
                ]);
                foreach (array_values(array_unique((array) ($candidate['evidence_keys'] ?? []))) as $evidenceKey) {
                    if (preg_match('/\Achunk:(\d+):([a-f0-9]{12})\z/', (string) $evidenceKey, $matches) !== 1) {
                        continue;
                    }
                    $chunk = KnowledgeChunk::query()->whereKey((int) $matches[1])->where('knowledge_base_id', $library->knowledge_base_id)->first();
                    if (! $chunk || ! str_starts_with((string) $chunk->content_hash, $matches[2])) {
                        continue;
                    }
                    $excerpt = mb_substr((string) $chunk->content, 0, 5000);
                    $value->evidences()->create([
                        'knowledge_chunk_id' => $chunk->id,
                        'source_hash' => (string) $chunk->source_hash,
                        'content_hash' => (string) $chunk->content_hash,
                        'source_locator_json' => ['section_path' => (string) $chunk->section_path],
                        'excerpt' => $excerpt,
                        'excerpt_hash' => hash('sha256', trim($excerpt)),
                        'is_primary' => true,
                        'created_by_admin_id' => $run->created_by_admin_id,
                    ]);
                }
                $created++;
            }
            $result['conflicts'] = $conflicts;
            $failedBatches = count(array_filter((array) ($result['batches'] ?? []), fn ($batch) => data_get($batch, 'status') === 'failed'));
            $status = $created === 0 && $conflicts === [] ? 'failed' : (($created < $generationLimit || $conflicts !== [] || $failedBatches > 0) ? 'partial' : 'completed');
            $retryableFailure = $status === KnowledgeFactGenerationRun::STATUS_FAILED
                && $failedBatches > 0;
            $run->forceFill([
                'status' => $status,
                'active_key' => null,
                'result_json' => $result,
                'retryable_failure' => $retryableFailure,
                'finalizer_lease_token' => null,
                'finalizer_lease_expires_at' => null,
                $status === 'failed' ? 'failed_at' : 'completed_at' => now(),
            ])->save();
            if ($created > 0) {
                $library->increment('working_version');
            }
            $library->forceFill(['workflow_status' => 'review_required'])->save();
        }, 3);
    }

    public function cancel(KnowledgeFactGenerationRun $run): void
    {
        $batchId = DB::transaction(function () use ($run): ?string {
            $locked = KnowledgeFactGenerationRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isActive()) {
                return null;
            }
            $library = KnowledgeFactLibrary::query()->whereKey($locked->library_id)->lockForUpdate()->firstOrFail();
            $locked->forceFill(['cancel_requested_at' => now()])->save();
            $this->markCancelled($locked, $library);

            return $locked->job_batch_id;
        });
        if ($batchId !== null) {
            Bus::findBatch($batchId)?->cancel();
        }
    }

    public function resolveConflict(int $runId, string $candidateKey, string $action, ?string $newStableKey, Admin $admin): KnowledgeFactGenerationRun
    {
        return DB::transaction(function () use ($runId, $candidateKey, $action, $newStableKey, $admin): KnowledgeFactGenerationRun {
            $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            $result = (array) $run->result_json;
            $conflicts = array_values((array) ($result['conflicts'] ?? []));
            $index = collect($conflicts)->search(fn (mixed $candidate): bool => is_array($candidate) && hash_equals((string) ($candidate['_candidate_key'] ?? $this->candidateKey($candidate)), $candidateKey));
            abort_if($index === false, 404);
            $candidate = $conflicts[$index];

            app(KnowledgeFactEditor::class)->resolveGeneratedCandidate($run->library, $candidate, $action, $newStableKey, $admin, $run->id);
            $result['resolved'][] = ['candidate_key' => $candidateKey, 'action' => $action, 'stable_key' => $action === 'create_with_new_key' ? ($newStableKey ?? '') : $candidate['stable_key']];
            array_splice($conflicts, (int) $index, 1);
            $result['conflicts'] = $conflicts;
            $run->forceFill(['result_json' => $result, 'result_hash' => hash('sha256', json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))])->save();

            return $run->fresh();
        }, 3);
    }

    public function markFinalizeFailure(
        int $runId,
        ?Throwable $exception = null,
        ?int $executionAttempt = null,
        ?string $leaseToken = null,
    ): void {
        if ($executionAttempt === null || $leaseToken === null || $leaseToken === '') {
            return;
        }
        DB::transaction(function () use ($runId, $executionAttempt, $leaseToken): void {
            $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run instanceof KnowledgeFactGenerationRun
                || ! $run->isActive()
                || (int) $run->execution_attempt !== $executionAttempt
                || (string) $run->finalizer_lease_token === ''
                || ! hash_equals((string) $run->finalizer_lease_token, $leaseToken)) {
                return;
            }
            $library = KnowledgeFactLibrary::query()->whereKey($run->library_id)->lockForUpdate()->firstOrFail();
            $run->forceFill([
                'status' => KnowledgeFactGenerationRun::STATUS_FAILED,
                'active_key' => null,
                'error_code' => 'knowledge_fact_generation_finalize_failed',
                'error_message' => $this->errorSanitizer->sanitize(
                    'knowledge_fact_generation_finalize_failed',
                ),
                'retryable_failure' => true,
                'finalizer_lease_expires_at' => null,
                'failed_at' => now(),
            ])->save();
            $library->forceFill(['workflow_status' => 'failed'])->save();
        }, 3);
    }

    private function failRun(int $runId, string $code, ?Throwable $exception = null): void
    {
        DB::transaction(function () use ($runId, $code, $exception): void {
            $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || ! $run->isActive()) {
                return;
            }
            $library = KnowledgeFactLibrary::query()->whereKey($run->library_id)->lockForUpdate()->firstOrFail();
            $run->forceFill([
                'status' => 'failed', 'active_key' => null, 'error_code' => $code,
                'error_message' => $exception === null ? $code : $code.':'.$exception::class, 'failed_at' => now(),
            ])->save();
            $library->forceFill(['workflow_status' => 'failed'])->save();
        }, 3);
    }

    private function markObsolete(KnowledgeFactGenerationExecutionContext $context): void
    {
        DB::transaction(function () use ($context): void {
            try {
                $this->executionGuard->assertCurrent($context);
            } catch (AiModelAccessException) {
                return;
            }
            $run = KnowledgeFactGenerationRun::query()->whereKey($context->runId)->lockForUpdate()->firstOrFail();
            if (! $run->isActive()) {
                return;
            }
            $library = KnowledgeFactLibrary::query()->whereKey($run->library_id)->lockForUpdate()->firstOrFail();
            $run->forceFill([
                'status' => 'obsolete',
                'active_key' => null,
                'retryable_failure' => false,
                'completed_at' => now(),
            ])->save();
            $library->forceFill(['workflow_status' => 'review_required'])->save();
        }, 3);
    }

    private function completeWithoutGeneration(int $runId): void
    {
        DB::transaction(function () use ($runId): void {
            $run = KnowledgeFactGenerationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            $library = KnowledgeFactLibrary::query()->whereKey($run->library_id)->lockForUpdate()->firstOrFail();
            $run->forceFill([
                'status' => 'completed',
                'active_key' => null,
                'retryable_failure' => false,
                'completed_at' => now(),
            ])->save();
            $library->forceFill(['workflow_status' => 'idle'])->save();
        }, 3);
    }

    private function markCancelled(KnowledgeFactGenerationRun $run, KnowledgeFactLibrary $library): void
    {
        $run->forceFill([
            'status' => 'cancelled',
            'active_key' => null,
            'retryable_failure' => false,
            'cancelled_at' => now(),
        ])->save();
        $library->forceFill(['workflow_status' => 'idle'])->save();
    }

    private function activeKey(int $libraryId): string
    {
        return "knowledge-fact-library:{$libraryId}";
    }

    /** @param array<string,mixed> $candidate */
    private function candidateKey(array $candidate): string
    {
        unset($candidate['_candidate_key']);

        return hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @param array<string,mixed> $candidate @return array<string,mixed> */
    public function candidateValueMetadata(array $candidate): array
    {
        $scope = array_filter([
            'entity' => trim((string) ($candidate['scope_entity'] ?? '')),
            'region' => trim((string) ($candidate['scope_region'] ?? '')),
            'channel' => trim((string) ($candidate['scope_channel'] ?? '')),
            'statistic_definition' => trim((string) ($candidate['statistic_definition'] ?? '')),
        ], static fn (string $value): bool => $value !== '');
        $tolerance = trim((string) ($candidate['comparison_tolerance'] ?? ''));

        return [
            'temporal_kind' => in_array((string) ($candidate['temporal_kind'] ?? ''), ['timeless', 'observed', 'interval'], true) ? $candidate['temporal_kind'] : 'timeless',
            'scope_json' => $scope,
            'scope_hash' => $this->valuePolicy->scopeHash($scope),
            'valid_from' => ($candidate['valid_from'] ?? '') !== '' ? $candidate['valid_from'] : null,
            'valid_to' => ($candidate['valid_to'] ?? '') !== '' ? $candidate['valid_to'] : null,
            'observed_at' => ($candidate['observed_at'] ?? '') !== '' ? $candidate['observed_at'] : null,
            'comparison_policy_json' => $tolerance === '' ? [] : ['tolerance' => $tolerance],
        ];
    }
}
