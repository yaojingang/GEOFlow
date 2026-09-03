<?php

namespace App\Services\GeoFlow;

use App\Contracts\ArticleAiOptimizationRefiner;
use App\Contracts\ProviderAttemptAwareArticleAiOptimizationRefiner;
use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Jobs\ProcessArticleAiOptimizationJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiOptimizationStep;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleAiQualityRollout;
use App\Models\ArticleDistribution;
use App\Models\Task;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\Admin\AiModelProviderUsageSession;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Support\GeoFlow\AiModelFailoverDecider;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Exceptions\FailoverableException;
use Throwable;

final class ArticleAiOptimizationCoordinator
{
    public const ALGORITHM_VERSION = '1.1.1';

    /** @var list<string> */
    private const AUTO_FIXABLE_ISSUE_CODES = [
        'knowledge_contradiction',
        'data_mismatch',
        'citation_scope_mismatch',
        'ad_absolute_claim',
        'ad_false_or_misleading',
        'content_integrity',
        'citation_missing',
        'unsupported_claim',
    ];

    public function __construct(
        private readonly ArticleAiOptimizationPolicy $optimizationPolicy,
        private readonly ArticleAiQualityPolicyResolver $qualityPolicyResolver,
        private readonly ArticleAiQualityVersionPolicy $qualityVersionPolicy,
        private readonly ArticleRiskScanner $riskScanner,
        private readonly ArticleAiQualityInspectionService $inspectionService,
        private readonly ArticleAiOptimizationRefiner $refiner,
        private readonly ArticleAiOptimizationPatchValidator $patchValidator,
        private readonly AdminAiModelAccessResolver $adminAiModelAccessResolver,
        private readonly AiModelFailoverDecider $failoverDecider,
        private readonly AiExecutionContextFactory $aiExecutionContextFactory,
        private readonly AiModelUsageAttemptFactory $usageAttempts,
        private readonly ArticleAiOptimizationExecutionBoundaryHook $executionBoundaryHook,
    ) {}

    public function start(
        Article $article,
        string $strategy,
        AiModel $optimizationModel,
        string $trigger,
        ?int $requestedByAdminId = null,
        bool $dispatch = true,
        ?string $requestKey = null,
    ): ArticleAiOptimizationRun {
        $requestKey = trim((string) $requestKey);
        if ($requestKey !== '') {
            $existingRequest = ArticleAiOptimizationRun::query()
                ->where('request_key', $requestKey)
                ->where('article_id', (int) $article->id)
                ->first();
            if ($existingRequest) {
                $this->assertOptimizationExecutionCurrent($existingRequest);

                return $this->ensureScheduled(
                    $existingRequest,
                    $article,
                    $dispatch,
                    $requestedByAdminId,
                );
            }
        }
        if (! (bool) config('geoflow.ai_quality_optimization_enabled', false)) {
            throw new ArticleAiOptimizationException('article_ai_optimization_disabled', httpStatus: 409);
        }
        if (! in_array($trigger, [
            ArticleAiOptimizationRun::TRIGGER_TASK_AUTO,
            ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            ArticleAiOptimizationRun::TRIGGER_API_MANUAL,
        ], true)) {
            throw new ArticleAiOptimizationException('article_ai_optimization_trigger_invalid', httpStatus: 422);
        }
        if ((string) $optimizationModel->status !== 'active'
            || ! in_array((string) ($optimizationModel->model_type ?? ''), ['', 'chat'], true)) {
            throw new ArticleAiOptimizationException('article_ai_optimization_model_unavailable', httpStatus: 422);
        }

        try {
            $run = DB::transaction(function () use (
                $article,
                $strategy,
                $optimizationModel,
                $trigger,
                $requestedByAdminId,
                $requestKey,
            ): ArticleAiOptimizationRun {
                $lockedArticle = Article::query()->whereKey((int) $article->id)->lockForUpdate()->first();
                if (! $lockedArticle || $lockedArticle->trashed()) {
                    throw new ArticleAiOptimizationException('article_ai_optimization_article_unavailable', httpStatus: 404);
                }
                if ((string) $lockedArticle->status !== 'draft') {
                    throw new ArticleAiOptimizationException('article_ai_optimization_draft_required', httpStatus: 409);
                }

                $lockedTask = null;
                if ($lockedArticle->task_id) {
                    $lockedTask = Task::withTrashed()
                        ->whereKey((int) $lockedArticle->task_id)
                        ->lockForUpdate()
                        ->first();
                    if ($lockedTask instanceof Task && ! $lockedTask->trashed()) {
                        $lockedTask->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
                        $lockedArticle->setRelation('task', $lockedTask);
                    }
                }
                if ($trigger === ArticleAiOptimizationRun::TRIGGER_TASK_AUTO
                    && (! $lockedTask instanceof Task
                        || $lockedTask->trashed()
                        || ! (bool) $lockedTask->ai_quality_enabled
                        || ! (bool) $lockedTask->ai_quality_auto_optimize_enabled
                        || (string) $lockedTask->ai_quality_optimization_level !== $strategy)) {
                    throw new ArticleAiOptimizationException(
                        'article_ai_optimization_task_policy_changed',
                        httpStatus: 409,
                    );
                }
                $executionAdmin = $this->optimizationExecutionAdmin($lockedTask, $requestedByAdminId);
                $this->assertRequestedModelForBothActors(
                    $executionAdmin,
                    $requestedByAdminId,
                    $optimizationModel,
                );
                $optimizationModels = $this->optimizationModelCandidates(
                    $optimizationModel,
                    $lockedTask,
                    $executionAdmin,
                );

                $qualityPolicy = $this->qualityPolicyResolver->forExecutionAdmin(
                    $this->qualityPolicyResolver->resolveForManualInspection($lockedArticle),
                    $executionAdmin,
                );
                $this->qualityPolicyResolver->assertExecutable($qualityPolicy);
                $qualityVersionSelection = $this->qualityVersionPolicy->selection((int) $lockedArticle->id);
                $currentQualityFingerprint = $this->inspectionService->currentFingerprint(
                    $lockedArticle,
                    $qualityPolicy,
                    $this->inspectionService->rules(),
                    $qualityVersionSelection,
                );
                $qualityCandidateIds = array_values(array_map(
                    static fn (AiModel $candidate): int => (int) $candidate->id,
                    $this->qualityPolicyResolver->modelCandidates($qualityPolicy),
                ));
                $sourceCheck = ArticleAiQualityCheck::query()
                    ->where('article_id', (int) $lockedArticle->id)
                    ->where('gate_applied', true)
                    ->where('status', 'completed')
                    ->where('inspection_scope', 'full')
                    ->where('input_fingerprint', $currentQualityFingerprint)
                    ->latest('id')
                    ->limit(50)
                    ->get()
                    ->first(function (ArticleAiQualityCheck $check) use ($executionAdmin, $qualityCandidateIds): bool {
                        $snapshot = data_get($check->execution_meta, 'ai_execution');

                        return is_array($snapshot)
                            && (int) ($snapshot['model_access_admin_id'] ?? 0) === (int) $executionAdmin->id
                            && (string) ($snapshot['model_access_admin_role'] ?? '') === $this->aiExecutionContextFactory->normalizedRole($executionAdmin)
                            && (int) ($snapshot['ai_config_access_version'] ?? 0) === max(1, (int) $executionAdmin->ai_config_access_version)
                            && (int) ($snapshot['resolver_policy_version'] ?? 0) === AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION
                            && array_values(array_map('intval', (array) ($snapshot['model_candidate_ids'] ?? []))) === $qualityCandidateIds;
                    });
                $sourceCheckId = $sourceCheck?->id;
                $policy = $this->optimizationPolicy->resolve(
                    $strategy,
                    (int) ($qualityPolicy['pass_score'] ?? 85),
                );
                $policyHash = $this->policyHash(
                    $lockedArticle,
                    $qualityPolicy,
                    $policy,
                    $optimizationModels,
                    $lockedTask,
                    $trigger,
                );
                $snapshot = $this->qualityPolicyResolver->articleSnapshot($lockedArticle);
                $baseHash = $this->riskScanner->contentHash($snapshot);
                $activeDedupeKey = hash('sha256', implode("\0", [
                    (int) $lockedArticle->id,
                    $baseHash,
                    $policyHash,
                    $trigger,
                    (int) $optimizationModel->id,
                ]));
                $existing = ArticleAiOptimizationRun::query()
                    ->where('active_dedupe_key', $activeDedupeKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing instanceof ArticleAiOptimizationRun) {
                    return $existing;
                }
                if (ArticleAiOptimizationRun::query()
                    ->where('article_id', (int) $lockedArticle->id)
                    ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES)
                    ->lockForUpdate()
                    ->exists()) {
                    throw new ArticleAiOptimizationException('article_ai_optimization_already_running');
                }

                $run = ArticleAiOptimizationRun::query()->create([
                    'article_id' => (int) $lockedArticle->id,
                    'task_id' => $lockedTask?->id,
                    'requested_by_admin_id' => $requestedByAdminId,
                    'request_key' => $requestKey !== '' ? $requestKey : (string) Str::uuid(),
                    'trigger' => $trigger,
                    'strategy' => $strategy,
                    'target_score' => $policy['target_score'],
                    'max_rounds' => $policy['max_rounds'],
                    'completed_rounds' => 0,
                    'status' => $sourceCheckId ? ArticleAiOptimizationRun::STATUS_QUEUED : ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY,
                    'base_article_hash' => $baseHash,
                    'policy_hash' => $policyHash,
                    'active_dedupe_key' => $activeDedupeKey,
                    'deadline_at' => now()->addSeconds(
                        $policy['estimated_seconds']
                        + max(70, (int) config('geoflow.ai_quality_job_timeout_seconds', 245))
                        + max(1, (int) config('geoflow.ai_quality_front_queue_wait_seconds', 10))
                        + 120,
                    ),
                    'execution_meta' => [
                        'policy' => $policy,
                        'optimization_model_id' => (int) $optimizationModel->id,
                        'optimization_model_ids' => array_values(array_map(
                            static fn (AiModel $model): int => (int) $model->id,
                            $optimizationModels,
                        )),
                        'optimization_model_selection_mode' => (string) ($lockedTask?->model_selection_mode ?? 'fixed'),
                        'model_access_admin_id' => (int) $executionAdmin->id,
                        'model_access_admin_role' => $this->aiExecutionContextFactory->normalizedRole($executionAdmin),
                        'ai_config_access_version' => max(1, (int) $executionAdmin->ai_config_access_version),
                        'resolver_policy_version' => AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION,
                        'quality_policy_snapshot' => $this->qualityPolicyResolver->snapshot($qualityPolicy),
                        'quality_model_candidate_ids' => $qualityCandidateIds,
                    ],
                ]);

                if ($sourceCheckId) {
                    $sourceCheck = ArticleAiQualityCheck::query()
                        ->whereKey((int) $sourceCheckId)
                        ->lockForUpdate()
                        ->first();
                    $sourceSnapshot = is_array($sourceCheck?->article_snapshot) ? $sourceCheck->article_snapshot : [];
                    if (! $sourceCheck
                        || (string) $sourceCheck->status !== 'completed'
                        || (string) $sourceCheck->inspection_scope !== 'full'
                        || ! $this->qualityCheckMatchesRunExecution($sourceCheck, $run)
                        || ! hash_equals($baseHash, $this->riskScanner->contentHash($sourceSnapshot))) {
                        $run->forceFill(['status' => ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY])->save();
                    } else {
                        $run->forceFill(['source_check_id' => (int) $sourceCheck->id])->save();
                        if ((string) $sourceCheck->decision === 'passed'
                            && (int) $sourceCheck->score >= (int) $run->target_score) {
                            $run->forceFill([
                                'status' => ArticleAiOptimizationRun::STATUS_COMPLETED,
                                'stop_reason' => 'target_already_met',
                                'active_dedupe_key' => null,
                                'finished_at' => now(),
                            ])->save();
                        }
                    }
                }

                return $run->fresh();
            });
        } catch (QueryException $exception) {
            $run = ArticleAiOptimizationRun::query()
                ->where('article_id', (int) $article->id)
                ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES)
                ->latest('id')
                ->first();
            if (! $run instanceof ArticleAiOptimizationRun) {
                throw $exception;
            }
        }

        return $this->ensureScheduled($run, $article, $dispatch, $requestedByAdminId);
    }

    public function interceptCompletedWorkflow(int $checkId): bool
    {
        $check = ArticleAiQualityCheck::query()->with(['article', 'task'])->find($checkId);
        if (! $check
            || (string) $check->status !== 'completed'
            || ! (bool) $check->gate_applied
            || in_array((string) $check->evaluation_mode, ['optimization_candidate', 'optimization_final'], true)
            || ! $check->article
            || (string) $check->article->status !== 'draft') {
            return false;
        }

        $awaiting = ArticleAiOptimizationRun::query()
            ->where('source_check_id', $checkId)
            ->where('status', ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY)
            ->latest('id')
            ->first();
        if ($awaiting) {
            $continue = $this->activateAwaitingRun($awaiting, $check);
            if ($continue === 'dispatch') {
                $this->dispatch($awaiting->fresh());

                return true;
            }

            return $continue === 'hold';
        }

        $task = $check->task;
        if (! (bool) config('geoflow.ai_quality_optimization_enabled', false)
            || ! $task
            || ! (bool) $task->ai_quality_enabled
            || ! (bool) $task->ai_quality_auto_optimize_enabled
            || ! $task->aiModel
            || ! $this->rolloutSelected((int) $task->id, 'ai_quality_optimization_percent')) {
            return false;
        }

        $this->holdWorkflowForOptimization($checkId);
        try {
            $run = $this->start(
                $check->article,
                (string) $task->ai_quality_optimization_level,
                $task->aiModel,
                ArticleAiOptimizationRun::TRIGGER_TASK_AUTO,
                dispatch: true,
            );
        } catch (Throwable $exception) {
            $this->markWorkflowHeldForReview($checkId, $exception instanceof ArticleAiOptimizationException
                ? $exception->errorCode()
                : 'article_ai_optimization_start_failed');
            report($exception);

            return true;
        }

        if ((string) $run->status === ArticleAiOptimizationRun::STATUS_COMPLETED) {
            $this->resetWorkflowToPending($checkId);

            return false;
        }

        return true;
    }

    public function recoverWaitingWorkflow(int $checkId): bool
    {
        $latestRun = ArticleAiOptimizationRun::query()
            ->where('source_check_id', $checkId)
            ->latest('id')
            ->first();
        if ($latestRun && in_array((string) $latestRun->status, ArticleAiOptimizationRun::ACTIVE_STATUSES, true)) {
            return false;
        }
        if ($latestRun
            && (string) $latestRun->status === ArticleAiOptimizationRun::STATUS_CANCELLED
            && in_array((string) $latestRun->stop_reason, [
                'task_auto_optimization_disabled',
                'optimization_feature_disabled',
            ], true)) {
            $this->resetWorkflowToPending($checkId);
            $this->inspectionService->applyCompletedWorkflow($checkId);

            return true;
        }
        if ($latestRun
            && (string) $latestRun->status === ArticleAiOptimizationRun::STATUS_STALE
            && (string) $latestRun->stop_reason === 'task_optimization_level_changed') {
            if ($this->interceptCompletedWorkflow($checkId)) {
                return true;
            }

            $this->resetWorkflowToPending($checkId);
            $this->inspectionService->applyCompletedWorkflow($checkId);

            return true;
        }
        if ($latestRun && in_array((string) $latestRun->status, [
            ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW,
            ArticleAiOptimizationRun::STATUS_FAILED,
            ArticleAiOptimizationRun::STATUS_STALE,
            ArticleAiOptimizationRun::STATUS_CANCELLED,
        ], true)) {
            $this->markWorkflowHeldForReview($checkId, (string) ($latestRun->stop_reason ?: $latestRun->error_code ?: 'optimization_unavailable'));

            return true;
        }
        if ($this->interceptCompletedWorkflow($checkId)) {
            return true;
        }

        $this->resetWorkflowToPending($checkId);
        $this->inspectionService->applyCompletedWorkflow($checkId);

        return true;
    }

    public function process(int $runId, ?string $attemptOwner = null): void
    {
        if (! (bool) config('geoflow.ai_quality_optimization_enabled', false)) {
            $run = $this->cancel($runId, 'optimization_feature_disabled');
            if ($run->source_check_id) {
                $this->recoverWaitingWorkflow((int) $run->source_check_id);
            }

            return;
        }
        $claimed = $this->claim($runId, $attemptOwner);
        if ($claimed === null) {
            $staleRun = ArticleAiOptimizationRun::query()->find($runId);
            if ($staleRun
                && (string) $staleRun->status === ArticleAiOptimizationRun::STATUS_STALE
                && (string) $staleRun->stop_reason === 'task_auto_optimization_policy_changed'
                && $staleRun->source_check_id) {
                $this->recoverWaitingWorkflow((int) $staleRun->source_check_id);
            }

            return;
        }

        $run = null;
        $step = null;
        $modelAttempts = [];
        $winningUsageSession = null;
        try {
            $run = ArticleAiOptimizationRun::query()
                ->with(['article.task', 'sourceCheck', 'bestCheck'])
                ->findOrFail($runId);
            $inputCheck = $run->bestCheck ?: $run->sourceCheck;
            $article = $run->article;
            if (! $article instanceof Article || ! $inputCheck instanceof ArticleAiQualityCheck) {
                throw new ArticleAiOptimizationException('article_ai_optimization_input_unavailable');
            }
            if (! $this->qualityCheckMatchesRunExecution($inputCheck, $run)) {
                $this->markRunStale($run, 'optimization_execution_snapshot_changed');

                return;
            }
            $executionMeta = is_array($run->execution_meta) ? $run->execution_meta : [];
            $models = $this->modelsForRun($run, $article);
            $model = $models[0] ?? null;
            if (! $model instanceof AiModel) {
                throw new ArticleAiOptimizationException('article_ai_optimization_model_unavailable');
            }
            $snapshot = is_array($inputCheck->article_snapshot) ? $inputCheck->article_snapshot : [];
            $repairPlan = $this->repairPlan(
                $snapshot,
                $this->selectIssues($inputCheck),
                (int) $inputCheck->score,
                (string) $inputCheck->decision,
                (int) $run->target_score,
            );
            $repairTasks = $repairPlan['tasks'];
            $issues = $repairPlan['issues'];
            if ($repairTasks === []) {
                $this->finishForReview($runId, 'no_auto_fixable_issue');

                return;
            }
            $beforeHash = $this->riskScanner->contentHash($snapshot);
            $roundIndex = (int) $run->completed_rounds + 1;
            $step = $this->beginStep($run, $inputCheck, $model, $roundIndex, $beforeHash, $claimed, $issues);
            if ((string) $step->status === ArticleAiOptimizationRun::STATUS_EVALUATING) {
                return;
            }
            $refineAttempt = $this->claimStepRefineAttempt($run, $step, $claimed);

            $response = $this->refineWithFailover(
                $run,
                $step,
                $models,
                $this->refinerPrompt($run, $inputCheck, $issues, $repairTasks, $beforeHash),
                $modelAttempts,
                $refineAttempt,
                $winningUsageSession,
            );
            $result = is_array($response['result'] ?? null) ? $response['result'] : [];
            if (! hash_equals($beforeHash, (string) ($result['base_article_hash'] ?? ''))
                || (string) ($result['strategy'] ?? '') !== (string) $run->strategy) {
                throw new ArticleAiOptimizationException('article_ai_optimization_model_context_mismatch', httpStatus: 422);
            }

            $this->setPhase($runId, $claimed, ArticleAiOptimizationRun::STATUS_VALIDATING);
            $policy = is_array($executionMeta['policy'] ?? null)
                ? $executionMeta['policy']
                : $this->optimizationPolicy->resolve((string) $run->strategy, (int) $inputCheck->pass_score);
            $validated = $this->patchValidator->validateAndApply(
                $snapshot,
                $inputCheck,
                $this->repairOperations(
                    is_array($result['operations'] ?? null) ? $result['operations'] : [],
                    $repairTasks,
                ),
                $policy,
            );
            $afterHash = $this->riskScanner->contentHash($validated['candidate']);
            $resolvedModelId = (int) data_get($response, 'model.id', 0);
            $resolvedModel = collect($models)->first(
                static fn (AiModel $candidate): bool => (int) $candidate->id === $resolvedModelId,
            );
            if (! $resolvedModel instanceof AiModel) {
                throw new ArticleAiOptimizationException('article_ai_optimization_model_unavailable');
            }
            $this->executionBoundaryHook->beforeCandidateCommit($run, $step, $resolvedModel);
            DB::transaction(function () use ($article, $run, $step, $inputCheck, $validated, $response, $beforeHash, $afterHash, $claimed, $resolvedModel): void {
                $this->assertOptimizationExecutionCurrent($run, $resolvedModel);
                $candidate = $this->inspectionService->createOptimizationCandidate(
                    $article,
                    $validated['candidate'],
                    $inputCheck,
                    (int) $run->id,
                    (string) $run->trigger === ArticleAiOptimizationRun::TRIGGER_TASK_AUTO
                        ? 'optimization_task_candidate'
                        : 'optimization_manual_candidate',
                    dispatch: true,
                );
                $this->markEvaluating(
                    $run,
                    $step,
                    $inputCheck,
                    $candidate,
                    $validated,
                    $response,
                    $beforeHash,
                    $afterHash,
                    $claimed,
                );
            });
            $winningUsageSession?->succeeded();
        } catch (ArticleAiOptimizationException $exception) {
            $this->finalizeOptimizationUsageForException($winningUsageSession, $exception);
            if ($run instanceof ArticleAiOptimizationRun && $step instanceof ArticleAiOptimizationStep) {
                $this->recordStepModelAttempts($run, $step, $claimed, $modelAttempts);
            }
            if ($this->isPermanentOptimizationFailure($exception)) {
                $this->markFailed($runId, $exception, $claimed);

                return;
            }
            if ($exception->httpStatus() === 422 && $step instanceof ArticleAiOptimizationStep) {
                $this->rejectStep($runId, (int) $step->id, $claimed, $exception);

                return;
            }

            $this->releaseLease($runId, $claimed);
            throw $exception;
        } catch (Throwable $exception) {
            $this->finalizeOptimizationUsageForException($winningUsageSession, $exception);
            if ($run instanceof ArticleAiOptimizationRun && $step instanceof ArticleAiOptimizationStep) {
                $this->recordStepModelAttempts($run, $step, $claimed, $modelAttempts);
            }
            if ($this->isPermanentOptimizationFailure($exception)) {
                $permanent = $exception instanceof ArticleAiOptimizationException
                    ? $exception
                    : new ArticleAiOptimizationException(
                        'article_ai_optimization_provider_error',
                        previous: $exception,
                    );
                $this->markFailed($runId, $permanent, $claimed);

                return;
            }
            $this->releaseLease($runId, $claimed);
            throw $exception;
        }
    }

    public function candidateCompleted(int $checkId): void
    {
        $candidate = ArticleAiQualityCheck::query()->find($checkId);
        $runId = (int) data_get($candidate?->execution_meta, 'optimization_run_id', 0);
        if (! $candidate || $runId <= 0 || (string) $candidate->evaluation_mode !== 'optimization_candidate') {
            return;
        }
        $runInfo = ArticleAiOptimizationRun::query()->whereKey($runId)->first(['article_id', 'task_id']);
        if (! $runInfo) {
            return;
        }

        $outcome = DB::transaction(function () use ($runId, $runInfo, $checkId): array {
            $committedEpoch = max(1, (int) (ArticleAiQualityRollout::query()
                ->whereKey(1)
                ->lockForUpdate()
                ->value('epoch') ?? 1));
            $article = Article::query()->whereKey((int) $runInfo->article_id)->lockForUpdate()->first();
            if (! $article) {
                return ['action' => 'none'];
            }
            if ($runInfo->task_id) {
                $task = Task::withTrashed()->whereKey((int) $runInfo->task_id)->lockForUpdate()->first();
                if ($task instanceof Task && ! $task->trashed()) {
                    $task->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
                    $article->setRelation('task', $task);
                }
            }
            $run = ArticleAiOptimizationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || (string) $run->status !== ArticleAiOptimizationRun::STATUS_EVALUATING) {
                return ['action' => 'none'];
            }
            if ($run->deadline_at?->isPast()) {
                $run->forceFill([
                    'status' => ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW,
                    'stop_reason' => 'deadline_exceeded',
                    'active_dedupe_key' => null,
                    'lease_owner' => null,
                    'lease_expires_at' => null,
                    'finished_at' => now(),
                ])->save();

                return ['action' => 'none'];
            }
            if (! $this->policyHashMatches($article, $run)) {
                $this->markRunStale($run, 'optimization_policy_changed');

                return ['action' => 'none'];
            }
            $candidate = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $candidate
                || (string) $candidate->status !== 'completed'
                || ! $this->inspectionService->rolloutEpochMatches($candidate, $committedEpoch)) {
                $this->markRunStale($run, 'rollout_epoch_changed');

                return ['action' => 'none'];
            }
            $step = ArticleAiOptimizationStep::query()
                ->where('run_id', $runId)
                ->where('output_check_id', $checkId)
                ->lockForUpdate()
                ->first();
            $expectedInputCheckId = (int) ($run->best_check_id ?: $run->source_check_id);
            $expectedRound = (int) $run->completed_rounds + 1;
            if (! $step
                || (string) $step->status !== ArticleAiOptimizationRun::STATUS_EVALUATING
                || (int) $step->round_index !== $expectedRound
                || (int) $step->input_check_id !== $expectedInputCheckId) {
                return ['action' => 'none'];
            }
            $input = ArticleAiQualityCheck::query()->whereKey((int) $step->input_check_id)->first();
            if (! $input || ! $this->inspectionService->rolloutEpochMatches($input, $committedEpoch)) {
                $this->markRunStale($run, 'rollout_epoch_changed');

                return ['action' => 'none'];
            }
            if (! $this->qualityCheckMatchesRunExecution($input, $run)
                || ! $this->qualityCheckMatchesRunExecution($candidate, $run)) {
                $this->markRunStale($run, 'optimization_execution_snapshot_changed');

                return ['action' => 'none'];
            }

            $strictAccepted = $this->candidateAccepted($input, $candidate);
            $progressAccepted = ! $strictAccepted
                && (int) $step->round_index < (int) $run->max_rounds
                && $this->candidateMadeTargetedProgress($input, $candidate, (array) $step->selected_causes);
            $accepted = $strictAccepted || $progressAccepted;
            $candidateSnapshot = is_array($candidate->article_snapshot) ? $candidate->article_snapshot : [];
            $candidateHash = $this->riskScanner->contentHash($candidateSnapshot);
            $step->forceFill([
                'status' => $accepted ? 'accepted' : 'rejected',
                'rejection_code' => $accepted ? null : 'candidate_not_improved',
                'after_score' => $candidate->score,
                'after_decision' => $candidate->decision,
                'after_hash' => $candidateHash,
                'finished_at' => now(),
            ])->save();

            $run->forceFill([
                'completed_rounds' => max((int) $run->completed_rounds, (int) $step->round_index),
                'lease_owner' => null,
                'lease_expires_at' => null,
            ]);
            if (! $accepted) {
                $run->forceFill([
                    'status' => ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW,
                    'stop_reason' => 'candidate_not_improved',
                    'active_dedupe_key' => null,
                    'finished_at' => now(),
                ])->save();

                return ['action' => 'none'];
            }

            $run->forceFill([
                'best_check_id' => (int) $candidate->id,
                'candidate_hash' => $candidateHash,
            ]);
            if ($progressAccepted) {
                $run->forceFill(['status' => ArticleAiOptimizationRun::STATUS_QUEUED])->save();

                return ['action' => 'dispatch'];
            }

            $shouldAutoApply = $this->autoApplySelected($run);
            $targetMet = (string) $candidate->decision === 'passed'
                && (int) $candidate->score >= (int) $run->target_score;
            if ($targetMet) {
                $run->forceFill([
                    'status' => ArticleAiOptimizationRun::STATUS_CANDIDATE_READY,
                    'stop_reason' => 'target_met',
                    'deadline_at' => $shouldAutoApply
                        ? now()->addSeconds(max(60, (int) config('geoflow.ai_quality_optimization_recovery_stale_seconds', 300)))
                        : null,
                ])->save();

                return [
                    'action' => $shouldAutoApply ? 'apply' : 'none',
                    'candidate_hash' => $candidateHash,
                ];
            }
            if ((int) $run->completed_rounds < (int) $run->max_rounds) {
                $run->forceFill(['status' => ArticleAiOptimizationRun::STATUS_QUEUED])->save();

                return ['action' => 'dispatch'];
            }

            $run->forceFill([
                'status' => ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW,
                'stop_reason' => 'round_limit_reached',
                'active_dedupe_key' => null,
                'finished_at' => now(),
            ])->save();

            return ['action' => 'none'];
        });

        if (($outcome['action'] ?? null) === 'dispatch') {
            $run = ArticleAiOptimizationRun::query()->find($runId);
            if ($run) {
                $this->dispatch($run);
            }
        } elseif (($outcome['action'] ?? null) === 'apply') {
            $this->apply($runId, (string) ($outcome['candidate_hash'] ?? ''));
        }
    }

    public function apply(int $runId, string $candidateHash, ?int $adminId = null): ArticleAiOptimizationRun
    {
        if (! (bool) config('geoflow.ai_quality_optimization_enabled', false)) {
            throw new ArticleAiOptimizationException('article_ai_optimization_disabled', httpStatus: 409);
        }
        $runInfo = ArticleAiOptimizationRun::query()->whereKey($runId)->firstOrFail(['article_id', 'task_id']);
        $applyResult = DB::transaction(function () use ($runId, $runInfo, $candidateHash, $adminId): array {
            $committedEpoch = max(1, (int) (ArticleAiQualityRollout::query()
                ->whereKey(1)
                ->lockForUpdate()
                ->value('epoch') ?? 1));
            $article = Article::query()->whereKey((int) $runInfo->article_id)->lockForUpdate()->firstOrFail();
            if ($runInfo->task_id) {
                $task = Task::withTrashed()->whereKey((int) $runInfo->task_id)->lockForUpdate()->first();
                if ($task instanceof Task && ! $task->trashed()) {
                    $task->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
                    $article->setRelation('task', $task);
                }
            }
            $run = ArticleAiOptimizationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            $currentSnapshot = $this->qualityPolicyResolver->articleSnapshot($article);
            $currentHash = $this->riskScanner->contentHash($currentSnapshot);
            if ((string) $run->status === ArticleAiOptimizationRun::STATUS_COMPLETED
                && $run->final_check_id !== null
                && $run->applied_article_hash !== null
                && ! data_get($run->execution_meta, 'rolled_back_at')
                && hash_equals((string) $run->candidate_hash, $candidateHash)
                && hash_equals((string) $run->applied_article_hash, $currentHash)) {
                return ['final_check_id' => 0];
            }
            $manualFallback = (string) $run->status === ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW
                && in_array((string) $run->trigger, [
                    ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
                    ArticleAiOptimizationRun::TRIGGER_API_MANUAL,
                ], true);
            if (((string) $run->status !== ArticleAiOptimizationRun::STATUS_CANDIDATE_READY && ! $manualFallback)
                || (string) $article->status !== 'draft'
                || ! hash_equals((string) $run->candidate_hash, $candidateHash)) {
                throw new ArticleAiOptimizationException('article_ai_optimization_candidate_conflict');
            }
            if (! hash_equals((string) $run->base_article_hash, $currentHash)) {
                $this->markRunStale($run, 'article_changed');

                return ['error_code' => 'article_ai_optimization_stale'];
            }
            if (! $this->policyHashMatches($article, $run)) {
                $this->markRunStale($run, 'optimization_policy_changed');

                return ['error_code' => 'article_ai_optimization_stale'];
            }
            if ($this->hasActiveDistribution((int) $article->id)) {
                throw new ArticleAiOptimizationException('article_ai_optimization_distribution_active');
            }
            $checks = ArticleAiQualityCheck::query()
                ->whereIn('id', array_values(array_unique(array_filter([
                    (int) $run->source_check_id,
                    (int) $run->best_check_id,
                ]))))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $source = $checks->get((int) $run->source_check_id);
            $candidate = $checks->get((int) $run->best_check_id);
            if (! $source instanceof ArticleAiQualityCheck || ! $candidate instanceof ArticleAiQualityCheck) {
                throw new ArticleAiOptimizationException('article_ai_optimization_candidate_invalid');
            }
            if (! $this->qualityCheckMatchesRunExecution($source, $run)
                || ! $this->qualityCheckMatchesRunExecution($candidate, $run)) {
                $this->markRunStale($run, 'optimization_execution_snapshot_changed');

                return ['error_code' => 'article_ai_optimization_stale'];
            }
            if (! $this->inspectionService->rolloutEpochMatches($source, $committedEpoch)
                || ! $this->inspectionService->rolloutEpochMatches($candidate, $committedEpoch)) {
                $this->markRunStale($run, 'rollout_epoch_changed');

                return ['error_code' => 'article_ai_optimization_stale'];
            }
            $step = ArticleAiOptimizationStep::query()
                ->where('run_id', $runId)
                ->where('output_check_id', (int) $candidate->id)
                ->lockForUpdate()
                ->firstOrFail();
            $snapshot = is_array($candidate->article_snapshot) ? $candidate->article_snapshot : [];
            if ((string) $candidate->status !== 'completed'
                || (! $manualFallback && (string) $candidate->decision !== 'passed')
                || ($manualFallback && ! in_array((string) $candidate->decision, ['passed', 'needs_review'], true))
                || (! $manualFallback && (int) $candidate->score < (int) $run->target_score)
                || ($manualFallback && ! $this->candidateAccepted($source, $candidate))
                || ! hash_equals($candidateHash, $this->riskScanner->contentHash($snapshot))) {
                throw new ArticleAiOptimizationException('article_ai_optimization_candidate_invalid');
            }
            $risk = $this->riskScanner->scan($snapshot);
            if ((string) $risk['status'] === 'blocked') {
                throw new ArticleAiOptimizationException('article_ai_optimization_candidate_risk');
            }

            $run->forceFill(['status' => ArticleAiOptimizationRun::STATUS_APPLYING])->save();
            $article->forceFill(array_intersect_key($snapshot, array_flip([
                'title', 'excerpt', 'content', 'keywords', 'meta_description',
            ])))->save();
            $appliedHash = $this->riskScanner->contentHash($this->qualityPolicyResolver->articleSnapshot($article));
            if (! hash_equals($candidateHash, $appliedHash)) {
                throw new ArticleAiOptimizationException('article_ai_optimization_apply_hash_mismatch');
            }

            $candidateMeta = is_array($candidate->execution_meta) ? $candidate->execution_meta : [];
            $candidate->forceFill([
                'evaluation_mode' => 'optimization_final',
                'gate_applied' => true,
                'supersedes_check_id' => $run->source_check_id,
                'execution_meta' => array_replace($candidateMeta, [
                    'workflow_apply' => [
                        'status' => 'pending',
                        'attempts' => 0,
                        'error_code' => null,
                        'updated_at' => now()->toIso8601String(),
                    ],
                    'optimization_applied_by_admin_id' => $adminId,
                ]),
            ])->save();
            $this->setWorkflowStatusOnCheck($source, 'superseded');
            $runMeta = is_array($run->execution_meta) ? $run->execution_meta : [];
            $run->forceFill([
                'status' => ArticleAiOptimizationRun::STATUS_COMPLETED,
                'final_check_id' => (int) $candidate->id,
                'applied_article_hash' => $appliedHash,
                'active_dedupe_key' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
                'execution_meta' => array_replace($runMeta, [
                    'applied_at' => now()->toIso8601String(),
                    'applied_by_admin_id' => $adminId,
                ]),
            ])->save();

            return ['final_check_id' => (int) $candidate->id];
        });
        if (isset($applyResult['error_code'])) {
            throw new ArticleAiOptimizationException((string) $applyResult['error_code']);
        }
        $finalCheckId = (int) ($applyResult['final_check_id'] ?? 0);

        if ($finalCheckId > 0) {
            $this->inspectionService->applyCompletedWorkflow($finalCheckId);
        }

        return ArticleAiOptimizationRun::query()->with(['bestCheck', 'finalCheck'])->findOrFail($runId);
    }

    public function retryAutoApply(int $runId): bool
    {
        $run = ArticleAiOptimizationRun::query()->find($runId);
        if (! $run instanceof ArticleAiOptimizationRun
            || (string) $run->status !== ArticleAiOptimizationRun::STATUS_CANDIDATE_READY) {
            return false;
        }
        if (! $this->autoApplySelected($run)) {
            ArticleAiOptimizationRun::query()
                ->whereKey($runId)
                ->where('status', ArticleAiOptimizationRun::STATUS_CANDIDATE_READY)
                ->update(['deadline_at' => null, 'updated_at' => now()]);

            return false;
        }

        $this->apply($runId, (string) $run->candidate_hash);

        return true;
    }

    public function cancel(
        int $runId,
        string $reason = 'cancelled_by_admin',
        ?int $adminId = null,
    ): ArticleAiOptimizationRun {
        $runInfo = ArticleAiOptimizationRun::query()->whereKey($runId)->firstOrFail(['article_id', 'task_id']);

        return DB::transaction(function () use ($runId, $runInfo, $reason, $adminId): ArticleAiOptimizationRun {
            $article = Article::query()->whereKey((int) $runInfo->article_id)->lockForUpdate()->firstOrFail();
            if ($runInfo->task_id) {
                $task = Task::withTrashed()->whereKey((int) $runInfo->task_id)->lockForUpdate()->first();
                if ($task instanceof Task && ! $task->trashed()) {
                    $task->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
                    $article->setRelation('task', $task);
                }
            }
            $run = ArticleAiOptimizationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            if (! in_array((string) $run->status, ArticleAiOptimizationRun::ACTIVE_STATUSES, true)) {
                return $run;
            }
            $candidateIds = ArticleAiOptimizationStep::query()
                ->where('run_id', $runId)
                ->whereNotNull('output_check_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('output_check_id');
            ArticleAiOptimizationStep::query()->where('run_id', $runId)->lockForUpdate()->get(['id']);
            ArticleAiQualityCheck::query()
                ->whereIn('id', $candidateIds)
                ->whereIn('status', ['queued', 'running'])
                ->update([
                    'status' => 'cancelled',
                    'active_dedupe_key' => null,
                    'error_code' => 'optimization_cancelled',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            $executionMeta = is_array($run->execution_meta) ? $run->execution_meta : [];
            $run->forceFill([
                'status' => ArticleAiOptimizationRun::STATUS_CANCELLED,
                'stop_reason' => Str::limit($reason, 80, ''),
                'active_dedupe_key' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'cancelled_at' => now(),
                'finished_at' => now(),
                'execution_meta' => array_replace($executionMeta, [
                    'cancelled_by_admin_id' => $adminId,
                    'cancelled_at' => now()->toIso8601String(),
                ]),
            ])->save();

            return $run->fresh();
        });
    }

    public function rollback(int $runId, ?int $adminId = null): ArticleAiOptimizationRun
    {
        $runInfo = ArticleAiOptimizationRun::query()->whereKey($runId)->firstOrFail(['article_id', 'task_id']);
        $rolledBack = DB::transaction(function () use ($runId, $runInfo, $adminId): bool {
            $article = Article::query()->whereKey((int) $runInfo->article_id)->lockForUpdate()->firstOrFail();
            if ($runInfo->task_id) {
                Task::withTrashed()->whereKey((int) $runInfo->task_id)->lockForUpdate()->first();
            }
            $run = ArticleAiOptimizationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            $checkIds = array_values(array_unique(array_filter([
                (int) $run->source_check_id,
                (int) $run->final_check_id,
            ])));
            $checks = ArticleAiQualityCheck::query()
                ->whereIn('id', $checkIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $source = $checks->get((int) $run->source_check_id);
            $final = $checks->get((int) $run->final_check_id);
            $currentHash = $this->riskScanner->contentHash($this->qualityPolicyResolver->articleSnapshot($article));
            if (data_get($run->execution_meta, 'rolled_back_at')) {
                $sourceSnapshot = is_array($source?->article_snapshot) ? $source->article_snapshot : [];
                if ($source
                    && hash_equals($this->riskScanner->contentHash($sourceSnapshot), $currentHash)) {
                    return false;
                }

                throw new ArticleAiOptimizationException('article_ai_optimization_rollback_conflict');
            }
            if ((string) $run->status !== ArticleAiOptimizationRun::STATUS_COMPLETED
                || (string) $article->status !== 'draft'
                || ! hash_equals(
                    (string) $run->applied_article_hash,
                    $currentHash,
                )
                || $this->hasActiveDistribution((int) $article->id)
                || ! $source
                || ! $final) {
                throw new ArticleAiOptimizationException('article_ai_optimization_rollback_conflict');
            }
            ArticleAiOptimizationStep::query()->where('run_id', $runId)->lockForUpdate()->get(['id']);
            $sourceSnapshot = is_array($source->article_snapshot) ? $source->article_snapshot : [];
            $article->forceFill(array_intersect_key($sourceSnapshot, array_flip([
                'title', 'excerpt', 'content', 'keywords', 'meta_description',
            ])))->save();
            $final->forceFill([
                'status' => 'stale',
                'active_dedupe_key' => null,
                'error_code' => 'optimization_rolled_back',
                'error_message' => 'AI 优化内容已由管理员回滚。',
                'finished_at' => now(),
            ])->save();
            $meta = is_array($run->execution_meta) ? $run->execution_meta : [];
            $run->forceFill(['execution_meta' => array_replace($meta, [
                'rolled_back_at' => now()->toIso8601String(),
                'rolled_back_by_admin_id' => $adminId,
            ])])->save();

            return true;
        });

        if (! $rolledBack) {
            return ArticleAiOptimizationRun::query()->findOrFail($runId);
        }

        $article = Article::query()->findOrFail((int) $runInfo->article_id);
        $this->riskScanner->record($article, 'ai_optimization_rollback', $adminId);
        $this->inspectionService->requestManualInspection(
            $article,
            trigger: 'optimization_rollback',
            dispatch: true,
            auditAdminId: $adminId,
            allowSampling: false,
        );

        return ArticleAiOptimizationRun::query()->findOrFail($runId);
    }

    /** @return array<string,mixed>|null */
    public function statusForArticle(Article|int $article): ?array
    {
        $articleId = $article instanceof Article ? (int) $article->id : $article;
        $run = ArticleAiOptimizationRun::query()
            ->where('article_id', $articleId)
            ->latest('id')
            ->first([
                'id', 'task_id', 'request_key', 'trigger', 'strategy', 'target_score', 'max_rounds',
                'completed_rounds', 'status', 'stop_reason', 'error_code', 'candidate_hash',
                'source_check_id', 'best_check_id', 'final_check_id', 'applied_article_hash', 'deadline_at',
                'started_at', 'finished_at', 'created_at', 'updated_at', 'execution_meta',
            ]);
        if (! $run) {
            return null;
        }
        $best = $run->best_check_id
            ? ArticleAiQualityCheck::query()->find((int) $run->best_check_id, ['id', 'status', 'score', 'decision'])
            : null;
        $source = $run->source_check_id
            ? ArticleAiQualityCheck::query()->find((int) $run->source_check_id, ['id', 'status', 'score', 'decision'])
            : null;
        $lastAttempt = ArticleAiOptimizationStep::query()
            ->where('run_id', (int) $run->id)
            ->whereNotNull('after_score')
            ->orderByDesc('round_index')
            ->first(['before_score', 'after_score', 'rejection_code']);
        $manualFallbackCanApply = (string) $run->status === ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW
            && in_array((string) $run->trigger, [
                ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
                ArticleAiOptimizationRun::TRIGGER_API_MANUAL,
            ], true)
            && $run->candidate_hash !== null
            && $source instanceof ArticleAiQualityCheck
            && $best instanceof ArticleAiQualityCheck
            && (string) $best->status === 'completed'
            && in_array((string) $best->decision, ['passed', 'needs_review'], true)
            && $this->candidateAccepted($source, $best);
        $rollbackArticle = (string) $run->status === ArticleAiOptimizationRun::STATUS_COMPLETED
            ? Article::query()->find($articleId)
            : null;
        $rollbackHashMatches = $rollbackArticle
            && (string) $rollbackArticle->status === 'draft'
            && $run->applied_article_hash
            && hash_equals(
                (string) $run->applied_article_hash,
                $this->riskScanner->contentHash($this->qualityPolicyResolver->articleSnapshot($rollbackArticle)),
            );
        $active = in_array((string) $run->status, ArticleAiOptimizationRun::ACTIVE_STATUSES, true);
        $shouldPoll = $active
            && ((string) $run->status !== ArticleAiOptimizationRun::STATUS_CANDIDATE_READY
                || $this->autoApplySelected($run));

        return [
            'run_id' => (int) $run->id,
            'request_key' => (string) $run->request_key,
            'trigger' => (string) $run->trigger,
            'strategy' => (string) $run->strategy,
            'target_score' => (int) $run->target_score,
            'max_rounds' => (int) $run->max_rounds,
            'completed_rounds' => (int) $run->completed_rounds,
            'status' => (string) $run->status,
            'active' => $active,
            'should_poll' => $shouldPoll,
            'stop_reason' => $run->stop_reason,
            'error_code' => $run->error_code,
            'candidate_hash' => $run->candidate_hash,
            'can_preview' => $run->best_check_id !== null,
            'can_apply' => (string) $run->status === ArticleAiOptimizationRun::STATUS_CANDIDATE_READY
                || $manualFallbackCanApply,
            'can_cancel' => in_array((string) $run->status, ArticleAiOptimizationRun::ACTIVE_STATUSES, true),
            'can_rollback' => (string) $run->status === ArticleAiOptimizationRun::STATUS_COMPLETED
                && $run->final_check_id !== null
                && $run->applied_article_hash !== null
                && $rollbackHashMatches
                && ! data_get($run->execution_meta, 'rolled_back_at')
                && ! $this->hasActiveDistribution($articleId),
            'best_score' => $best?->score,
            'best_decision' => $best?->decision,
            'last_attempt' => $lastAttempt ? [
                'before_score' => $lastAttempt->before_score,
                'after_score' => $lastAttempt->after_score,
                'rejection_code' => $lastAttempt->rejection_code,
            ] : null,
            'deadline_at' => $run->deadline_at?->toIso8601String(),
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed> */
    public function candidate(int $runId): array
    {
        $run = ArticleAiOptimizationRun::query()
            ->with(['sourceCheck:id,article_snapshot,score,decision', 'bestCheck:id,article_snapshot,score,decision'])
            ->findOrFail($runId);
        if (! $run->bestCheck) {
            throw new ArticleAiOptimizationException('article_ai_optimization_candidate_unavailable', httpStatus: 409);
        }
        $before = is_array($run->sourceCheck?->article_snapshot) ? $run->sourceCheck->article_snapshot : [];
        $after = is_array($run->bestCheck->article_snapshot) ? $run->bestCheck->article_snapshot : [];
        $stepRecords = ArticleAiOptimizationStep::query()
            ->with([
                'inputCheck:id,article_snapshot',
                'outputCheck:id,article_snapshot',
            ])
            ->where('run_id', $runId)
            ->orderBy('round_index')
            ->limit(50)
            ->get([
                'id', 'round_index', 'input_check_id', 'output_check_id', 'status',
                'applied_patch', 'validation', 'before_score', 'after_score',
                'before_decision', 'after_decision',
            ]);
        $steps = $stepRecords
            ->map(static fn (ArticleAiOptimizationStep $step): array => [
                'round' => (int) $step->round_index,
                'status' => (string) $step->status,
                'validation' => is_array($step->validation) ? $step->validation : [],
                'before_score' => $step->before_score,
                'after_score' => $step->after_score,
                'before_decision' => $step->before_decision,
                'after_decision' => $step->after_decision,
            ])->all();
        $modifications = $stepRecords
            ->where('status', 'accepted')
            ->flatMap(function (ArticleAiOptimizationStep $step) use ($before, $after): array {
                $stepBefore = is_array($step->inputCheck?->article_snapshot)
                    ? $step->inputCheck->article_snapshot
                    : $before;
                $stepAfter = is_array($step->outputCheck?->article_snapshot)
                    ? $step->outputCheck->article_snapshot
                    : $after;

                return collect(is_array($step->applied_patch) ? $step->applied_patch : [])->map(function (mixed $operation) use ($stepBefore, $stepAfter, $step): ?array {
                    if (! is_array($operation)) {
                        return null;
                    }
                    $field = (string) ($operation['field'] ?? 'content');
                    $start = max(0, (int) ($operation['replace_start'] ?? 0));
                    $end = max($start, (int) ($operation['replace_end'] ?? $start));
                    $contextStart = max(0, $start - 80);
                    $beforeValue = (string) ($stepBefore[$field] ?? '');
                    $afterValue = (string) ($stepAfter[$field] ?? '');

                    return [
                        'round' => (int) $step->round_index,
                        'field' => $field,
                        'before_text' => mb_substr($beforeValue, $contextStart, min(240, max(1, $end - $contextStart + 80)), 'UTF-8'),
                        'after_text' => mb_substr($afterValue, $contextStart, min(240, max(1, mb_strlen((string) ($operation['replacement'] ?? ''), 'UTF-8') + 160)), 'UTF-8'),
                        'issue_codes' => array_values(array_map('strval', (array) ($operation['issue_codes'] ?? []))),
                        'evidence_keys' => array_values(array_map('strval', (array) ($operation['evidence_keys'] ?? []))),
                        'reason' => (string) ($operation['reason'] ?? ''),
                    ];
                })->filter()->all();
            })
            ->filter()
            ->take(50)
            ->values()
            ->all();

        return [
            'run_id' => (int) $run->id,
            'status' => (string) $run->status,
            'strategy' => (string) $run->strategy,
            'target_score' => (int) $run->target_score,
            'candidate_hash' => (string) $run->candidate_hash,
            'before_score' => $run->sourceCheck?->score,
            'after_score' => $run->bestCheck->score,
            'before_decision' => $run->sourceCheck?->decision,
            'after_decision' => $run->bestCheck->decision,
            'steps' => $steps,
            'field_counts' => collect($modifications)->countBy('field')->all(),
            'modifications' => $modifications,
        ];
    }

    public function markFailed(int $runId, Throwable $exception, ?string $attemptOwner = null): void
    {
        ArticleAiOptimizationRun::query()
            ->whereKey($runId)
            ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES)
            ->when($attemptOwner !== null && $attemptOwner !== '', function ($query) use ($attemptOwner): void {
                $query->where('execution_meta->attempt_owner', $attemptOwner);
            })
            ->update([
                'status' => ArticleAiOptimizationRun::STATUS_FAILED,
                'active_dedupe_key' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'error_code' => $exception instanceof ArticleAiOptimizationException
                    ? $exception->errorCode()
                    : 'article_ai_optimization_failed',
                'error_message' => Str::limit($exception->getMessage(), 500, ''),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function activateAwaitingRun(ArticleAiOptimizationRun $awaiting, ArticleAiQualityCheck $completedCheck): string
    {
        return DB::transaction(function () use ($awaiting, $completedCheck): string {
            $article = Article::query()->whereKey((int) $awaiting->article_id)->lockForUpdate()->first();
            if (! $article) {
                return 'hold';
            }
            if ($awaiting->task_id) {
                Task::withTrashed()->whereKey((int) $awaiting->task_id)->lockForUpdate()->first();
            }
            $run = ArticleAiOptimizationRun::query()->whereKey((int) $awaiting->id)->lockForUpdate()->first();
            $check = ArticleAiQualityCheck::query()->whereKey((int) $completedCheck->id)->lockForUpdate()->first();
            if (! $run || ! $check || (string) $run->status !== ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY) {
                return 'hold';
            }
            $this->setWorkflowStatusOnCheck($check, 'waiting_optimization');
            $snapshot = is_array($check->article_snapshot) ? $check->article_snapshot : [];
            if ((string) $check->inspection_scope !== 'full'
                || ! $this->qualityCheckMatchesRunExecution($check, $run)
                || ! hash_equals((string) $run->base_article_hash, $this->riskScanner->contentHash($snapshot))) {
                $reason = $this->qualityCheckMatchesRunExecution($check, $run)
                    ? 'full_quality_check_unavailable'
                    : 'optimization_execution_snapshot_changed';
                if ($reason === 'optimization_execution_snapshot_changed') {
                    $this->markRunStale($run, $reason);
                } else {
                    $run->forceFill([
                        'status' => ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW,
                        'stop_reason' => $reason,
                        'active_dedupe_key' => null,
                        'finished_at' => now(),
                    ])->save();
                }
                $this->setWorkflowStatusOnCheck($check, 'held_for_review', $reason);

                return 'hold';
            }
            if ((string) $check->decision === 'passed' && (int) $check->score >= (int) $run->target_score) {
                $run->forceFill([
                    'status' => ArticleAiOptimizationRun::STATUS_COMPLETED,
                    'stop_reason' => 'target_already_met',
                    'active_dedupe_key' => null,
                    'finished_at' => now(),
                ])->save();
                $this->setWorkflowStatusOnCheck($check, 'pending');

                return 'continue';
            }

            $run->forceFill(['status' => ArticleAiOptimizationRun::STATUS_QUEUED])->save();

            return 'dispatch';
        });
    }

    private function holdWorkflowForOptimization(int $checkId): void
    {
        $checkInfo = ArticleAiQualityCheck::query()->whereKey($checkId)->first(['article_id', 'task_id']);
        if (! $checkInfo) {
            return;
        }
        DB::transaction(function () use ($checkId, $checkInfo): void {
            Article::query()->whereKey((int) $checkInfo->article_id)->lockForUpdate()->first();
            if ($checkInfo->task_id) {
                Task::withTrashed()->whereKey((int) $checkInfo->task_id)->lockForUpdate()->first();
            }
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if ($check && (string) $check->status === 'completed') {
                $this->setWorkflowStatusOnCheck($check, 'waiting_optimization');
            }
        });
    }

    private function resetWorkflowToPending(int $checkId): void
    {
        $this->setWorkflowStatus($checkId, 'pending');
    }

    private function markWorkflowHeldForReview(int $checkId, string $errorCode): void
    {
        $this->setWorkflowStatus($checkId, 'held_for_review', $errorCode);
    }

    private function setWorkflowStatus(int $checkId, string $status, ?string $errorCode = null): void
    {
        $checkInfo = ArticleAiQualityCheck::query()->whereKey($checkId)->first(['article_id', 'task_id']);
        if (! $checkInfo) {
            return;
        }
        DB::transaction(function () use ($checkId, $checkInfo, $status, $errorCode): void {
            Article::query()->whereKey((int) $checkInfo->article_id)->lockForUpdate()->first();
            if ($checkInfo->task_id) {
                Task::withTrashed()->whereKey((int) $checkInfo->task_id)->lockForUpdate()->first();
            }
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if ($check) {
                $this->setWorkflowStatusOnCheck($check, $status, $errorCode);
            }
        });
    }

    private function setWorkflowStatusOnCheck(
        ArticleAiQualityCheck $check,
        string $status,
        ?string $errorCode = null,
    ): void {
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        $workflow = is_array($executionMeta['workflow_apply'] ?? null)
            ? $executionMeta['workflow_apply']
            : [];
        $executionMeta['workflow_apply'] = [
            'status' => $status,
            'attempts' => (int) ($workflow['attempts'] ?? 0),
            'error_code' => $errorCode,
            'updated_at' => now()->toIso8601String(),
        ];
        $check->forceFill(['execution_meta' => $executionMeta])->save();
    }

    private function rolloutSelected(int $subjectId, string $configKey): bool
    {
        $percent = max(0, min(100, (int) config('geoflow.'.$configKey, 0)));

        return $percent >= 100 || ($percent > 0
            && (abs(crc32($configKey.':'.$subjectId)) % 100) < $percent);
    }

    private function claim(int $runId, ?string $attemptOwner = null): ?string
    {
        $runInfo = ArticleAiOptimizationRun::query()
            ->whereKey($runId)
            ->first(['article_id', 'task_id', 'source_check_id', 'best_check_id']);
        if (! $runInfo) {
            return null;
        }

        return DB::transaction(function () use ($runId, $runInfo, $attemptOwner): ?string {
            $article = Article::query()->whereKey((int) $runInfo->article_id)->lockForUpdate()->first();
            if (! $article) {
                return null;
            }
            $lockedTask = $runInfo->task_id
                ? Task::withTrashed()->whereKey((int) $runInfo->task_id)->lockForUpdate()->first()
                : null;
            if ($lockedTask instanceof Task && ! $lockedTask->trashed()) {
                $lockedTask->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
                $article->setRelation('task', $lockedTask);
            }
            $run = ArticleAiOptimizationRun::query()->whereKey($runId)->lockForUpdate()->first();
            if (! $run || (string) $run->status !== ArticleAiOptimizationRun::STATUS_QUEUED) {
                return null;
            }
            $inputCheckId = (int) ($run->best_check_id ?: $run->source_check_id);
            $input = $inputCheckId > 0
                ? ArticleAiQualityCheck::query()->whereKey($inputCheckId)->lockForUpdate()->first()
                : null;
            ArticleAiOptimizationStep::query()
                ->where('run_id', $runId)
                ->where('round_index', (int) $run->completed_rounds + 1)
                ->lockForUpdate()
                ->first();

            if ((string) $run->trigger === ArticleAiOptimizationRun::TRIGGER_TASK_AUTO
                && (! $lockedTask instanceof Task
                    || $lockedTask->trashed()
                    || ! (bool) $lockedTask->ai_quality_enabled
                    || ! (bool) $lockedTask->ai_quality_auto_optimize_enabled
                    || (string) $lockedTask->ai_quality_optimization_level !== (string) $run->strategy)) {
                $this->markRunStale($run, 'task_auto_optimization_policy_changed');

                return null;
            }

            if ((string) $article->status !== 'draft'
                || ! $input
                || (string) $input->status !== 'completed'
                || (string) $input->inspection_scope !== 'full') {
                $this->markRunStale($run, 'optimization_input_changed');

                return null;
            }
            try {
                $this->assertOptimizationExecutionCurrent($run);
            } catch (ArticleAiOptimizationException $exception) {
                if ($this->isPermanentOptimizationFailure($exception)) {
                    $this->markFailed($runId, $exception);

                    return null;
                }

                throw $exception;
            }
            if (! $this->qualityCheckMatchesRunExecution($input, $run)) {
                $this->markRunStale($run, 'optimization_execution_snapshot_changed');

                return null;
            }
            $currentHash = $this->riskScanner->contentHash($this->qualityPolicyResolver->articleSnapshot($article));
            if (! hash_equals((string) $run->base_article_hash, $currentHash)) {
                $this->markRunStale($run, 'article_changed');

                return null;
            }
            if (! $this->policyHashMatches($article, $run)) {
                $this->markRunStale($run, 'optimization_policy_changed');

                return null;
            }
            if ($run->deadline_at?->isPast()) {
                $run->forceFill([
                    'status' => ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW,
                    'stop_reason' => 'deadline_exceeded',
                    'active_dedupe_key' => null,
                    'finished_at' => now(),
                ])->save();

                return null;
            }

            $owner = trim((string) $attemptOwner) !== '' ? trim((string) $attemptOwner) : (string) Str::uuid();
            $executionMeta = is_array($run->execution_meta) ? $run->execution_meta : [];
            $run->forceFill([
                'status' => ArticleAiOptimizationRun::STATUS_PLANNING,
                'lease_owner' => $owner,
                'lease_expires_at' => now()->addSeconds($this->leaseSeconds()),
                'started_at' => $run->started_at ?: now(),
                'execution_meta' => array_replace($executionMeta, ['attempt_owner' => $owner]),
            ])->save();

            return $owner;
        });
    }

    /** @return list<array<string,mixed>> */
    private function selectIssues(ArticleAiQualityCheck $check): array
    {
        $severity = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];

        return collect(is_array($check->issues) ? $check->issues : [])
            ->flatMap(static fn (mixed $issue): array => is_array($issue)
                ? ArticleAiOptimizationPatchValidator::expandIssueOccurrences($issue)
                : [])
            ->filter(static fn (mixed $issue): bool => is_array($issue)
                && (string) ($issue['location_status'] ?? '') === 'resolved'
                && in_array((string) ($issue['code'] ?? ''), self::AUTO_FIXABLE_ISSUE_CODES, true))
            ->sortByDesc(static fn (array $issue): int => ($severity[(string) ($issue['severity'] ?? '')] ?? 0) * 1000
                + (int) ($issue['deduction'] ?? 0))
            ->take(50)
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  list<array<string,mixed>>  $issues
     * @return array{tasks:list<array<string,mixed>>,issues:list<array<string,mixed>>}
     */
    private function repairPlan(
        array $snapshot,
        array $issues,
        int $currentScore,
        string $currentDecision,
        int $targetScore,
    ): array {
        $severityRanks = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        $grouped = collect($issues)
            ->groupBy(static fn (array $issue): string => implode(':', [
                (string) ($issue['field'] ?? ''),
                (int) ($issue['start_offset'] ?? -1),
                (int) ($issue['end_offset'] ?? -1),
            ]))
            ->map(function ($group) use ($snapshot, $severityRanks): ?array {
                $groupIssues = $group->values();
                $first = $groupIssues->first();
                if (! is_array($first)) {
                    return null;
                }
                $field = (string) ($first['field'] ?? '');
                $start = (int) ($first['start_offset'] ?? -1);
                $end = (int) ($first['end_offset'] ?? -1);
                $fieldValue = (string) ($snapshot[$field] ?? '');
                if ($field === '' || $start < 0 || $end <= $start || $end > mb_strlen($fieldValue, 'UTF-8')) {
                    return null;
                }
                $sourceText = mb_substr($fieldValue, $start, $end - $start, 'UTF-8');
                if ($sourceText === '' || $groupIssues->contains(
                    static fn (array $issue): bool => trim((string) ($issue['quote'] ?? '')) !== trim($sourceText),
                )) {
                    return null;
                }

                $priorityRank = $groupIssues->max(
                    static fn (array $issue): int => $severityRanks[(string) ($issue['severity'] ?? '')] ?? 0,
                );
                $evidenceKeys = $groupIssues
                    ->pluck('evidence_keys')
                    ->flatten()
                    ->map('strval')
                    ->filter()
                    ->unique()
                    ->values()
                    ->all();
                $issueCodes = $groupIssues->pluck('code')->map('strval')->filter()->unique()->values()->all();

                return [
                    'field' => $field,
                    'anchor_start' => $start,
                    'anchor_end' => $end,
                    'source_text' => $sourceText,
                    'priority' => array_search($priorityRank, $severityRanks, true) ?: 'low',
                    'priority_rank' => $priorityRank,
                    'estimated_gain' => $groupIssues->sum(fn (array $issue): int => $this->estimatedIssueGain($issue)),
                    'issue_codes' => $issueCodes,
                    'root_cause_keys' => $groupIssues
                        ->pluck('root_cause_key')
                        ->map('strval')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                    'evidence_keys' => $evidenceKeys,
                    'reasons' => $groupIssues->pluck('reason')->map('strval')->filter()->unique()->values()->all(),
                    'suggestions' => $groupIssues->pluck('suggestion')->map('strval')->filter()->unique()->values()->all(),
                    'rewrite_rule' => $evidenceKeys === []
                        && collect($issueCodes)->intersect(['citation_missing', 'unsupported_claim'])->isNotEmpty()
                            ? '没有可用证据：按建议删除、弱化或条件化原主张；不得补写来源、功能、数字、实体或其他新事实。'
                            : '只按质检原因和建议修改原句，不扩展到相邻内容。',
                    '_issues' => $groupIssues->all(),
                ];
            })
            ->filter()
            ->sort(static function (array $left, array $right): int {
                $priority = (int) $right['priority_rank'] <=> (int) $left['priority_rank'];
                if ($priority !== 0) {
                    return $priority;
                }
                $gain = (int) $right['estimated_gain'] <=> (int) $left['estimated_gain'];

                return $gain !== 0 ? $gain : (int) $left['anchor_start'] <=> (int) $right['anchor_start'];
            })
            ->values();

        $scoreGap = max(0, $targetScore - $currentScore);
        $repairAllSelectedPriorities = $scoreGap === 0 && $currentDecision !== 'passed';
        $selected = [];
        $estimatedGain = 0;
        foreach ($grouped as $task) {
            if (count($selected) >= 12) {
                break;
            }
            $selected[] = $task;
            $estimatedGain += max(1, (int) ($task['estimated_gain'] ?? 0));
            if (! $repairAllSelectedPriorities && $estimatedGain >= max(1, $scoreGap)) {
                break;
            }
        }

        $selectedIssues = collect($selected)
            ->pluck('_issues')
            ->flatten(1)
            ->values()
            ->all();
        $tasks = collect($selected)
            ->values()
            ->map(static function (array $task, int $index): array {
                unset($task['priority_rank'], $task['_issues']);
                $task['task_id'] = 'R'.($index + 1);

                return $task;
            })
            ->all();

        return ['tasks' => $tasks, 'issues' => $selectedIssues];
    }

    /** @param array<string,mixed> $issue */
    private function estimatedIssueGain(array $issue): int
    {
        $deduction = (int) ($issue['deduction'] ?? 0);
        if ($deduction > 0) {
            return $deduction;
        }

        $severity = (string) ($issue['severity'] ?? 'medium');
        $dimension = match ((string) ($issue['code'] ?? '')) {
            'knowledge_contradiction', 'unsupported_claim' => 'knowledge',
            'data_mismatch', 'citation_missing', 'citation_scope_mismatch' => 'data',
            'ad_absolute_claim', 'ad_false_or_misleading' => 'advertising',
            default => 'content',
        };
        $deductions = [
            'knowledge' => ['critical' => 20, 'high' => 10, 'medium' => 5, 'low' => 2],
            'data' => ['critical' => 15, 'high' => 8, 'medium' => 4, 'low' => 1],
            'advertising' => ['critical' => 20, 'high' => 12, 'medium' => 6, 'low' => 2],
            'content' => ['critical' => 10, 'high' => 5, 'medium' => 3, 'low' => 1],
        ];

        return (int) ($deductions[$dimension][$severity] ?? $deductions[$dimension]['medium']);
    }

    /**
     * @param  list<array<string,mixed>>  $operations
     * @param  list<array<string,mixed>>  $repairTasks
     * @return list<array<string,mixed>>
     */
    private function repairOperations(array $operations, array $repairTasks): array
    {
        $tasksBySignature = collect($repairTasks)->keyBy(
            static function (array $task): string {
                $keys = array_values(array_map('strval', (array) ($task['root_cause_keys'] ?? [])));
                sort($keys);

                return implode('|', $keys);
            },
        );
        $resolved = [];
        foreach ($operations as $operation) {
            if (! is_array($operation) || ! is_string($operation['replacement'] ?? null)) {
                throw new ArticleAiOptimizationException('article_ai_optimization_invalid_model_output', httpStatus: 422);
            }
            $rootCauseKeys = array_values(array_unique(array_filter(array_map(
                'strval',
                is_array($operation['root_cause_keys'] ?? null) ? $operation['root_cause_keys'] : [],
            ))));
            sort($rootCauseKeys);
            $signature = implode('|', $rootCauseKeys);
            $task = $tasksBySignature->get($signature);
            if (! is_array($task) || isset($resolved[$signature])) {
                throw new ArticleAiOptimizationException('article_ai_optimization_invalid_model_output', httpStatus: 422);
            }
            $resolved[$signature] = [
                'field' => (string) $task['field'],
                'replacement' => $this->sanitizeRepairReplacement((string) $operation['replacement'], $task),
                'issue_codes' => (array) $task['issue_codes'],
                'root_cause_keys' => (array) $task['root_cause_keys'],
                'evidence_keys' => (array) $task['evidence_keys'],
                'reason' => '按质检原因和建议修改定位原句',
            ];
        }
        if (count($resolved) !== count($repairTasks)) {
            throw new ArticleAiOptimizationException('article_ai_optimization_invalid_model_output', httpStatus: 422);
        }

        return array_values($resolved);
    }

    /** @param array<string,mixed> $task */
    private function sanitizeRepairReplacement(string $replacement, array $task): string
    {
        $isUnverifiedEvidenceGap = (array) ($task['evidence_keys'] ?? []) === []
            && collect((array) ($task['issue_codes'] ?? []))
                ->intersect(['citation_missing', 'unsupported_claim'])
                ->isNotEmpty();
        if (! $isUnverifiedEvidenceGap) {
            return $replacement;
        }

        $replacement = str_replace(['“', '”', '「', '」', '『', '』'], '', $replacement);
        $sourceText = (string) ($task['source_text'] ?? '');
        if (preg_match('/^(\s*(?:[-+*]|\d+[.)])\s+)/u', $sourceText, $sourcePrefix) === 1) {
            $replacement = preg_replace('/^\s*(?:[-+*]|\d+[.)])\s+/u', '', $replacement) ?? $replacement;

            return (string) $sourcePrefix[1].ltrim($replacement);
        }
        if (preg_match('/^(\s*>+\s*)/u', $sourceText, $sourcePrefix) === 1) {
            $replacement = preg_replace('/^\s*>+\s*/u', '', $replacement) ?? $replacement;

            return (string) $sourcePrefix[1].ltrim($replacement);
        }

        return $replacement;
    }

    private function beginStep(
        ArticleAiOptimizationRun $run,
        ArticleAiQualityCheck $inputCheck,
        AiModel $model,
        int $roundIndex,
        string $beforeHash,
        string $leaseOwner,
        array $selectedIssues,
    ): ArticleAiOptimizationStep {
        return DB::transaction(function () use ($run, $inputCheck, $model, $roundIndex, $beforeHash, $leaseOwner, $selectedIssues): ArticleAiOptimizationStep {
            Article::query()->whereKey((int) $run->article_id)->lockForUpdate()->firstOrFail();
            if ($run->task_id) {
                Task::withTrashed()->whereKey((int) $run->task_id)->lockForUpdate()->first();
            }
            $lockedRun = ArticleAiOptimizationRun::query()->whereKey((int) $run->id)->lockForUpdate()->firstOrFail();
            ArticleAiQualityCheck::query()->whereKey((int) $inputCheck->id)->lockForUpdate()->firstOrFail();
            $step = ArticleAiOptimizationStep::query()
                ->where('run_id', (int) $run->id)
                ->where('round_index', $roundIndex)
                ->lockForUpdate()
                ->first();
            if ($step) {
                return $step;
            }
            if (! hash_equals((string) $lockedRun->lease_owner, $leaseOwner)
                || (string) $lockedRun->status !== ArticleAiOptimizationRun::STATUS_PLANNING) {
                throw new ArticleAiOptimizationException('article_ai_optimization_lease_lost');
            }

            $lockedRun->forceFill(['status' => ArticleAiOptimizationRun::STATUS_REWRITING])->save();

            return ArticleAiOptimizationStep::query()->create([
                'run_id' => (int) $lockedRun->id,
                'round_index' => $roundIndex,
                'input_check_id' => (int) $inputCheck->id,
                'ai_model_id' => (int) $model->id,
                'request_key' => (string) Str::uuid(),
                'status' => ArticleAiOptimizationRun::STATUS_REWRITING,
                'selected_causes' => $selectedIssues,
                'before_hash' => $beforeHash,
                'before_score' => $inputCheck->score,
                'before_decision' => $inputCheck->decision,
                'started_at' => now(),
            ]);
        });
    }

    private function claimStepRefineAttempt(
        ArticleAiOptimizationRun $run,
        ArticleAiOptimizationStep $step,
        string $leaseOwner,
    ): int {
        return DB::transaction(function () use ($run, $step, $leaseOwner): int {
            Article::query()->whereKey((int) $run->article_id)->lockForUpdate()->firstOrFail();
            if ($run->task_id) {
                Task::withTrashed()->whereKey((int) $run->task_id)->lockForUpdate()->first();
            }
            $lockedRun = ArticleAiOptimizationRun::query()->whereKey((int) $run->id)->lockForUpdate()->firstOrFail();
            $lockedStep = ArticleAiOptimizationStep::query()->whereKey((int) $step->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals((string) $lockedRun->lease_owner, $leaseOwner)
                || ! in_array((string) $lockedRun->status, [
                    ArticleAiOptimizationRun::STATUS_PLANNING,
                    ArticleAiOptimizationRun::STATUS_REWRITING,
                ], true)) {
                throw new ArticleAiOptimizationException('article_ai_optimization_lease_lost');
            }
            $lockedRun->forceFill(['status' => ArticleAiOptimizationRun::STATUS_REWRITING])->save();
            $executionMeta = is_array($lockedStep->execution_meta) ? $lockedStep->execution_meta : [];
            $attempt = max(0, (int) ($executionMeta['refine_attempt'] ?? 0)) + 1;
            $lockedStep->forceFill([
                'execution_meta' => array_replace($executionMeta, ['refine_attempt' => $attempt]),
            ])->save();

            return $attempt;
        });
    }

    /** @param list<array<string,mixed>> $issues @param list<array<string,mixed>> $repairTasks */
    private function refinerPrompt(
        ArticleAiOptimizationRun $run,
        ArticleAiQualityCheck $check,
        array $issues,
        array $repairTasks,
        string $baseHash,
    ): string {
        $payload = [
            'base_article_hash' => $baseHash,
            'strategy' => (string) $run->strategy,
            'target_score' => (int) $run->target_score,
            'quality_result' => [
                'decision' => (string) $check->decision,
                'score' => (int) $check->score,
                'summary' => (string) $check->summary,
                'issues' => $issues,
                'gate_reasons' => is_array($check->gate_reasons) ? $check->gate_reasons : [],
            ],
            'repair_tasks' => $repairTasks,
            'previous_failures' => ArticleAiOptimizationStep::query()
                ->where('run_id', (int) $run->id)
                ->where('status', 'rejected')
                ->whereNotNull('rejection_code')
                ->orderBy('round_index')
                ->limit(3)
                ->pluck('rejection_code')
                ->map('strval')
                ->values()
                ->all(),
            'evidence' => $this->selectedEvidence($check, $issues),
        ];

        return implode("\n", [
            '<untrusted_input>',
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT),
            '</untrusted_input>',
        ]);
    }

    /** @param list<array<string,mixed>> $issues @return list<array<string,mixed>> */
    private function selectedEvidence(ArticleAiQualityCheck $check, array $issues): array
    {
        $keys = collect($issues)
            ->pluck('evidence_keys')
            ->flatten()
            ->map('strval')
            ->filter()
            ->unique();
        if ($keys->isEmpty()) {
            return [];
        }

        $remainingCharacters = 12000;

        return collect(is_array($check->evidence_snapshot) ? $check->evidence_snapshot : [])
            ->filter(static fn (mixed $item): bool => is_array($item)
                && $keys->contains((string) ($item['stable_key'] ?? $item['id'] ?? '')))
            ->take(12)
            ->map(static function (array $item) use (&$remainingCharacters): array {
                $content = mb_substr((string) ($item['content'] ?? ''), 0, max(0, $remainingCharacters), 'UTF-8');
                $remainingCharacters -= mb_strlen($content, 'UTF-8');

                return [
                    'stable_key' => (string) ($item['stable_key'] ?? $item['id'] ?? ''),
                    'title' => mb_substr((string) ($item['title'] ?? ''), 0, 300, 'UTF-8'),
                    'content' => $content,
                ];
            })
            ->filter(static fn (array $item): bool => $item['stable_key'] !== '' && $item['content'] !== '')
            ->values()
            ->all();
    }

    private function setPhase(int $runId, string $leaseOwner, string $phase): void
    {
        $updated = ArticleAiOptimizationRun::query()
            ->whereKey($runId)
            ->where('lease_owner', $leaseOwner)
            ->whereIn('status', [
                ArticleAiOptimizationRun::STATUS_PLANNING,
                ArticleAiOptimizationRun::STATUS_REWRITING,
                ArticleAiOptimizationRun::STATUS_VALIDATING,
            ])
            ->update(['status' => $phase, 'updated_at' => now()]);
        if ($updated !== 1) {
            throw new ArticleAiOptimizationException('article_ai_optimization_lease_lost');
        }
    }

    /** @param array<string,mixed> $validated @param array<string,mixed> $response */
    private function markEvaluating(
        ArticleAiOptimizationRun $run,
        ArticleAiOptimizationStep $step,
        ArticleAiQualityCheck $inputCheck,
        ArticleAiQualityCheck $candidate,
        array $validated,
        array $response,
        string $beforeHash,
        string $afterHash,
        string $leaseOwner,
    ): void {
        DB::transaction(function () use ($run, $step, $inputCheck, $candidate, $validated, $response, $beforeHash, $afterHash, $leaseOwner): void {
            Article::query()->whereKey((int) $run->article_id)->lockForUpdate()->firstOrFail();
            if ($run->task_id) {
                Task::withTrashed()->whereKey((int) $run->task_id)->lockForUpdate()->first();
            }
            $lockedRun = ArticleAiOptimizationRun::query()->whereKey((int) $run->id)->lockForUpdate()->firstOrFail();
            ArticleAiQualityCheck::query()->whereKey((int) $inputCheck->id)->lockForUpdate()->firstOrFail();
            ArticleAiQualityCheck::query()->whereKey((int) $candidate->id)->lockForUpdate()->firstOrFail();
            $lockedStep = ArticleAiOptimizationStep::query()->whereKey((int) $step->id)->lockForUpdate()->firstOrFail();
            if (! hash_equals((string) $lockedRun->lease_owner, $leaseOwner)) {
                throw new ArticleAiOptimizationException('article_ai_optimization_lease_lost');
            }
            $existingUsageMeta = is_array($lockedStep->usage_meta) ? $lockedStep->usage_meta : [];
            $existingExecutionMeta = is_array($lockedStep->execution_meta) ? $lockedStep->execution_meta : [];
            $modelAttempts = array_values(array_merge(
                is_array($existingExecutionMeta['model_attempts'] ?? null)
                    ? $existingExecutionMeta['model_attempts']
                    : [],
                is_array($response['model_attempts'] ?? null) ? $response['model_attempts'] : [],
            ));

            $lockedStep->forceFill([
                'ai_model_id' => (int) data_get($response, 'model.id', $lockedStep->ai_model_id),
                'status' => ArticleAiOptimizationRun::STATUS_EVALUATING,
                'output_check_id' => (int) $candidate->id,
                'patch_plan' => $validated['operations'] ?? [],
                'applied_patch' => $validated['operations'] ?? [],
                'validation' => array_replace((array) ($validated['validation'] ?? []), [
                    'changed_characters' => (int) ($validated['changed_characters'] ?? 0),
                    'changed_percent' => (float) ($validated['changed_percent'] ?? 0),
                ]),
                'before_hash' => $beforeHash,
                'after_hash' => $afterHash,
                'usage_meta' => array_replace(
                    $existingUsageMeta,
                    is_array($response['usage'] ?? null) ? $response['usage'] : [],
                    ['model_attempts' => $modelAttempts],
                ),
                'execution_meta' => array_replace($existingExecutionMeta, [
                    'response_mode' => (string) ($response['mode'] ?? 'structured'),
                    'model' => is_array($response['model'] ?? null) ? $response['model'] : [],
                    'model_attempts' => $modelAttempts,
                ]),
            ])->save();
            $lockedRun->forceFill([
                'status' => ArticleAiOptimizationRun::STATUS_EVALUATING,
                'lease_owner' => null,
                'lease_expires_at' => null,
            ])->save();
        });
    }

    private function rejectStep(
        int $runId,
        int $stepId,
        string $leaseOwner,
        ArticleAiOptimizationException $exception,
    ): void {
        $shouldDispatch = DB::transaction(function () use ($runId, $stepId, $leaseOwner, $exception): bool {
            $info = ArticleAiOptimizationRun::query()->whereKey($runId)->first(['article_id', 'task_id']);
            if (! $info) {
                return false;
            }
            Article::query()->whereKey((int) $info->article_id)->lockForUpdate()->first();
            if ($info->task_id) {
                Task::withTrashed()->whereKey((int) $info->task_id)->lockForUpdate()->first();
            }
            $run = ArticleAiOptimizationRun::query()->whereKey($runId)->lockForUpdate()->first();
            $step = ArticleAiOptimizationStep::query()->whereKey($stepId)->lockForUpdate()->first();
            if (! $run || ! $step || ! hash_equals((string) $run->lease_owner, $leaseOwner)) {
                return false;
            }
            $step->forceFill([
                'status' => 'rejected',
                'rejection_code' => $exception->errorCode(),
                'rejection_message' => Str::limit($exception->getMessage(), 500, ''),
                'finished_at' => now(),
            ])->save();
            $completedRounds = max((int) $run->completed_rounds, (int) $step->round_index);
            $rejectedCount = ArticleAiOptimizationStep::query()
                ->where('run_id', $runId)
                ->where('status', 'rejected')
                ->count();
            $canRetry = $rejectedCount < 2 && $completedRounds < (int) $run->max_rounds;
            $run->forceFill([
                'completed_rounds' => $completedRounds,
                'status' => $canRetry
                    ? ArticleAiOptimizationRun::STATUS_QUEUED
                    : ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW,
                'stop_reason' => $canRetry ? null : 'patch_validation_failed',
                'error_code' => $exception->errorCode(),
                'active_dedupe_key' => $canRetry ? $run->active_dedupe_key : null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => $canRetry ? null : now(),
            ])->save();

            return $canRetry;
        });
        if ($shouldDispatch) {
            $run = ArticleAiOptimizationRun::query()->find($runId);
            if ($run) {
                $this->dispatch($run);
            }
        }
    }

    private function releaseLease(int $runId, string $leaseOwner): void
    {
        ArticleAiOptimizationRun::query()
            ->whereKey($runId)
            ->where('lease_owner', $leaseOwner)
            ->update([
                'status' => ArticleAiOptimizationRun::STATUS_QUEUED,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function finishForReview(int $runId, string $reason): void
    {
        ArticleAiOptimizationRun::query()
            ->whereKey($runId)
            ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES)
            ->update([
                'status' => ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW,
                'stop_reason' => $reason,
                'active_dedupe_key' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    private function candidateAccepted(ArticleAiQualityCheck $before, ArticleAiQualityCheck $after): bool
    {
        $beforeIssues = $this->comparableIssues($before);
        $afterIssues = $this->comparableIssues($after);
        $criticalCount = static fn ($issues): int => $issues->filter(
            static fn (mixed $issue): bool => is_array($issue)
                && (string) ($issue['severity'] ?? '') === 'critical'
        )->count();
        $severeCount = static fn ($issues): int => $issues->filter(
            static fn (mixed $issue): bool => is_array($issue)
                && in_array((string) ($issue['severity'] ?? ''), ['critical', 'high'], true)
        )->count();
        if ($criticalCount($afterIssues) > $criticalCount($beforeIssues)
            || $severeCount($afterIssues) > $severeCount($beforeIssues)
            || $this->hasNewSevereRootCause($beforeIssues, $afterIssues)
            || count((array) $after->gate_reasons) > count((array) $before->gate_reasons)) {
            return false;
        }
        $beforeMediumKeys = $beforeIssues
            ->filter(static fn (mixed $issue): bool => is_array($issue) && (string) ($issue['severity'] ?? '') === 'medium')
            ->map(static fn (array $issue): string => ArticleAiOptimizationPatchValidator::rootCauseKeyForIssue($issue))
            ->filter()
            ->unique();
        $newMediumDeduction = $afterIssues
            ->filter(static fn (mixed $issue): bool => is_array($issue) && (string) ($issue['severity'] ?? '') === 'medium')
            ->reject(static fn (array $issue): bool => $beforeMediumKeys->contains(
                ArticleAiOptimizationPatchValidator::rootCauseKeyForIssue($issue),
            ))
            ->sum(static fn (array $issue): int => (int) ($issue['deduction'] ?? 0));
        if ($newMediumDeduction > 0) {
            return false;
        }
        $lowDeduction = static fn ($issues): int => $issues
            ->filter(static fn (mixed $issue): bool => is_array($issue) && (string) ($issue['severity'] ?? '') === 'low')
            ->sum(static fn (array $issue): int => max(0, (int) ($issue['deduction'] ?? 0)));
        if ($lowDeduction($afterIssues) > $lowDeduction($beforeIssues)) {
            return false;
        }

        $decisionRank = ['error' => 0, 'blocked' => 1, 'needs_review' => 2, 'passed' => 3];
        $beforeRank = $decisionRank[(string) $before->decision] ?? 0;
        $afterRank = $decisionRank[(string) $after->decision] ?? 0;
        $beforeScore = (int) $before->score;
        $afterScore = (int) $after->score;

        if ($afterRank > $beforeRank) {
            return $afterScore >= $beforeScore - 2;
        }

        return $afterRank === $beforeRank && $afterScore >= $beforeScore + 3;
    }

    /**
     * A full reinspection can expose a different set of unsupported claims after an exact repair.
     * Keep the bounded loop moving when every selected anchor changed and the candidate remains safe.
     *
     * @param  list<array<string,mixed>>  $selectedCauses
     */
    private function candidateMadeTargetedProgress(
        ArticleAiQualityCheck $before,
        ArticleAiQualityCheck $after,
        array $selectedCauses,
    ): bool {
        $locatedCauses = collect($selectedCauses)
            ->filter(static fn (mixed $issue): bool => is_array($issue)
                && (string) ($issue['location_status'] ?? '') === 'resolved'
                && trim((string) ($issue['field'] ?? '')) !== ''
                && trim((string) ($issue['quote'] ?? '')) !== ''
                && (int) ($issue['start_offset'] ?? -1) >= 0
                && (int) ($issue['end_offset'] ?? -1) > (int) ($issue['start_offset'] ?? -1));
        if ($locatedCauses->isEmpty()) {
            return false;
        }

        $candidateSnapshot = is_array($after->article_snapshot) ? $after->article_snapshot : [];
        $allSelectedAnchorsChanged = $locatedCauses->every(static function (array $issue) use ($candidateSnapshot): bool {
            $field = (string) $issue['field'];
            $quote = (string) $issue['quote'];
            $start = (int) $issue['start_offset'];
            $candidateValue = (string) ($candidateSnapshot[$field] ?? '');

            return mb_substr($candidateValue, $start, mb_strlen($quote, 'UTF-8'), 'UTF-8') !== $quote;
        });
        if (! $allSelectedAnchorsChanged) {
            return false;
        }

        $decisionRank = ['error' => 0, 'blocked' => 1, 'needs_review' => 2, 'passed' => 3];
        $beforeRank = $decisionRank[(string) $before->decision] ?? 0;
        $afterRank = $decisionRank[(string) $after->decision] ?? 0;
        if ($afterRank < $beforeRank || (int) $after->score < (int) $before->score - 2) {
            return false;
        }
        if (count((array) $after->gate_reasons) > count((array) $before->gate_reasons)) {
            return false;
        }

        $beforeIssues = $this->comparableIssues($before);
        $afterIssues = $this->comparableIssues($after);
        if ($this->hasNewSevereRootCause($beforeIssues, $afterIssues)) {
            return false;
        }

        $fixableSevereCount = static fn ($issues): int => $issues
            ->filter(static fn (mixed $issue): bool => is_array($issue)
            && in_array((string) ($issue['code'] ?? ''), self::AUTO_FIXABLE_ISSUE_CODES, true)
            && in_array((string) ($issue['severity'] ?? ''), ['critical', 'high'], true))
            ->count();

        return $fixableSevereCount($afterIssues) <= $fixableSevereCount($beforeIssues);
    }

    /** @return Collection<int,array<string,mixed>> */
    private function comparableIssues(ArticleAiQualityCheck $check): Collection
    {
        return collect(is_array($check->issues) ? $check->issues : [])
            ->filter(static fn (mixed $issue): bool => is_array($issue))
            ->values();
    }

    private function hasNewSevereRootCause(Collection $beforeIssues, Collection $afterIssues): bool
    {
        $beforeKeys = $beforeIssues
            ->filter(static fn (array $issue): bool => in_array(
                (string) ($issue['severity'] ?? ''),
                ['critical', 'high'],
                true,
            ))
            ->map(static fn (array $issue): string => ArticleAiOptimizationPatchValidator::rootCauseKeyForIssue($issue))
            ->filter()
            ->unique();

        return $afterIssues
            ->filter(static fn (array $issue): bool => in_array(
                (string) ($issue['severity'] ?? ''),
                ['critical', 'high'],
                true,
            ))
            ->contains(static fn (array $issue): bool => ! $beforeKeys->contains(
                ArticleAiOptimizationPatchValidator::rootCauseKeyForIssue($issue),
            ));
    }

    private function optimizationExecutionAdmin(?Task $task, ?int $requestedByAdminId): Admin
    {
        $adminId = $task instanceof Task && ! $task->trashed()
            ? (int) ($task->model_access_admin_id ?? 0)
            : (int) ($requestedByAdminId ?? 0);
        $admin = $adminId > 0 ? Admin::query()->find($adminId) : null;
        if (! $admin instanceof Admin || (string) $admin->status !== 'active') {
            throw new ArticleAiOptimizationException('ai_execution_admin_inactive', httpStatus: 409);
        }

        return $admin;
    }

    private function assertRequestedModelForBothActors(
        Admin $executionAdmin,
        ?int $requestedByAdminId,
        AiModel $requestedModel,
    ): void {
        $this->assertOptimizationModelUsable($executionAdmin, $requestedModel);
        $requestAdminId = (int) ($requestedByAdminId ?? 0);
        if ($requestAdminId <= 0 || $requestAdminId === (int) $executionAdmin->id) {
            return;
        }
        $requestAdmin = Admin::query()->find($requestAdminId);
        if (! $requestAdmin instanceof Admin) {
            throw new ArticleAiOptimizationException('ai_execution_admin_inactive', httpStatus: 409);
        }
        $this->assertOptimizationModelUsable($requestAdmin, $requestedModel);
    }

    private function assertOptimizationModelUsable(Admin $admin, AiModel $model): void
    {
        try {
            $this->adminAiModelAccessResolver->assertUsable($admin, $model);
        } catch (AiModelAccessException $exception) {
            throw $this->optimizationAccessException($exception);
        }
    }

    private function assertOptimizationExecutionCurrent(
        ArticleAiOptimizationRun $run,
        ?AiModel $model = null,
    ): Admin {
        $executionMeta = is_array($run->execution_meta) ? $run->execution_meta : [];
        $adminId = (int) ($executionMeta['model_access_admin_id'] ?? 0);
        if ($adminId <= 0
            || ! in_array((string) ($executionMeta['model_access_admin_role'] ?? ''), ['admin', 'super_admin'], true)
            || (int) ($executionMeta['ai_config_access_version'] ?? 0) <= 0
            || (int) ($executionMeta['resolver_policy_version'] ?? 0) <= 0) {
            throw new ArticleAiOptimizationException(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED, httpStatus: 409);
        }
        $admin = $adminId > 0 ? Admin::query()->find($adminId) : null;
        if (! $admin instanceof Admin || (string) $admin->status !== 'active') {
            throw new ArticleAiOptimizationException('ai_execution_admin_inactive', httpStatus: 409);
        }
        if ((string) ($executionMeta['model_access_admin_role'] ?? '') !== $this->aiExecutionContextFactory->normalizedRole($admin)
            || (int) ($executionMeta['ai_config_access_version'] ?? 0) !== max(1, (int) $admin->ai_config_access_version)
            || (int) ($executionMeta['resolver_policy_version'] ?? 0) !== AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION) {
            throw new ArticleAiOptimizationException('ai_config_access_revoked', httpStatus: 409);
        }
        if ($run->task_id) {
            $task = Task::withTrashed()->find((int) $run->task_id);
            if (! $task instanceof Task
                || $task->trashed()
                || (int) ($task->model_access_admin_id ?? 0) !== $adminId
                || (int) ($task->model_access_policy_version ?? 0) !== AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION) {
                throw new ArticleAiOptimizationException('ai_config_access_revoked', httpStatus: 409);
            }
        }
        if ($model instanceof AiModel) {
            $this->assertOptimizationModelUsable($admin, $model);
        }

        return $admin;
    }

    private function optimizationAccessException(AiModelAccessException $exception): ArticleAiOptimizationException
    {
        return new ArticleAiOptimizationException(
            $exception->getErrorCode(),
            httpStatus: $exception->getErrorCode() === AiModelAccessException::AI_MODEL_NOT_ACCESSIBLE ? 404 : 409,
            previous: $exception,
        );
    }

    /** @return array<string,int|string|null> */
    private function qualityExecutionSnapshotForRun(ArticleAiOptimizationRun $run): array
    {
        $executionMeta = is_array($run->execution_meta) ? $run->execution_meta : [];

        return [
            'model_access_admin_id' => (int) ($executionMeta['model_access_admin_id'] ?? 0),
            'model_access_admin_role' => (string) ($executionMeta['model_access_admin_role'] ?? ''),
            'ai_config_access_version' => (int) ($executionMeta['ai_config_access_version'] ?? 0),
            'resolver_policy_version' => (int) ($executionMeta['resolver_policy_version'] ?? 0),
            'source_type' => 'article_ai_optimization_run',
            'source_id' => (int) $run->id,
            'model_candidate_ids' => array_values(array_map(
                'intval',
                is_array($executionMeta['quality_model_candidate_ids'] ?? null)
                    ? $executionMeta['quality_model_candidate_ids']
                    : [],
            )),
        ];
    }

    private function qualityCheckMatchesRunExecution(
        ArticleAiQualityCheck $check,
        ArticleAiOptimizationRun $run,
    ): bool {
        $runMeta = is_array($run->execution_meta) ? $run->execution_meta : [];
        $checkSnapshot = data_get($check->execution_meta, 'ai_execution');
        if (! is_array($checkSnapshot)) {
            return false;
        }

        $runCandidateIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($runMeta['quality_model_candidate_ids'] ?? null)
                ? $runMeta['quality_model_candidate_ids']
                : [],
        ), static fn (int $modelId): bool => $modelId > 0)));
        $checkCandidateIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($checkSnapshot['model_candidate_ids'] ?? null)
                ? $checkSnapshot['model_candidate_ids']
                : [],
        ), static fn (int $modelId): bool => $modelId > 0)));

        return (int) ($checkSnapshot['model_access_admin_id'] ?? 0) > 0
            && (int) ($checkSnapshot['model_access_admin_id'] ?? 0) === (int) ($runMeta['model_access_admin_id'] ?? 0)
            && (string) ($checkSnapshot['model_access_admin_role'] ?? '') === (string) ($runMeta['model_access_admin_role'] ?? '')
            && (int) ($checkSnapshot['ai_config_access_version'] ?? 0) === (int) ($runMeta['ai_config_access_version'] ?? 0)
            && (int) ($checkSnapshot['resolver_policy_version'] ?? 0) === (int) ($runMeta['resolver_policy_version'] ?? 0)
            && $runCandidateIds !== []
            && $checkCandidateIds === $runCandidateIds;
    }

    private function isPermanentOptimizationFailure(Throwable $exception): bool
    {
        if ($exception instanceof ArticleAiOptimizationException
            && in_array($exception->errorCode(), [
                AiModelAccessException::AI_MODEL_NOT_ACCESSIBLE,
                AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE,
                AiModelAccessException::AI_CONFIG_ACCESS_REVOKED,
                AiModelAccessException::AI_CONFIG_OWNER_INACTIVE,
                AiModelAccessException::AI_MODEL_UNAVAILABLE,
                AiModelAccessException::AI_EMBEDDING_INCOMPATIBLE,
            ], true)) {
            return true;
        }
        $current = $exception;
        for ($depth = 0; $depth < 8 && $current instanceof Throwable; $depth++) {
            if ($current instanceof AiModelAccessException
                || $current instanceof PermanentAiProviderException
                || $current instanceof InvalidArgumentException
                || ($current instanceof AiException && ! $current instanceof FailoverableException)
                || $current->getMessage() === 'article_ai_optimization_model_configuration_incomplete') {
                return true;
            }
            $current = $current->getPrevious();
        }

        return $this->failoverDecider->isPermanentProviderFailure($exception);
    }

    /** @return list<AiModel> */
    private function optimizationModelCandidates(
        AiModel $requestedModel,
        ?Task $task,
        Admin $executionAdmin,
    ): array {
        if ($task instanceof Task
            && ! $task->trashed()
            && (int) $task->ai_model_id !== (int) $requestedModel->id) {
            throw new ArticleAiOptimizationException('article_ai_optimization_model_unavailable', httpStatus: 409);
        }
        $this->assertOptimizationModelUsable($executionAdmin, $requestedModel);
        if (! $task instanceof Task || (string) $task->model_selection_mode !== 'smart_failover') {
            return [$requestedModel];
        }

        $candidates = $this->adminAiModelAccessResolver
            ->resolveCandidates($executionAdmin, 'chat')
            ->values();
        $requested = $candidates->firstWhere('id', (int) $requestedModel->id);
        if (! $requested instanceof AiModel) {
            throw new ArticleAiOptimizationException('ai_model_not_accessible', httpStatus: 404);
        }
        $personal = $candidates
            ->filter(static fn (AiModel $model): bool => (int) $model->owner_admin_id === (int) $executionAdmin->id);
        $shared = $candidates
            ->reject(static fn (AiModel $model): bool => (int) $model->owner_admin_id === (int) $executionAdmin->id);
        $ordered = collect([$requested])
            ->concat($personal->reject(static fn (AiModel $model): bool => (int) $model->id === (int) $requested->id))
            ->concat($shared->reject(static fn (AiModel $model): bool => (int) $model->id === (int) $requested->id));

        return $ordered
            ->take($this->modelAttemptLimit())
            ->values()
            ->all();
    }

    /** @return list<AiModel> */
    private function modelsForRun(ArticleAiOptimizationRun $run, Article $article): array
    {
        $executionAdmin = $this->assertOptimizationExecutionCurrent($run);
        $executionMeta = is_array($run->execution_meta) ? $run->execution_meta : [];
        $ids = collect(is_array($executionMeta['optimization_model_ids'] ?? null)
            ? $executionMeta['optimization_model_ids']
            : [$executionMeta['optimization_model_id'] ?? $article->task?->ai_model_id])
            ->map('intval')
            ->filter()
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $models = AiModel::query()
            ->whereIn('id', $ids->all())
            ->get()
            ->keyBy('id');
        $primaryId = (int) ($executionMeta['optimization_model_id'] ?? 0);
        $safe = [];
        foreach ($ids as $id) {
            $model = $models->get($id);
            if (! $model instanceof AiModel) {
                if ($id === $primaryId) {
                    throw new ArticleAiOptimizationException('article_ai_optimization_model_unavailable');
                }

                continue;
            }
            try {
                $this->adminAiModelAccessResolver->assertUsable($executionAdmin, $model);
            } catch (AiModelAccessException $exception) {
                if ($id === $primaryId) {
                    throw $this->optimizationAccessException($exception);
                }

                continue;
            }
            $safe[] = $model;
        }

        return $safe;
    }

    /**
     * @param  list<AiModel>  $models
     * @param  list<array<string,mixed>>  $attempts
     * @return array<string,mixed>
     */
    private function refineWithFailover(
        ArticleAiOptimizationRun $run,
        ArticleAiOptimizationStep $step,
        array $models,
        string $prompt,
        array &$attempts,
        int $refineAttempt,
        ?AiModelProviderUsageSession &$winningUsageSession,
    ): array {
        $lastException = null;
        $quotaReserve = (string) $run->trigger === ArticleAiOptimizationRun::TRIGGER_TASK_AUTO
            ? max(0, (int) config('geoflow.ai_quality_optimization_bulk_quota_reserve', 2))
            : 0;
        foreach ($models as $candidateIndex => $model) {
            $providerUsageSession = $this->optimizationProviderUsageSession(
                $run,
                $step,
                $model,
                $refineAttempt,
                $candidateIndex + 1,
                $prompt,
            );
            try {
                $this->assertOptimizationExecutionCurrent($run, $model);
                $requestTimeout = max(30, min(300, (int) config('geoflow.ai_quality_request_timeout_seconds', 160)));
                $response = $this->refiner instanceof ProviderAttemptAwareArticleAiOptimizationRefiner
                    && $providerUsageSession instanceof AiModelProviderUsageSession
                    ? $this->refiner->refineTrackingProviderAttempts(
                        $model,
                        $prompt,
                        $requestTimeout,
                        $quotaReserve,
                        $providerUsageSession,
                    )
                    : $this->refiner->refine(
                        $model,
                        $prompt,
                        $requestTimeout,
                        $quotaReserve,
                    );
                $this->assertOptimizationExecutionCurrent($run, $model);
                $attempts[] = [
                    'model_id' => (int) $model->id,
                    'status' => 'success',
                    'error_code' => null,
                ];
                $response['model_attempts'] = $attempts;
                $winningUsageSession = $providerUsageSession;

                return $response;
            } catch (Throwable $exception) {
                $this->finalizeOptimizationUsageForException($providerUsageSession, $exception);
                $attempts[] = [
                    'model_id' => (int) $model->id,
                    'status' => 'failed',
                    'error_code' => $exception instanceof ArticleAiOptimizationException
                        ? $exception->errorCode()
                        : 'article_ai_optimization_provider_error',
                ];
                $lastException = $exception;
                if (! $this->failoverDecider->shouldFailover($exception)) {
                    throw $exception;
                }
            }
        }

        throw $lastException ?? new ArticleAiOptimizationException('article_ai_optimization_model_unavailable');
    }

    private function optimizationProviderUsageSession(
        ArticleAiOptimizationRun $run,
        ArticleAiOptimizationStep $step,
        AiModel $model,
        int $refineAttempt,
        int $candidateOrdinal,
        string $requestPayload,
    ): ?AiModelProviderUsageSession {
        if (! $this->refiner instanceof ProviderAttemptAwareArticleAiOptimizationRefiner) {
            return null;
        }
        $executionMeta = is_array($run->execution_meta) ? $run->execution_meta : [];
        $executionAdminId = (int) ($executionMeta['model_access_admin_id'] ?? 0);
        $accessVersion = (int) ($executionMeta['ai_config_access_version'] ?? 0);
        if ($executionAdminId <= 0
            || $accessVersion <= 0
            || ! Str::isUuid((string) $step->request_key)) {
            throw new ArticleAiOptimizationException(AiModelAccessException::AI_CONFIG_ACCESS_REVOKED);
        }
        $providerOrdinal = 0;
        $modelSource = $this->usageAttempts->sourceFor($model, $executionAdminId);

        return new AiModelProviderUsageSession(function (string $mode) use (
            &$providerOrdinal,
            $accessVersion,
            $candidateOrdinal,
            $executionAdminId,
            $model,
            $modelSource,
            $refineAttempt,
            $requestPayload,
            $step,
        ) {
            $providerOrdinal++;

            return $this->usageAttempts->beginForAdmin(
                model: $model,
                executionAdminId: $executionAdminId,
                accessVersion: $accessVersion,
                executionScope: AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
                modelSource: $modelSource,
                requestId: (string) $step->request_key,
                requestPayload: $requestPayload,
                callKey: sprintf(
                    'r%d.a%d.c%d.p%d.%s',
                    (int) $step->round_index,
                    $refineAttempt,
                    $candidateOrdinal,
                    $providerOrdinal,
                    $mode,
                ),
                operation: 'article_ai_optimization.refine',
                businessSource: 'article_ai_optimization',
                sourceType: ArticleAiOptimizationStep::class,
                sourceId: (int) $step->id,
            );
        });
    }

    private function finalizeOptimizationUsageForException(
        ?AiModelProviderUsageSession $usageSession,
        Throwable $exception,
    ): void {
        if (! $usageSession instanceof AiModelProviderUsageSession) {
            return;
        }
        $errorCode = $exception instanceof ArticleAiOptimizationException
            ? $exception->errorCode()
            : 'article_ai_optimization_result_not_committed';
        if (in_array($errorCode, [
            AiModelAccessException::AI_MODEL_NOT_ACCESSIBLE,
            AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE,
            AiModelAccessException::AI_CONFIG_ACCESS_REVOKED,
            AiModelAccessException::AI_CONFIG_OWNER_INACTIVE,
            AiModelAccessException::AI_MODEL_UNAVAILABLE,
        ], true)) {
            $usageSession->revoked($errorCode);

            return;
        }
        $usageSession->discarded($errorCode);
    }

    /** @param list<array<string,mixed>> $attempts */
    private function recordStepModelAttempts(
        ArticleAiOptimizationRun $run,
        ArticleAiOptimizationStep $step,
        string $leaseOwner,
        array $attempts,
    ): void {
        if ($attempts === []) {
            return;
        }
        DB::transaction(function () use ($run, $step, $leaseOwner, $attempts): void {
            Article::query()->whereKey((int) $run->article_id)->lockForUpdate()->first();
            if ($run->task_id) {
                Task::withTrashed()->whereKey((int) $run->task_id)->lockForUpdate()->first();
            }
            $lockedRun = ArticleAiOptimizationRun::query()->whereKey((int) $run->id)->lockForUpdate()->first();
            $lockedStep = ArticleAiOptimizationStep::query()->whereKey((int) $step->id)->lockForUpdate()->first();
            if (! $lockedRun
                || ! $lockedStep
                || ! hash_equals((string) $lockedRun->lease_owner, $leaseOwner)) {
                return;
            }
            $usageMeta = is_array($lockedStep->usage_meta) ? $lockedStep->usage_meta : [];
            $executionMeta = is_array($lockedStep->execution_meta) ? $lockedStep->execution_meta : [];
            $allAttempts = array_values(array_merge(
                is_array($executionMeta['model_attempts'] ?? null) ? $executionMeta['model_attempts'] : [],
                $attempts,
            ));
            $lockedStep->forceFill([
                'usage_meta' => array_replace($usageMeta, ['model_attempts' => $allAttempts]),
                'execution_meta' => array_replace($executionMeta, ['model_attempts' => $allAttempts]),
            ])->save();
        });
    }

    /** @param array<string,mixed> $qualityPolicy @param array<string,mixed> $policy @param list<AiModel> $optimizationModels */
    private function policyHash(
        Article $article,
        array $qualityPolicy,
        array $policy,
        array $optimizationModels,
        ?Task $task,
        string $trigger,
    ): string {
        $primaryQualityPolicy = $qualityPolicy;
        $primaryQualityPolicy['model_candidates'] = ($qualityPolicy['model'] ?? null) instanceof AiModel
            ? [$qualityPolicy['model']]
            : [];
        $primaryOptimizationModel = $optimizationModels[0] ?? null;

        return $this->optimizationPolicy->hash([
            'algorithm_version' => self::ALGORITHM_VERSION,
            'patch_validator_version' => ArticleAiOptimizationPatchValidator::VERSION,
            'rollout_epoch' => max(1, (int) (ArticleAiQualityRollout::query()->whereKey(1)->value('epoch') ?? 1)),
            'optimization_policy' => $policy,
            'quality_fingerprint_input' => $this->qualityPolicyResolver->fingerprintInput(
                $article,
                $primaryQualityPolicy,
                $this->inspectionService->rules(),
            ),
            'optimization_model_selection_mode' => (string) ($task?->model_selection_mode ?? 'fixed'),
            'task_auto_policy' => $trigger === ArticleAiOptimizationRun::TRIGGER_TASK_AUTO ? [
                'enabled' => (bool) $task?->ai_quality_auto_optimize_enabled,
                'strategy' => (string) ($task?->ai_quality_optimization_level ?? ''),
            ] : null,
            'optimization_models' => $primaryOptimizationModel instanceof AiModel ? [[
                'id' => (int) $primaryOptimizationModel->id,
                'model_id' => (string) $primaryOptimizationModel->model_id,
                'version' => (string) $primaryOptimizationModel->version,
                'status' => (string) $primaryOptimizationModel->status,
                'api_url' => (string) $primaryOptimizationModel->api_url,
                'max_tokens' => $primaryOptimizationModel->max_tokens === null ? null : (int) $primaryOptimizationModel->max_tokens,
                'failover_priority' => $primaryOptimizationModel->failover_priority === null ? null : (int) $primaryOptimizationModel->failover_priority,
            ]] : [],
        ]);
    }

    private function policyHashMatches(Article $article, ArticleAiOptimizationRun $run): bool
    {
        try {
            $executionMeta = is_array($run->execution_meta) ? $run->execution_meta : [];
            $primaryId = (int) ($executionMeta['optimization_model_id'] ?? $article->task?->ai_model_id ?? 0);
            $primary = $primaryId > 0 ? AiModel::query()->find($primaryId) : null;
            if (! $primary instanceof AiModel) {
                return false;
            }
            $executionAdmin = $this->assertOptimizationExecutionCurrent($run);
            $qualityPolicy = $this->qualityPolicyResolver->forExecutionAdmin(
                $this->qualityPolicyResolver->resolveForManualInspection($article),
                $executionAdmin,
            );
            $this->qualityPolicyResolver->assertExecutable($qualityPolicy);
            $policy = $this->optimizationPolicy->resolve(
                (string) $run->strategy,
                (int) ($qualityPolicy['pass_score'] ?? 85),
            );
            $models = $this->optimizationModelCandidates($primary, $article->task, $executionAdmin);

            return hash_equals(
                (string) $run->policy_hash,
                $this->policyHash(
                    $article,
                    $qualityPolicy,
                    $policy,
                    $models,
                    $article->task,
                    (string) $run->trigger,
                ),
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function autoApplySelected(ArticleAiOptimizationRun $run): bool
    {
        if ((string) $run->trigger !== ArticleAiOptimizationRun::TRIGGER_TASK_AUTO
            || ! (bool) config('geoflow.ai_quality_optimization_enabled', false)
            || ! (bool) config('geoflow.ai_quality_optimization_auto_apply_enabled', false)) {
            return false;
        }
        $task = $run->task_id ? Task::query()->find((int) $run->task_id) : null;
        if (! $task || ! (bool) $task->ai_quality_auto_optimize_enabled) {
            return false;
        }
        $percent = max(0, min(100, (int) config('geoflow.ai_quality_optimization_auto_apply_percent', 0)));

        return $percent >= 100 || (abs(crc32('ai-quality-auto-apply:'.(int) $run->article_id)) % 100) < $percent;
    }

    private function markRunStale(ArticleAiOptimizationRun $run, string $reason): void
    {
        $run->forceFill([
            'status' => ArticleAiOptimizationRun::STATUS_STALE,
            'stop_reason' => $reason,
            'active_dedupe_key' => null,
            'lease_owner' => null,
            'lease_expires_at' => null,
            'finished_at' => now(),
        ])->save();
    }

    private function hasActiveDistribution(int $articleId): bool
    {
        return ArticleDistribution::query()
            ->where('article_id', $articleId)
            ->whereIn('status', ['queued', 'sending', 'synced', 'outcome_unknown'])
            ->exists();
    }

    private function leaseSeconds(): int
    {
        $requestTimeout = max(30, min(
            300,
            (int) config('geoflow.ai_quality_request_timeout_seconds', 160),
        ));
        $jobTimeout = max(60, (int) config('geoflow.ai_quality_optimization_job_timeout_seconds', 850));

        return max(
            60,
            min(
                max(60, $jobTimeout - 30),
                max(
                    (int) config('geoflow.ai_quality_optimization_lease_seconds', 300),
                    ($requestTimeout * 2 * $this->modelAttemptLimit()) + 60,
                ),
            ),
        );
    }

    private function modelAttemptLimit(): int
    {
        $requestTimeout = max(30, min(
            300,
            (int) config('geoflow.ai_quality_request_timeout_seconds', 160),
        ));
        $jobTimeout = max(60, (int) config('geoflow.ai_quality_optimization_job_timeout_seconds', 850));
        $budgetLimit = max(1, intdiv(max(0, $jobTimeout - 120), $requestTimeout * 2));

        return max(1, min(
            3,
            $budgetLimit,
            (int) config('geoflow.ai_quality_optimization_max_model_attempts', 2),
        ));
    }

    private function ensureScheduled(
        ArticleAiOptimizationRun $run,
        Article $article,
        bool $dispatch,
        ?int $requestedByAdminId,
    ): ArticleAiOptimizationRun {
        $freshRun = $run->fresh(['sourceCheck']);
        if (! $freshRun instanceof ArticleAiOptimizationRun) {
            return $run;
        }
        $run = $freshRun;
        if (! (bool) config('geoflow.ai_quality_optimization_enabled', false)) {
            return $run;
        }

        if ((string) $run->status === ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY) {
            $source = $run->sourceCheck;
            if (! $source instanceof ArticleAiQualityCheck
                || in_array((string) $source->status, ['failed', 'cancelled', 'stale'], true)
                || ! $this->qualityCheckMatchesRunExecution($source, $run)) {
                $source = $this->inspectionService->requestManualInspection(
                    $article->fresh(),
                    trigger: 'optimization_manual',
                    dispatch: false,
                    auditAdminId: $requestedByAdminId,
                    allowSampling: false,
                    aiExecutionSnapshot: $this->qualityExecutionSnapshotForRun($run),
                );
                $run->forceFill(['source_check_id' => (int) $source->id])->save();
            }

            if ($dispatch && (string) $source->status === 'queued') {
                $this->inspectionService->dispatchQueuedInspection($source);
            } elseif ($dispatch && (string) $source->status === 'completed') {
                $this->interceptCompletedWorkflow((int) $source->id);
            }
        } elseif ($dispatch && (string) $run->status === ArticleAiOptimizationRun::STATUS_QUEUED) {
            $this->dispatch($run);
        }

        return $run->fresh(['sourceCheck']);
    }

    private function dispatch(ArticleAiOptimizationRun $run): void
    {
        $queue = (string) $run->trigger === ArticleAiOptimizationRun::TRIGGER_TASK_AUTO
            ? (string) config('geoflow.ai_quality_optimization_bulk_queue', 'ai-content-optimization-bulk')
            : (string) config('geoflow.ai_quality_optimization_queue', 'ai-content-optimization');
        ProcessArticleAiOptimizationJob::dispatch((int) $run->id)
            ->onConnection('redis')
            ->onQueue($queue)
            ->afterCommit();
    }
}
