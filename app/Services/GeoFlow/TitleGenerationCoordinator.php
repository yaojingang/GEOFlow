<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\TitleGenerationExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Exceptions\TitleGenerationException;
use App\Jobs\ProcessTitleGenerationBatchJob;
use App\Jobs\ResumeTitleGenerationRunJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\KeywordLibrary;
use App\Models\Title;
use App\Models\TitleGenerationRun;
use App\Models\TitleLibrary;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use App\Support\LibraryImportPolicy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class TitleGenerationCoordinator
{
    public function __construct(
        private readonly TitleAiGenerationService $generationService,
        private readonly ?TitleGenerationAiExecutionGuard $aiExecutionGuard = null,
        private readonly ?AiExecutionErrorSanitizer $errorSanitizer = null,
    ) {}

    /**
     * @param  array{keyword_library_id:int,ai_model_id:int,title_count:int,title_style:string,custom_prompt?:string|null,confirmed_keyword_reuse?:bool}  $payload
     */
    public function start(TitleLibrary $library, array $payload, ?int $adminId, string $locale): TitleGenerationRun
    {
        $batchSize = min(
            (int) config('geoflow.title_ai_batch_size', 50),
            (int) $payload['title_count'],
        );
        try {
            $run = DB::transaction(function () use ($library, $payload, $adminId, $locale, $batchSize): TitleGenerationRun {
                TitleLibrary::query()->whereKey($library->getKey())->lockForUpdate()->firstOrFail();
                if (! KeywordLibrary::query()->whereKey($payload['keyword_library_id'])->lockForUpdate()->first()) {
                    throw new TitleGenerationException('title_generation_no_keywords');
                }
                $executionAdmin = $adminId !== null && $adminId > 0
                    ? Admin::query()->whereKey($adminId)->lockForUpdate()->firstOrFail()
                    : null;
                if ($executionAdmin instanceof Admin && (string) $executionAdmin->status !== 'active') {
                    throw new TitleGenerationException('ai_execution_admin_inactive');
                }
                if (! $executionAdmin instanceof Admin && $this->executionGuard()->identityRequired()) {
                    throw new TitleGenerationException('ai_config_access_revoked');
                }
                $aiModel = AiModel::query()
                    ->whereKey($payload['ai_model_id'])
                    ->where('status', 'active')
                    ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'chat'")
                    ->lockForUpdate()
                    ->first();
                if (! $aiModel) {
                    throw new TitleGenerationException('title_generation_ai_model_unavailable');
                }
                $executionIdentity = $executionAdmin instanceof Admin
                    ? $this->executionGuard()->snapshotForCreation($executionAdmin, $aiModel)
                    : null;

                if (TitleGenerationRun::query()->where('active_key', $this->activeKey((int) $library->getKey()))->exists()) {
                    throw new TitleGenerationException('title_generation_active');
                }
                $this->enforceSubmissionCapacity(
                    (int) $payload['ai_model_id'],
                    $adminId,
                    (int) $payload['title_count'],
                );

                $run = TitleGenerationRun::query()->forceCreate([
                    'title_library_id' => (int) $library->getKey(),
                    'keyword_library_id' => $payload['keyword_library_id'],
                    'ai_model_id' => $payload['ai_model_id'],
                    'created_by_admin_id' => $adminId,
                    'model_access_admin_id' => $executionIdentity['model_access_admin_id'] ?? null,
                    'model_access_admin_role' => $executionIdentity['model_access_admin_role'] ?? null,
                    'ai_config_access_version' => $executionIdentity['ai_config_access_version'] ?? null,
                    'requested_ai_model_id' => $executionIdentity['requested_ai_model_id'] ?? null,
                    'resolved_ai_model_id' => null,
                    'resolved_model_source' => null,
                    'model_resolved_at' => null,
                    'resolver_policy_version' => $executionIdentity['resolver_policy_version'] ?? null,
                    'status' => TitleGenerationRun::STATUS_QUEUED,
                    'active_key' => $this->activeKey((int) $library->getKey()),
                    'requested_count' => $payload['title_count'],
                    'batch_size' => $batchSize,
                    'model_request_budget' => $this->modelRequestBudget((int) $payload['title_count']),
                    'title_style' => $payload['title_style'],
                    'custom_prompt' => trim((string) ($payload['custom_prompt'] ?? '')) ?: null,
                    'locale' => $locale,
                    'keyword_snapshot' => ['keywords' => [], 'cursor_id' => 0, 'available_count' => 0],
                    'available_at' => now(),
                ]);

                $this->materializeRunKeywords(
                    (int) $run->getKey(),
                    (int) $payload['keyword_library_id'],
                );

                $keywordCount = DB::table('title_generation_run_keywords')
                    ->where('title_generation_run_id', $run->getKey())
                    ->count();
                if ($keywordCount === 0) {
                    throw new TitleGenerationException('title_generation_no_keywords');
                }
                if ((int) $payload['title_count'] > $keywordCount
                    && ! ($payload['confirmed_keyword_reuse'] ?? false)) {
                    throw new TitleGenerationException('title_generation_keyword_reuse_confirmation_required');
                }

                $pivotId = (int) (DB::table('title_generation_run_keywords')
                    ->where('title_generation_run_id', $run->getKey())
                    ->inRandomOrder()
                    ->value('id') ?? 0);
                $run->forceFill([
                    'keyword_snapshot' => $this->keywordWindow(
                        (int) $run->getKey(),
                        max(0, $pivotId - 1),
                        $batchSize,
                    ),
                ])->save();

                return $run;
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)
                && TitleGenerationRun::query()
                    ->where('active_key', $this->activeKey((int) $library->getKey()))
                    ->exists()) {
                throw new TitleGenerationException('title_generation_active', $exception);
            }

            throw $exception;
        }

        try {
            $this->dispatchRun($run);
        } catch (Throwable $exception) {
            $this->markDispatchFailure(
                (int) $run->getKey(),
                (int) $run->batch_sequence,
                (string) $run->dispatch_token,
            );

            throw $exception;
        }

        return $run->fresh() ?? $run;
    }

    private function executionGuard(): TitleGenerationAiExecutionGuard
    {
        return $this->aiExecutionGuard ?? app(TitleGenerationAiExecutionGuard::class);
    }

    public function retry(TitleGenerationRun $run, Admin|int|null $actor = null): TitleGenerationRun
    {
        $run = DB::transaction(function () use ($run, $actor): TitleGenerationRun {
            $lockedRun = TitleGenerationRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            TitleLibrary::query()->whereKey($lockedRun->title_library_id)->lockForUpdate()->firstOrFail();

            if (! $lockedRun->isRetryable()) {
                throw new TitleGenerationException('title_generation_not_retryable');
            }
            $actorId = $actor instanceof Admin ? (int) $actor->getKey() : (int) ($actor ?? 0);
            if ($this->executionGuard()->identityComplete($lockedRun)) {
                if ($actorId !== (int) $lockedRun->model_access_admin_id) {
                    throw new TitleGenerationException('ai_model_not_accessible');
                }
                $this->executionGuard()->assertFrozenIdentityCurrent(
                    $lockedRun,
                    (int) $lockedRun->requested_ai_model_id,
                );
            } else {
                throw new TitleGenerationException('ai_config_access_revoked');
            }

            if (TitleGenerationRun::query()
                ->where('active_key', $this->activeKey((int) $lockedRun->title_library_id))
                ->whereKeyNot($lockedRun->getKey())
                ->exists()) {
                throw new TitleGenerationException('title_generation_active');
            }
            $this->enforceSubmissionCapacity(
                (int) ($lockedRun->requested_ai_model_id ?? $lockedRun->ai_model_id),
                $lockedRun->model_access_admin_id ?? $lockedRun->created_by_admin_id,
                max(0, (int) $lockedRun->requested_count - (int) $lockedRun->saved_count),
            );

            $lockedRun->forceFill([
                'status' => TitleGenerationRun::STATUS_QUEUED,
                'active_key' => $this->activeKey((int) $lockedRun->title_library_id),
                'consecutive_empty_batches' => 0,
                'available_at' => now(),
                'dispatch_token' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'batch_attempt_count' => 0,
                'manual_retry_count' => (int) $lockedRun->manual_retry_count + 1,
                'failure_code' => null,
                'error_code' => null,
                'retryable_failure' => true,
                'last_error' => null,
                'failed_at' => null,
                'cancelled_at' => null,
            ])->save();

            return $lockedRun;
        }, 3);

        try {
            $this->dispatchRun($run);
        } catch (Throwable $exception) {
            $this->markDispatchFailure(
                (int) $run->getKey(),
                (int) $run->batch_sequence,
                (string) $run->dispatch_token,
            );

            throw $exception;
        }

        return $run->fresh() ?? $run;
    }

    public function cancel(TitleGenerationRun $run, Admin|int|null $actor = null): TitleGenerationRun
    {
        return DB::transaction(function () use ($run, $actor): TitleGenerationRun {
            $lockedRun = TitleGenerationRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            if ($this->executionGuard()->identityComplete($lockedRun)) {
                $actorId = $actor instanceof Admin ? (int) $actor->getKey() : (int) ($actor ?? 0);
                if ($actorId !== (int) $lockedRun->model_access_admin_id) {
                    throw new TitleGenerationException('ai_model_not_accessible');
                }
            }
            if (! $lockedRun->isActive()) {
                throw new TitleGenerationException('title_generation_not_cancellable');
            }

            $lockedRun->forceFill([
                'status' => TitleGenerationRun::STATUS_CANCELLED,
                'active_key' => null,
                'available_at' => null,
                'dispatch_token' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'failure_code' => null,
                'last_error' => null,
                'cancelled_at' => now(),
            ])->save();

            return $lockedRun;
        }, 3);
    }

    public function processBatch(int $runId, int $batchSequence, string $leaseToken): void
    {
        $run = $this->claimBatch($runId, $batchSequence, $leaseToken);
        if (! $run) {
            return;
        }

        $remainingCount = max(0, (int) $run->requested_count - (int) $run->saved_count);
        if ($remainingCount === 0) {
            $this->completeRun($runId, $batchSequence, $leaseToken);

            return;
        }

        $requestBudgetRemaining = max(
            0,
            (int) $run->model_request_budget - (int) $run->model_request_count,
        );
        if ($requestBudgetRemaining === 0) {
            $this->markRequestBudgetExhausted($runId, $batchSequence, $leaseToken);

            return;
        }

        $batchSize = min((int) $run->batch_size, $remainingCount, $requestBudgetRemaining);
        $executionContext = $this->executionGuard()->contextFromClaimedRun($run);
        $candidates = $this->executionGuard()->resolveCandidates($executionContext);
        if ($candidates->isEmpty()) {
            throw new RuntimeException('title_generation_ai_model_unavailable');
        }

        $keywords = $this->snapshotKeywords((array) $run->keyword_snapshot);
        if ($keywords === []) {
            throw new RuntimeException('title_generation_no_keywords');
        }

        $previousLocale = app()->getLocale();
        app()->setLocale((string) $run->locale);

        $result = null;
        $resolvedModel = null;
        try {
            foreach ($candidates as $candidateIndex => $candidate) {
                if ($executionContext instanceof TitleGenerationExecutionContext) {
                    $this->executionGuard()->assertCurrent($executionContext, $candidate);
                    $this->executionGuard()->recordResolvedModel($executionContext, $candidate);
                }
                $result = $this->generationService->generateTitles(
                    $candidate,
                    $keywords,
                    $batchSize,
                    (string) $run->title_style,
                    $this->buildBatchPrompt($run),
                    $executionContext,
                    $candidateIndex + 1,
                );
                if ($result->succeeded()) {
                    $resolvedModel = $candidate;
                    if ($executionContext instanceof TitleGenerationExecutionContext) {
                        $this->executionGuard()->assertCurrent($executionContext, $candidate);
                    }

                    break;
                }

                $hasNextCandidate = $candidateIndex < $candidates->count() - 1;
                if (! $result->retryable || ! $hasNextCandidate) {
                    break;
                }
            }
        } catch (AiModelAccessException $exception) {
            if ($result?->usageDelivery instanceof TitleGenerationUsageDelivery) {
                if ($this->executionGuard()->claimedExecutionIsCurrent($executionContext)) {
                    $result->usageDelivery->revoked($exception->getErrorCode());
                } else {
                    $result->usageDelivery->discarded('title_generation_batch_claim_lost');

                    return;
                }
            }

            throw $exception;
        } finally {
            app()->setLocale($previousLocale);
        }

        if (! $result instanceof TitleGenerationOutcome) {
            throw new RuntimeException('title_generation_ai_model_unavailable');
        }

        if (! $result->succeeded()) {
            if ($result->quotaWasExhausted()) {
                $nextRun = $this->deferForQuota($runId, $batchSequence, $leaseToken);
                if ($nextRun?->status === TitleGenerationRun::STATUS_QUEUED) {
                    try {
                        ResumeTitleGenerationRunJob::dispatch(
                            (int) $nextRun->getKey(),
                            (int) $nextRun->batch_sequence,
                        )->onQueue('geoflow')->delay($nextRun->available_at);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                }

                return;
            }

            if (! $result->retryable) {
                throw PermanentAiProviderException::fromProviderFailure(
                    new RuntimeException($result->failureCode ?? 'title_generation_model_failed'),
                );
            }

            throw new RuntimeException('title_generation_model_failed:'.($result->failureCode ?? 'unknown'));
        }

        try {
            $rawTitles = array_slice($result->titles, 0, $batchSize);
            [$validTitles, $invalidCount] = $this->normalizeTitles($rawTitles);
            $nextRun = $this->persistBatch(
                $runId,
                $batchSequence,
                $leaseToken,
                $keywords,
                $batchSize,
                count($rawTitles),
                $validTitles,
                $invalidCount,
                $executionContext,
                $resolvedModel,
            );
            if (! $nextRun instanceof TitleGenerationRun) {
                $result->usageDelivery?->discarded('title_generation_batch_claim_lost');

                return;
            }
            $result->usageDelivery?->succeeded();
        } catch (AiModelAccessException $exception) {
            if ($this->executionGuard()->claimedExecutionIsCurrent($executionContext)) {
                $result->usageDelivery?->revoked($exception->getErrorCode());

                throw $exception;
            }

            $result->usageDelivery?->discarded('title_generation_batch_claim_lost');

            return;
        } catch (Throwable $exception) {
            $result->usageDelivery?->discarded('title_generation_batch_persistence_failed');

            throw $exception;
        }

        if ($nextRun?->status === TitleGenerationRun::STATUS_QUEUED) {
            try {
                $this->dispatchRun($nextRun);
            } catch (Throwable $exception) {
                $this->markDispatchFailure(
                    (int) $nextRun->getKey(),
                    (int) $nextRun->batch_sequence,
                    (string) $nextRun->dispatch_token,
                );
                report($exception);
            }
        }
    }

    public function markFailed(
        int $runId,
        int $batchSequence,
        string $leaseToken,
        Throwable $exception,
        bool $retryable = true,
    ): void {
        DB::transaction(function () use ($runId, $batchSequence, $leaseToken, $exception, $retryable): void {
            $run = TitleGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || (int) $run->batch_sequence !== $batchSequence) {
                return;
            }

            $matchesRunningLease = $run->status === TitleGenerationRun::STATUS_RUNNING
                && hash_equals((string) $run->lease_token, $leaseToken);
            $matchesQueuedDispatch = $run->status === TitleGenerationRun::STATUS_QUEUED
                && hash_equals((string) $run->dispatch_token, $leaseToken);
            if (! $matchesRunningLease && ! $matchesQueuedDispatch) {
                return;
            }

            $run->forceFill([
                'status' => (int) $run->saved_count > 0
                    ? TitleGenerationRun::STATUS_PARTIAL
                    : TitleGenerationRun::STATUS_FAILED,
                'active_key' => null,
                'dispatch_token' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'failure_code' => $retryable ? 'batch_failed' : 'permanent_ai_failure',
                'error_code' => $exception instanceof AiModelAccessException
                    ? $exception->getErrorCode()
                    : ($exception instanceof PermanentAiProviderException
                        ? $exception->getErrorCode()
                        : null),
                'retryable_failure' => $retryable,
                'last_error' => $this->executionErrorSanitizer()->sanitize(
                    match (true) {
                        $exception instanceof AiModelAccessException => $exception->getErrorCode(),
                        $exception instanceof PermanentAiProviderException => $exception->getErrorCode(),
                        default => $this->safeBatchFailureCode($exception),
                    },
                    $retryable ? 'title_generation_batch_failed' : 'title_generation_permanent_ai_failure',
                ),
                'failed_at' => now(),
            ])->save();
        }, 3);
    }

    public function recoverStalled(int $limit = 100): int
    {
        $staleBefore = now()->subSeconds((int) config('geoflow.title_ai_recovery_stale_seconds', 300));
        $runIds = TitleGenerationRun::query()
            ->where(function ($query) use ($staleBefore): void {
                $query->where(function ($queued) use ($staleBefore): void {
                    $queued->where('status', TitleGenerationRun::STATUS_QUEUED)
                        ->where('available_at', '<=', now())
                        ->where('updated_at', '<=', $staleBefore);
                })->orWhere(function ($running): void {
                    $running->where('status', TitleGenerationRun::STATUS_RUNNING)
                        ->where('lease_expires_at', '<=', now());
                });
            })
            ->orderBy('id')
            ->limit(max(1, min(500, $limit)))
            ->pluck('id');

        $recovered = 0;
        foreach ($runIds as $runId) {
            $run = DB::transaction(function () use ($runId): ?TitleGenerationRun {
                $lockedRun = TitleGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
                if (! $lockedRun || ! $lockedRun->isActive()) {
                    return null;
                }

                $isRecoverable = ($lockedRun->status === TitleGenerationRun::STATUS_QUEUED
                        && $lockedRun->available_at?->isPast())
                    || ($lockedRun->status === TitleGenerationRun::STATUS_RUNNING
                        && $lockedRun->lease_expires_at?->isPast());
                if (! $isRecoverable) {
                    return null;
                }

                if (! $this->executionGuard()->identityComplete($lockedRun)) {
                    $lockedRun->forceFill([
                        'status' => (int) $lockedRun->saved_count > 0
                            ? TitleGenerationRun::STATUS_PARTIAL
                            : TitleGenerationRun::STATUS_FAILED,
                        'active_key' => null,
                        'available_at' => null,
                        'dispatch_token' => null,
                        'lease_token' => null,
                        'lease_expires_at' => null,
                        'failure_code' => 'permanent_ai_failure',
                        'error_code' => AiModelAccessException::AI_CONFIG_ACCESS_REVOKED,
                        'retryable_failure' => false,
                        'last_error' => AiModelAccessException::AI_CONFIG_ACCESS_REVOKED,
                        'failed_at' => now(),
                    ])->save();

                    return null;
                }
                if ($this->executionGuard()->identityComplete($lockedRun)) {
                    try {
                        $this->executionGuard()->assertFrozenIdentityCurrent(
                            $lockedRun,
                            (int) $lockedRun->requested_ai_model_id,
                        );
                    } catch (AiModelAccessException $exception) {
                        $lockedRun->forceFill([
                            'status' => (int) $lockedRun->saved_count > 0
                                ? TitleGenerationRun::STATUS_PARTIAL
                                : TitleGenerationRun::STATUS_FAILED,
                            'active_key' => null,
                            'available_at' => null,
                            'dispatch_token' => null,
                            'lease_token' => null,
                            'lease_expires_at' => null,
                            'failure_code' => 'permanent_ai_failure',
                            'error_code' => $exception->getErrorCode(),
                            'retryable_failure' => false,
                            'last_error' => $exception->getErrorCode(),
                            'failed_at' => now(),
                        ])->save();

                        return null;
                    }
                }

                $dispatchToken = $lockedRun->status === TitleGenerationRun::STATUS_RUNNING
                    ? (string) Str::uuid7()
                    : $lockedRun->dispatch_token;

                $lockedRun->forceFill([
                    'status' => TitleGenerationRun::STATUS_QUEUED,
                    'available_at' => now(),
                    'dispatch_token' => $dispatchToken,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                ])->save();

                return $lockedRun;
            }, 3);

            if (! $run) {
                continue;
            }

            try {
                $this->dispatchRun($run);
                $recovered++;
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $recovered;
    }

    public function resumeDeferred(int $runId, int $batchSequence): void
    {
        $run = DB::transaction(function () use ($runId, $batchSequence): ?TitleGenerationRun {
            $lockedRun = TitleGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $lockedRun
                || $lockedRun->status !== TitleGenerationRun::STATUS_QUEUED
                || (int) $lockedRun->batch_sequence !== $batchSequence
                || (string) $lockedRun->failure_code !== 'quota_wait'
                || $lockedRun->available_at?->isFuture()) {
                return null;
            }

            try {
                if (! $this->executionGuard()->identityComplete($lockedRun)) {
                    throw AiModelAccessException::configAccessRevokedForAdminId(
                        (int) ($lockedRun->model_access_admin_id ?? 0),
                    );
                }
                $this->executionGuard()->assertFrozenIdentityCurrent(
                    $lockedRun,
                    (int) $lockedRun->requested_ai_model_id,
                );
            } catch (AiModelAccessException $exception) {
                $lockedRun->forceFill([
                    'status' => (int) $lockedRun->saved_count > 0
                        ? TitleGenerationRun::STATUS_PARTIAL
                        : TitleGenerationRun::STATUS_FAILED,
                    'active_key' => null,
                    'available_at' => null,
                    'dispatch_token' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'failure_code' => 'permanent_ai_failure',
                    'error_code' => $exception->getErrorCode(),
                    'retryable_failure' => false,
                    'last_error' => $exception->getErrorCode(),
                    'failed_at' => now(),
                ])->save();

                return null;
            }

            return $lockedRun;
        }, 3);

        if ($run) {
            $this->dispatchRun($run);
        }
    }

    public function markResumeFailed(int $runId, int $batchSequence, Throwable $exception): void
    {
        TitleGenerationRun::query()
            ->whereKey($runId)
            ->where('status', TitleGenerationRun::STATUS_QUEUED)
            ->where('batch_sequence', $batchSequence)
            ->where('failure_code', 'quota_wait')
            ->update([
                'available_at' => now()->addMinutes(15),
                'failure_code' => 'quota_resume_failed',
                'last_error' => 'title_generation_quota_resume_failed',
                'updated_at' => now(),
            ]);
    }

    private function claimBatch(int $runId, int $batchSequence, string $leaseToken): ?TitleGenerationRun
    {
        return DB::transaction(function () use ($runId, $batchSequence, $leaseToken): ?TitleGenerationRun {
            $run = TitleGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || (int) $run->batch_sequence !== $batchSequence) {
                return null;
            }

            $canClaim = ($run->status === TitleGenerationRun::STATUS_QUEUED
                    && hash_equals((string) $run->dispatch_token, $leaseToken))
                || ($run->status === TitleGenerationRun::STATUS_RUNNING
                    && hash_equals((string) $run->lease_token, $leaseToken));
            if (! $canClaim) {
                return null;
            }

            if (! $this->executionGuard()->identityComplete($run)) {
                $run->forceFill([
                    'status' => (int) $run->saved_count > 0
                        ? TitleGenerationRun::STATUS_PARTIAL
                        : TitleGenerationRun::STATUS_FAILED,
                    'active_key' => null,
                    'available_at' => null,
                    'dispatch_token' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'failure_code' => 'permanent_ai_failure',
                    'error_code' => AiModelAccessException::AI_CONFIG_ACCESS_REVOKED,
                    'retryable_failure' => false,
                    'last_error' => AiModelAccessException::AI_CONFIG_ACCESS_REVOKED,
                    'failed_at' => now(),
                ])->save();

                return null;
            }

            $attemptCount = (int) $run->batch_attempt_count + 1;
            if ($attemptCount > (int) config('geoflow.title_ai_max_batch_attempts', 3)) {
                $run->forceFill([
                    'status' => (int) $run->saved_count > 0
                        ? TitleGenerationRun::STATUS_PARTIAL
                        : TitleGenerationRun::STATUS_FAILED,
                    'active_key' => null,
                    'available_at' => null,
                    'dispatch_token' => null,
                    'lease_token' => null,
                    'lease_expires_at' => null,
                    'failure_code' => 'batch_attempts_exhausted',
                    'last_error' => 'title_generation_batch_attempts_exhausted',
                    'failed_at' => now(),
                ])->save();

                return null;
            }

            $run->forceFill([
                'status' => TitleGenerationRun::STATUS_RUNNING,
                'available_at' => null,
                'dispatch_token' => null,
                'lease_token' => $leaseToken,
                'lease_expires_at' => now()->addSeconds((int) config('geoflow.title_ai_lease_seconds', 420)),
                'batch_attempt_count' => $attemptCount,
                'started_at' => $run->started_at ?? now(),
                'failure_code' => null,
                'last_error' => null,
            ])->save();

            if ($this->executionGuard()->identityComplete($run)) {
                $context = $this->executionGuard()->contextFromClaimedRun($run);
                $this->executionGuard()->assertCurrent($context, $context->requestedModelId);
            }

            return $run;
        }, 3);
    }

    /**
     * @param  list<string>  $keywords
     * @param  list<string>  $titles
     */
    private function persistBatch(
        int $runId,
        int $batchSequence,
        string $leaseToken,
        array $keywords,
        int $requestedFromModel,
        int $generatedCount,
        array $titles,
        int $invalidCount,
        ?TitleGenerationExecutionContext $executionContext = null,
        ?AiModel $resolvedModel = null,
    ): ?TitleGenerationRun {
        return DB::transaction(function () use (
            $runId,
            $batchSequence,
            $leaseToken,
            $keywords,
            $requestedFromModel,
            $generatedCount,
            $titles,
            $invalidCount,
            $executionContext,
            $resolvedModel,
        ): ?TitleGenerationRun {
            $run = TitleGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run
                || $run->status !== TitleGenerationRun::STATUS_RUNNING
                || (int) $run->batch_sequence !== $batchSequence
                || ! hash_equals((string) $run->lease_token, $leaseToken)) {
                return null;
            }
            if ($executionContext instanceof TitleGenerationExecutionContext) {
                $this->executionGuard()->assertCurrent($executionContext, $resolvedModel);
            }

            $legacyExisting = $titles === []
                ? []
                : Title::query()
                    ->where('library_id', $run->title_library_id)
                    ->whereIn('title', $titles)
                    ->pluck('title')
                    ->all();
            $existingLookup = array_fill_keys($legacyExisting, true);
            $now = now();
            $rows = [];
            foreach ($titles as $index => $title) {
                if (isset($existingLookup[$title])) {
                    continue;
                }

                $rows[] = [
                    'library_id' => (int) $run->title_library_id,
                    'title' => $title,
                    'title_fingerprint' => Title::fingerprintFor($title),
                    'keyword' => $keywords[$index % count($keywords)],
                    'is_ai_generated' => true,
                    'used_count' => 0,
                    'usage_count' => 0,
                    'created_at' => $now,
                ];
            }

            $insertedCount = $rows === []
                ? 0
                : DB::table((new Title)->getTable())->insertOrIgnore($rows);
            $duplicateCount = max(0, count($titles) - $insertedCount);
            $savedCount = (int) $run->saved_count + $insertedCount;
            $modelRequestCount = (int) $run->model_request_count + $requestedFromModel;
            $emptyBatchCount = $insertedCount === 0
                ? (int) $run->consecutive_empty_batches + 1
                : 0;
            $completed = $savedCount >= (int) $run->requested_count;
            $noProgress = ! $completed
                && $emptyBatchCount >= (int) config('geoflow.title_ai_max_empty_batches', 3);
            $requestBudgetExhausted = ! $completed
                && ! $noProgress
                && $modelRequestCount >= max(1, (int) $run->model_request_budget);
            $nextStatus = $completed
                ? TitleGenerationRun::STATUS_COMPLETED
                : (($noProgress || $requestBudgetExhausted)
                    ? TitleGenerationRun::STATUS_PARTIAL
                    : TitleGenerationRun::STATUS_QUEUED);
            $delaySeconds = (int) config('geoflow.title_ai_batch_delay_seconds', 1);
            $nextKeywordSnapshot = (array) $run->keyword_snapshot;
            if ($nextStatus === TitleGenerationRun::STATUS_QUEUED) {
                $nextKeywordSnapshot = $this->nextKeywordSnapshot(
                    $run,
                    min((int) $run->batch_size, max(1, (int) $run->requested_count - $savedCount)),
                );
            }

            $run->forceFill([
                'status' => $nextStatus,
                'active_key' => in_array($nextStatus, [TitleGenerationRun::STATUS_COMPLETED, TitleGenerationRun::STATUS_PARTIAL], true)
                    ? null
                    : $run->active_key,
                'batch_sequence' => $batchSequence + 1,
                'requested_from_model_count' => (int) $run->requested_from_model_count + $requestedFromModel,
                'generated_count' => (int) $run->generated_count + $generatedCount,
                'saved_count' => $savedCount,
                'duplicate_count' => (int) $run->duplicate_count + $duplicateCount,
                'invalid_count' => (int) $run->invalid_count + $invalidCount,
                'batch_count' => (int) $run->batch_count + 1,
                'consecutive_empty_batches' => $emptyBatchCount,
                'model_request_count' => $modelRequestCount,
                'batch_attempt_count' => 0,
                'keyword_snapshot' => $nextKeywordSnapshot,
                'available_at' => $nextStatus === TitleGenerationRun::STATUS_QUEUED
                    ? now()->addSeconds($delaySeconds)
                    : null,
                'dispatch_token' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'failure_code' => $noProgress
                    ? 'no_progress'
                    : ($requestBudgetExhausted ? 'request_budget_exhausted' : null),
                'last_error' => $noProgress
                    ? 'title_generation_no_progress'
                    : ($requestBudgetExhausted ? 'title_generation_request_budget_exhausted' : null),
                'completed_at' => $completed ? now() : null,
                'failed_at' => ($noProgress || $requestBudgetExhausted) ? now() : null,
            ])->save();

            if ($insertedCount > 0) {
                TitleLibrary::query()->whereKey($run->title_library_id)->increment('title_count', $insertedCount);
            }

            return $run;
        }, 3);
    }

    private function deferForQuota(int $runId, int $batchSequence, string $leaseToken): ?TitleGenerationRun
    {
        return DB::transaction(function () use ($runId, $batchSequence, $leaseToken): ?TitleGenerationRun {
            $run = TitleGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run
                || $run->status !== TitleGenerationRun::STATUS_RUNNING
                || (int) $run->batch_sequence !== $batchSequence
                || ! hash_equals((string) $run->lease_token, $leaseToken)) {
                return null;
            }

            $run->forceFill([
                'status' => TitleGenerationRun::STATUS_QUEUED,
                'available_at' => now()->addDay()->startOfDay()->addMinute(),
                'dispatch_token' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'batch_attempt_count' => 0,
                'failure_code' => 'quota_wait',
                'last_error' => null,
            ])->save();

            return $run;
        }, 3);
    }

    private function completeRun(int $runId, int $batchSequence, string $leaseToken): void
    {
        DB::transaction(function () use ($runId, $batchSequence, $leaseToken): void {
            $run = TitleGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run
                || $run->status !== TitleGenerationRun::STATUS_RUNNING
                || (int) $run->batch_sequence !== $batchSequence
                || ! hash_equals((string) $run->lease_token, $leaseToken)) {
                return;
            }

            $run->forceFill([
                'status' => TitleGenerationRun::STATUS_COMPLETED,
                'active_key' => null,
                'available_at' => null,
                'dispatch_token' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'completed_at' => now(),
            ])->save();
        }, 3);
    }

    private function markRequestBudgetExhausted(int $runId, int $batchSequence, string $leaseToken): void
    {
        DB::transaction(function () use ($runId, $batchSequence, $leaseToken): void {
            $run = TitleGenerationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run
                || $run->status !== TitleGenerationRun::STATUS_RUNNING
                || (int) $run->batch_sequence !== $batchSequence
                || ! hash_equals((string) $run->lease_token, $leaseToken)) {
                return;
            }

            $run->forceFill([
                'status' => TitleGenerationRun::STATUS_PARTIAL,
                'active_key' => null,
                'available_at' => null,
                'dispatch_token' => null,
                'lease_token' => null,
                'lease_expires_at' => null,
                'failure_code' => 'request_budget_exhausted',
                'last_error' => 'title_generation_request_budget_exhausted',
                'failed_at' => now(),
            ])->save();
        }, 3);
    }

    private function dispatchRun(TitleGenerationRun $run): void
    {
        $connectionName = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connectionName}.driver");
        if (! app()->environment('testing')
            && ! in_array($driver, ['database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new TitleGenerationException('title_generation_async_queue_required');
        }

        $leaseToken = trim((string) $run->dispatch_token);
        if ($leaseToken === '') {
            $leaseToken = (string) Str::uuid7();
            $updated = TitleGenerationRun::query()
                ->whereKey($run->getKey())
                ->where('status', TitleGenerationRun::STATUS_QUEUED)
                ->where('batch_sequence', $run->batch_sequence)
                ->whereNull('dispatch_token')
                ->update(['dispatch_token' => $leaseToken]);
            if ($updated !== 1) {
                return;
            }
            $run->dispatch_token = $leaseToken;
        }

        $dispatch = ProcessTitleGenerationBatchJob::dispatch(
            (int) $run->getKey(),
            (int) ($run->requested_ai_model_id ?? $run->ai_model_id),
            (int) $run->batch_sequence,
            $leaseToken,
        )->onQueue('geoflow');

        if ($run->available_at?->isFuture()) {
            $dispatch->delay($run->available_at);
        }
    }

    private function markDispatchFailure(
        int $runId,
        int $batchSequence,
        string $dispatchToken,
    ): void {
        $query = TitleGenerationRun::query()
            ->whereKey($runId)
            ->where('status', TitleGenerationRun::STATUS_QUEUED)
            ->where('batch_sequence', $batchSequence);
        trim($dispatchToken) === ''
            ? $query->whereNull('dispatch_token')
            : $query->where('dispatch_token', $dispatchToken);
        $query->update([
            'status' => TitleGenerationRun::STATUS_FAILED,
            'active_key' => null,
            'available_at' => null,
            'dispatch_token' => null,
            'failure_code' => 'dispatch_failed',
            'last_error' => 'title_generation_dispatch_failed',
            'failed_at' => now(),
            'cancelled_at' => null,
            'completed_at' => null,
            'updated_at' => now(),
        ]);
    }

    private function buildBatchPrompt(TitleGenerationRun $run): string
    {
        $parts = [];
        $customPrompt = trim((string) $run->custom_prompt);
        if ($customPrompt !== '') {
            $parts[] = $customPrompt;
        }

        $parts[] = sprintf('这是第 %d 批，请生成全新且互不重复的标题。', (int) $run->batch_sequence + 1);
        $recentLimit = (int) config('geoflow.title_ai_recent_title_sample_limit', 20);
        if ($recentLimit > 0) {
            $recentTitles = Title::query()
                ->where('library_id', $run->title_library_id)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit($recentLimit)
                ->pluck('title')
                ->all();
            if ($recentTitles !== []) {
                $parts[] = '请避开这些已存在标题：'.implode('；', $recentTitles);
            }
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<int, mixed>  $titles
     * @return array{0:list<string>,1:int}
     */
    private function normalizeTitles(array $titles): array
    {
        $normalized = [];
        $invalidCount = 0;
        foreach ($titles as $title) {
            $value = TitleAiGenerationService::normalizeTitle((string) $title);
            if ($value === '' || ! LibraryImportPolicy::titleFitsStorage($value)) {
                $invalidCount++;

                continue;
            }

            if (isset($normalized[$value])) {
                $invalidCount++;

                continue;
            }

            $normalized[$value] = $value;
        }

        return [array_values($normalized), $invalidCount];
    }

    private function materializeRunKeywords(int $runId, int $keywordLibraryId): void
    {
        DB::table('title_generation_run_keywords')->insertUsing(
            ['title_generation_run_id', 'source_keyword_id', 'keyword'],
            DB::table('keywords')
                ->where('library_id', $keywordLibraryId)
                ->selectRaw('? as title_generation_run_id, id as source_keyword_id, keyword', [$runId]),
        );
    }

    /**
     * Keep only the current batch window in the run row. Successive batches move
     * the cursor through the immutable run snapshot, so source-library edits do
     * not change a submitted task.
     *
     * @return array{keywords:list<string>,cursor_id:int,available_count:int}
     */
    private function nextKeywordSnapshot(TitleGenerationRun $run, int $limit): array
    {
        $snapshot = (array) $run->keyword_snapshot;
        $next = $this->keywordWindow(
            (int) $run->getKey(),
            (int) ($snapshot['cursor_id'] ?? 0),
            $limit,
        );

        return $next['keywords'] !== [] ? $next : [
            'keywords' => $this->snapshotKeywords($snapshot),
            'cursor_id' => (int) ($snapshot['cursor_id'] ?? 0),
            'available_count' => (int) ($snapshot['available_count'] ?? 0),
        ];
    }

    /** @return array{keywords:list<string>,cursor_id:int,available_count:int} */
    private function keywordWindow(int $runId, int $afterId, int $limit): array
    {
        $baseQuery = static fn () => DB::table('title_generation_run_keywords')
            ->where('title_generation_run_id', $runId);
        $availableCount = (int) $baseQuery()->count();
        $windowSize = min(max(1, $limit), $availableCount);
        if ($windowSize === 0) {
            return ['keywords' => [], 'cursor_id' => 0, 'available_count' => 0];
        }

        $rows = $baseQuery()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($windowSize)
            ->get(['id', 'keyword']);
        if ($rows->count() < $windowSize) {
            $rows = $rows->concat(
                $baseQuery()
                    ->where('id', '<=', $afterId)
                    ->orderBy('id')
                    ->limit($windowSize - $rows->count())
                    ->get(['id', 'keyword']),
            );
        }

        return [
            'keywords' => $rows
                ->map(static fn (object $keyword): string => Title::normalizeText((string) $keyword->keyword))
                ->filter()
                ->values()
                ->all(),
            'cursor_id' => (int) ($rows->last()?->id ?? 0),
            'available_count' => $availableCount,
        ];
    }

    /** @return list<string> */
    private function snapshotKeywords(array $snapshot): array
    {
        $rawKeywords = isset($snapshot['keywords']) && is_array($snapshot['keywords'])
            ? $snapshot['keywords']
            : $snapshot;

        return array_values(array_filter(
            array_map(static fn (mixed $keyword): string => trim((string) $keyword), $rawKeywords),
        ));
    }

    private function activeKey(int $libraryId): string
    {
        return 'title-library:'.$libraryId;
    }

    private function modelRequestBudget(int $targetCount): int
    {
        return max(1, $targetCount) * (int) config('geoflow.title_ai_max_request_multiplier', 3);
    }

    private function enforceSubmissionCapacity(int $aiModelId, ?int $adminId, int $requestedCount): void
    {
        $activeStatuses = [TitleGenerationRun::STATUS_QUEUED, TitleGenerationRun::STATUS_RUNNING];
        if ($adminId !== null && $adminId > 0) {
            $activeRunCount = TitleGenerationRun::query()
                ->where(function ($query) use ($adminId): void {
                    $query->where('model_access_admin_id', $adminId)
                        ->orWhere(function ($legacy) use ($adminId): void {
                            $legacy->whereNull('model_access_admin_id')
                                ->where('created_by_admin_id', $adminId);
                        });
                })
                ->whereIn('status', $activeStatuses)
                ->count();
            if ($activeRunCount >= (int) config('geoflow.title_ai_max_active_runs_per_admin', 3)) {
                throw new TitleGenerationException('title_generation_capacity_exceeded');
            }
        }

        $pendingTitleCount = (int) TitleGenerationRun::query()
            ->where(function ($query) use ($aiModelId): void {
                $query->where('ai_model_id', $aiModelId)
                    ->orWhere('requested_ai_model_id', $aiModelId)
                    ->orWhere('resolved_ai_model_id', $aiModelId);
            })
            ->whereIn('status', $activeStatuses)
            ->selectRaw('COALESCE(SUM(requested_count - saved_count), 0) as pending_count')
            ->value('pending_count');
        if ($pendingTitleCount + $requestedCount
            > (int) config('geoflow.title_ai_max_pending_titles_per_model', 300_000)) {
            throw new TitleGenerationException('title_generation_capacity_exceeded');
        }
    }

    private function safeBatchFailureCode(Throwable $exception): string
    {
        $code = trim($exception->getMessage());

        return preg_match('/^title_generation_[a-z_]+(?::[a-z_]+)?$/', $code) === 1
            ? Str::limit($code, 200, '')
            : 'title_generation_batch_failed';
    }

    private function executionErrorSanitizer(): AiExecutionErrorSanitizer
    {
        return $this->errorSanitizer ?? app(AiExecutionErrorSanitizer::class);
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        $sqlState = (string) $exception->getCode();
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return $sqlState === '23505'
            || ($sqlState === '23000' && $driverCode === 1062)
            || ($driverCode === 19 && str_contains($exception->getMessage(), 'title_generation_runs.active_key'));
    }
}
