<?php

namespace App\Services\GeoFlow;

use App\Contracts\ArticleAiQualityReviewer;
use App\Contracts\DeadlineAwareArticleAiQualityReviewer;
use App\Contracts\ProviderAttemptAwareArticleAiQualityReviewer;
use App\Contracts\VersionAwareArticleAiQualityReviewer;
use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\ApiException;
use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Jobs\ProcessArticleAiQualityJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleAiQualityRollout;
use App\Models\ArticleAiQualitySegment;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\GeoFlow\KnowledgeFacts\ArticleAtomicFactInspector;
use App\Support\GeoFlow\AiModelFailoverDecider;
use App\Support\GeoFlow\AiQualityRetrievalBasis;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ArticleAiQualityInspectionService
{
    private const WORKFLOW_APPLY_MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly ArticleAiQualityPolicyResolver $policyResolver,
        private readonly ArticleAiQualityFingerprint $fingerprint,
        private readonly ArticleFactCandidateExtractor $factExtractor,
        private readonly ArticleAiQualityRetrievalCoordinator $retrievalCoordinator,
        private readonly ArticleAiQualityEvidenceCache $evidenceCache,
        private readonly ArticleAiQualityPrincipleCompiler $principleCompiler,
        private readonly ArticleAiQualitySegmenter $segmenter,
        private readonly ArticleAiQualitySampleBuilder $sampleBuilder,
        private readonly ArticleRiskScanner $riskScanner,
        private readonly ArticleAiQualityPromptRenderer $promptRenderer,
        private readonly ArticleAiQualityResultValidator $resultValidator,
        private readonly ArticleAiQualityScorer $scorer,
        private readonly ArticleAiQualityScorerV2 $scorerV2,
        private readonly ArticleAiQualityVersionPolicy $versionPolicy,
        private readonly ArticleAiQualityRolloutPolicy $rolloutPolicy,
        private readonly ArticleAtomicFactInspector $atomicFactInspector,
        private readonly ArticleAiQualityReviewer $reviewer,
        private readonly ArticleAiQualityWorkerLiveness $workerLiveness,
        private readonly AiQualityAuditService $auditService,
        private readonly AiExecutionAccessGuard $aiExecutionAccessGuard,
        private readonly AiExecutionContextFactory $aiExecutionContextFactory,
        private readonly AiModelFailoverDecider $aiModelFailoverDecider,
        private readonly ArticleAiQualityExecutionBoundaryHook $executionBoundaryHook,
        private readonly AiModelUsageAttemptFactory $usageAttempts,
    ) {}

    public function requestManualInspection(
        Article $article,
        string $trigger = 'admin_manual',
        bool $dispatch = true,
        ?int $auditAdminId = null,
        ?int $apiTokenId = null,
        ?array $requestedWorkflowState = null,
        bool $allowSampling = true,
        bool $rejectWhenOptimizationActive = false,
        ?int $expectedPolicyVersion = null,
        ?array $aiExecutionSnapshot = null,
    ): ArticleAiQualityCheck {
        return DB::transaction(function () use ($article, $trigger, $dispatch, $auditAdminId, $apiTokenId, $requestedWorkflowState, $allowSampling, $rejectWhenOptimizationActive, $expectedPolicyVersion, $aiExecutionSnapshot): ArticleAiQualityCheck {
            $article = Article::query()
                ->whereKey((int) $article->id)
                ->lockForUpdate()
                ->firstOrFail();
            $currentPolicyVersion = max(1, (int) $article->ai_quality_policy_version);
            if ($expectedPolicyVersion !== null && $currentPolicyVersion !== $expectedPolicyVersion) {
                throw new ApiException(
                    'article_ai_quality_config_version_conflict',
                    'AI 质检配置已更新，请刷新后重试',
                    409,
                    [
                        'expected_config_version' => $expectedPolicyVersion,
                        'current_config_version' => $currentPolicyVersion,
                    ],
                );
            }
            if ($article->task_id) {
                $task = Task::withTrashed()->whereKey((int) $article->task_id)->lockForUpdate()->first();
                if ($task instanceof Task) {
                    $task->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
                    $article->setRelation('task', $task);
                }
            }
            if ($rejectWhenOptimizationActive && ArticleAiOptimizationRun::query()
                ->where('article_id', (int) $article->id)
                ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->exists()) {
                throw new ArticleAiOptimizationException(
                    'article_ai_optimization_recheck_conflict',
                    httpStatus: 409,
                );
            }
            $policy = $this->policyResolver->resolveForManualInspection($article);
            if (! $allowSampling) {
                $policy['timeout_sampling_enabled'] = false;
            }
            $this->policyResolver->assertExecutable($policy);
            $article->forceFill([
                'ai_quality_required_at_creation' => true,
                'ai_quality_policy_snapshot' => $this->policyResolver->snapshot($policy),
            ]);

            if ((string) $article->status === 'draft' && (string) $article->review_status !== 'rejected') {
                $article->forceFill([
                    'status' => 'draft',
                    'review_status' => 'pending',
                    'published_at' => null,
                ]);
            }
            if ($article->isDirty()) {
                $article->save();
            }

            $check = $this->createOrReuse(
                $article,
                trigger: $trigger,
                dispatch: $dispatch,
                force: true,
                resolvedPolicy: $policy,
                aiExecutionSnapshot: $aiExecutionSnapshot,
            );
            if (! $check instanceof ArticleAiQualityCheck) {
                throw new RuntimeException('ai_quality_policy_unavailable');
            }

            $check = ArticleAiQualityCheck::query()->whereKey((int) $check->id)->lockForUpdate()->firstOrFail();
            $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
            $manualRequests = is_array($executionMeta['manual_requests'] ?? null)
                ? $executionMeta['manual_requests']
                : [];
            $manualRequests[] = [
                'trigger' => $trigger,
                'admin_id' => $auditAdminId,
                'api_token_id' => $apiTokenId,
                'requested_at' => now()->toISOString(),
            ];
            $requestedWorkflowState = $this->sanitizeRequestedWorkflowState($requestedWorkflowState);
            $check->forceFill([
                'execution_meta' => array_replace($executionMeta, [
                    'manual_requests' => array_slice($manualRequests, -50),
                    'requested_workflow_state' => $requestedWorkflowState,
                ]),
            ])->save();
            $this->auditService->record('article_quality_check_requested', [
                'task_id' => $article->task_id ? (int) $article->task_id : null,
                'article_id' => (int) $article->id,
                'article_ai_quality_check_id' => (int) $check->id,
                'admin_id' => $auditAdminId,
                'api_token_id' => $apiTokenId,
                'policy_version' => (int) ($policy['policy_version'] ?? 1),
                'basis_hash' => (string) $check->retrieval_basis_hash,
                'reason_code' => $trigger,
                'metadata' => [
                    'requested_retrieval_mode' => (string) $check->requested_retrieval_mode,
                    'inspection_scope' => (string) $check->inspection_scope,
                ],
            ]);

            return $check;
        });
    }

    public function dispatchQueuedInspection(ArticleAiQualityCheck|int $check): void
    {
        $checkId = $check instanceof ArticleAiQualityCheck ? (int) $check->id : $check;
        if ($checkId <= 0 || ! ArticleAiQualityCheck::query()
            ->whereKey($checkId)
            ->where('status', 'queued')
            ->exists()) {
            return;
        }

        $this->dispatchCheck($checkId);
    }

    public function createOrReuse(
        Article $article,
        ?TaskRun $taskRun = null,
        string $trigger = 'generation',
        bool $dispatch = true,
        bool $force = false,
        ?array $resolvedPolicy = null,
        ?array $aiExecutionSnapshot = null,
    ): ?ArticleAiQualityCheck {
        return DB::transaction(function () use ($article, $taskRun, $trigger, $dispatch, $force, $resolvedPolicy, $aiExecutionSnapshot): ?ArticleAiQualityCheck {
            $article = Article::query()
                ->whereKey((int) $article->id)
                ->lockForUpdate()
                ->first();
            if (! $article) {
                return null;
            }
            if ($resolvedPolicy === null && $article->task_id) {
                $task = Task::withTrashed()
                    ->whereKey((int) $article->task_id)
                    ->lockForUpdate()
                    ->first();
                if ($task) {
                    $article->setRelation('task', $task);
                }
            }
            $policy = $resolvedPolicy ?? $this->policyResolver->resolve($article);
            if (! ($policy['required'] ?? false)) {
                return null;
            }

            $aiExecutionSnapshot = $this->qualityExecutionSnapshot(
                $article,
                $taskRun,
                $aiExecutionSnapshot,
                ($policy['model'] ?? null) instanceof AiModel ? (int) $policy['model']->id : null,
            );
            if ($aiExecutionSnapshot !== null) {
                $executionAdmin = $this->aiExecutionAccessGuard->assertPersistedAdminSnapshot(
                    $aiExecutionSnapshot,
                    $article->task_id ? (int) $article->task_id : null,
                );
                $policy = $this->policyResolver->forExecutionAdmin($policy, $executionAdmin);
                $policy = $this->withFrozenExecutionCandidates($policy, $aiExecutionSnapshot);
            }
            $this->policyResolver->assertExecutable($policy);
            $modelCandidates = $this->policyResolver->modelCandidates($policy);
            $policy['model_candidates'] = $modelCandidates;
            if ($aiExecutionSnapshot !== null) {
                $aiExecutionSnapshot['model_candidate_ids'] = array_values(array_map(
                    static fn (AiModel $candidate): int => (int) $candidate->id,
                    $modelCandidates,
                ));
            }
            $articleSnapshot = $this->policyResolver->articleSnapshot($article);
            $versionSelection = $this->versionPolicy->selection((int) $article->id);
            $fullRules = $this->rules();
            $principleSnapshot = $this->principleCompiler->compile(
                $articleSnapshot,
                $fullRules,
                array_values($this->policyResolver->fingerprintInput($article, $policy, $fullRules)['knowledge'] ?? []),
                is_array($policy['publication_context'] ?? null) ? $policy['publication_context'] : [],
            );
            $rules = (string) ($versionSelection['principles'] ?? 'v1') === 'v2'
                ? $this->principleCompiler->rules($principleSnapshot)
                : $fullRules;
            $prompt = $policy['prompt'];
            $promptTemplate = $this->promptTemplate($prompt, (string) $versionSelection['execution']);
            $fingerprintInput = $this->fingerprintInput(
                $article,
                $policy,
                $rules,
                $promptTemplate,
                (string) $versionSelection['execution'],
            );
            $retrievalMode = (string) ($policy['retrieval_mode'] ?? AiQualityRetrievalMode::legacyDefault());
            $retrievalBasis = AiQualityRetrievalBasis::make(
                $retrievalMode,
                (int) ($policy['policy_version'] ?? 1),
                array_values($fingerprintInput['knowledge'] ?? []),
                $this->rolloutPolicy->state(),
                $this->retrievalCoordinator->strategyVersion($retrievalMode),
                $this->retrievalExecutionOptions(),
            );
            $inputFingerprint = $this->fingerprint->make($fingerprintInput, $versionSelection['algorithm_version']);
            $activeKey = hash('sha256', (int) $article->id."\0".$inputFingerprint);

            $existingActive = ArticleAiQualityCheck::query()->where('active_dedupe_key', $activeKey)->first();
            if ($existingActive) {
                if ($this->remainingDeadlineSeconds($this->deadlineAt($existingActive)) > 0) {
                    return $existingActive;
                }

                $this->markFailed(
                    $existingActive,
                    new ArticleAiQualityRuntimeException($this->workerLiveness->expirationCode($existingActive), true),
                );
            }

            if (! $force) {
                $existingResult = ArticleAiQualityCheck::query()
                    ->where('article_id', $article->id)
                    ->where('input_fingerprint', $inputFingerprint)
                    ->where('status', 'completed')
                    ->where('gate_applied', true)
                    ->latest('id')
                    ->first();
                if ($existingResult) {
                    return $existingResult;
                }
            }

            $segments = $this->segmenter->segment((string) ($articleSnapshot['content'] ?? ''));
            $policySnapshot = $this->policyResolver->snapshot($policy);
            $createdAt = now();
            $primaryDeadlineAt = $createdAt->copy()->addSeconds((int) config('geoflow.ai_quality_deadline_seconds', 180));
            $deadlineAt = $primaryDeadlineAt->copy();
            if ((bool) ($policySnapshot['timeout_sampling_enabled'] ?? false)) {
                $deadlineAt->addSeconds(
                    (int) config('geoflow.ai_quality_sampled_fallback_seconds', 45)
                    + (int) config('geoflow.ai_quality_persistence_reserve_seconds', 10),
                );
            }
            $model = $policy['model'];
            $previous = ArticleAiQualityCheck::query()
                ->where('article_id', $article->id)
                ->where('gate_applied', true)
                ->latest('id')
                ->first();
            if ($previous
                && ! hash_equals((string) $previous->input_fingerprint, $inputFingerprint)
                && ! ((string) $previous->status === 'stale'
                    && (string) $previous->error_code === 'quality_basis_changed')
                && in_array((string) $previous->status, ['queued', 'running', 'completed', 'failed', 'stale'], true)) {
                $previous->forceFill([
                    'status' => 'stale',
                    'active_dedupe_key' => null,
                    'error_code' => 'input_changed',
                    'error_message' => '文章或质检依据已更新，当前结果已经过期。',
                    'finished_at' => $previous->finished_at ?: now(),
                ])->save();
                ArticleAiQualitySegment::query()
                    ->where('article_ai_quality_check_id', (int) $previous->id)
                    ->whereIn('status', ['queued', 'running', 'failed'])
                    ->update([
                        'status' => 'stale',
                        'error_code' => 'input_changed',
                        'error_message' => '文章或质检依据已经变化。',
                        'finished_at' => now(),
                        'updated_at' => now(),
                    ]);
                $this->holdUnpublishedArticleForReview((int) $article->id);
            }

            try {
                $check = DB::transaction(function () use (
                    $article,
                    $taskRun,
                    $trigger,
                    $policy,
                    $rules,
                    $articleSnapshot,
                    $segments,
                    $prompt,
                    $promptTemplate,
                    $model,
                    $modelCandidates,
                    $previous,
                    $inputFingerprint,
                    $activeKey,
                    $fingerprintInput,
                    $versionSelection,
                    $principleSnapshot,
                    $policySnapshot,
                    $createdAt,
                    $primaryDeadlineAt,
                    $deadlineAt,
                    $retrievalMode,
                    $retrievalBasis,
                    $aiExecutionSnapshot,
                ): ArticleAiQualityCheck {
                    $storedEpoch = (int) data_get($retrievalBasis->toArray(), 'rollout.epoch', 1);
                    $committedEpoch = max(1, (int) (ArticleAiQualityRollout::query()->whereKey(1)->value('epoch') ?? 1));
                    if ($storedEpoch !== $committedEpoch) {
                        throw new ArticleAiQualityRuntimeException('ai_quality_rollout_epoch_changed', true);
                    }
                    $check = ArticleAiQualityCheck::query()->create([
                        'article_id' => (int) $article->id,
                        'task_id' => $article->task_id ? (int) $article->task_id : null,
                        'task_run_id' => $taskRun?->id,
                        'prompt_id' => (int) $prompt->id,
                        'ai_model_id' => (int) $model->id,
                        'supersedes_check_id' => $previous?->id,
                        'request_key' => (string) Str::uuid(),
                        'active_dedupe_key' => $activeKey,
                        'status' => 'queued',
                        'inspection_scope' => 'full',
                        'requested_retrieval_mode' => $retrievalMode,
                        'retrieval_strategy_version' => (string) data_get($retrievalBasis->toArray(), 'strategy_version'),
                        'retrieval_basis_hash' => $retrievalBasis->hash(),
                        'primary_deadline_at' => $primaryDeadlineAt,
                        'deadline_at' => $deadlineAt,
                        'pass_score' => (int) $policy['pass_score'],
                        'manual_override_min_score' => (int) $policy['manual_override_min_score'],
                        'segment_count' => count($segments),
                        'article_snapshot' => $articleSnapshot,
                        'prompt_template_snapshot' => mb_substr($promptTemplate, 0, 50000, 'UTF-8'),
                        'advertising_rules_snapshot' => $rules,
                        'model_snapshot' => array_replace($this->modelSnapshot($model), [
                            'selection_mode' => (string) ($policy['model_selection_mode'] ?? 'fixed'),
                            'candidate_ids' => array_values(array_map(static fn (AiModel $candidate): int => (int) $candidate->id, $modelCandidates)),
                        ]),
                        'article_content_hash' => hash('sha256', json_encode($articleSnapshot, JSON_UNESCAPED_UNICODE)),
                        'prompt_hash' => hash('sha256', $promptTemplate),
                        'knowledge_hash' => hash('sha256', json_encode($fingerprintInput['knowledge'] ?? [], JSON_UNESCAPED_UNICODE)),
                        'input_fingerprint' => $inputFingerprint,
                        'algorithm_version' => $versionSelection['algorithm_version'],
                        'gate_applied' => true,
                        'evaluation_mode' => 'primary',
                        'scoring_version' => $versionSelection['scoring'],
                        'execution_meta' => [
                            'trigger' => $trigger,
                            'policy_source' => $policy['source'] ?? 'unknown',
                            'knowledge_base_ids' => array_values(array_map('intval', $policy['knowledge_base_ids'] ?? [])),
                            'model_selection_mode' => (string) ($policy['model_selection_mode'] ?? 'fixed'),
                            'model_candidate_ids' => array_values(array_map(static fn (AiModel $candidate): int => (int) $candidate->id, $modelCandidates)),
                            'ai_execution' => $aiExecutionSnapshot,
                            'model_attempts' => [],
                            'segment_runs' => [],
                            'version_selection' => $versionSelection,
                            'policy_snapshot' => $policySnapshot,
                            'principle_snapshot' => $principleSnapshot,
                            'current_phase' => 'queued',
                            'retrieval_basis' => $retrievalBasis->toArray(),
                        ],
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);

                    foreach ($segments as $segment) {
                        $check->segments()->create([
                            'segment_index' => (int) $segment['index'],
                            'start_offset' => (int) $segment['start_offset'],
                            'end_offset' => (int) $segment['end_offset'],
                            'input_hash' => (string) $segment['input_hash'],
                            'status' => 'queued',
                        ]);
                    }
                    $this->createRetrievalSourceLedger(
                        $check,
                        $retrievalMode,
                        array_values($fingerprintInput['knowledge'] ?? []),
                    );

                    return $check;
                });
            } catch (QueryException $exception) {
                $check = ArticleAiQualityCheck::query()->where('active_dedupe_key', $activeKey)->first();
                if (! $check) {
                    throw $exception;
                }
            }

            if ($dispatch && $check->status === 'queued') {
                DB::afterCommit(fn () => $this->dispatchCheck((int) $check->id));
            }

            return $check;
        });
    }

    /**
     * @param  list<array<string,mixed>>  $sources
     */
    private function createRetrievalSourceLedger(
        ArticleAiQualityCheck $check,
        string $retrievalMode,
        array $sources,
    ): void {
        $dependencyKinds = match ($retrievalMode) {
            AiQualityRetrievalMode::ATOMIC_FIRST => ['atomic', 'chunk'],
            AiQualityRetrievalMode::KNOWLEDGE_BROAD => ['raw_content'],
            default => ['chunk'],
        };

        foreach ($sources as $source) {
            foreach ($dependencyKinds as $dependencyKind) {
                $check->sources()->create([
                    'knowledge_base_id' => (int) ($source['id'] ?? 0) ?: null,
                    'knowledge_base_name_snapshot' => (string) ($source['name'] ?? '知识库'),
                    'dependency_kind' => $dependencyKind,
                    'source_hash' => $dependencyKind === 'raw_content'
                        ? (string) ($source['raw_content_hash'] ?? '')
                        : (string) ($source['chunk_source_hash'] ?? ''),
                    'chunk_serving_generation' => (string) ($source['chunk_serving_generation'] ?? '') ?: null,
                    'chunk_manifest_hash' => (string) ($source['chunk_manifest_hash'] ?? '') ?: null,
                    'fact_revision_id' => (int) data_get($source, 'atomic_facts.revision_id') ?: null,
                    'fact_library_hash' => (string) data_get($source, 'atomic_facts.library_hash', '') ?: null,
                    'readiness_status' => 'ready',
                ]);
            }
        }
    }

    /** @param array<string,mixed> $candidateSnapshot */
    public function createOptimizationCandidate(
        Article $article,
        array $candidateSnapshot,
        ArticleAiQualityCheck $baseline,
        int $runId,
        string $trigger,
        bool $dispatch = true,
    ): ArticleAiQualityCheck {
        $check = DB::transaction(function () use ($article, $candidateSnapshot, $baseline, $runId, $trigger): ArticleAiQualityCheck {
            $article = Article::query()->whereKey((int) $article->id)->lockForUpdate()->firstOrFail();
            if ($article->task_id) {
                $task = Task::withTrashed()->whereKey((int) $article->task_id)->lockForUpdate()->first();
                if ($task instanceof Task) {
                    $task->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
                    $article->setRelation('task', $task);
                }
            }
            $run = ArticleAiOptimizationRun::query()->whereKey($runId)->lockForUpdate()->firstOrFail();
            if ((int) $run->article_id !== (int) $article->id
                || ! in_array((string) $run->status, ArticleAiOptimizationRun::ACTIVE_STATUSES, true)) {
                throw new ArticleAiOptimizationException('article_ai_optimization_run_stale');
            }
            $baseline = ArticleAiQualityCheck::query()->whereKey((int) $baseline->id)->lockForUpdate()->firstOrFail();
            if ((int) $baseline->article_id !== (int) $article->id || (string) $baseline->status !== 'completed') {
                throw new ArticleAiOptimizationException('article_ai_optimization_baseline_invalid');
            }

            $executionMeta = is_array($baseline->execution_meta) ? $baseline->execution_meta : [];
            $storedPolicy = is_array($executionMeta['policy_snapshot'] ?? null)
                ? $executionMeta['policy_snapshot']
                : (array) ($article->ai_quality_policy_snapshot ?? []);
            $storedPolicy['timeout_sampling_enabled'] = false;
            $storedPolicy['retrieval_mode'] = (string) (
                $baseline->requested_retrieval_mode ?: AiQualityRetrievalMode::legacyDefault()
            );
            $storedPolicy['retrieval_mode_explicit'] = (bool) ($storedPolicy['retrieval_mode_explicit'] ?? false);
            $policy = $this->policyResolver->fromArticleSnapshot(
                $storedPolicy,
                (string) ($storedPolicy['source'] ?? 'article_snapshot'),
            );
            $runExecutionMeta = is_array($run->execution_meta) ? $run->execution_meta : [];
            $aiExecutionSnapshot = $this->qualityExecutionSnapshot($article, null, [
                'model_access_admin_id' => $runExecutionMeta['model_access_admin_id'] ?? null,
                'model_access_admin_role' => $runExecutionMeta['model_access_admin_role'] ?? null,
                'ai_config_access_version' => $runExecutionMeta['ai_config_access_version'] ?? null,
                'resolver_policy_version' => $runExecutionMeta['resolver_policy_version'] ?? null,
                'source_type' => 'article_ai_optimization_run',
                'source_id' => (int) $run->id,
            ], ($policy['model'] ?? null) instanceof AiModel ? (int) $policy['model']->id : null);
            if ($aiExecutionSnapshot === null) {
                throw AiModelAccessException::configAccessRevokedForAdminId(
                    (int) ($runExecutionMeta['model_access_admin_id'] ?? 0),
                );
            }
            $executionAdmin = $this->aiExecutionAccessGuard->assertPersistedAdminSnapshot(
                $aiExecutionSnapshot,
                $article->task_id ? (int) $article->task_id : null,
            );
            $policy = $this->policyResolver->forExecutionAdmin($policy, $executionAdmin);
            $policy = $this->withFrozenExecutionCandidates($policy, $aiExecutionSnapshot);
            $this->policyResolver->assertExecutable($policy);
            $modelCandidates = $this->policyResolver->modelCandidates($policy);
            $policy['model_candidates'] = $modelCandidates;
            $aiExecutionSnapshot['model_candidate_ids'] = array_values(array_map(
                static fn (AiModel $candidate): int => (int) $candidate->id,
                $modelCandidates,
            ));
            $snapshot = array_replace(
                $this->policyResolver->articleSnapshot($article),
                array_intersect_key($candidateSnapshot, array_flip(['title', 'excerpt', 'content', 'keywords', 'meta_description'])),
            );
            $candidateArticle = clone $article;
            $candidateArticle->forceFill($snapshot);
            $versionSelection = is_array($executionMeta['version_selection'] ?? null)
                ? $executionMeta['version_selection']
                : $this->versionPolicy->selection((int) $article->id);
            $baselineRules = $this->rulesForCheck($baseline);
            if (! $this->retrievalBasisMatches($baseline, $policy, $baselineRules)) {
                throw new ArticleAiOptimizationException('article_ai_optimization_baseline_stale');
            }
            $principleSnapshot = is_array($executionMeta['principle_snapshot'] ?? null)
                ? $executionMeta['principle_snapshot']
                : null;
            if ((string) ($versionSelection['principles'] ?? 'v1') === 'v2') {
                $fullRules = $this->rules();
                $principleSnapshot = $this->principleCompiler->compile(
                    $snapshot,
                    $fullRules,
                    array_values($this->policyResolver->fingerprintInput(
                        $candidateArticle,
                        $policy,
                        $fullRules,
                    )['knowledge'] ?? []),
                    is_array($policy['publication_context'] ?? null) ? $policy['publication_context'] : [],
                );
                $rules = $this->principleCompiler->rules($principleSnapshot);
                $fingerprintRules = $fullRules;
            } else {
                $rules = $baselineRules;
                $fingerprintRules = $rules;
            }
            $inputFingerprint = $this->currentFingerprint(
                $candidateArticle,
                $policy,
                $fingerprintRules,
                $versionSelection,
            );
            $activeKey = hash('sha256', $runId."\0".$inputFingerprint."\0optimization_candidate");
            $existing = ArticleAiQualityCheck::query()->where('active_dedupe_key', $activeKey)->first();
            if ($existing instanceof ArticleAiQualityCheck) {
                return $existing;
            }

            $segments = $this->segmenter->segment((string) ($snapshot['content'] ?? ''));
            $createdAt = now();
            $deadlineAt = $createdAt->copy()->addSeconds((int) config('geoflow.ai_quality_deadline_seconds', 180));
            $prompt = $policy['prompt'];
            $model = $policy['model'];
            $promptTemplate = (string) ($baseline->prompt_template_snapshot ?: $prompt->content);
            $check = ArticleAiQualityCheck::query()->create([
                'article_id' => (int) $article->id,
                'task_id' => $article->task_id ? (int) $article->task_id : null,
                'prompt_id' => (int) $prompt->id,
                'ai_model_id' => (int) $model->id,
                'request_key' => (string) Str::uuid(),
                'active_dedupe_key' => $activeKey,
                'status' => 'queued',
                'inspection_scope' => 'full',
                'requested_retrieval_mode' => (string) (
                    $baseline->requested_retrieval_mode ?: AiQualityRetrievalMode::legacyDefault()
                ),
                'retrieval_strategy_version' => (string) $baseline->retrieval_strategy_version,
                'retrieval_basis_hash' => (string) $baseline->retrieval_basis_hash,
                'primary_deadline_at' => $deadlineAt,
                'deadline_at' => $deadlineAt,
                'pass_score' => (int) $policy['pass_score'],
                'manual_override_min_score' => (int) $policy['manual_override_min_score'],
                'segment_count' => count($segments),
                'article_snapshot' => $snapshot,
                'prompt_template_snapshot' => mb_substr($promptTemplate, 0, 50000, 'UTF-8'),
                'advertising_rules_snapshot' => $rules,
                'model_snapshot' => array_replace($this->modelSnapshot($model), [
                    'selection_mode' => (string) ($policy['model_selection_mode'] ?? 'fixed'),
                    'candidate_ids' => array_values(array_map(static fn (AiModel $candidate): int => (int) $candidate->id, $modelCandidates)),
                ]),
                'article_content_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE)),
                'prompt_hash' => hash('sha256', $promptTemplate),
                'knowledge_hash' => (string) $baseline->knowledge_hash,
                'input_fingerprint' => $inputFingerprint,
                'algorithm_version' => (string) ($versionSelection['algorithm_version'] ?? $baseline->algorithm_version),
                'gate_applied' => false,
                'evaluation_mode' => 'optimization_candidate',
                'baseline_check_id' => (int) $baseline->id,
                'scoring_version' => (string) ($versionSelection['scoring'] ?? $baseline->scoring_version),
                'execution_meta' => [
                    'trigger' => $trigger,
                    'optimization_run_id' => $runId,
                    'policy_source' => $policy['source'] ?? 'unknown',
                    'knowledge_base_ids' => array_values(array_map('intval', $policy['knowledge_base_ids'] ?? [])),
                    'model_selection_mode' => (string) ($policy['model_selection_mode'] ?? 'fixed'),
                    'model_candidate_ids' => array_values(array_map(static fn (AiModel $candidate): int => (int) $candidate->id, $modelCandidates)),
                    'ai_execution' => $aiExecutionSnapshot,
                    'model_attempts' => [],
                    'segment_runs' => [],
                    'version_selection' => $versionSelection,
                    'principle_snapshot' => $principleSnapshot,
                    'policy_snapshot' => array_replace($this->policyResolver->snapshot($policy), ['timeout_sampling_enabled' => false]),
                    'requested_workflow_state' => $this->sanitizeRequestedWorkflowState(
                        is_array($executionMeta['requested_workflow_state'] ?? null)
                            ? $executionMeta['requested_workflow_state']
                            : null,
                    ),
                    'current_phase' => 'queued',
                    'retrieval_basis' => data_get($executionMeta, 'retrieval_basis', []),
                ],
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            foreach ($segments as $segment) {
                $check->segments()->create([
                    'segment_index' => (int) $segment['index'],
                    'start_offset' => (int) $segment['start_offset'],
                    'end_offset' => (int) $segment['end_offset'],
                    'input_hash' => (string) $segment['input_hash'],
                    'status' => 'queued',
                ]);
            }
            $baseline->loadMissing('sources');
            foreach ($baseline->sources as $source) {
                $check->sources()->create([
                    ...$source->only([
                        'knowledge_base_id',
                        'knowledge_base_name_snapshot',
                        'dependency_kind',
                        'source_hash',
                        'chunk_serving_generation',
                        'chunk_manifest_hash',
                        'fact_revision_id',
                        'fact_library_hash',
                        'readiness_status',
                        'used_provider',
                        'used_at',
                    ]),
                ]);
            }

            return $check;
        });

        if ($dispatch && (string) $check->status === 'queued') {
            DB::afterCommit(fn () => $this->dispatchCheck((int) $check->id));
        }

        return $check;
    }

    public function process(
        ArticleAiQualityCheck|int $check,
        bool $allowRunningRecovery = false,
        ?string $expectedScope = null,
    ): ArticleAiQualityCheck {
        $checkId = $check instanceof ArticleAiQualityCheck ? (int) $check->id : $check;
        $check = ArticleAiQualityCheck::query()->with(['article', 'segments'])->findOrFail($checkId);
        if ($expectedScope !== null && (string) $check->inspection_scope !== $expectedScope) {
            return $check;
        }
        if (in_array((string) $check->status, ['completed', 'failed', 'stale', 'cancelled'], true)) {
            return $check;
        }
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        [$aiExecutionSnapshot, $invalidExecutionSnapshot] = $this->qualityExecutionSnapshotForCheck($executionMeta);
        if ($invalidExecutionSnapshot
            || ($aiExecutionSnapshot === null && $this->aiExecutionContextFactory->identityRequired())) {
            return $this->markStale($check, AiModelAccessException::AI_CONFIG_ACCESS_REVOKED);
        }
        $executionAdmin = $aiExecutionSnapshot !== null
            ? $this->aiExecutionAccessGuard->assertPersistedAdminSnapshot(
                $aiExecutionSnapshot,
                $check->task_id ? (int) $check->task_id : null,
            )
            : null;
        if ((string) $check->inspection_scope === 'fallback_sampled') {
            return $this->processSampledFallback($check, $aiExecutionSnapshot, $executionAdmin);
        }
        if (! $check->article) {
            return $this->markCancelled($check, 'article_unavailable');
        }
        if ((string) $check->evaluation_mode === 'optimization_candidate') {
            $optimizationRunId = (int) data_get($check->execution_meta, 'optimization_run_id', 0);
            $optimizationActive = $optimizationRunId > 0 && ArticleAiOptimizationRun::query()
                ->whereKey($optimizationRunId)
                ->where('article_id', (int) $check->article_id)
                ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES)
                ->exists();
            if (! $optimizationActive) {
                return $this->markCancelled($check, 'optimization_run_inactive');
            }
        }
        $storedArticleSnapshot = is_array($check->article_snapshot) ? $check->article_snapshot : [];
        if (Str::length((string) ($storedArticleSnapshot['content'] ?? ''))
            > (int) config('geoflow.ai_quality_full_online_max_characters', 60000)) {
            $exception = new ArticleAiQualityRuntimeException('input_too_large', false);
            if ($this->tryStartSampledFallback($check, $exception)) {
                return $this->latestCheck($checkId);
            }

            throw $exception;
        }
        $deadlineAt = $this->primaryDeadlineAt($check);
        $persistenceReserveSeconds = (int) config('geoflow.ai_quality_persistence_reserve_seconds', 5);
        if ($this->remainingDeadlineSeconds($deadlineAt) <= $persistenceReserveSeconds) {
            $exception = new ArticleAiQualityRuntimeException('inspection_primary_deadline_exceeded', false);
            if ($this->tryStartSampledFallback($check, $exception)) {
                return $this->latestCheck($checkId);
            }

            throw $exception;
        }

        $storedPolicy = is_array($executionMeta['policy_snapshot'] ?? null)
            ? $executionMeta['policy_snapshot']
            : (is_array($check->article->ai_quality_policy_snapshot) ? $check->article->ai_quality_policy_snapshot : []);
        $policy = $this->policyResolver->fromArticleSnapshot(
            $storedPolicy,
            (string) ($storedPolicy['source'] ?? $executionMeta['policy_source'] ?? 'article_snapshot'),
        );
        $rules = $this->rulesForCheck($check);
        if (! $this->retrievalBasisMatches($check, $policy, $rules)) {
            return $this->markStale($check, 'ai_quality_retrieval_source_stale');
        }
        $this->policyResolver->assertExecutable($policy);
        $candidateIds = array_values(array_filter(array_map(
            'intval',
            is_array($executionMeta['model_candidate_ids'] ?? null) ? $executionMeta['model_candidate_ids'] : [],
        )));
        $policy['model_candidates'] = $aiExecutionSnapshot !== null
            ? AiModel::query()->whereIn('id', $candidateIds)->get()->sortBy(
                static fn (AiModel $model): int => array_search((int) $model->id, $candidateIds, true),
            )->values()->all()
            : $this->policyResolver->modelCandidates($policy);
        $requestedModelId = (int) ($aiExecutionSnapshot['requested_model_id'] ?? $check->ai_model_id ?? 0);
        if ($executionAdmin instanceof Admin && $requestedModelId > 0) {
            $this->aiExecutionAccessGuard->assertModelForPersistedAdminSnapshot(
                $aiExecutionSnapshot,
                $requestedModelId,
                $check->task_id ? (int) $check->task_id : null,
                $executionAdmin,
            );
        }
        $executionVersion = (string) data_get($executionMeta, 'version_selection.execution', 'legacy');
        $currentArticleHash = hash('sha256', json_encode(
            $this->policyResolver->articleSnapshot($check->article),
            JSON_UNESCAPED_UNICODE,
        ));
        if ((string) $check->evaluation_mode !== 'optimization_candidate'
            && ! hash_equals((string) $check->article_content_hash, $currentArticleHash)) {
            return $this->markStale($check);
        }

        $claimed = ArticleAiQualityCheck::query()
            ->whereKey($checkId)
            ->where('inspection_scope', 'full')
            ->where(function ($query) use ($allowRunningRecovery): void {
                $query->whereIn('status', ['queued', 'failed']);
                if ($allowRunningRecovery) {
                    $query->orWhere(function ($query): void {
                        $query->where('status', 'running')
                            ->where('updated_at', '<=', now()->subMinutes(9));
                    });
                }
            })
            ->update([
                'status' => 'running',
                'attempt_count' => DB::raw('attempt_count + 1'),
                'started_at' => $check->started_at ?: now(),
                'error_code' => null,
                'error_message' => null,
                'retrieval_failure_code' => null,
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return $this->latestCheck($checkId);
        }
        $check = ArticleAiQualityCheck::query()->with(['article', 'segments'])->findOrFail($checkId);
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        $timings = is_array($executionMeta['timings_ms'] ?? null) ? $executionMeta['timings_ms'] : [];
        $timings['queue_wait'] = $check->started_at
            ? max(0, (int) round($check->created_at->diffInMilliseconds($check->started_at)))
            : 0;

        $articleSnapshot = is_array($check->article_snapshot) ? $check->article_snapshot : [];
        $inspectionSnapshot = $articleSnapshot;
        if (is_array($check->fact_candidates_snapshot) && is_array($check->evidence_snapshot)) {
            $facts = $check->fact_candidates_snapshot;
            $evidence = $check->evidence_snapshot;
            $evidenceResult = [
                'fact_candidates' => $facts,
                'evidence' => $evidence,
                'knowledge_coverage' => (string) ($check->knowledge_coverage ?: 'insufficient'),
                'retrieval_meta' => is_array($executionMeta['retrieval'] ?? null)
                    ? $executionMeta['retrieval']
                    : [],
                'effective_retrieval_mode' => (string) (
                    $check->effective_retrieval_mode ?: $check->requested_retrieval_mode ?: AiQualityRetrievalMode::legacyDefault()
                ),
                'retrieval_strategy_version' => (string) $check->retrieval_strategy_version,
            ];
            $timings['claim_extraction'] = (int) ($timings['claim_extraction'] ?? 0);
            $timings['evidence_retrieval'] = (int) ($timings['evidence_retrieval'] ?? 0);
            $evidenceCacheMeta = ['status' => 'persisted_snapshot', 'key' => null];
        } else {
            $stageStartedAt = hrtime(true);
            $facts = $this->factExtractor->extract($inspectionSnapshot, 1000);
            $timings['claim_extraction'] = $this->elapsedMilliseconds($stageStartedAt);

            $stageStartedAt = hrtime(true);
            try {
                $cacheResult = $this->evidenceCache->remember([
                    'article_content_hash' => (string) $check->article_content_hash,
                    'knowledge_hash' => (string) $check->knowledge_hash,
                    'claim_hashes' => array_values(array_filter(array_map(
                        static fn (array $fact): string => (string) ($fact['claim_hash'] ?? ''),
                        $facts,
                    ))),
                    'generation_evidence_hash' => hash('sha256', json_encode(
                        $check->article?->generation_evidence_snapshot ?? [],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    )),
                    'retrieval_version' => 4,
                    'retrieval_mode' => (string) ($check->requested_retrieval_mode ?: AiQualityRetrievalMode::legacyDefault()),
                    'retrieval_basis_hash' => (string) $check->retrieval_basis_hash,
                    'limits' => [
                        (int) config('geoflow.ai_quality_max_evidence', 12),
                        (int) config('geoflow.ai_quality_max_evidence_characters', 6000),
                        (int) config('geoflow.ai_quality_max_fact_retrievals', 6),
                    ],
                ], fn (): array => $this->retrievalCoordinator->retrieve(
                    (string) ($check->requested_retrieval_mode ?: AiQualityRetrievalMode::legacyDefault()),
                    $policy['knowledge_base_ids'] ?? [],
                    $inspectionSnapshot,
                    $facts,
                    [
                        'max_evidence' => (int) config('geoflow.ai_quality_max_evidence', 12),
                        'max_characters' => (int) config('geoflow.ai_quality_max_evidence_characters', 6000),
                        'max_fact_retrievals' => (int) config('geoflow.ai_quality_max_fact_retrievals', 6),
                        'generation_evidence' => is_array($check->article?->generation_evidence_snapshot)
                            ? $check->article->generation_evidence_snapshot
                            : [],
                        'serving_generations' => $this->frozenServingGenerations($check),
                    ],
                )->toArray());
                $evidenceResult = $cacheResult['value'];
                $evidenceCacheMeta = [
                    'status' => $cacheResult['hit'] ? 'hit' : 'miss',
                    'key' => $cacheResult['key'],
                ];
            } catch (InvalidArgumentException $exception) {
                throw new ArticleAiQualityRuntimeException('evidence_retrieval_failed', false, $exception);
            } catch (Throwable $exception) {
                throw new ArticleAiQualityRuntimeException('evidence_retrieval_failed', true, $exception);
            }
            $timings['evidence_retrieval'] = $this->elapsedMilliseconds($stageStartedAt);
            $facts = $evidenceResult['fact_candidates'];
            $evidence = $evidenceResult['evidence'];
        }
        $effectiveRetrievalMode = $this->effectiveRetrievalMode($check, $evidenceResult);

        $executionMeta = array_replace($executionMeta, [
            'current_phase' => 'inspecting',
            'timings_ms' => $timings,
            'evidence_cache' => $evidenceCacheMeta,
            'generation_evidence_reused_count' => (int) ($evidenceResult['generation_evidence_reused_count'] ?? 0),
            'retrieval' => is_array($evidenceResult['retrieval_meta'] ?? null) ? $evidenceResult['retrieval_meta'] : [],
        ]);

        $evidenceStored = ArticleAiQualityCheck::query()
            ->whereKey($checkId)
            ->where('status', 'running')
            ->where('inspection_scope', 'full')
            ->update([
                'fact_candidates_snapshot' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'evidence_snapshot' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'knowledge_coverage' => $evidenceResult['knowledge_coverage'],
                'effective_retrieval_mode' => $effectiveRetrievalMode,
                'retrieval_strategy_version' => (string) ($evidenceResult['retrieval_strategy_version'] ?? $check->retrieval_strategy_version),
                'execution_meta' => json_encode($executionMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        if ($evidenceStored !== 1) {
            return $this->latestCheck($checkId);
        }
        $this->markRetrievalSourcesUsed($checkId, $evidenceResult);

        $validatedResults = [];
        $rawResults = [];
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $modelMeta = [];
        $modes = [];
        $modelAttempts = [];
        $segmentRuns = is_array($executionMeta['segment_runs'] ?? null) ? $executionMeta['segment_runs'] : [];
        $modelCandidates = null;
        $segments = $this->segmenter->segment((string) ($inspectionSnapshot['content'] ?? ''));

        foreach ($segments as $segmentData) {
            $segment = $check->segments->firstWhere('segment_index', (int) $segmentData['index']);
            if (! $segment) {
                throw new RuntimeException('ai_quality_segment_missing');
            }
            if ($segment->status === 'completed' && is_array($segment->validated_result)) {
                $validatedResults[] = $this->resultValidator->normalizeLegacyRemovedDisclosureArtifacts(
                    $segment->validated_result,
                );
                $rawResults[] = $segment->model_result;
                $runMeta = is_array($segmentRuns[(string) $segmentData['index']] ?? null)
                    ? $segmentRuns[(string) $segmentData['index']]
                    : [];
                $this->accumulateRunTelemetry($runMeta, $usage, $modelMeta, $modes, $modelAttempts);

                continue;
            }

            if ($this->remainingDeadlineSeconds($deadlineAt) <= $persistenceReserveSeconds + 5) {
                $exception = new ArticleAiQualityRuntimeException('remaining_budget_insufficient', false);
                if ($this->tryStartSampledFallback($checkId, $exception)) {
                    return $this->latestCheck($checkId);
                }

                throw $exception;
            }

            if ($this->providerExecutionIdentityMissing($aiExecutionSnapshot)) {
                return $this->markStale($check, AiModelAccessException::AI_CONFIG_ACCESS_REVOKED);
            }
            $modelCandidates ??= $this->policyResolver->modelCandidates($policy);

            if (! $this->startSegment($checkId, (int) $segment->id)) {
                return $this->latestCheck($checkId);
            }

            $segmentFacts = $this->factsForSegment($facts, $segmentData);
            $segmentEvidence = $this->evidenceForFacts($evidence, $segmentFacts);
            $stageStartedAt = hrtime(true);
            $instructions = $this->promptRenderer->render((string) $check->prompt_template_snapshot, [
                'article_title' => (string) ($inspectionSnapshot['title'] ?? ''),
                'article_excerpt' => (string) ($inspectionSnapshot['excerpt'] ?? ''),
                'article_outline' => $this->outline((string) ($inspectionSnapshot['content'] ?? '')),
                'article_content' => (string) $segmentData['content'],
                'keywords' => (string) ($articleSnapshot['keywords'] ?? ''),
                'meta_description' => (string) ($articleSnapshot['meta_description'] ?? ''),
                'fact_candidates' => $segmentFacts,
                'knowledge' => $segmentEvidence,
                'advertising_rules' => $rules,
                'inspection_date' => now()->toDateString(),
                'publication_context' => $policy['publication_context'] ?? [],
                'segment_index' => (int) $segmentData['index'] + 1,
                'segment_count' => count($segments),
                'segment_start_offset' => (int) $segmentData['start_offset'],
            ]);
            $promptRenderMs = $this->elapsedMilliseconds($stageStartedAt);

            $review = null;
            $raw = [];
            $validated = [];
            $priorRunMeta = is_array($segmentRuns[(string) $segmentData['index']] ?? null)
                ? $segmentRuns[(string) $segmentData['index']]
                : [];
            $attempts = is_array($priorRunMeta['attempts'] ?? null) ? $priorRunMeta['attempts'] : [];
            $runUsage = $this->mergeUsage([], is_array($priorRunMeta['usage'] ?? null) ? $priorRunMeta['usage'] : []);
            $lastException = null;
            $modelTotalMs = 0;
            $validationMs = 0;
            $candidateQueue = array_values($modelCandidates);
            $invalidOutputRetries = [];
            $candidateOccurrences = [];
            $winningUsageSession = null;
            $segmentAttempt = (int) ArticleAiQualitySegment::query()
                ->whereKey((int) $segment->id)
                ->value('attempt_count');
            for ($candidateIndex = 0; $candidateIndex < count($candidateQueue); $candidateIndex++) {
                $candidate = $candidateQueue[$candidateIndex];
                if ((string) $candidate->status !== 'active'
                    || ! in_array((string) ($candidate->model_type ?? ''), ['', 'chat'], true)) {
                    $attempts[] = $this->modelAttempt($segmentData, $candidate, 'skipped', 'model_unavailable', 0);

                    continue;
                }
                if ($aiExecutionSnapshot !== null) {
                    try {
                        $candidate = $this->aiExecutionAccessGuard->assertModelForPersistedAdminSnapshot(
                            $aiExecutionSnapshot,
                            $candidate,
                            $check->task_id ? (int) $check->task_id : null,
                            $executionAdmin,
                        );
                    } catch (AiModelAccessException $exception) {
                        if ((int) $candidate->id === $requestedModelId
                            || ! in_array($exception->getErrorCode(), [
                                AiModelAccessException::AI_MODEL_NOT_ACCESSIBLE,
                                AiModelAccessException::AI_MODEL_UNAVAILABLE,
                            ], true)) {
                            throw $exception;
                        }
                        $attempts[] = $this->modelAttempt($segmentData, $candidate, 'skipped', $exception->getErrorCode(), 0);

                        continue;
                    }
                }

                $candidateStartedAt = hrtime(true);
                $modelId = (int) $candidate->id;
                $candidateOccurrences[$modelId] = (int) ($candidateOccurrences[$modelId] ?? 0) + 1;
                $candidateOccurrence = $candidateOccurrences[$modelId];
                $providerUsageSession = $this->qualityProviderUsageSession(
                    check: $check,
                    model: $candidate,
                    aiExecutionSnapshot: $aiExecutionSnapshot,
                    scope: 'full',
                    sourceType: ArticleAiQualitySegment::class,
                    sourceId: (int) $segment->id,
                    segmentIndex: (int) $segmentData['index'],
                    executionAttempt: $segmentAttempt,
                    candidateOrdinal: $candidateIndex + 1,
                    candidateOccurrence: $candidateOccurrence,
                    requestPayload: $instructions,
                );
                try {
                    $remainingSeconds = $this->remainingDeadlineSeconds($deadlineAt);
                    if ($remainingSeconds <= $persistenceReserveSeconds) {
                        throw new ArticleAiQualityRuntimeException('inspection_deadline_exceeded', false);
                    }
                    if ($candidateIndex > 0 && $remainingSeconds < 10) {
                        break;
                    }
                    $availableRequestSeconds = max(1, (int) floor($remainingSeconds - $persistenceReserveSeconds));
                    $remainingCandidateCount = max(1, count($candidateQueue) - $candidateIndex);
                    $candidateBudgetSeconds = $remainingCandidateCount > 1
                        ? max(10, (int) floor($availableRequestSeconds * 0.65))
                        : $availableRequestSeconds;
                    $requestTimeout = max(1, min(
                        (int) config('geoflow.ai_quality_request_timeout_seconds', 160),
                        $availableRequestSeconds,
                        $candidateBudgetSeconds,
                    ));
                    $modelStartedAt = hrtime(true);
                    try {
                        $candidateReview = $this->reviewer instanceof ProviderAttemptAwareArticleAiQualityReviewer
                            && $providerUsageSession instanceof ArticleAiQualityProviderUsageSession
                            ? $this->reviewer->reviewWithinVersionTrackingProviderAttempts(
                                $candidate,
                                $instructions,
                                $requestTimeout,
                                $executionVersion,
                                $providerUsageSession,
                            )
                            : ($this->reviewer instanceof VersionAwareArticleAiQualityReviewer
                                ? $this->reviewer->reviewWithinVersion(
                                    $candidate,
                                    $instructions,
                                    $requestTimeout,
                                    $executionVersion,
                                )
                                : ($this->reviewer instanceof DeadlineAwareArticleAiQualityReviewer
                                    ? $this->reviewer->reviewWithin($candidate, $instructions, $requestTimeout)
                                    : $this->reviewer->review($candidate, $instructions)));
                    } finally {
                        $modelTotalMs += $this->elapsedMilliseconds($modelStartedAt);
                    }
                    if ($aiExecutionSnapshot !== null) {
                        $this->aiExecutionAccessGuard->assertModelForPersistedAdminSnapshot(
                            $aiExecutionSnapshot,
                            $candidate,
                            $check->task_id ? (int) $check->task_id : null,
                        );
                    }
                    if ($this->remainingDeadlineSeconds($deadlineAt) <= $persistenceReserveSeconds) {
                        throw new ArticleAiQualityRuntimeException('inspection_deadline_exceeded', false);
                    }
                    $runUsage = $this->mergeUsage(
                        $runUsage,
                        is_array($candidateReview['usage'] ?? null) ? $candidateReview['usage'] : [],
                    );
                    if (! is_array($candidateReview['result'] ?? null)) {
                        throw new ArticleAiQualityRuntimeException('invalid_model_output', false);
                    }
                    $raw = $candidateReview['result'];
                    $validationStartedAt = hrtime(true);
                    try {
                        $validated = $this->resultValidator->validate(
                            $raw,
                            $articleSnapshot,
                            $segmentFacts,
                            $segmentEvidence,
                            $rules,
                            $segmentData,
                        );
                    } finally {
                        $validationMs += $this->elapsedMilliseconds($validationStartedAt);
                    }
                    $attempts[] = $this->modelAttempt(
                        $segmentData,
                        $candidate,
                        'succeeded',
                        null,
                        $this->elapsedMilliseconds($candidateStartedAt),
                    );
                    $review = $candidateReview;
                    $winningUsageSession = $providerUsageSession;
                    break;
                } catch (Throwable $exception) {
                    if ($exception instanceof AiModelAccessException) {
                        $providerUsageSession?->revoked($exception->getErrorCode());

                        throw $exception;
                    }
                    $lastException = $exception;
                    $errorCode = $this->safeErrorCode($exception);
                    $providerUsageSession?->discarded($errorCode);
                    $attempts[] = $this->modelAttempt(
                        $segmentData,
                        $candidate,
                        'failed',
                        $errorCode,
                        $this->elapsedMilliseconds($candidateStartedAt),
                    );
                    if ($errorCode === 'invalid_model_output'
                        && ! isset($invalidOutputRetries[$modelId])
                        && $this->remainingDeadlineSeconds($deadlineAt) > $persistenceReserveSeconds + 10) {
                        $invalidOutputRetries[$modelId] = true;
                        array_splice($candidateQueue, $candidateIndex + 1, 0, [$candidate]);
                    }
                    if ($errorCode !== 'invalid_model_output'
                        && $this->aiModelFailoverDecider->isPermanentProviderFailure($exception)) {
                        throw $exception;
                    }
                    if ($errorCode !== 'invalid_model_output'
                        && ! $this->aiModelFailoverDecider->shouldFailover($exception)
                        && ! in_array($errorCode, [
                            'model_timeout',
                            'provider_timeout',
                            'provider_rate_limited',
                            'provider_gateway_error',
                            'provider_circuit_open',
                        ], true)) {
                        throw $exception;
                    }
                }
            }
            if (! is_array($review)) {
                $this->recordSegmentRun($checkId, (int) $segmentData['index'], [
                    'attempts' => $attempts,
                    'usage' => $runUsage,
                    'model' => [],
                    'mode' => null,
                    'timings_ms' => [
                        'prompt_render' => $promptRenderMs,
                        'model_total' => $modelTotalMs,
                        'validation' => $validationMs,
                    ],
                ]);

                throw $lastException ?? new RuntimeException('ai_quality_model_unavailable');
            }
            $validated['knowledge_coverage'] = $evidenceResult['knowledge_coverage'];
            $runMeta = [
                'attempts' => $attempts,
                'usage' => $runUsage,
                'model' => is_array($review['model'] ?? null) ? $review['model'] : [],
                'mode' => (string) ($review['mode'] ?? ''),
                'timings_ms' => [
                    'prompt_render' => $promptRenderMs,
                    'model_total' => $modelTotalMs,
                    'validation' => $validationMs,
                ],
            ];
            [$storedSegmentRaw, $segmentRawTruncated] = $this->boundedRawPayload($raw);
            $runMeta['raw_model_output_truncated'] = $segmentRawTruncated;
            try {
                $this->executionBoundaryHook->beforeFullSegmentCommit($check, $segment, $candidate);
                $segmentCompleted = $this->completeSegment(
                    $checkId,
                    (int) $segment->id,
                    $storedSegmentRaw,
                    $validated,
                    $runMeta,
                    $aiExecutionSnapshot,
                    (int) data_get($review, 'model.id', (int) $candidate->id),
                );
            } catch (AiModelAccessException $exception) {
                $winningUsageSession?->revoked($exception->getErrorCode());

                throw $exception;
            }
            if (! $segmentCompleted) {
                $winningUsageSession?->discarded('ai_result_not_committed');

                return $this->latestCheck($checkId);
            }
            $winningUsageSession?->succeeded();

            $validatedResults[] = $validated;
            $rawResults[] = $raw;
            $segmentRuns[(string) $segmentData['index']] = $runMeta;
            $this->accumulateRunTelemetry($runMeta, $usage, $modelMeta, $modes, $modelAttempts);

            if (ArticleAiQualitySegment::query()
                ->where('article_ai_quality_check_id', $checkId)
                ->whereIn('status', ['queued', 'running', 'failed'])
                ->exists()) {
                return $this->queueNextSegment($checkId);
            }
        }

        $aggregate = $this->aggregate($validatedResults, $evidenceResult['knowledge_coverage']);
        $atomicFacts = $this->atomicFactsFromRetrievalResult($check, $evidenceResult, $articleSnapshot, $policy);
        if ((bool) ($atomicFacts['formal'] ?? false)) {
            $aggregate['issues'] = array_values(array_merge($aggregate['issues'], (array) data_get($atomicFacts, 'inspection.issues', [])));
        }
        $executionMeta['atomic_facts'] = $atomicFacts;
        $usage = $this->withRetrievalUsageBreakdown($usage, $atomicFacts);
        $scoringStartedAt = hrtime(true);
        $score = (string) $check->scoring_version === 'v2'
            ? $this->scorerV2->score($aggregate, (int) $check->pass_score, (int) $check->manual_override_min_score)
            : $this->scorer->score($aggregate, (int) $check->pass_score, (int) $check->manual_override_min_score);
        $score = $this->applyRetrievalDecisionCaps($score, $check, $evidenceResult, $atomicFacts);
        $timings = $this->aggregateTimings($timings, $segmentRuns);
        $timings['scoring'] = $this->elapsedMilliseconds($scoringStartedAt);
        $timings['persistence'] = 0;
        $timings['total'] = $this->endToEndElapsedMilliseconds($check);
        $completedAt = now();
        [$storedRawResults, $rawResultsTruncated] = $this->boundedRawPayload(array_slice($rawResults, 0, 20));
        $persistenceStartedAt = hrtime(true);
        $completed = DB::transaction(function () use (
            $checkId,
            $score,
            $aggregate,
            $storedRawResults,
            $rawResultsTruncated,
            $modelMeta,
            $usage,
            $modes,
            $validatedResults,
            $modelAttempts,
            $segmentRuns,
            $timings,
            $completedAt,
            $executionMeta,
            $aiExecutionSnapshot,
        ): int {
            $committedEpoch = max(1, (int) (ArticleAiQualityRollout::query()
                ->whereKey(1)
                ->lockForUpdate()
                ->value('epoch') ?? 1));
            $current = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $current
                || (string) $current->status !== 'running'
                || (string) $current->inspection_scope !== 'full'
                || ! $this->rolloutEpochMatches($current, $committedEpoch)
                || $this->remainingDeadlineSeconds($this->primaryDeadlineAt($current)) <= 0) {
                return 0;
            }
            if ($aiExecutionSnapshot !== null) {
                $this->aiExecutionAccessGuard->assertPersistedAdminSnapshot(
                    $aiExecutionSnapshot,
                    $current->task_id ? (int) $current->task_id : null,
                );
            }

            return ArticleAiQualityCheck::query()
                ->whereKey($checkId)
                ->where('status', 'running')
                ->where('inspection_scope', 'full')
                ->where(function ($query): void {
                    $query->where('primary_deadline_at', '>', now())
                        ->orWhere(function ($legacy): void {
                            $legacy->whereNull('primary_deadline_at')
                                ->where('created_at', '>', now()->subSeconds((int) config('geoflow.ai_quality_deadline_seconds', 180)));
                        });
                })
                ->update([
                    'status' => 'completed',
                    'decision' => $score['decision'],
                    'score' => $score['score'],
                    'summary' => $aggregate['summary'],
                    'promotion_context' => $aggregate['promotion_context'],
                    'knowledge_coverage' => $aggregate['knowledge_coverage'],
                    'dimension_scores' => json_encode($score['dimension_scores'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'issues' => json_encode($score['issues'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'uncertainties' => json_encode($score['uncertainties'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'confidence' => $score['confidence'] ?? null,
                    'gate_reasons' => json_encode($score['gate_reasons'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'truncated_issue_count' => (int) ($aggregate['truncated_issue_count'] ?? 0),
                    'raw_model_output' => json_encode($storedRawResults, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'ai_model_id' => isset($modelMeta['id']) ? (int) $modelMeta['id'] : (int) $current->ai_model_id,
                    'model_snapshot' => json_encode(
                        array_replace(is_array($current->model_snapshot) ? $current->model_snapshot : [], $modelMeta),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                    'usage_meta' => json_encode($usage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'execution_meta' => json_encode(
                        array_replace(is_array($current->execution_meta) ? $current->execution_meta : [], [
                            'atomic_facts' => $executionMeta['atomic_facts'] ?? [],
                            'output_modes' => array_values(array_unique($modes)),
                            'completed_segments' => count($validatedResults),
                            'model_attempts' => $modelAttempts,
                            'segment_runs' => $segmentRuns,
                            'current_phase' => 'finished',
                            'timings_ms' => $timings,
                            'raw_model_output_truncated' => $rawResultsTruncated,
                            'workflow_apply' => [
                                'status' => 'pending',
                                'attempts' => 0,
                                'error_code' => null,
                                'updated_at' => $completedAt->toIso8601String(),
                            ],
                        ]),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                    'active_dedupe_key' => null,
                    'finished_at' => $completedAt,
                    'updated_at' => $completedAt,
                ]);
        });
        if ($completed !== 1) {
            $latest = $this->latestCheck($checkId);
            if (in_array((string) $latest->status, ['queued', 'running'], true)
                && (string) $latest->inspection_scope === 'full'
                && $this->remainingDeadlineSeconds($this->primaryDeadlineAt($latest)) <= 0) {
                $exception = new ArticleAiQualityRuntimeException('inspection_primary_deadline_exceeded', false);
                if (! $this->tryStartSampledFallback($latest, $exception)) {
                    $this->markFailed($latest, $exception);
                }

                return $this->latestCheck($checkId);
            }

            return $latest;
        }

        $timings['persistence'] = $this->elapsedMilliseconds($persistenceStartedAt);
        $timings['total'] = $this->endToEndElapsedMilliseconds($check);
        $completedCheck = ArticleAiQualityCheck::query()->findOrFail($checkId);
        $completedMeta = is_array($completedCheck->execution_meta) ? $completedCheck->execution_meta : [];
        $completedCheck->forceFill([
            'execution_meta' => array_replace($completedMeta, ['timings_ms' => $timings]),
        ])->save();

        $check = $this->latestCheck($checkId);
        $this->continueAfterCompletedCheck($check->loadMissing(['article', 'task']));
        if ((bool) data_get($check->execution_meta, 'version_selection.shadow_v2', false)) {
            $this->createShadowScore($check, $aggregate);
        }

        return $check;
    }

    /** @param array<string, mixed> $policy @param array<string, mixed> $rules @param array<string, mixed> $versionSelection */
    public function currentFingerprint(
        Article $article,
        array $policy,
        array $rules,
        array $versionSelection,
    ): string {
        if ((string) ($versionSelection['principles'] ?? 'v1') === 'v2') {
            $articleSnapshot = $this->policyResolver->articleSnapshot($article);
            $principleSnapshot = $this->principleCompiler->compile(
                $articleSnapshot,
                $rules,
                array_values($this->policyResolver->fingerprintInput($article, $policy, $rules)['knowledge'] ?? []),
                is_array($policy['publication_context'] ?? null) ? $policy['publication_context'] : [],
            );
            $rules = $this->principleCompiler->rules($principleSnapshot);
        }
        $executionVersion = (string) ($versionSelection['execution'] ?? 'legacy');
        $promptTemplate = $this->promptTemplate($policy['prompt'], $executionVersion);

        return $this->fingerprint->make(
            $this->fingerprintInput($article, $policy, $rules, $promptTemplate, $executionVersion),
            (string) ($versionSelection['algorithm_version'] ?? ArticleAiQualityFingerprint::ALGORITHM_VERSION),
        );
    }

    public function tryStartSampledFallback(
        ArticleAiQualityCheck|int $check,
        Throwable $exception,
        bool $dispatch = true,
    ): bool {
        $checkId = $check instanceof ArticleAiQualityCheck ? (int) $check->id : $check;
        $errorCode = $this->safeErrorCode($exception);
        if (! $this->sampledFallbackEligible($errorCode)) {
            return false;
        }

        return DB::transaction(function () use ($checkId, $errorCode, $dispatch): bool {
            $taskId = (int) (ArticleAiQualityCheck::query()->whereKey($checkId)->value('task_id') ?? 0);
            $task = $taskId > 0
                ? Task::query()->whereKey($taskId)->lockForUpdate()->first()
                : null;
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            $executionMeta = is_array($check?->execution_meta) ? $check->execution_meta : [];
            [$aiExecutionSnapshot, $invalidExecutionSnapshot] = $this->qualityExecutionSnapshotForCheck($executionMeta);
            if ($check instanceof ArticleAiQualityCheck
                && ($invalidExecutionSnapshot
                    || ($aiExecutionSnapshot === null && $this->aiExecutionContextFactory->identityRequired()))) {
                $check->forceFill([
                    'status' => 'stale',
                    'decision' => null,
                    'active_dedupe_key' => null,
                    'error_code' => AiModelAccessException::AI_CONFIG_ACCESS_REVOKED,
                    'error_message' => 'AI 执行身份已失效。',
                    'finished_at' => now(),
                ])->save();

                return false;
            }
            if ($check instanceof ArticleAiQualityCheck && $aiExecutionSnapshot !== null) {
                $this->aiExecutionAccessGuard->assertPersistedAdminSnapshot(
                    $aiExecutionSnapshot,
                    $check->task_id ? (int) $check->task_id : null,
                );
            }
            $policySnapshot = is_array($executionMeta['policy_snapshot'] ?? null)
                ? $executionMeta['policy_snapshot']
                : [];
            if (! $check
                || ! in_array((string) $check->status, ['queued', 'running'], true)
                || (string) $check->inspection_scope !== 'full'
                || ! (bool) ($policySnapshot['timeout_sampling_enabled'] ?? false)
                || ! $task instanceof Task
                || ! (bool) $task->ai_quality_enabled
                || ! (bool) $task->ai_quality_timeout_sampling_enabled
                || $this->remainingDeadlineSeconds($this->deadlineAt($check)) <= 0) {
                return false;
            }

            $startedAt = now();
            $sampledDeadlineAt = $startedAt->copy()->addSeconds(
                (int) config('geoflow.ai_quality_sampled_fallback_seconds', 45)
                + (int) config('geoflow.ai_quality_persistence_reserve_seconds', 10),
            );
            $finalDeadlineAt = $this->deadlineAt($check);
            if ($sampledDeadlineAt->gt($finalDeadlineAt)) {
                $sampledDeadlineAt = $finalDeadlineAt->copy();
            }
            ArticleAiQualitySegment::query()
                ->where('article_ai_quality_check_id', $checkId)
                ->whereIn('status', ['queued', 'running', 'failed'])
                ->update([
                    'status' => 'cancelled',
                    'error_code' => 'sampled_fallback_started',
                    'error_message' => '完整质检未完成，本分段已因抽样降级而取消。',
                    'finished_at' => $startedAt,
                    'updated_at' => $startedAt,
                ]);

            $executionMeta['current_phase'] = 'sampling_queued';
            $executionMeta['fallback'] = [
                'trigger_code' => $errorCode,
                'started_at' => $startedAt->toIso8601String(),
                'algorithm_version' => ArticleAiQualitySampleBuilder::ALGORITHM_VERSION,
            ];
            $check->forceFill([
                'status' => 'queued',
                'decision' => null,
                'score' => null,
                'dimension_scores' => null,
                'inspection_scope' => 'fallback_sampled',
                'fallback_trigger_code' => $errorCode,
                'sampled_deadline_at' => $sampledDeadlineAt,
                'coverage_meta' => null,
                'error_code' => null,
                'error_message' => null,
                'execution_meta' => $executionMeta,
                'finished_at' => null,
                'updated_at' => $startedAt,
            ])->save();

            if ($dispatch) {
                DB::afterCommit(fn () => $this->dispatchCheck($checkId));
            }

            return true;
        });
    }

    /** @param array<string,mixed>|null $aiExecutionSnapshot */
    private function processSampledFallback(
        ArticleAiQualityCheck $check,
        ?array $aiExecutionSnapshot,
        ?Admin $executionAdmin,
    ): ArticleAiQualityCheck {
        $checkId = (int) $check->id;
        $reserveSeconds = (int) config('geoflow.ai_quality_persistence_reserve_seconds', 10);
        if ($this->remainingDeadlineSeconds($this->sampledDeadlineAt($check)) <= $reserveSeconds) {
            throw new ArticleAiQualityRuntimeException('inspection_deadline_exceeded', false);
        }
        if (! $check->article) {
            return $this->markCancelled($check, 'article_unavailable');
        }

        $claimed = ArticleAiQualityCheck::query()
            ->whereKey($checkId)
            ->where('status', 'queued')
            ->where('inspection_scope', 'fallback_sampled')
            ->where(function ($query): void {
                $query->where('sampled_deadline_at', '>', now())
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('sampled_deadline_at')->where('deadline_at', '>', now());
                    });
            })
            ->update([
                'status' => 'running',
                'attempt_count' => DB::raw('attempt_count + 1'),
                'started_at' => $check->started_at ?: now(),
                'error_code' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return $this->latestCheck($checkId);
        }

        $check = ArticleAiQualityCheck::query()->with(['article', 'segments'])->findOrFail($checkId);
        $articleSnapshot = is_array($check->article_snapshot) ? $check->article_snapshot : [];
        $currentArticleHash = hash('sha256', json_encode(
            $this->policyResolver->articleSnapshot($check->article),
            JSON_UNESCAPED_UNICODE,
        ));
        if (! hash_equals((string) $check->article_content_hash, $currentArticleHash)) {
            return $this->markStale($check);
        }

        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        $policySnapshot = is_array($executionMeta['policy_snapshot'] ?? null)
            ? $executionMeta['policy_snapshot']
            : [];
        $policy = $this->policyResolver->fromArticleSnapshot(
            $policySnapshot,
            (string) ($policySnapshot['source'] ?? 'article_snapshot'),
        );
        $rules = $this->rulesForCheck($check);
        if (! $this->retrievalBasisMatches($check, $policy, $rules)) {
            return $this->markStale($check, 'ai_quality_retrieval_source_stale');
        }
        $this->policyResolver->assertExecutable($policy);
        ArticleAiQualityCheck::query()->whereKey($checkId)->where('status', 'running')->update([
            'retrieval_failure_code' => null,
            'updated_at' => now(),
        ]);
        $extractedFacts = $this->factExtractor->extract($articleSnapshot, 1000);
        $facts = $this->mergeSampledFacts(
            $extractedFacts,
            is_array($check->fact_candidates_snapshot) ? $check->fact_candidates_snapshot : [],
        );

        if (is_array($check->evidence_snapshot)) {
            $evidence = $check->evidence_snapshot;
            $knowledgeCoverage = (string) ($check->knowledge_coverage ?: ($evidence === [] ? 'insufficient' : 'partial'));
            if (collect($facts)->contains(static fn (array $fact): bool => in_array(
                (string) ($fact['materiality'] ?? ''),
                ['high', 'medium'],
                true,
            ) && (string) ($fact['coverage_status'] ?? 'insufficient') !== 'sufficient')) {
                $knowledgeCoverage = 'insufficient';
            }
            $evidenceResult = [
                'evidence' => $evidence,
                'fact_candidates' => $facts,
                'knowledge_coverage' => $knowledgeCoverage,
                'effective_retrieval_mode' => (string) (
                    $check->effective_retrieval_mode ?: $check->requested_retrieval_mode ?: AiQualityRetrievalMode::legacyDefault()
                ),
                'retrieval_strategy_version' => (string) $check->retrieval_strategy_version,
                'retrieval_meta' => is_array($executionMeta['retrieval'] ?? null) ? $executionMeta['retrieval'] : [],
            ];
        } else {
            try {
                $evidenceResult = $this->retrievalCoordinator->retrieve(
                    (string) ($check->requested_retrieval_mode ?: AiQualityRetrievalMode::legacyDefault()),
                    $policy['knowledge_base_ids'] ?? [],
                    $articleSnapshot,
                    $facts,
                    [
                        'max_evidence' => (int) config('geoflow.ai_quality_max_evidence', 12),
                        'max_characters' => (int) config('geoflow.ai_quality_max_evidence_characters', 6000),
                        'max_fact_retrievals' => (int) config('geoflow.ai_quality_max_fact_retrievals', 6),
                        'generation_evidence' => is_array($check->article->generation_evidence_snapshot)
                            ? $check->article->generation_evidence_snapshot
                            : [],
                        'serving_generations' => $this->frozenServingGenerations($check),
                    ],
                )->toArray();
            } catch (InvalidArgumentException $exception) {
                throw new ArticleAiQualityRuntimeException('evidence_retrieval_failed', false, $exception);
            } catch (Throwable $exception) {
                throw new ArticleAiQualityRuntimeException('evidence_retrieval_failed', true, $exception);
            }
            $facts = is_array($evidenceResult['fact_candidates'] ?? null) ? $evidenceResult['fact_candidates'] : $facts;
            $evidence = is_array($evidenceResult['evidence'] ?? null) ? $evidenceResult['evidence'] : [];
            $knowledgeCoverage = (string) ($evidenceResult['knowledge_coverage'] ?? 'insufficient');
        }
        $effectiveRetrievalMode = $this->effectiveRetrievalMode($check, $evidenceResult);

        $riskScan = $this->riskScanner->scan($articleSnapshot);
        $sample = $this->sampleBuilder->build(
            $articleSnapshot,
            $facts,
            is_array($riskScan['matches'] ?? null) ? $riskScan['matches'] : [],
        );
        $coverage = array_replace($sample, [
            'deterministic_risk_status' => (string) ($riskScan['status'] ?? 'clean'),
            'deterministic_risk_match_count' => (int) ($riskScan['match_count'] ?? 0),
            'knowledge_coverage' => $knowledgeCoverage,
        ]);
        $promptFacts = $this->factsForSample($facts, (array) ($sample['sampled_ranges'] ?? []));
        $promptEvidence = $this->evidenceForFacts($evidence, $promptFacts);
        $coverage['fact_candidates_total'] = count($facts);
        $coverage['fact_candidates_prompted'] = count($promptFacts);

        $executionMeta['current_phase'] = 'sampling';
        $executionMeta['retrieval'] = is_array($evidenceResult['retrieval_meta'] ?? null)
            ? $evidenceResult['retrieval_meta']
            : (is_array($executionMeta['retrieval'] ?? null) ? $executionMeta['retrieval'] : []);
        $executionMeta['fallback'] = array_replace(
            is_array($executionMeta['fallback'] ?? null) ? $executionMeta['fallback'] : [],
            ['coverage_built_at' => now()->toIso8601String()],
        );
        $snapshotStored = ArticleAiQualityCheck::query()
            ->whereKey($checkId)
            ->where('status', 'running')
            ->where('inspection_scope', 'fallback_sampled')
            ->update([
                'fact_candidates_snapshot' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'evidence_snapshot' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'knowledge_coverage' => $knowledgeCoverage,
                'coverage_meta' => json_encode($coverage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'effective_retrieval_mode' => $effectiveRetrievalMode,
                'retrieval_strategy_version' => (string) ($evidenceResult['retrieval_strategy_version'] ?? $check->retrieval_strategy_version),
                'execution_meta' => json_encode($executionMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        if ($snapshotStored !== 1) {
            return $this->latestCheck($checkId);
        }
        $this->markRetrievalSourcesUsed($checkId, $evidenceResult ?? []);

        $executionVersion = (string) data_get($executionMeta, 'version_selection.execution', 'legacy');
        $instructions = $this->promptRenderer->render((string) $check->prompt_template_snapshot, [
            'article_title' => (string) ($articleSnapshot['title'] ?? ''),
            'article_excerpt' => (string) ($articleSnapshot['excerpt'] ?? ''),
            'article_outline' => $this->outline((string) ($articleSnapshot['content'] ?? '')),
            'article_content' => (string) ($sample['sampled_content'] ?? ''),
            'keywords' => (string) ($articleSnapshot['keywords'] ?? ''),
            'meta_description' => (string) ($articleSnapshot['meta_description'] ?? ''),
            'fact_candidates' => $promptFacts,
            'knowledge' => $promptEvidence,
            'advertising_rules' => $rules,
            'inspection_date' => now()->toDateString(),
            'publication_context' => array_replace(
                is_array($policy['publication_context'] ?? null) ? $policy['publication_context'] : [],
                ['inspection_scope' => 'fallback_sampled', 'coverage' => $this->publicCoverage($coverage)],
            ),
            'segment_index' => 1,
            'segment_count' => 1,
            'segment_start_offset' => 0,
        ]);

        if ($this->providerExecutionIdentityMissing($aiExecutionSnapshot)) {
            return $this->markStale($check, AiModelAccessException::AI_CONFIG_ACCESS_REVOKED);
        }

        $candidateIds = array_values(array_filter(array_map(
            'intval',
            is_array($executionMeta['model_candidate_ids'] ?? null) ? $executionMeta['model_candidate_ids'] : [],
        )));
        $candidates = $aiExecutionSnapshot !== null
            ? AiModel::query()->whereIn('id', $candidateIds)->get()->sortBy(
                static fn (AiModel $model): int => array_search((int) $model->id, $candidateIds, true),
            )->values()->all()
            : $this->policyResolver->modelCandidates($policy);
        $requestedModelId = (int) ($aiExecutionSnapshot['requested_model_id'] ?? $check->ai_model_id ?? 0);
        if ($aiExecutionSnapshot !== null && $executionAdmin instanceof Admin && $requestedModelId > 0) {
            $this->aiExecutionAccessGuard->assertModelForPersistedAdminSnapshot(
                $aiExecutionSnapshot,
                $requestedModelId,
                $check->task_id ? (int) $check->task_id : null,
                $executionAdmin,
            );
        }
        $model = null;
        $selectedCandidateOrdinal = 0;
        foreach ($candidates as $candidateIndex => $candidate) {
            if (! $candidate instanceof AiModel
                || (string) $candidate->status !== 'active'
                || ! in_array((string) ($candidate->model_type ?? ''), ['', 'chat'], true)) {
                continue;
            }
            if ($aiExecutionSnapshot !== null) {
                try {
                    $candidate = $this->aiExecutionAccessGuard->assertModelForPersistedAdminSnapshot(
                        $aiExecutionSnapshot,
                        $candidate,
                        $check->task_id ? (int) $check->task_id : null,
                        $executionAdmin,
                    );
                } catch (AiModelAccessException $exception) {
                    if ((int) $candidate->id === $requestedModelId
                        || ! in_array($exception->getErrorCode(), [
                            AiModelAccessException::AI_MODEL_NOT_ACCESSIBLE,
                            AiModelAccessException::AI_MODEL_UNAVAILABLE,
                        ], true)) {
                        throw $exception;
                    }

                    continue;
                }
            }
            $model = $candidate;
            $selectedCandidateOrdinal = $candidateIndex + 1;
            break;
        }
        if (! $model instanceof AiModel) {
            throw new ArticleAiQualityRuntimeException('model_unavailable', false);
        }

        $remaining = max(1, $this->remainingDeadlineSeconds($this->sampledDeadlineAt($check)) - $reserveSeconds);
        $requestTimeout = min(
            (int) config('geoflow.ai_quality_sampled_request_timeout_seconds', 35),
            $remaining,
        );
        $this->executionBoundaryHook->beforeSampledOutbound($check, $model);
        if ($aiExecutionSnapshot !== null) {
            $executionAdmin = $this->aiExecutionAccessGuard->assertPersistedAdminSnapshot(
                $aiExecutionSnapshot,
                $check->task_id ? (int) $check->task_id : null,
            );
            $model = $this->aiExecutionAccessGuard->assertModelForPersistedAdminSnapshot(
                $aiExecutionSnapshot,
                $model,
                $check->task_id ? (int) $check->task_id : null,
                $executionAdmin,
            );
        }
        $providerUsageSession = $this->qualityProviderUsageSession(
            check: $check,
            model: $model,
            aiExecutionSnapshot: $aiExecutionSnapshot,
            scope: 'sampled',
            sourceType: ArticleAiQualityCheck::class,
            sourceId: $checkId,
            segmentIndex: 0,
            executionAttempt: (int) $check->attempt_count,
            candidateOrdinal: $selectedCandidateOrdinal,
            candidateOccurrence: 1,
            requestPayload: $instructions,
        );
        $review = $this->reviewer instanceof ProviderAttemptAwareArticleAiQualityReviewer
            && $providerUsageSession instanceof ArticleAiQualityProviderUsageSession
            ? $this->reviewer->reviewWithinVersionTrackingProviderAttempts(
                $model,
                $instructions,
                $requestTimeout,
                $executionVersion,
                $providerUsageSession,
            )
            : ($this->reviewer instanceof VersionAwareArticleAiQualityReviewer
                ? $this->reviewer->reviewWithinVersion($model, $instructions, $requestTimeout, $executionVersion)
                : ($this->reviewer instanceof DeadlineAwareArticleAiQualityReviewer
                    ? $this->reviewer->reviewWithin($model, $instructions, $requestTimeout)
                    : $this->reviewer->review($model, $instructions)));
        if ($aiExecutionSnapshot !== null) {
            try {
                $this->aiExecutionAccessGuard->assertModelForPersistedAdminSnapshot(
                    $aiExecutionSnapshot,
                    $model,
                    $check->task_id ? (int) $check->task_id : null,
                );
            } catch (AiModelAccessException $exception) {
                $providerUsageSession?->revoked($exception->getErrorCode());

                throw $exception;
            }
        }
        if ($this->remainingDeadlineSeconds($this->sampledDeadlineAt($check)) <= $reserveSeconds) {
            throw new ArticleAiQualityRuntimeException('inspection_deadline_exceeded', false);
        }

        $raw = is_array($review['result'] ?? null) ? $review['result'] : [];
        $validated = $this->resultValidator->validate($raw, $articleSnapshot, $promptFacts, $promptEvidence, $rules);
        $validated['knowledge_coverage'] = $knowledgeCoverage;
        $completedSegmentResults = $check->segments
            ->filter(static fn (ArticleAiQualitySegment $segment): bool => (string) $segment->status === 'completed'
                && is_array($segment->validated_result))
            ->pluck('validated_result')
            ->map(fn (array $result): array => $this->resultValidator->normalizeLegacyRemovedDisclosureArtifacts($result))
            ->values()
            ->all();
        $aggregate = $this->aggregate(array_merge($completedSegmentResults, [$validated]), $knowledgeCoverage);
        $atomicFacts = $this->atomicFactsFromRetrievalResult($check, $evidenceResult ?? [], $articleSnapshot, $policy);
        if ((bool) ($atomicFacts['formal'] ?? false)) {
            $aggregate['issues'] = array_values(array_merge($aggregate['issues'], (array) data_get($atomicFacts, 'inspection.issues', [])));
        }
        $executionMeta['atomic_facts'] = $atomicFacts;

        foreach ((array) ($riskScan['matches'] ?? []) as $match) {
            if ((string) ($match['severity'] ?? '') !== 'blocked') {
                continue;
            }
            $aggregate['issues'][] = [
                'code' => 'ad_absolute_claim',
                'code_family' => 'advertising_compliance',
                'severity' => 'critical',
                'field' => (string) ($match['field'] ?? 'content'),
                'quote' => (string) ($match['word'] ?? ''),
                'reason' => '确定性风险扫描命中阻断规则。',
                'suggestion' => (string) ($match['suggestion'] ?? '修改后重新质检。'),
                'location_status' => 'resolved',
                'references_valid' => true,
            ];
        }

        $score = (string) $check->scoring_version === 'v2'
            ? $this->scorerV2->score($aggregate, (int) $check->pass_score, (int) $check->manual_override_min_score)
            : $this->scorer->score($aggregate, (int) $check->pass_score, (int) $check->manual_override_min_score);
        $score = $this->applyRetrievalDecisionCaps($score, $check, $evidenceResult ?? [], $atomicFacts);
        $gateReasons = array_values(is_array($score['gate_reasons'] ?? null) ? $score['gate_reasons'] : []);
        $coverageSafe = ! (bool) ($coverage['mandatory_overflow'] ?? true)
            && (int) ($coverage['mandatory_claims_covered'] ?? -1) === (int) ($coverage['mandatory_claims_total'] ?? 0)
            && array_values($coverage['regions_covered'] ?? []) === ['front', 'middle', 'back'];
        $hasHighUncertainty = collect($aggregate['uncertainties'] ?? [])->contains(
            static fn (mixed $uncertainty): bool => is_array($uncertainty)
                && (string) ($uncertainty['materiality'] ?? '') === 'high',
        );
        $hasHighIssue = collect($score['issues'] ?? [])->contains(
            static fn (mixed $issue): bool => is_array($issue)
                && in_array((string) ($issue['severity'] ?? ''), ['critical', 'high'], true),
        );
        $rawOutputTruncated = (int) ($aggregate['truncated_issue_count'] ?? 0) > 0;
        foreach ([
            'sample_coverage_incomplete' => ! $coverageSafe,
            'sample_knowledge_insufficient' => $knowledgeCoverage !== 'sufficient',
            'sample_high_uncertainty' => $hasHighUncertainty,
            'sample_output_truncated' => $rawOutputTruncated,
            'sample_high_risk_issue' => $hasHighIssue && ($score['decision'] ?? null) !== 'blocked',
        ] as $reason => $applies) {
            if ($applies) {
                $gateReasons[] = $reason;
            }
        }
        $gateReasons = array_values(array_unique($gateReasons));
        if (($score['decision'] ?? null) !== 'blocked' && $gateReasons !== []) {
            $score['decision'] = 'needs_review';
        }
        $coverage['safe_for_auto_release'] = $score['decision'] === 'passed' && $gateReasons === [];
        $coverage['gate_reasons'] = $gateReasons;

        [$storedRaw, $storedRawTruncated] = $this->boundedRawPayload($raw);
        if ($storedRawTruncated && ($score['decision'] ?? null) === 'passed') {
            $score['decision'] = 'needs_review';
            $gateReasons[] = 'sample_raw_output_storage_truncated';
            $coverage['safe_for_auto_release'] = false;
            $coverage['gate_reasons'] = array_values(array_unique($gateReasons));
        }
        $primaryUsage = $this->mergeUsage([], is_array($review['usage'] ?? null) ? $review['usage'] : []);
        foreach ((array) ($executionMeta['segment_runs'] ?? []) as $segmentRun) {
            if (is_array($segmentRun)) {
                $primaryUsage = $this->mergeUsage(
                    $primaryUsage,
                    is_array($segmentRun['usage'] ?? null) ? $segmentRun['usage'] : [],
                );
            }
        }
        $usage = $this->withRetrievalUsageBreakdown($primaryUsage, $atomicFacts);

        $completedAt = now();
        $completeSampledCheck = function () use (
            $checkId,
            $completedAt,
            $score,
            $aggregate,
            $knowledgeCoverage,
            $coverage,
            $storedRaw,
            $usage,
            $executionMeta,
            $atomicFacts,
            $review,
            $aiExecutionSnapshot,
            $model,
        ): int {
            $committedEpoch = max(1, (int) (ArticleAiQualityRollout::query()
                ->whereKey(1)
                ->lockForUpdate()
                ->value('epoch') ?? 1));
            $current = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $current instanceof ArticleAiQualityCheck
                || (string) $current->status !== 'running'
                || (string) $current->inspection_scope !== 'fallback_sampled'
                || ! $this->rolloutEpochMatches($current, $committedEpoch)
                || $this->remainingDeadlineSeconds($this->sampledDeadlineAt($current)) <= 0) {
                return 0;
            }
            if ($aiExecutionSnapshot !== null) {
                $admin = $this->aiExecutionAccessGuard->assertPersistedAdminSnapshot(
                    $aiExecutionSnapshot,
                    $current->task_id ? (int) $current->task_id : null,
                );
                $this->aiExecutionAccessGuard->assertModelForPersistedAdminSnapshot(
                    $aiExecutionSnapshot,
                    $model,
                    $current->task_id ? (int) $current->task_id : null,
                    $admin,
                );
            }

            $current->forceFill([
                'status' => 'completed',
                'decision' => $score['decision'],
                'score' => $score['score'],
                'summary' => $aggregate['summary'],
                'promotion_context' => $aggregate['promotion_context'],
                'knowledge_coverage' => $knowledgeCoverage,
                'dimension_scores' => $score['dimension_scores'],
                'issues' => $score['issues'],
                'uncertainties' => $score['uncertainties'],
                'confidence' => $score['confidence'] ?? null,
                'gate_reasons' => $coverage['gate_reasons'],
                'truncated_issue_count' => (int) ($aggregate['truncated_issue_count'] ?? 0),
                'coverage_meta' => $coverage,
                'raw_model_output' => $storedRaw,
                'usage_meta' => $usage,
                'execution_meta' => array_replace($executionMeta, [
                    'atomic_facts' => $atomicFacts,
                    'current_phase' => 'finished',
                    'output_modes' => [(string) ($review['mode'] ?? '')],
                    'workflow_apply' => [
                        'status' => 'pending',
                        'attempts' => 0,
                        'error_code' => null,
                        'updated_at' => $completedAt->toIso8601String(),
                    ],
                ]),
                'active_dedupe_key' => null,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => $completedAt,
                'updated_at' => $completedAt,
            ])->save();

            return 1;
        };
        try {
            $this->executionBoundaryHook->beforeSampledCommit($check, $model);
            $completed = DB::transaction($completeSampledCheck);
        } catch (AiModelAccessException $exception) {
            $providerUsageSession?->revoked($exception->getErrorCode());

            throw $exception;
        }
        if ($completed !== 1) {
            $providerUsageSession?->discarded('ai_result_not_committed');

            throw new ArticleAiQualityRuntimeException('inspection_deadline_exceeded', false);
        }
        $providerUsageSession?->succeeded();

        $completedCheck = $this->latestCheck($checkId);
        $this->continueAfterCompletedCheck($completedCheck->loadMissing(['article', 'task']));

        return $this->latestCheck($checkId);
    }

    /** @param array<string,mixed> $coverage @return array<string,mixed> */
    private function publicCoverage(array $coverage): array
    {
        unset($coverage['sampled_content']);
        if (is_array($coverage['sampled_ranges'] ?? null)) {
            $coverage['sampled_ranges'] = array_values(array_map(static function (array $range): array {
                unset($range['content']);

                return $range;
            }, array_values(array_filter($coverage['sampled_ranges'], 'is_array'))));
        }

        return $coverage;
    }

    public function markFailed(
        ArticleAiQualityCheck|int $check,
        Throwable $exception,
        ?string $expectedScope = null,
    ): bool {
        if ($this->tryStartSampledFallback($check, $exception)) {
            return true;
        }

        $checkId = $check instanceof ArticleAiQualityCheck ? (int) $check->id : $check;
        $errorCode = $this->safeErrorCode($exception);
        $retryable = $this->retryableFailure($exception, $errorCode);
        $failureContext = $this->safeFailureContext($exception, $errorCode, $retryable);

        return DB::transaction(function () use ($checkId, $errorCode, $retryable, $failureContext, $expectedScope): bool {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check
                || ! in_array((string) $check->status, ['queued', 'running'], true)
                || ($expectedScope !== null && (string) $check->inspection_scope !== $expectedScope)) {
                return false;
            }

            ArticleAiQualitySegment::query()
                ->where('article_ai_quality_check_id', $checkId)
                ->whereIn('status', ['queued', 'running', 'failed'])
                ->update([
                    'status' => 'failed',
                    'error_code' => $errorCode,
                    'error_message' => 'AI 质检分段执行失败。',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            [$executionMeta, $usageMeta] = $this->terminalTelemetry($check);
            $executionMeta['retryable_failure'] = $retryable;
            $executionMeta['failure'] = $failureContext;
            $executionMeta['current_phase'] = 'finished';
            $check->forceFill([
                'status' => 'failed',
                'decision' => 'error',
                'score' => null,
                'dimension_scores' => null,
                'summary' => null,
                'issues' => null,
                'uncertainties' => null,
                'confidence' => null,
                'gate_reasons' => null,
                'active_dedupe_key' => null,
                'error_code' => $errorCode,
                'error_message' => 'AI 质检执行失败，请稍后重试或联系管理员检查模型配置。',
                'execution_meta' => $executionMeta,
                'usage_meta' => $usageMeta,
                'finished_at' => now(),
            ])->save();
            $this->holdUnpublishedArticleForReview((int) $check->article_id);

            return true;
        });
    }

    public function markRetryPending(ArticleAiQualityCheck|int $check, Throwable $exception): void
    {
        $checkId = $check instanceof ArticleAiQualityCheck ? (int) $check->id : $check;
        $errorCode = $this->safeErrorCode($exception);

        DB::transaction(function () use ($checkId, $errorCode): void {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check
                || ! in_array((string) $check->status, ['queued', 'running'], true)
                || $this->remainingDeadlineSeconds($this->deadlineAt($check)) <= 0) {
                return;
            }

            ArticleAiQualitySegment::query()
                ->where('article_ai_quality_check_id', $checkId)
                ->where('status', 'running')
                ->update([
                    'status' => 'failed',
                    'error_code' => $errorCode,
                    'error_message' => '本分段将在队列下一次尝试时重试。',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            [$executionMeta, $usageMeta] = $this->terminalTelemetry($check);
            $executionMeta['retryable_failure'] = true;
            $check->forceFill([
                'status' => 'queued',
                'decision' => null,
                'error_code' => $errorCode,
                'error_message' => 'AI 质检本次执行未完成，系统将自动重试。',
                'execution_meta' => $executionMeta,
                'usage_meta' => $usageMeta,
                'finished_at' => null,
            ])->save();
            $this->holdUnpublishedArticleForReview((int) $check->article_id);
        });
    }

    public function recoverStuckCheck(ArticleAiQualityCheck|int $check): bool
    {
        $checkId = $check instanceof ArticleAiQualityCheck ? (int) $check->id : $check;
        $candidate = ArticleAiQualityCheck::query()->find($checkId);
        if ($candidate
            && in_array((string) $candidate->status, ['queued', 'running'], true)
            && $this->remainingDeadlineSeconds($this->deadlineAt($candidate)) <= 0) {
            return $this->markFailed(
                $candidate,
                new ArticleAiQualityRuntimeException($this->workerLiveness->expirationCode($candidate), true),
            );
        }

        return DB::transaction(function () use ($checkId): bool {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check
                || ! in_array((string) $check->status, ['queued', 'running'], true)
                || $this->remainingDeadlineSeconds($this->deadlineAt($check)) <= 0
                || $check->updated_at?->isAfter(now()->subSeconds(
                    (int) config('geoflow.ai_quality_recovery_stale_seconds', 60),
                ))) {
                return false;
            }

            if ((string) $check->status === 'running') {
                ArticleAiQualitySegment::query()
                    ->where('article_ai_quality_check_id', $checkId)
                    ->where('status', 'running')
                    ->update([
                        'status' => 'failed',
                        'error_code' => 'worker_interrupted',
                        'error_message' => '质检进程中断，系统将从当前进度恢复。',
                        'finished_at' => now(),
                        'updated_at' => now(),
                    ]);
                $check->forceFill([
                    'status' => 'queued',
                    'decision' => 'error',
                    'error_code' => 'worker_interrupted',
                    'error_message' => '质检进程中断，系统已经重新排队。',
                    'finished_at' => null,
                ])->save();
            } else {
                $check->forceFill([
                    'error_code' => 'queue_recovered',
                    'error_message' => '质检排队等待时间过长，系统已经重新投递。',
                ])->save();
            }

            DB::afterCommit(fn () => $this->dispatchCheck($checkId, 2));

            return true;
        });
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $path = resource_path('rules/advertising-cn-v1.json');
        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded) || ! isset($decoded['version'], $decoded['rules'])) {
            throw new RuntimeException('ai_quality_rules_unavailable');
        }

        return $decoded;
    }

    /** @return array<string,mixed> */
    private function rulesForCheck(ArticleAiQualityCheck $check): array
    {
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        if ((string) data_get($executionMeta, 'version_selection.principles', 'v1') === 'v2') {
            $snapshot = is_array($executionMeta['principle_snapshot'] ?? null)
                ? $executionMeta['principle_snapshot']
                : [];

            return $this->principleCompiler->rules($snapshot);
        }

        return is_array($check->advertising_rules_snapshot)
            ? $check->advertising_rules_snapshot
            : $this->rules();
    }

    private function startSegment(int $checkId, int $segmentId): bool
    {
        return DB::transaction(function () use ($checkId, $segmentId): bool {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check
                || (string) $check->status !== 'running'
                || (string) $check->inspection_scope !== 'full'
                || $this->remainingDeadlineSeconds($this->primaryDeadlineAt($check)) <= 0) {
                return false;
            }
            $segment = ArticleAiQualitySegment::query()
                ->whereKey($segmentId)
                ->where('article_ai_quality_check_id', $checkId)
                ->lockForUpdate()
                ->first();
            if (! $segment || ! in_array((string) $segment->status, ['queued', 'failed', 'running'], true)) {
                return false;
            }

            $segment->forceFill([
                'status' => 'running',
                'attempt_count' => (int) $segment->attempt_count + 1,
                'started_at' => now(),
                'finished_at' => null,
                'error_code' => null,
                'error_message' => null,
            ])->save();

            return true;
        });
    }

    /** @param array<string, mixed> $raw @param array<string, mixed> $validated @param array<string, mixed> $runMeta */
    private function completeSegment(
        int $checkId,
        int $segmentId,
        array $raw,
        array $validated,
        array $runMeta,
        ?array $aiExecutionSnapshot = null,
        ?int $resolvedModelId = null,
    ): bool {
        return DB::transaction(function () use ($checkId, $segmentId, $raw, $validated, $runMeta, $aiExecutionSnapshot, $resolvedModelId): bool {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check
                || (string) $check->status !== 'running'
                || (string) $check->inspection_scope !== 'full'
                || $this->remainingDeadlineSeconds($this->primaryDeadlineAt($check)) <= 0) {
                return false;
            }
            if ($aiExecutionSnapshot !== null) {
                $admin = $this->aiExecutionAccessGuard->assertPersistedAdminSnapshot(
                    $aiExecutionSnapshot,
                    $check->task_id ? (int) $check->task_id : null,
                );
                if ($resolvedModelId !== null && $resolvedModelId > 0) {
                    $this->aiExecutionAccessGuard->assertModelForPersistedAdminSnapshot(
                        $aiExecutionSnapshot,
                        $resolvedModelId,
                        $check->task_id ? (int) $check->task_id : null,
                        $admin,
                    );
                }
            }
            $segment = ArticleAiQualitySegment::query()
                ->whereKey($segmentId)
                ->where('article_ai_quality_check_id', $checkId)
                ->lockForUpdate()
                ->first();
            if (! $segment || (string) $segment->status !== 'running') {
                return false;
            }

            $segment->forceFill([
                'status' => 'completed',
                'model_result' => $raw,
                'validated_result' => $validated,
                'finished_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();
            $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
            $segmentRuns = is_array($executionMeta['segment_runs'] ?? null) ? $executionMeta['segment_runs'] : [];
            $segmentRuns[(string) $segment->segment_index] = $runMeta;
            $check->forceFill([
                'completed_segment_count' => (int) $check->completed_segment_count + 1,
                'execution_meta' => array_replace($executionMeta, ['segment_runs' => $segmentRuns]),
            ])->save();

            return true;
        });
    }

    /** @param array<string|int,mixed> $payload @return array{array<string|int,mixed>,bool} */
    private function boundedRawPayload(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json) && strlen($json) <= 65536) {
            return [$payload, false];
        }

        $json = is_string($json) ? $json : '';

        return [[
            '_truncated' => true,
            'original_bytes' => strlen($json),
            'sha256' => hash('sha256', $json),
            'preview_base64' => base64_encode(substr($json, 0, 44000)),
        ], true];
    }

    /** @param array<string, mixed> $runMeta */
    private function recordSegmentRun(int $checkId, int $segmentIndex, array $runMeta): void
    {
        DB::transaction(function () use ($checkId, $segmentIndex, $runMeta): void {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check
                || (string) $check->status !== 'running'
                || (string) $check->inspection_scope !== 'full') {
                return;
            }

            $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
            $segmentRuns = is_array($executionMeta['segment_runs'] ?? null) ? $executionMeta['segment_runs'] : [];
            $segmentRuns[(string) $segmentIndex] = $runMeta;
            $check->forceFill([
                'execution_meta' => array_replace($executionMeta, ['segment_runs' => $segmentRuns]),
            ])->save();
        });
    }

    private function latestCheck(int $checkId): ArticleAiQualityCheck
    {
        return ArticleAiQualityCheck::query()->with('segments')->findOrFail($checkId);
    }

    private function queueNextSegment(int $checkId): ArticleAiQualityCheck
    {
        $queued = DB::transaction(function () use ($checkId): bool {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check
                || (string) $check->status !== 'running'
                || (string) $check->inspection_scope !== 'full'
                || $this->remainingDeadlineSeconds($this->primaryDeadlineAt($check)) <= 0) {
                return false;
            }

            $check->forceFill([
                'status' => 'queued',
                'decision' => null,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => null,
            ])->save();

            DB::afterCommit(fn () => $this->dispatchCheck($checkId, 2));

            return true;
        });

        if (! $queued) {
            $latest = $this->latestCheck($checkId);
            if (in_array((string) $latest->status, ['queued', 'running'], true)
                && (string) $latest->inspection_scope === 'full'
                && $this->remainingDeadlineSeconds($this->primaryDeadlineAt($latest)) <= 0) {
                $exception = new ArticleAiQualityRuntimeException('inspection_primary_deadline_exceeded', false);
                if (! $this->tryStartSampledFallback($latest, $exception)) {
                    $this->markFailed($latest, $exception);
                }
            }
        }

        return $this->latestCheck($checkId);
    }

    private function markStale(ArticleAiQualityCheck $check, string $errorCode = 'input_changed'): ArticleAiQualityCheck
    {
        ArticleAiQualityCheck::query()
            ->whereKey((int) $check->id)
            ->whereIn('status', ['queued', 'running', 'failed'])
            ->update([
                'status' => 'stale',
                'decision' => null,
                'active_dedupe_key' => null,
                'error_code' => $errorCode,
                'retrieval_failure_code' => $errorCode === 'ai_quality_retrieval_source_stale' ? $errorCode : null,
                'error_message' => '文章或质检依据已经变化。',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        ArticleAiQualitySegment::query()
            ->where('article_ai_quality_check_id', (int) $check->id)
            ->whereIn('status', ['queued', 'running', 'failed'])
            ->update([
                'status' => 'stale',
                'error_code' => $errorCode,
                'error_message' => '文章或质检依据已经变化。',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        $this->holdUnpublishedArticleForReview((int) $check->article_id);

        return $this->latestCheck((int) $check->id);
    }

    /**
     * @param  array<string,mixed>  $policy
     * @param  array<string,mixed>  $rules
     */
    public function retrievalBasisMatches(ArticleAiQualityCheck $check, array $policy, array $rules): bool
    {
        $storedHash = trim((string) $check->retrieval_basis_hash);
        if ($storedHash === '') {
            return false;
        }
        if ($check->sources()->where('readiness_status', 'legacy_unknown')->exists()) {
            return false;
        }

        $storedEpoch = max(1, (int) data_get($check->execution_meta, 'retrieval_basis.rollout.epoch', 1));
        $committedEpoch = max(1, (int) (ArticleAiQualityRollout::query()->whereKey(1)->value('epoch') ?? 1));
        if ($storedEpoch !== $committedEpoch) {
            return false;
        }

        $mode = (string) ($check->requested_retrieval_mode
            ?: ($policy['retrieval_mode'] ?? AiQualityRetrievalMode::legacyDefault()));
        $fingerprintInput = $this->policyResolver->fingerprintInput($check->article, $policy, $rules);
        $basis = AiQualityRetrievalBasis::make(
            $mode,
            (int) ($policy['policy_version'] ?? 1),
            array_values($fingerprintInput['knowledge'] ?? []),
            $this->rolloutPolicy->state(),
            $this->retrievalCoordinator->strategyVersion($mode),
            $this->retrievalExecutionOptions(),
        );

        return hash_equals($storedHash, $basis->hash());
    }

    public function rolloutEpochMatches(ArticleAiQualityCheck $check, ?int $committedEpoch = null): bool
    {
        $storedEpoch = max(1, (int) data_get($check->execution_meta, 'retrieval_basis.rollout.epoch', 1));
        $committedEpoch ??= max(1, (int) (ArticleAiQualityRollout::query()->whereKey(1)->value('epoch') ?? 1));

        return $storedEpoch === $committedEpoch;
    }

    /** @return array<string,mixed> */
    private function retrievalExecutionOptions(): array
    {
        return [
            'max_sources' => 5,
            'max_evidence' => (int) config('geoflow.ai_quality_max_evidence', 12),
            'max_characters' => (int) config('geoflow.ai_quality_max_evidence_characters', 6000),
            'max_fact_retrievals' => (int) config('geoflow.ai_quality_max_fact_retrievals', 6),
            'max_atomic_claims' => (int) config('geoflow.ai_quality_max_atomic_claims', 24),
            'sampled_max_characters' => (int) config('geoflow.ai_quality_sampled_max_characters', 6000),
            'sampled_max_ranges' => (int) config('geoflow.ai_quality_sampled_max_ranges', 12),
        ];
    }

    /** @return array<int,string> */
    private function frozenServingGenerations(ArticleAiQualityCheck $check): array
    {
        return collect((array) data_get($check->execution_meta, 'retrieval_basis.knowledge_sources', []))
            ->mapWithKeys(static function (array $source): array {
                $knowledgeBaseId = (int) ($source['id'] ?? 0);
                $generation = trim((string) ($source['chunk_serving_generation'] ?? ''));

                return $knowledgeBaseId > 0 && $generation !== '' ? [$knowledgeBaseId => $generation] : [];
            })
            ->all();
    }

    /** @param array<string,mixed> $retrievalResult */
    private function effectiveRetrievalMode(ArticleAiQualityCheck $check, array $retrievalResult): string
    {
        $requestedMode = (string) ($check->requested_retrieval_mode ?: AiQualityRetrievalMode::legacyDefault());
        $effectiveMode = (string) ($retrievalResult['effective_retrieval_mode'] ?? '');
        if (! AiQualityRetrievalMode::isValid($effectiveMode) || $effectiveMode !== $requestedMode) {
            throw new ArticleAiQualityRuntimeException('evidence_retrieval_failed', false);
        }

        return $effectiveMode;
    }

    /** @param array<string,mixed> $retrievalResult */
    private function markRetrievalSourcesUsed(int $checkId, array $retrievalResult): void
    {
        $path = array_values(array_filter(array_map(
            'strval',
            (array) data_get($retrievalResult, 'retrieval_meta.path', []),
        )));
        $providers = [
            'raw_content' => [
                'provider' => in_array('knowledge_broad', $path, true) ? 'knowledge_broad' : null,
                'source_key' => 'knowledge_broad',
            ],
            'atomic' => [
                'provider' => in_array('atomic', $path, true) ? 'atomic' : null,
                'source_key' => 'atomic',
            ],
            'chunk' => [
                'provider' => in_array('chunk', $path, true) || in_array('chunk_fallback', $path, true)
                    ? (in_array('chunk_fallback', $path, true) ? 'chunk_fallback' : 'chunk')
                    : null,
                'source_key' => 'chunk',
            ],
        ];
        $sourceMap = data_get($retrievalResult, 'retrieval_meta.source_knowledge_base_ids');
        foreach ($providers as $dependencyKind => $providerConfig) {
            $provider = $providerConfig['provider'];
            if ($provider === null) {
                continue;
            }
            $query = DB::table('article_ai_quality_check_sources')
                ->where('article_ai_quality_check_id', $checkId)
                ->where('dependency_kind', $dependencyKind);
            if (is_array($sourceMap)) {
                $sourceIds = array_values(array_unique(array_filter(array_map(
                    'intval',
                    (array) ($sourceMap[$providerConfig['source_key']] ?? []),
                ))));
                if ($sourceIds === []) {
                    continue;
                }
                $query->whereIn('knowledge_base_id', $sourceIds);
            }
            $query->update([
                'used_provider' => $provider,
                'used_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string,mixed>  $score
     * @param  array<string,mixed>  $retrievalResult
     * @param  array<string,mixed>  $atomicFacts
     * @return array<string,mixed>
     */
    private function applyRetrievalDecisionCaps(
        array $score,
        ArticleAiQualityCheck $check,
        array $retrievalResult,
        array $atomicFacts,
    ): array {
        $reasons = array_values(is_array($score['gate_reasons'] ?? null) ? $score['gate_reasons'] : []);
        $mode = (string) ($check->requested_retrieval_mode ?: AiQualityRetrievalMode::legacyDefault());
        if ($mode === AiQualityRetrievalMode::KNOWLEDGE_BROAD) {
            $hasUnreviewedEvidence = collect($retrievalResult['evidence'] ?? [])->contains(
                static fn (array $item): bool => ! in_array(
                    strtolower((string) data_get($item, 'metadata.review_status', 'unreviewed')),
                    ['reviewed', 'approved', 'verified'],
                    true,
                ),
            );
            if ($hasUnreviewedEvidence) {
                $reasons[] = 'knowledge_governance_review_required';
            }
            if ((string) $check->inspection_scope === 'fallback_sampled') {
                $reasons[] = 'knowledge_broad_sampled_review_required';
            }
        }
        if ((int) data_get($retrievalResult, 'retrieval_meta.prompt_injection_risk_count', 0) > 0) {
            $reasons[] = 'knowledge_prompt_injection_review_required';
        }
        if ($mode === AiQualityRetrievalMode::ATOMIC_FIRST) {
            if ((int) data_get($atomicFacts, 'inspection.uninspected_claim_count', 0) !== 0) {
                $reasons[] = 'ai_quality_retrieval_claim_coverage_incomplete';
            }
            if ((int) data_get($atomicFacts, 'inspection.conflict_count', 0) > 0) {
                $reasons[] = 'ai_quality_retrieval_cross_kb_conflict';
            }
        }
        $reasons = array_values(array_unique($reasons));
        if ($reasons !== [] && (string) ($score['decision'] ?? '') === 'passed') {
            $score['decision'] = 'needs_review';
        }
        $score['gate_reasons'] = $reasons;

        return $score;
    }

    private function markCancelled(ArticleAiQualityCheck $check, string $code): ArticleAiQualityCheck
    {
        ArticleAiQualityCheck::query()
            ->whereKey((int) $check->id)
            ->whereIn('status', ['queued', 'running', 'failed'])
            ->update([
                'status' => 'cancelled',
                'active_dedupe_key' => null,
                'error_code' => $code,
                'error_message' => '关联文章不可用，质检已经取消。',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        ArticleAiQualitySegment::query()
            ->where('article_ai_quality_check_id', (int) $check->id)
            ->whereIn('status', ['queued', 'running', 'failed'])
            ->update([
                'status' => 'cancelled',
                'error_code' => $code,
                'error_message' => '关联文章不可用，分段质检已经取消。',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        return $this->latestCheck((int) $check->id);
    }

    /** @param list<array<string, mixed>> $results */
    private function aggregate(array $results, string $coverage): array
    {
        $promotionContexts = array_column($results, 'promotion_context');
        $promotion = match (true) {
            in_array('uncertain', $promotionContexts, true) => 'uncertain',
            in_array('mixed', $promotionContexts, true),
            in_array('promotional', $promotionContexts, true) && in_array('informational', $promotionContexts, true) => 'mixed',
            in_array('promotional', $promotionContexts, true) => 'promotional',
            default => 'informational',
        };

        return [
            'summary' => implode(' ', array_values(array_filter(array_unique(array_map(
                static fn (array $result): string => trim((string) ($result['summary'] ?? '')),
                $results,
            ))))),
            'promotion_context' => $promotion,
            'knowledge_coverage' => $coverage,
            'issues' => array_values(array_merge(...array_map(static fn (array $result): array => $result['issues'] ?? [], $results))),
            'uncertainties' => array_values(array_merge(...array_map(static fn (array $result): array => $result['uncertainties'] ?? [], $results))),
            'truncated_issue_count' => array_sum(array_map(
                static fn (array $result): int => (int) ($result['truncated_issue_count'] ?? 0),
                $results,
            )),
        ];
    }

    /** @param array<string,mixed> $articleSnapshot @param array<string,mixed> $policy @return array<string,mixed> */
    private function atomicFactsForCheck(ArticleAiQualityCheck $check, array $articleSnapshot, array $policy): array
    {
        if ((string) $check->requested_retrieval_mode === AiQualityRetrievalMode::ATOMIC_FIRST) {
            return [
                'mode' => 'atomic_first_pending',
                'formal' => false,
                'shadow' => false,
            ];
        }

        $shadow = $this->rolloutPolicy->atomicShadowEnabled((int) $check->article_id);
        if (! $shadow) {
            return ['mode' => 'disabled', 'formal' => false, 'shadow' => false];
        }

        $content = collect(['title', 'excerpt', 'content', 'keywords', 'meta_description'])
            ->map(static fn (string $field): string => trim((string) ($articleSnapshot[$field] ?? '')))
            ->filter()
            ->implode("\n");
        $inspection = $this->atomicFactInspector->inspect($content, array_values(array_map('intval', $policy['knowledge_base_ids'] ?? [])));

        return [
            'mode' => 'shadow',
            'formal' => false,
            'shadow' => true,
            'inspection' => $inspection,
        ];
    }

    /**
     * @param  array<string,mixed>  $retrievalResult
     * @param  array<string,mixed>  $articleSnapshot
     * @param  array<string,mixed>  $policy
     * @return array<string,mixed>
     */
    private function atomicFactsFromRetrievalResult(
        ArticleAiQualityCheck $check,
        array $retrievalResult,
        array $articleSnapshot,
        array $policy,
    ): array {
        if ((string) $check->requested_retrieval_mode === AiQualityRetrievalMode::ATOMIC_FIRST) {
            $inspection = data_get($retrievalResult, 'retrieval_meta.atomic_facts');
            if (! is_array($inspection)) {
                $inspection = data_get($check->execution_meta, 'retrieval.atomic_facts');
            }

            return is_array($inspection)
                ? [
                    'mode' => 'atomic_first',
                    'formal' => true,
                    'shadow' => false,
                    'inspection' => $inspection,
                ]
                : [
                    'mode' => 'atomic_first_unavailable',
                    'formal' => false,
                    'shadow' => false,
                ];
        }

        return $this->atomicFactsForCheck($check, $articleSnapshot, $policy);
    }

    /** @param array<string, mixed> $aggregate */
    private function createShadowScore(ArticleAiQualityCheck $baseline, array $aggregate): void
    {
        if (ArticleAiQualityCheck::query()->where('baseline_check_id', $baseline->id)->where('evaluation_mode', 'shadow')->exists()) {
            return;
        }

        $score = $this->scorerV2->score(
            $aggregate,
            (int) $baseline->pass_score,
            (int) $baseline->manual_override_min_score,
        );
        $shadow = $baseline->replicate([
            'request_key',
            'active_dedupe_key',
            'article_snapshot',
            'fact_candidates_snapshot',
            'evidence_snapshot',
            'prompt_template_snapshot',
            'advertising_rules_snapshot',
            'raw_model_output',
            'usage_meta',
            'created_at',
            'updated_at',
        ]);
        $shadowMeta = is_array($baseline->execution_meta) ? $baseline->execution_meta : [];
        $shadow->forceFill([
            'request_key' => (string) Str::uuid(),
            'active_dedupe_key' => null,
            'supersedes_check_id' => null,
            'baseline_check_id' => (int) $baseline->id,
            'gate_applied' => false,
            'evaluation_mode' => 'shadow',
            'scoring_version' => 'v2',
            'algorithm_version' => preg_replace('/score=\d+/', 'score=2', (string) $baseline->algorithm_version),
            'score' => $score['score'],
            'decision' => $score['decision'],
            'dimension_scores' => $score['dimension_scores'],
            'issues' => $score['issues'],
            'uncertainties' => $score['uncertainties'],
            'confidence' => $score['confidence'],
            'gate_reasons' => $score['gate_reasons'],
            'truncated_issue_count' => (int) ($aggregate['truncated_issue_count'] ?? 0),
            'segment_count' => 0,
            'completed_segment_count' => 0,
            'execution_meta' => array_replace($shadowMeta, [
                'evaluation_mode' => 'shadow',
                'baseline_check_id' => (int) $baseline->id,
            ]),
        ])->save();
    }

    private function promptTemplate(Prompt $prompt, string $executionVersion): string
    {
        if ($executionVersion !== 'legacy'
            || (string) $prompt->system_key !== 'article_quality.cn_ads_knowledge.v1') {
            return (string) $prompt->content;
        }

        $content = file_get_contents(resource_path('prompts/article-quality-cn-v1-legacy.txt'));
        if ($content === false || trim($content) === '') {
            throw new RuntimeException('ai_quality_legacy_prompt_unavailable');
        }

        return trim($content);
    }

    /** @param array<string, mixed> $policy @param array<string, mixed> $rules @return array<string, mixed> */
    private function fingerprintInput(
        Article $article,
        array $policy,
        array $rules,
        string $promptTemplate,
        string $executionVersion,
    ): array {
        $input = $this->policyResolver->fingerprintInput($article, $policy, $rules);
        $input['prompt']['hash'] = hash('sha256', $promptTemplate);
        $input['prompt']['execution_version'] = $executionVersion;
        $input['schema_version'] = $executionVersion === 'fast_v2'
            ? 'article-quality-schema-2.1.0'
            : 'article-quality-schema-1.0.0';

        return $input;
    }

    /** @param list<array<string, mixed>> $facts @param array<string, mixed> $segment */
    private function factsForSegment(array $facts, array $segment): array
    {
        return array_values(array_filter($facts, static function (array $fact) use ($segment): bool {
            if (($fact['field'] ?? null) !== 'content') {
                return true;
            }

            return (int) ($fact['start_offset'] ?? 0) < (int) $segment['end_offset']
                && (int) ($fact['end_offset'] ?? 0) > (int) $segment['start_offset'];
        }));
    }

    /**
     * Keep each model call scoped to evidence referenced by the facts it receives.
     *
     * @param  list<array<string, mixed>>  $evidence
     * @param  list<array<string, mixed>>  $facts
     * @return list<array<string, mixed>>
     */
    private function evidenceForFacts(array $evidence, array $facts): array
    {
        $references = [];
        foreach ($facts as $fact) {
            foreach (is_array($fact['knowledge_refs'] ?? null) ? $fact['knowledge_refs'] : [] as $reference) {
                $reference = trim((string) $reference);
                if ($reference !== '') {
                    $references[$reference] = true;
                }
            }
        }

        if ($references === []) {
            return [];
        }

        return array_values(array_filter(
            $evidence,
            static fn (array $item): bool => isset($references[(string) ($item['id'] ?? '')]),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $extractedFacts
     * @param  list<array<string, mixed>>  $storedFacts
     * @return list<array<string, mixed>>
     */
    private function mergeSampledFacts(array $extractedFacts, array $storedFacts): array
    {
        $storedByHash = [];
        foreach ($storedFacts as $storedFact) {
            $claimHash = (string) ($storedFact['claim_hash'] ?? '');
            if ($claimHash !== '') {
                $storedByHash[$claimHash] = $storedFact;
            }
        }

        return array_values(array_map(static function (array $fact) use ($storedByHash): array {
            $stored = $storedByHash[(string) ($fact['claim_hash'] ?? '')] ?? null;
            if (! is_array($stored)) {
                return array_replace($fact, [
                    'knowledge_refs' => [],
                    'coverage_status' => 'insufficient',
                    'retrieval_status' => 'not_retrieved_before_fallback',
                ]);
            }

            foreach (['knowledge_refs', 'coverage_status', 'retrieval_status'] as $key) {
                if (array_key_exists($key, $stored)) {
                    $fact[$key] = $stored[$key];
                }
            }

            return $fact;
        }, $extractedFacts));
    }

    /**
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $sampledRanges
     * @return list<array<string, mixed>>
     */
    private function factsForSample(array $facts, array $sampledRanges): array
    {
        $selected = array_values(array_filter($facts, static function (array $fact) use ($sampledRanges): bool {
            $occurrences = array_values(array_filter(
                is_array($fact['occurrences'] ?? null) ? $fact['occurrences'] : [$fact],
                'is_array',
            ));

            foreach ($occurrences as $occurrence) {
                if ((string) ($occurrence['field'] ?? $fact['field'] ?? '') !== 'content') {
                    return true;
                }
                $start = (int) ($occurrence['start_offset'] ?? $fact['start_offset'] ?? 0);
                $end = (int) ($occurrence['end_offset'] ?? $fact['end_offset'] ?? $start);
                foreach ($sampledRanges as $range) {
                    if ($start < (int) ($range['end_offset'] ?? 0)
                        && $end > (int) ($range['start_offset'] ?? 0)) {
                        return true;
                    }
                }
            }

            return false;
        }));

        return array_slice($selected, 0, 100);
    }

    private function outline(string $content): array
    {
        preg_match_all('/^#{1,6}\s+(.+)$/mu', $content, $matches);

        return array_values(array_map('trim', $matches[1] ?? []));
    }

    /** @return array<string, mixed> */
    private function modelSnapshot(AiModel $model): array
    {
        return [
            'id' => (int) $model->id,
            'name' => (string) $model->name,
            'version' => (string) $model->version,
            'model_id' => (string) $model->model_id,
            'api_url_host' => (string) parse_url((string) $model->api_url, PHP_URL_HOST),
            'max_tokens' => (int) ($model->max_tokens ?? 0),
        ];
    }

    private function continueAfterCompletedCheck(ArticleAiQualityCheck $check): void
    {
        if ((string) $check->evaluation_mode === 'optimization_candidate') {
            app(ArticleAiOptimizationCoordinator::class)->candidateCompleted((int) $check->id);

            return;
        }

        $this->applyCompletedWorkflow($check);
    }

    public function applyCompletedWorkflow(ArticleAiQualityCheck|int $check): void
    {
        $checkId = $check instanceof ArticleAiQualityCheck ? (int) $check->id : $check;
        if ($this->supersedeCompletedCheckWhenBasisChanged($checkId)) {
            return;
        }
        if (app(ArticleAiOptimizationCoordinator::class)->interceptCompletedWorkflow($checkId)) {
            return;
        }
        if (! $this->beginWorkflowApplyAttempt($checkId)) {
            return;
        }

        $check = ArticleAiQualityCheck::query()->with(['article', 'task'])->find($checkId);
        if (! $check || (string) $check->status !== 'completed') {
            return;
        }

        try {
            if (! $check->article) {
                throw new RuntimeException('article_unavailable');
            }

            if ($check->decision !== 'passed') {
                $this->holdUnpublishedArticleForReview((int) $check->article_id);
                $this->updateWorkflowApply($checkId, 'succeeded');

                return;
            }

            $manualReviewRequired = (bool) data_get(
                $check->execution_meta,
                'policy_snapshot.manual_review_required',
                true,
            );
            $sampledAutoReleaseAuthorized = (string) $check->inspection_scope !== 'fallback_sampled'
                || (
                    (bool) data_get($check->execution_meta, 'policy_snapshot.timeout_sampling_enabled', false)
                    && (bool) ($check->task?->ai_quality_timeout_sampling_enabled ?? false)
                    && (string) data_get($check->execution_meta, 'policy_snapshot.sampling_algorithm_version', '') === ArticleAiQualitySampleBuilder::ALGORITHM_VERSION
                    && (int) data_get($check->execution_meta, 'policy_snapshot.sampling_max_characters', 0) === (int) config('geoflow.ai_quality_sampled_max_characters', 6000)
                    && (int) data_get($check->execution_meta, 'policy_snapshot.sampling_max_ranges', 0) === (int) config('geoflow.ai_quality_sampled_max_ranges', 12)
                    && (string) data_get($check->execution_meta, 'policy_snapshot.risk_scan_algorithm_version', '') === ArticleRiskScanner::SCAN_ALGORITHM_VERSION
                    && (string) data_get($check->coverage_meta, 'algorithm_version', '') === ArticleAiQualitySampleBuilder::ALGORITHM_VERSION
                    && $this->versionPolicy->sampledAutoReleaseEnabled()
                    && (bool) data_get($check->coverage_meta, 'safe_for_auto_release', false)
                );
            if (! ($check->task instanceof Task)
                || $manualReviewRequired
                || (bool) $check->task->need_review
                || ! $sampledAutoReleaseAuthorized
                || $check->article->review_status === 'rejected') {
                $this->updateWorkflowApply($checkId, 'succeeded');

                return;
            }

            $requestedWorkflowState = is_array($check->execution_meta['requested_workflow_state'] ?? null)
                ? $check->execution_meta['requested_workflow_state']
                : null;
            $targetState = $requestedWorkflowState !== null
                && in_array((string) ($requestedWorkflowState['status'] ?? ''), ['published', 'private'], true)
                ? $requestedWorkflowState
                : ['status' => 'draft', 'review_status' => 'approved', 'published_at' => null];
            $this->applyPassedWorkflowUnderRolloutFence($checkId, $targetState);
        } catch (Throwable $exception) {
            $this->failWorkflowApplyAttempt($checkId);
            report($exception);
        }
    }

    /** @param array{status:string,review_status:string,published_at:mixed} $targetState */
    private function applyPassedWorkflowUnderRolloutFence(int $checkId, array $targetState): bool
    {
        $checkInfo = ArticleAiQualityCheck::query()->whereKey($checkId)->first(['article_id', 'task_id']);
        if (! $checkInfo) {
            return false;
        }

        return DB::transaction(function () use ($checkId, $checkInfo, $targetState): bool {
            $rollout = ArticleAiQualityRollout::query()->whereKey(1)->lockForUpdate()->first();
            $committedEpoch = max(1, (int) ($rollout?->epoch ?? 1));
            $article = Article::query()->whereKey((int) $checkInfo->article_id)->lockForUpdate()->first();
            if (! $article) {
                return false;
            }
            $task = $checkInfo->task_id
                ? Task::withTrashed()->whereKey((int) $checkInfo->task_id)->lockForUpdate()->first()
                : null;
            if ($task instanceof Task && ! $task->trashed()) {
                $task->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
                $article->setRelation('task', $task);
            }
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check
                || (string) $check->status !== 'completed'
                || (string) $check->decision !== 'passed') {
                return false;
            }
            $check->setRelation('article', $article);
            if ($task instanceof Task && ! $task->trashed()) {
                $check->setRelation('task', $task);
            }

            $this->rolloutPolicy->forget();
            $manualReviewRequired = (bool) data_get(
                $check->execution_meta,
                'policy_snapshot.manual_review_required',
                true,
            );
            $sampledAutoReleaseAuthorized = (string) $check->inspection_scope !== 'fallback_sampled'
                || (
                    (bool) data_get($check->execution_meta, 'policy_snapshot.timeout_sampling_enabled', false)
                    && (bool) ($task?->ai_quality_timeout_sampling_enabled ?? false)
                    && $this->versionPolicy->sampledAutoReleaseEnabled()
                    && (bool) data_get($check->coverage_meta, 'safe_for_auto_release', false)
                );
            if (! $task instanceof Task
                || $task->trashed()
                || $manualReviewRequired
                || (bool) $task->need_review
                || ! $sampledAutoReleaseAuthorized
                || (string) $article->review_status === 'rejected') {
                $this->setWorkflowApplyStatus($check, 'succeeded');

                return true;
            }

            $basisIsCurrent = $this->rolloutEpochMatches($check, $committedEpoch);
            try {
                $policy = $this->policyResolver->resolve($article);
                $this->policyResolver->assertExecutable($policy);
                $currentFingerprint = $this->currentFingerprint(
                    $article,
                    $policy,
                    $this->rules(),
                    $this->versionPolicy->selection((int) $article->id),
                );
                $basisIsCurrent = $basisIsCurrent
                    && hash_equals((string) $check->input_fingerprint, $currentFingerprint)
                    && $this->retrievalBasisMatches($check, $policy, $this->rules());
            } catch (Throwable $exception) {
                $basisIsCurrent = false;
                report($exception);
            }
            if (! $basisIsCurrent) {
                $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
                $executionMeta['workflow_apply'] = [
                    'status' => 'superseded',
                    'attempts' => (int) data_get($executionMeta, 'workflow_apply.attempts', 0),
                    'error_code' => 'quality_basis_changed',
                    'updated_at' => now()->toIso8601String(),
                ];
                $check->forceFill([
                    'status' => 'stale',
                    'active_dedupe_key' => null,
                    'error_code' => 'quality_basis_changed',
                    'error_message' => '质检依据已更新，系统将使用最新依据重新质检。',
                    'execution_meta' => $executionMeta,
                    'finished_at' => $check->finished_at ?: now(),
                ])->save();

                return false;
            }

            $targetAlreadyApplied = (string) $article->status === (string) $targetState['status']
                && (string) $article->review_status === (string) $targetState['review_status'];
            if (! $targetAlreadyApplied) {
                if ((string) $article->status !== 'draft') {
                    $this->setWorkflowApplyStatus($check, 'succeeded');

                    return true;
                }
                $article = app(ArticleWorkflowTransitionService::class)->transition(
                    $article,
                    $targetState,
                    'ai_quality_passed',
                    null,
                    null,
                    false,
                );
            }
            if ((string) $article->status === 'published') {
                app(DistributionOrchestrator::class)->enqueueForArticle($article, throwOnFailure: true);
            }
            $this->setWorkflowApplyStatus($check, 'succeeded');

            return true;
        }, 3);
    }

    private function setWorkflowApplyStatus(
        ArticleAiQualityCheck $check,
        string $status,
        ?string $errorCode = null,
    ): void {
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        $workflowApply = is_array($executionMeta['workflow_apply'] ?? null)
            ? $executionMeta['workflow_apply']
            : [];
        $executionMeta['workflow_apply'] = [
            'status' => $status,
            'attempts' => (int) ($workflowApply['attempts'] ?? 0),
            'error_code' => $errorCode,
            'updated_at' => now()->toIso8601String(),
        ];
        $check->forceFill(['execution_meta' => $executionMeta])->save();
    }

    private function supersedeCompletedCheckWhenBasisChanged(int $checkId): bool
    {
        $check = ArticleAiQualityCheck::query()->with(['article.task'])->find($checkId);
        if (! $check
            || (string) $check->status !== 'completed'
            || ! (bool) $check->gate_applied
            || (string) $check->evaluation_mode === 'optimization_candidate'
            || ! $check->article) {
            return false;
        }

        $article = $check->article;
        $policy = $this->policyResolver->resolve($article);
        $basisChanged = ! (bool) ($policy['required'] ?? false);
        try {
            if (! $basisChanged) {
                $this->policyResolver->assertExecutable($policy);
                $currentFingerprint = $this->currentFingerprint(
                    $article,
                    $policy,
                    $this->rules(),
                    $this->versionPolicy->selection((int) $article->id),
                );
                $basisChanged = ! hash_equals((string) $check->input_fingerprint, $currentFingerprint)
                    || ! $this->retrievalBasisMatches($check, $policy, $this->rules());
            }
        } catch (Throwable $exception) {
            $basisChanged = true;
            report($exception);
        }
        if (! $basisChanged) {
            return false;
        }

        $superseded = DB::transaction(function () use ($checkId): bool {
            $locked = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $locked || (string) $locked->status !== 'completed') {
                return false;
            }
            $executionMeta = is_array($locked->execution_meta) ? $locked->execution_meta : [];
            $executionMeta['workflow_apply'] = [
                'status' => 'superseded',
                'attempts' => (int) data_get($executionMeta, 'workflow_apply.attempts', 0),
                'error_code' => 'quality_basis_changed',
                'updated_at' => now()->toIso8601String(),
            ];
            $locked->forceFill([
                'status' => 'stale',
                'active_dedupe_key' => null,
                'error_code' => 'quality_basis_changed',
                'error_message' => '质检依据已更新，系统将使用最新依据重新质检。',
                'execution_meta' => $executionMeta,
                'finished_at' => $locked->finished_at ?: now(),
            ])->save();

            return true;
        });
        if (! $superseded) {
            return true;
        }

        $this->holdUnpublishedArticleForReview((int) $article->id);
        if ((bool) ($policy['required'] ?? false)) {
            try {
                $this->createOrReuse(
                    $article->fresh(),
                    trigger: 'quality_basis_changed',
                    dispatch: true,
                    force: true,
                    resolvedPolicy: $policy,
                );
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return true;
    }

    public function retryCompletedWorkflow(ArticleAiQualityCheck|int $check): bool
    {
        $checkId = $check instanceof ArticleAiQualityCheck ? (int) $check->id : $check;
        $reset = DB::transaction(function () use ($checkId): bool {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check || (string) $check->status !== 'completed') {
                return false;
            }
            $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
            $workflowApply = is_array($executionMeta['workflow_apply'] ?? null)
                ? $executionMeta['workflow_apply']
                : [];
            if (! in_array((string) ($workflowApply['status'] ?? ''), ['failed', 'exhausted'], true)) {
                return false;
            }
            $executionMeta['workflow_apply'] = [
                'status' => 'pending',
                'attempts' => 0,
                'error_code' => null,
                'operator_retry_at' => now()->toIso8601String(),
                'updated_at' => now()->toIso8601String(),
            ];
            $check->forceFill(['execution_meta' => $executionMeta])->save();

            return true;
        });
        if (! $reset) {
            return false;
        }

        $this->applyCompletedWorkflow($checkId);

        return true;
    }

    private function beginWorkflowApplyAttempt(int $checkId): bool
    {
        return DB::transaction(function () use ($checkId): bool {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check
                || (string) $check->status !== 'completed'
                || ! (bool) $check->gate_applied
                || (string) $check->evaluation_mode === 'optimization_candidate') {
                return false;
            }

            $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
            $workflowApply = is_array($executionMeta['workflow_apply'] ?? null)
                ? $executionMeta['workflow_apply']
                : [];
            $status = (string) ($workflowApply['status'] ?? 'pending');
            if (in_array($status, ['succeeded', 'exhausted', 'waiting_optimization'], true)) {
                return false;
            }
            if ($status === 'processing' && ! $this->workflowApplyIsStale($workflowApply)) {
                return false;
            }

            $attempts = (int) ($workflowApply['attempts'] ?? 0);
            if ($attempts >= self::WORKFLOW_APPLY_MAX_ATTEMPTS) {
                $executionMeta['workflow_apply'] = [
                    'status' => 'exhausted',
                    'attempts' => $attempts,
                    'error_code' => 'workflow_apply_exhausted',
                    'updated_at' => now()->toIso8601String(),
                ];
                $check->forceFill(['execution_meta' => $executionMeta])->save();

                return false;
            }

            $executionMeta['workflow_apply'] = [
                'status' => 'processing',
                'attempts' => $attempts + 1,
                'error_code' => null,
                'updated_at' => now()->toIso8601String(),
            ];
            $check->forceFill(['execution_meta' => $executionMeta])->save();

            return true;
        });
    }

    private function failWorkflowApplyAttempt(int $checkId): void
    {
        DB::transaction(function () use ($checkId): void {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check || (string) $check->status !== 'completed') {
                return;
            }

            $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
            $workflowApply = is_array($executionMeta['workflow_apply'] ?? null)
                ? $executionMeta['workflow_apply']
                : [];
            $attempts = (int) ($workflowApply['attempts'] ?? 0);
            $exhausted = $attempts >= self::WORKFLOW_APPLY_MAX_ATTEMPTS;
            $executionMeta['workflow_apply'] = [
                'status' => $exhausted ? 'exhausted' : 'failed',
                'attempts' => $attempts,
                'error_code' => $exhausted ? 'workflow_apply_exhausted' : 'workflow_apply_failed',
                'updated_at' => now()->toIso8601String(),
            ];
            $check->forceFill(['execution_meta' => $executionMeta])->save();
        });
    }

    private function updateWorkflowApply(int $checkId, string $status, ?string $errorCode = null): void
    {
        DB::transaction(function () use ($checkId, $status, $errorCode): void {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check || (string) $check->status !== 'completed') {
                return;
            }

            $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
            $workflowApply = is_array($executionMeta['workflow_apply'] ?? null)
                ? $executionMeta['workflow_apply']
                : [];
            $executionMeta['workflow_apply'] = [
                'status' => $status,
                'attempts' => (int) ($workflowApply['attempts'] ?? 0),
                'error_code' => $errorCode,
                'updated_at' => now()->toIso8601String(),
            ];
            $check->forceFill(['execution_meta' => $executionMeta])->save();
        });
    }

    /** @param array<string, mixed>|null $state @return array{status:string,review_status:string,published_at:mixed}|null */
    private function sanitizeRequestedWorkflowState(?array $state): ?array
    {
        if ($state === null
            || ! in_array((string) ($state['status'] ?? ''), ['published', 'private'], true)
            || ! in_array((string) ($state['review_status'] ?? ''), ['approved', 'auto_approved'], true)) {
            return null;
        }

        $publishedAt = $state['published_at'] ?? null;
        if ($publishedAt instanceof \DateTimeInterface) {
            $publishedAt = $publishedAt->format('Y-m-d H:i:s');
        }

        return [
            'status' => (string) $state['status'],
            'review_status' => (string) $state['review_status'],
            'published_at' => $publishedAt,
        ];
    }

    private function holdUnpublishedArticleForReview(int $articleId): void
    {
        Article::query()
            ->whereKey($articleId)
            ->where('status', 'draft')
            ->where('review_status', '!=', 'rejected')
            ->update([
                'status' => 'draft',
                'review_status' => 'pending',
                'published_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function dispatchCheck(int $checkId, int $delaySeconds = 0): void
    {
        try {
            $check = ArticleAiQualityCheck::query()->find($checkId, ['execution_meta', 'inspection_scope']);
            $trigger = is_array($check?->execution_meta)
                ? (string) ($check->execution_meta['trigger'] ?? '')
                : '';
            $queue = in_array($trigger, ['reconcile', 'backfill', 'optimization_task_candidate'], true)
                ? (string) config('geoflow.ai_quality_backfill_queue', 'ai-quality-backfill')
                : (string) config('geoflow.ai_quality_queue', 'ai-quality');
            $expectedScope = (string) ($check?->inspection_scope ?: 'full');
            if ($delaySeconds > 0) {
                ProcessArticleAiQualityJob::dispatch($checkId, $expectedScope)
                    ->delay(now()->addSeconds($delaySeconds))
                    ->onConnection('redis')
                    ->onQueue($queue);
            } else {
                ProcessArticleAiQualityJob::dispatch($checkId, $expectedScope)->onConnection('redis')->onQueue($queue);
            }
        } catch (Throwable $exception) {
            $this->markFailed(
                $checkId,
                new RuntimeException('ai_quality_queue_dispatch_failed', 0, $exception),
                $expectedScope ?? null,
            );

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array{segment_index:int,model_id:int,model_name:string,provider:string,duration_ms:int,outcome:string,error_code:?string}
     */
    private function modelAttempt(
        array $segment,
        AiModel $model,
        string $outcome,
        ?string $errorCode,
        int $durationMs,
    ): array {
        return [
            'segment_index' => (int) ($segment['index'] ?? 0),
            'model_id' => (int) $model->id,
            'model_name' => (string) $model->name,
            'provider' => (string) (parse_url((string) $model->api_url, PHP_URL_HOST) ?: 'unknown'),
            'duration_ms' => max(0, $durationMs),
            'outcome' => $outcome,
            'error_code' => $errorCode,
        ];
    }

    /**
     * @param  array<string, mixed>  $runMeta
     * @param  array{prompt_tokens:int,completion_tokens:int,total_tokens:int}  $usage
     * @param  array<string, mixed>  $modelMeta
     * @param  list<string>  $modes
     * @param  list<array<string, mixed>>  $modelAttempts
     */
    private function accumulateRunTelemetry(
        array $runMeta,
        array &$usage,
        array &$modelMeta,
        array &$modes,
        array &$modelAttempts,
    ): void {
        $runUsage = is_array($runMeta['usage'] ?? null) ? $runMeta['usage'] : [];
        foreach (array_keys($usage) as $key) {
            $usage[$key] += (int) ($runUsage[$key] ?? $runUsage[Str::camel($key)] ?? 0);
        }
        if (is_array($runMeta['model'] ?? null) && $runMeta['model'] !== []) {
            $modelMeta = $runMeta['model'];
        }
        $mode = trim((string) ($runMeta['mode'] ?? ''));
        if ($mode !== '') {
            $modes[] = $mode;
        }
        if (is_array($runMeta['attempts'] ?? null)) {
            foreach ($runMeta['attempts'] as $attempt) {
                if (is_array($attempt)) {
                    $modelAttempts[] = $attempt;
                }
            }
        }
    }

    /**
     * @param  array<string,mixed>  $usage
     * @param  array<string,mixed>  $atomicFacts
     * @return array<string,mixed>
     */
    private function withRetrievalUsageBreakdown(array $usage, array $atomicFacts): array
    {
        $primaryReview = $this->mergeUsage([], $usage);
        $atomicUsage = (array) data_get($atomicFacts, 'inspection.usage', []);
        $atomicPrompt = (int) ($atomicUsage['prompt_tokens'] ?? $atomicUsage['input_tokens'] ?? 0);
        $atomicCompletion = (int) ($atomicUsage['completion_tokens'] ?? $atomicUsage['output_tokens'] ?? 0);
        $atomicTotal = (int) ($atomicUsage['total_tokens'] ?? ($atomicPrompt + $atomicCompletion));

        return [
            ...$primaryReview,
            'primary_review' => $primaryReview,
            'atomic_verification' => [
                'prompt_tokens' => $atomicPrompt,
                'completion_tokens' => $atomicCompletion,
                'total_tokens' => $atomicTotal,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $addition
     * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int}
     */
    private function mergeUsage(array $base, array $addition): array
    {
        $basePrompt = (int) ($base['prompt_tokens'] ?? $base['promptTokens'] ?? 0);
        $baseCompletion = (int) ($base['completion_tokens'] ?? $base['completionTokens'] ?? 0);
        $additionPrompt = (int) ($addition['prompt_tokens'] ?? $addition['promptTokens'] ?? 0);
        $additionCompletion = (int) ($addition['completion_tokens'] ?? $addition['completionTokens'] ?? 0);
        $baseTotal = (int) ($base['total_tokens'] ?? $base['totalTokens'] ?? ($basePrompt + $baseCompletion));
        $additionTotal = (int) ($addition['total_tokens'] ?? $addition['totalTokens'] ?? ($additionPrompt + $additionCompletion));

        $merged = [
            'prompt_tokens' => $basePrompt + $additionPrompt,
            'completion_tokens' => $baseCompletion + $additionCompletion,
            'total_tokens' => $baseTotal + $additionTotal,
        ];

        return $merged;
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    /** @param array<string, mixed> $workflowApply */
    private function workflowApplyIsStale(array $workflowApply): bool
    {
        $updatedAt = trim((string) ($workflowApply['updated_at'] ?? ''));
        if ($updatedAt === '') {
            return true;
        }

        try {
            return Date::parse($updatedAt)->lte(
                now()->subSeconds((int) config('geoflow.ai_quality_recovery_stale_seconds', 60)),
            );
        } catch (Throwable) {
            return true;
        }
    }

    private function remainingDeadlineSeconds(CarbonInterface $deadlineAt): float
    {
        return (float) $deadlineAt->format('U.u') - (float) now()->format('U.u');
    }

    public function deadlineAt(ArticleAiQualityCheck $check): CarbonInterface
    {
        return $check->deadline_at
            ?: ($check->created_at ?: now())->copy()->addSeconds((int) config('geoflow.ai_quality_deadline_seconds', 180));
    }

    public function primaryDeadlineAt(ArticleAiQualityCheck $check): CarbonInterface
    {
        return $check->primary_deadline_at
            ?: ($check->created_at ?: now())->copy()->addSeconds((int) config('geoflow.ai_quality_deadline_seconds', 180));
    }

    public function sampledDeadlineAt(ArticleAiQualityCheck $check): CarbonInterface
    {
        return $check->sampled_deadline_at ?: $this->deadlineAt($check);
    }

    private function endToEndElapsedMilliseconds(ArticleAiQualityCheck $check): int
    {
        if (! $check->created_at) {
            return 0;
        }

        return max(0, (int) round($check->created_at->diffInMilliseconds(now())));
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<int|string, mixed>  $segmentRuns
     * @return array<string, int>
     */
    private function aggregateTimings(array $base, array $segmentRuns): array
    {
        $timings = array_map(static fn (mixed $value): int => max(0, (int) $value), $base);
        foreach (['prompt_render', 'model_total', 'validation'] as $key) {
            $timings[$key] = 0;
        }
        foreach ($segmentRuns as $run) {
            $runTimings = is_array($run['timings_ms'] ?? null) ? $run['timings_ms'] : [];
            foreach (['prompt_render', 'model_total', 'validation'] as $key) {
                $timings[$key] += max(0, (int) ($runTimings[$key] ?? 0));
            }
        }

        return $timings;
    }

    /** @return array{array<string, mixed>,array{prompt_tokens:int,completion_tokens:int,total_tokens:int}} */
    private function terminalTelemetry(ArticleAiQualityCheck $check): array
    {
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        $segmentRuns = is_array($executionMeta['segment_runs'] ?? null) ? $executionMeta['segment_runs'] : [];
        ksort($segmentRuns, SORT_NUMERIC);
        $attempts = [];
        $usage = $this->mergeUsage([], []);
        foreach ($segmentRuns as $runMeta) {
            if (! is_array($runMeta)) {
                continue;
            }
            $usage = $this->mergeUsage($usage, is_array($runMeta['usage'] ?? null) ? $runMeta['usage'] : []);
            foreach (is_array($runMeta['attempts'] ?? null) ? $runMeta['attempts'] : [] as $attempt) {
                if (is_array($attempt)) {
                    $attempts[] = $attempt;
                }
            }
        }
        $executionMeta['model_attempts'] = $attempts;

        return [$executionMeta, $usage];
    }

    /**
     * @param  array<string,mixed>  $executionMeta
     * @return array{0:array<string,mixed>|null,1:bool}
     */
    private function qualityExecutionSnapshotForCheck(array $executionMeta): array
    {
        $rawSnapshot = $executionMeta['ai_execution'] ?? null;
        if ($rawSnapshot === null) {
            return [null, false];
        }
        if (! is_array($rawSnapshot)) {
            return [null, true];
        }

        $candidateIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($rawSnapshot['model_candidate_ids'] ?? null)
                ? $rawSnapshot['model_candidate_ids']
                : [],
        ), static fn (int $modelId): bool => $modelId > 0)));
        $storedCandidateIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($executionMeta['model_candidate_ids'] ?? null)
                ? $executionMeta['model_candidate_ids']
                : [],
        ), static fn (int $modelId): bool => $modelId > 0)));
        $snapshot = [
            'model_access_admin_id' => (int) ($rawSnapshot['model_access_admin_id'] ?? 0),
            'model_access_admin_role' => trim((string) ($rawSnapshot['model_access_admin_role'] ?? '')),
            'ai_config_access_version' => (int) ($rawSnapshot['ai_config_access_version'] ?? 0),
            'resolver_policy_version' => (int) ($rawSnapshot['resolver_policy_version'] ?? 0),
            'requested_model_id' => (int) ($rawSnapshot['requested_model_id'] ?? 0),
            'source_type' => trim((string) ($rawSnapshot['source_type'] ?? '')),
            'source_id' => (int) ($rawSnapshot['source_id'] ?? 0),
            'model_candidate_ids' => $candidateIds,
        ];
        $invalid = $snapshot['model_access_admin_id'] <= 0
            || $snapshot['model_access_admin_role'] === ''
            || $snapshot['ai_config_access_version'] <= 0
            || $snapshot['resolver_policy_version'] !== AiExecutionContext::CURRENT_RESOLVER_POLICY_VERSION
            || $snapshot['requested_model_id'] <= 0
            || $snapshot['source_type'] === ''
            || $snapshot['source_id'] <= 0
            || $candidateIds === []
            || $candidateIds !== $storedCandidateIds;

        return [$invalid ? null : $snapshot, $invalid];
    }

    /** @param array<string,mixed> $policy @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function withFrozenExecutionCandidates(array $policy, array $snapshot): array
    {
        $candidateIds = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($snapshot['model_candidate_ids'] ?? null) ? $snapshot['model_candidate_ids'] : [],
        ), static fn (int $modelId): bool => $modelId > 0)));
        if ($candidateIds === []) {
            return $policy;
        }

        $policy['model_candidates'] = AiModel::query()
            ->whereIn('id', $candidateIds)
            ->get()
            ->sortBy(static fn (AiModel $model): int => array_search((int) $model->id, $candidateIds, true))
            ->values()
            ->all();

        return $policy;
    }

    /**
     * @param  array<string,mixed>|null  $override
     * @return array<string,int|string|list<int>|null>|null
     */
    private function qualityExecutionSnapshot(
        Article $article,
        ?TaskRun $taskRun,
        ?array $override,
        ?int $requestedModelId,
    ): ?array {
        if (is_array($override)) {
            $snapshot = [
                'model_access_admin_id' => (int) ($override['model_access_admin_id'] ?? 0),
                'model_access_admin_role' => trim((string) ($override['model_access_admin_role'] ?? '')),
                'ai_config_access_version' => (int) ($override['ai_config_access_version'] ?? 0),
                'resolver_policy_version' => (int) ($override['resolver_policy_version'] ?? 0),
                'requested_model_id' => $requestedModelId,
                'source_type' => trim((string) ($override['source_type'] ?? 'article_ai_quality_check')),
                'source_id' => (int) ($override['source_id'] ?? $article->task_id ?? $article->id),
                'model_candidate_ids' => array_values(array_unique(array_filter(array_map(
                    'intval',
                    is_array($override['model_candidate_ids'] ?? null) ? $override['model_candidate_ids'] : [],
                ), static fn (int $modelId): bool => $modelId > 0))),
            ];

            return $snapshot['model_access_admin_id'] > 0
                && $snapshot['model_access_admin_role'] !== ''
                && $snapshot['ai_config_access_version'] > 0
                && $snapshot['resolver_policy_version'] > 0
                    ? $snapshot
                    : null;
        }

        if ($taskRun instanceof TaskRun) {
            $snapshot = [
                'model_access_admin_id' => (int) ($taskRun->model_access_admin_id ?? 0),
                'model_access_admin_role' => trim((string) ($taskRun->model_access_admin_role ?? '')),
                'ai_config_access_version' => (int) ($taskRun->ai_config_access_version ?? 0),
                'resolver_policy_version' => (int) ($taskRun->resolver_policy_version ?? 0),
                'requested_model_id' => $requestedModelId,
                'source_type' => 'task_run',
                'source_id' => (int) $taskRun->id,
            ];

            return $snapshot['model_access_admin_id'] > 0
                && $snapshot['model_access_admin_role'] !== ''
                && $snapshot['ai_config_access_version'] > 0
                && $snapshot['resolver_policy_version'] > 0
                    ? $snapshot
                    : null;
        }

        $task = $article->relationLoaded('task') && $article->task instanceof Task
            ? $article->task
            : ($article->task_id ? Task::withTrashed()->find((int) $article->task_id) : null);
        if (! $task instanceof Task || $task->trashed()) {
            return null;
        }
        $adminId = (int) ($task->model_access_admin_id ?? 0);
        $admin = $adminId > 0 ? Admin::query()->find($adminId) : null;
        if (! $admin instanceof Admin) {
            return null;
        }

        $snapshot = [
            'model_access_admin_id' => $adminId,
            'model_access_admin_role' => trim((string) ($task->model_access_admin_role ?? $this->aiExecutionContextFactory->normalizedRole($admin))),
            'ai_config_access_version' => max(1, (int) $admin->ai_config_access_version),
            'resolver_policy_version' => (int) ($task->model_access_policy_version ?? 0),
            'requested_model_id' => $requestedModelId,
            'source_type' => 'task',
            'source_id' => (int) $task->id,
        ];
        if ($snapshot['model_access_admin_role'] === '' || $snapshot['resolver_policy_version'] <= 0) {
            if ($this->aiExecutionContextFactory->identityRequired()) {
                throw AiModelAccessException::configAccessRevoked($admin);
            }

            return null;
        }

        return $snapshot;
    }

    private function qualityProviderUsageSession(
        ArticleAiQualityCheck $check,
        AiModel $model,
        ?array $aiExecutionSnapshot,
        string $scope,
        string $sourceType,
        int $sourceId,
        int $segmentIndex,
        int $executionAttempt,
        int $candidateOrdinal,
        int $candidateOccurrence,
        string $requestPayload,
    ): ?ArticleAiQualityProviderUsageSession {
        if (! $this->reviewer instanceof ProviderAttemptAwareArticleAiQualityReviewer
            || $aiExecutionSnapshot === null
            || ! Str::isUuid((string) $check->request_key)) {
            return null;
        }

        $providerOrdinal = 0;
        $executionAdminId = (int) ($aiExecutionSnapshot['model_access_admin_id'] ?? 0);
        $accessVersion = (int) ($aiExecutionSnapshot['ai_config_access_version'] ?? 0);
        $modelSource = $this->usageAttempts->sourceFor($model, $executionAdminId);

        return new ArticleAiQualityProviderUsageSession(function (string $mode) use (
            &$providerOrdinal,
            $accessVersion,
            $candidateOccurrence,
            $candidateOrdinal,
            $check,
            $executionAdminId,
            $executionAttempt,
            $model,
            $modelSource,
            $requestPayload,
            $scope,
            $segmentIndex,
            $sourceId,
            $sourceType,
        ) {
            $providerOrdinal++;
            $callKey = sprintf(
                '%s.segment-%d.attempt-%d.candidate-%d.occurrence-%d.retry-%d.provider-%d-%s',
                $scope,
                $segmentIndex,
                $executionAttempt,
                $candidateOrdinal,
                $candidateOccurrence,
                max(0, $candidateOccurrence - 1),
                $providerOrdinal,
                $mode,
            );

            return $this->usageAttempts->beginForAdmin(
                model: $model,
                executionAdminId: $executionAdminId,
                accessVersion: $accessVersion,
                executionScope: AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
                modelSource: $modelSource,
                requestId: (string) $check->request_key,
                requestPayload: $requestPayload,
                callKey: $callKey,
                operation: 'article_ai_quality.inspect',
                businessSource: 'article_ai_quality',
                sourceType: $sourceType,
                sourceId: $sourceId,
            );
        });
    }

    /** @param array<string,mixed>|null $aiExecutionSnapshot */
    private function providerExecutionIdentityMissing(?array $aiExecutionSnapshot): bool
    {
        return $aiExecutionSnapshot === null
            && $this->reviewer instanceof ProviderAttemptAwareArticleAiQualityReviewer;
    }

    private function safeErrorCode(Throwable $exception): string
    {
        if ($exception instanceof AiModelAccessException) {
            return $exception->getErrorCode();
        }
        if ($exception instanceof ArticleAiQualityRuntimeException) {
            return $exception->safeCode();
        }

        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'queue_dispatch') => 'queue_dispatch_failed',
            str_contains($message, 'timeout') => 'model_timeout',
            str_contains($message, 'quota') || str_contains($message, 'limit') => 'model_quota_exceeded',
            str_contains($message, 'structure'),
            str_contains($message, 'json'),
            str_starts_with($message, 'ai_quality_') && (
                str_contains($message, '_invalid')
                || str_contains($message, '_missing_field')
                || str_contains($message, '_unknown_field')
            ) => 'invalid_model_output',
            str_contains($message, 'configuration'), str_contains($message, 'unavailable') => 'model_unavailable',
            default => 'inspection_failed',
        };
    }

    private function sampledFallbackEligible(string $errorCode): bool
    {
        return in_array($errorCode, [
            'inspection_primary_deadline_exceeded',
            'inspection_deadline_exceeded',
            'provider_timeout',
            'model_timeout',
            'input_too_large',
            'model_output_truncated',
            'output_budget_exhausted',
            'remaining_budget_insufficient',
        ], true);
    }

    private function retryableFailure(Throwable $exception, string $errorCode): bool
    {
        if ($exception instanceof ArticleAiQualityRuntimeException) {
            return $exception->retryable();
        }

        return in_array($errorCode, [
            'queue_dispatch_failed',
            'model_timeout',
            'provider_timeout',
            'provider_rate_limited',
            'provider_gateway_error',
            'provider_circuit_open',
            'evidence_retrieval_failed',
            'worker_interrupted',
        ], true);
    }

    /** @return array{code:string,retryable:bool,http_status:?int,provider_code:?string} */
    private function safeFailureContext(Throwable $exception, string $errorCode, bool $retryable): array
    {
        $httpStatus = $exception instanceof ArticleAiQualityRuntimeException
            ? $exception->httpStatus()
            : null;
        $providerCode = $exception instanceof ArticleAiQualityRuntimeException
            ? $exception->providerCode()
            : null;
        $providerCode = is_string($providerCode)
            && preg_match('/\A[A-Za-z0-9._:-]{1,80}\z/D', $providerCode) === 1
                ? $providerCode
                : null;

        return [
            'code' => $errorCode,
            'retryable' => $retryable,
            'http_status' => is_int($httpStatus) && $httpStatus >= 100 && $httpStatus <= 599
                ? $httpStatus
                : null,
            'provider_code' => $providerCode,
        ];
    }
}
