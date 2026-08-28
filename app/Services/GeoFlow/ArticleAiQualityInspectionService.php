<?php

namespace App\Services\GeoFlow;

use App\Contracts\ArticleAiQualityReviewer;
use App\Jobs\ProcessArticleAiQualityJob;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleAiQualitySegment;
use App\Models\Task;
use App\Models\TaskRun;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ArticleAiQualityInspectionService
{
    public function __construct(
        private readonly ArticleAiQualityPolicyResolver $policyResolver,
        private readonly ArticleAiQualityFingerprint $fingerprint,
        private readonly ArticleFactCandidateExtractor $factExtractor,
        private readonly ArticleAiQualityEvidenceBuilder $evidenceBuilder,
        private readonly ArticleAiQualitySegmenter $segmenter,
        private readonly ArticleAiQualityPromptRenderer $promptRenderer,
        private readonly ArticleAiQualityResultValidator $resultValidator,
        private readonly ArticleAiQualityScorer $scorer,
        private readonly ArticleAiQualityReviewer $reviewer,
    ) {}

    public function requestManualInspection(
        Article $article,
        string $trigger = 'admin_manual',
        bool $dispatch = true,
        ?int $auditAdminId = null,
        ?int $apiTokenId = null,
        ?array $requestedWorkflowState = null,
    ): ArticleAiQualityCheck {
        return DB::transaction(function () use ($article, $trigger, $dispatch, $auditAdminId, $apiTokenId, $requestedWorkflowState): ArticleAiQualityCheck {
            $article = Article::query()
                ->whereKey((int) $article->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($article->task_id) {
                $task = Task::withTrashed()->whereKey((int) $article->task_id)->lockForUpdate()->first();
                if ($task instanceof Task) {
                    $task->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
                    $article->setRelation('task', $task);
                }
            }
            $policy = $this->policyResolver->resolveForManualInspection($article);
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

            return $check;
        });
    }

    public function createOrReuse(
        Article $article,
        ?TaskRun $taskRun = null,
        string $trigger = 'generation',
        bool $dispatch = true,
        bool $force = false,
        ?array $resolvedPolicy = null,
    ): ?ArticleAiQualityCheck {
        return DB::transaction(function () use ($article, $taskRun, $trigger, $dispatch, $force, $resolvedPolicy): ?ArticleAiQualityCheck {
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

            $this->policyResolver->assertExecutable($policy);
            $modelCandidates = $this->policyResolver->modelCandidates($policy);
            $policy['model_candidates'] = $modelCandidates;
            $rules = $this->rules();
            $fingerprintInput = $this->policyResolver->fingerprintInput($article, $policy, $rules);
            $inputFingerprint = $this->fingerprint->make($fingerprintInput);
            $activeKey = hash('sha256', (int) $article->id."\0".$inputFingerprint);

            $existingActive = ArticleAiQualityCheck::query()->where('active_dedupe_key', $activeKey)->first();
            if ($existingActive) {
                return $existingActive;
            }

            if (! $force) {
                $existingResult = ArticleAiQualityCheck::query()
                    ->where('article_id', $article->id)
                    ->where('input_fingerprint', $inputFingerprint)
                    ->where('status', 'completed')
                    ->latest('id')
                    ->first();
                if ($existingResult) {
                    return $existingResult;
                }
            }

            $articleSnapshot = $this->policyResolver->articleSnapshot($article);
            $segments = $this->segmenter->segment((string) ($articleSnapshot['content'] ?? ''));
            $prompt = $policy['prompt'];
            $model = $policy['model'];
            $previous = ArticleAiQualityCheck::query()->where('article_id', $article->id)->latest('id')->first();
            if ($previous
                && ! hash_equals((string) $previous->input_fingerprint, $inputFingerprint)
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
                    $model,
                    $modelCandidates,
                    $previous,
                    $inputFingerprint,
                    $activeKey,
                    $fingerprintInput,
                ): ArticleAiQualityCheck {
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
                        'pass_score' => (int) $policy['pass_score'],
                        'manual_override_min_score' => (int) $policy['manual_override_min_score'],
                        'segment_count' => count($segments),
                        'article_snapshot' => $articleSnapshot,
                        'prompt_template_snapshot' => mb_substr((string) $prompt->content, 0, 50000, 'UTF-8'),
                        'advertising_rules_snapshot' => $rules,
                        'model_snapshot' => array_replace($this->modelSnapshot($model), [
                            'selection_mode' => (string) ($policy['model_selection_mode'] ?? 'fixed'),
                            'candidate_ids' => array_values(array_map(static fn (AiModel $candidate): int => (int) $candidate->id, $modelCandidates)),
                        ]),
                        'article_content_hash' => hash('sha256', json_encode($articleSnapshot, JSON_UNESCAPED_UNICODE)),
                        'prompt_hash' => hash('sha256', (string) $prompt->content),
                        'knowledge_hash' => hash('sha256', json_encode($fingerprintInput['knowledge'] ?? [], JSON_UNESCAPED_UNICODE)),
                        'input_fingerprint' => $inputFingerprint,
                        'algorithm_version' => ArticleAiQualityFingerprint::ALGORITHM_VERSION,
                        'execution_meta' => [
                            'trigger' => $trigger,
                            'policy_source' => $policy['source'] ?? 'unknown',
                            'knowledge_base_ids' => array_values(array_map('intval', $policy['knowledge_base_ids'] ?? [])),
                            'model_selection_mode' => (string) ($policy['model_selection_mode'] ?? 'fixed'),
                            'model_candidate_ids' => array_values(array_map(static fn (AiModel $candidate): int => (int) $candidate->id, $modelCandidates)),
                            'model_attempts' => [],
                            'segment_runs' => [],
                        ],
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

    public function process(ArticleAiQualityCheck|int $check, bool $allowRunningRecovery = false): ArticleAiQualityCheck
    {
        $checkId = $check instanceof ArticleAiQualityCheck ? (int) $check->id : $check;
        $check = ArticleAiQualityCheck::query()->with(['article', 'segments'])->findOrFail($checkId);
        if (in_array((string) $check->status, ['completed', 'stale', 'cancelled'], true)) {
            return $check;
        }
        if (! $check->article) {
            return $this->markCancelled($check, 'article_unavailable');
        }

        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        $policy = ($executionMeta['policy_source'] ?? null) === 'manual_article'
            ? $this->policyResolver->resolveForManualInspection($check->article)
            : $this->policyResolver->resolve($check->article);
        $this->policyResolver->assertExecutable($policy);
        $policy['model_candidates'] = $this->policyResolver->modelCandidates($policy);
        $rules = $this->rules();
        $currentFingerprint = $this->fingerprint->make($this->policyResolver->fingerprintInput($check->article, $policy, $rules));
        if (! hash_equals((string) $check->input_fingerprint, $currentFingerprint)) {
            return $this->markStale($check);
        }

        $claimed = ArticleAiQualityCheck::query()
            ->whereKey($checkId)
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
                'updated_at' => now(),
            ]);
        if ($claimed !== 1) {
            return $this->latestCheck($checkId);
        }
        $check = ArticleAiQualityCheck::query()->with(['article', 'segments'])->findOrFail($checkId);

        $articleSnapshot = is_array($check->article_snapshot) ? $check->article_snapshot : [];
        $facts = $this->factExtractor->extract($articleSnapshot);
        $evidenceResult = $this->evidenceBuilder->build(
            $policy['knowledge_base_ids'] ?? [],
            $articleSnapshot,
            $facts,
            (int) config('geoflow.ai_quality_max_evidence', 24),
            (int) config('geoflow.ai_quality_max_evidence_characters', 12000),
            (int) config('geoflow.ai_quality_max_fact_retrievals', 60),
        );
        $facts = $evidenceResult['fact_candidates'];
        $evidence = $evidenceResult['evidence'];

        $evidenceStored = ArticleAiQualityCheck::query()
            ->whereKey($checkId)
            ->where('status', 'running')
            ->update([
                'fact_candidates_snapshot' => json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'evidence_snapshot' => json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'knowledge_coverage' => $evidenceResult['knowledge_coverage'],
                'updated_at' => now(),
            ]);
        if ($evidenceStored !== 1) {
            return $this->latestCheck($checkId);
        }

        $validatedResults = [];
        $rawResults = [];
        $usage = ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0];
        $modelMeta = [];
        $modes = [];
        $modelAttempts = [];
        $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
        $segmentRuns = is_array($executionMeta['segment_runs'] ?? null) ? $executionMeta['segment_runs'] : [];
        $modelCandidates = $this->policyResolver->modelCandidates($policy);
        $segments = $this->segmenter->segment((string) ($articleSnapshot['content'] ?? ''));

        foreach ($segments as $segmentData) {
            $segment = $check->segments->firstWhere('segment_index', (int) $segmentData['index']);
            if (! $segment) {
                throw new RuntimeException('ai_quality_segment_missing');
            }
            if ($segment->status === 'completed' && is_array($segment->validated_result)) {
                $validatedResults[] = $segment->validated_result;
                $rawResults[] = $segment->model_result;
                $runMeta = is_array($segmentRuns[(string) $segmentData['index']] ?? null)
                    ? $segmentRuns[(string) $segmentData['index']]
                    : [];
                $this->accumulateRunTelemetry($runMeta, $usage, $modelMeta, $modes, $modelAttempts);

                continue;
            }

            if (! $this->startSegment($checkId, (int) $segment->id)) {
                return $this->latestCheck($checkId);
            }

            $segmentFacts = $this->factsForSegment($facts, $segmentData);
            $instructions = $this->promptRenderer->render((string) $check->prompt_template_snapshot, [
                'article_title' => (string) ($articleSnapshot['title'] ?? ''),
                'article_excerpt' => (string) ($articleSnapshot['excerpt'] ?? ''),
                'article_outline' => $this->outline((string) ($articleSnapshot['content'] ?? '')),
                'article_content' => (string) $segmentData['content'],
                'keywords' => (string) ($articleSnapshot['keywords'] ?? ''),
                'meta_description' => (string) ($articleSnapshot['meta_description'] ?? ''),
                'fact_candidates' => $segmentFacts,
                'knowledge' => $evidence,
                'advertising_rules' => $rules,
                'inspection_date' => now()->toDateString(),
                'publication_context' => $policy['publication_context'] ?? [],
                'segment_index' => (int) $segmentData['index'] + 1,
                'segment_count' => count($segments),
                'segment_start_offset' => (int) $segmentData['start_offset'],
            ]);

            $review = null;
            $raw = [];
            $validated = [];
            $priorRunMeta = is_array($segmentRuns[(string) $segmentData['index']] ?? null)
                ? $segmentRuns[(string) $segmentData['index']]
                : [];
            $attempts = is_array($priorRunMeta['attempts'] ?? null) ? $priorRunMeta['attempts'] : [];
            $runUsage = $this->mergeUsage([], is_array($priorRunMeta['usage'] ?? null) ? $priorRunMeta['usage'] : []);
            $lastException = null;
            foreach ($modelCandidates as $candidate) {
                if ((string) $candidate->status !== 'active'
                    || ! in_array((string) ($candidate->model_type ?? ''), ['', 'chat'], true)) {
                    $attempts[] = $this->modelAttempt($segmentData, $candidate, 'skipped', 'model_unavailable');

                    continue;
                }

                try {
                    $candidateReview = $this->reviewer->review($candidate, $instructions);
                    $runUsage = $this->mergeUsage(
                        $runUsage,
                        is_array($candidateReview['usage'] ?? null) ? $candidateReview['usage'] : [],
                    );
                    $raw = $candidateReview['result'];
                    $validated = $this->resultValidator->validate(
                        $raw,
                        $articleSnapshot,
                        $facts,
                        $evidence,
                        $rules,
                        $segmentData,
                    );
                    $attempts[] = $this->modelAttempt($segmentData, $candidate, 'succeeded', null);
                    $review = $candidateReview;
                    break;
                } catch (Throwable $exception) {
                    $lastException = $exception;
                    $attempts[] = $this->modelAttempt(
                        $segmentData,
                        $candidate,
                        'failed',
                        $this->safeErrorCode($exception),
                    );
                }
            }
            if (! is_array($review)) {
                $this->recordSegmentRun($checkId, (int) $segmentData['index'], [
                    'attempts' => $attempts,
                    'usage' => $runUsage,
                    'model' => [],
                    'mode' => null,
                ]);

                throw $lastException ?? new RuntimeException('ai_quality_model_unavailable');
            }
            $validated['knowledge_coverage'] = $evidenceResult['knowledge_coverage'];
            $runMeta = [
                'attempts' => $attempts,
                'usage' => $runUsage,
                'model' => is_array($review['model'] ?? null) ? $review['model'] : [],
                'mode' => (string) ($review['mode'] ?? ''),
            ];
            if (! $this->completeSegment($checkId, (int) $segment->id, $raw, $validated, $runMeta)) {
                return $this->latestCheck($checkId);
            }

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
        $score = $this->scorer->score($aggregate, (int) $check->pass_score, (int) $check->manual_override_min_score);
        $completedAt = now();
        $completed = DB::transaction(function () use (
            $checkId,
            $score,
            $aggregate,
            $rawResults,
            $modelMeta,
            $usage,
            $modes,
            $validatedResults,
            $modelAttempts,
            $segmentRuns,
            $completedAt,
        ): int {
            $current = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $current || (string) $current->status !== 'running') {
                return 0;
            }

            return ArticleAiQualityCheck::query()
                ->whereKey($checkId)
                ->where('status', 'running')
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
                    'raw_model_output' => json_encode(array_slice($rawResults, 0, 20), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'ai_model_id' => isset($modelMeta['id']) ? (int) $modelMeta['id'] : (int) $current->ai_model_id,
                    'model_snapshot' => json_encode(
                        array_replace(is_array($current->model_snapshot) ? $current->model_snapshot : [], $modelMeta),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                    'usage_meta' => json_encode($usage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'execution_meta' => json_encode(
                        array_replace(is_array($current->execution_meta) ? $current->execution_meta : [], [
                            'output_modes' => array_values(array_unique($modes)),
                            'completed_segments' => count($validatedResults),
                            'model_attempts' => $modelAttempts,
                            'segment_runs' => $segmentRuns,
                        ]),
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                    ),
                    'active_dedupe_key' => null,
                    'finished_at' => $completedAt,
                    'updated_at' => $completedAt,
                ]);
        });
        if ($completed !== 1) {
            return $this->latestCheck($checkId);
        }

        $check = $this->latestCheck($checkId);
        $this->applyCompletedWorkflow($check->loadMissing(['article', 'task']));

        return $check;
    }

    public function markFailed(ArticleAiQualityCheck|int $check, Throwable $exception): void
    {
        $checkId = $check instanceof ArticleAiQualityCheck ? (int) $check->id : $check;
        $errorCode = $this->safeErrorCode($exception);

        DB::transaction(function () use ($checkId, $errorCode): void {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check || ! in_array((string) $check->status, ['queued', 'running', 'failed'], true)) {
                return;
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
            $check->forceFill([
                'status' => 'failed',
                'decision' => 'error',
                'active_dedupe_key' => null,
                'error_code' => $errorCode,
                'error_message' => 'AI 质检执行失败，请稍后重试或联系管理员检查模型配置。',
                'execution_meta' => $executionMeta,
                'usage_meta' => $usageMeta,
                'finished_at' => now(),
            ])->save();
            $this->holdUnpublishedArticleForReview((int) $check->article_id);
        });
    }

    public function markRetryPending(ArticleAiQualityCheck|int $check, Throwable $exception): void
    {
        $checkId = $check instanceof ArticleAiQualityCheck ? (int) $check->id : $check;
        $errorCode = $this->safeErrorCode($exception);

        DB::transaction(function () use ($checkId, $errorCode): void {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check || ! in_array((string) $check->status, ['queued', 'running', 'failed'], true)) {
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
            $check->forceFill([
                'status' => 'queued',
                'decision' => 'error',
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

        return DB::transaction(function () use ($checkId): bool {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check
                || ! in_array((string) $check->status, ['queued', 'running'], true)
                || $check->updated_at?->isAfter(now()->subMinutes(15))) {
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

            DB::afterCommit(fn () => $this->dispatchCheck($checkId));

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

    private function startSegment(int $checkId, int $segmentId): bool
    {
        return DB::transaction(function () use ($checkId, $segmentId): bool {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check || (string) $check->status !== 'running') {
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
    private function completeSegment(int $checkId, int $segmentId, array $raw, array $validated, array $runMeta): bool
    {
        return DB::transaction(function () use ($checkId, $segmentId, $raw, $validated, $runMeta): bool {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check || (string) $check->status !== 'running') {
                return false;
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

    /** @param array<string, mixed> $runMeta */
    private function recordSegmentRun(int $checkId, int $segmentIndex, array $runMeta): void
    {
        DB::transaction(function () use ($checkId, $segmentIndex, $runMeta): void {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check || (string) $check->status !== 'running') {
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
        DB::transaction(function () use ($checkId): void {
            $check = ArticleAiQualityCheck::query()->whereKey($checkId)->lockForUpdate()->first();
            if (! $check || (string) $check->status !== 'running') {
                return;
            }

            $check->forceFill([
                'status' => 'queued',
                'decision' => null,
                'error_code' => null,
                'error_message' => null,
                'finished_at' => null,
            ])->save();

            DB::afterCommit(fn () => $this->dispatchCheck($checkId));
        });

        return $this->latestCheck($checkId);
    }

    private function markStale(ArticleAiQualityCheck $check): ArticleAiQualityCheck
    {
        ArticleAiQualityCheck::query()
            ->whereKey((int) $check->id)
            ->whereIn('status', ['queued', 'running', 'failed'])
            ->update([
                'status' => 'stale',
                'decision' => null,
                'active_dedupe_key' => null,
                'error_code' => 'input_changed',
                'error_message' => '文章或质检依据已经变化。',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        ArticleAiQualitySegment::query()
            ->where('article_ai_quality_check_id', (int) $check->id)
            ->whereIn('status', ['queued', 'running', 'failed'])
            ->update([
                'status' => 'stale',
                'error_code' => 'input_changed',
                'error_message' => '文章或质检依据已经变化。',
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        $this->holdUnpublishedArticleForReview((int) $check->article_id);

        return $this->latestCheck((int) $check->id);
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
        ];
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

    private function applyCompletedWorkflow(ArticleAiQualityCheck $check): void
    {
        if (! $check->article) {
            return;
        }

        if ($check->decision !== 'passed') {
            $this->holdUnpublishedArticleForReview((int) $check->article_id);

            return;
        }

        if (! ($check->task instanceof Task)
            || (int) $check->task->need_review === 1
            || $check->article->status !== 'draft'
            || $check->article->review_status === 'rejected') {
            return;
        }

        try {
            $requestedWorkflowState = is_array($check->execution_meta['requested_workflow_state'] ?? null)
                ? $check->execution_meta['requested_workflow_state']
                : null;
            $targetState = $requestedWorkflowState !== null
                && in_array((string) ($requestedWorkflowState['status'] ?? ''), ['published', 'private'], true)
                ? $requestedWorkflowState
                : ['status' => 'draft', 'review_status' => 'approved', 'published_at' => null];
            $article = app(ArticleWorkflowTransitionService::class)->transition(
                $check->article,
                $targetState,
                'ai_quality_passed',
                null,
                null,
                false,
            );
            if ((string) $article->status === 'published') {
                app(DistributionOrchestrator::class)->enqueueForArticle($article);
            }
        } catch (Throwable) {
            // Existing risk and workflow gates retain the pending state when approval is unsafe.
        }
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

    private function dispatchCheck(int $checkId): void
    {
        try {
            ProcessArticleAiQualityJob::dispatch($checkId)->onQueue('geoflow');
        } catch (Throwable $exception) {
            $this->markFailed($checkId, new RuntimeException('ai_quality_queue_dispatch_failed', 0, $exception));

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $segment
     * @return array{segment_index:int,model_id:int,model_name:string,outcome:string,error_code:?string}
     */
    private function modelAttempt(
        array $segment,
        AiModel $model,
        string $outcome,
        ?string $errorCode,
    ): array {
        return [
            'segment_index' => (int) ($segment['index'] ?? 0),
            'model_id' => (int) $model->id,
            'model_name' => (string) $model->name,
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
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $addition
     * @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int}
     */
    private function mergeUsage(array $base, array $addition): array
    {
        $merged = [];
        foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $key) {
            $merged[$key] = (int) ($base[$key] ?? $base[Str::camel($key)] ?? 0)
                + (int) ($addition[$key] ?? $addition[Str::camel($key)] ?? 0);
        }

        return $merged;
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

    private function safeErrorCode(Throwable $exception): string
    {
        $message = strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'queue_dispatch') => 'queue_dispatch_failed',
            str_contains($message, 'timeout') => 'model_timeout',
            str_contains($message, 'quota') || str_contains($message, 'limit') => 'model_quota_exceeded',
            str_contains($message, 'structure'), str_contains($message, 'json') => 'invalid_model_output',
            str_contains($message, 'configuration'), str_contains($message, 'unavailable') => 'model_unavailable',
            default => 'inspection_failed',
        };
    }
}
