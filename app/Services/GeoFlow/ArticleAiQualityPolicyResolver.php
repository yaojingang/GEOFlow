<?php

namespace App\Services\GeoFlow;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use Illuminate\Support\Arr;
use RuntimeException;

class ArticleAiQualityPolicyResolver
{
    private const DEFAULT_PROMPT_SYSTEM_KEY = 'article_quality.cn_ads_knowledge.v1';

    public function __construct(
        private readonly AiQualityRetrievalReadinessService $retrievalReadinessService,
        private readonly AdminAiModelAccessResolver $adminAiModelAccessResolver,
    ) {}

    /** @return array<string, mixed> */
    public function resolve(Article $article): array
    {
        $snapshot = is_array($article->ai_quality_policy_snapshot) ? $article->ai_quality_policy_snapshot : [];
        $required = (bool) $article->ai_quality_required_at_creation;
        $task = $this->taskForArticle($article);

        if ($task instanceof Task && ! $task->trashed()) {
            $taskPolicy = $this->fromTask($task, $article);
            if (($taskPolicy['required'] ?? false) || ! (bool) $article->ai_quality_required_at_creation) {
                return $taskPolicy;
            }
        }

        if (! $required) {
            return ['required' => false, 'source' => 'article_snapshot'];
        }

        return $this->fromIndependentArticle($article, $snapshot);
    }

    /** @return array<string, mixed> */
    public function resolveForManualInspection(Article $article): array
    {
        $task = $this->taskForArticle($article);
        $hasActiveTask = $task instanceof Task && ! $task->trashed();
        $current = $hasActiveTask
            ? $this->resolve($article)
            : $this->fromIndependentArticle(
                $article,
                is_array($article->ai_quality_policy_snapshot) ? $article->ai_quality_policy_snapshot : [],
            );
        if (! $hasActiveTask) {
            try {
                $this->assertExecutable($current);

                return $current;
            } catch (RuntimeException) {
                // Rebind deleted or disabled runtime dependencies while retaining the stored policy thresholds.
            }
        }
        if ($task instanceof Task && $task->trashed()) {
            $task = null;
        }
        $prompt = $task?->qualityPrompt ?: ($current['prompt'] ?? null);
        if (! $prompt instanceof Prompt || (string) $prompt->type !== 'quality_check') {
            $prompt = Prompt::query()
                ->where('system_key', self::DEFAULT_PROMPT_SYSTEM_KEY)
                ->where('type', 'quality_check')
                ->first();
        }

        $model = collect([$task?->qualityModel, $task?->aiModel, $current['model'] ?? null])
            ->first(fn (mixed $candidate): bool => $candidate instanceof AiModel
                && (string) $candidate->status === 'active'
                && $this->isChatModel($candidate));
        if (! $model instanceof AiModel) {
            $model = AiModel::query()
                ->where('status', 'active')
                ->where(function ($query): void {
                    $query->whereNull('model_type')
                        ->orWhere('model_type', '')
                        ->orWhere('model_type', 'chat');
                })
                ->orderBy('failover_priority')
                ->orderBy('id')
                ->first();
        }

        $knowledgeBaseIds = collect($current['knowledge_base_ids'] ?? [])
            ->map('intval')
            ->filter()
            ->values()
            ->all();
        if ($task instanceof Task) {
            $knowledgeBaseIds = $task->knowledgeBases->pluck('id')->map('intval')->all();
            if ((int) $task->knowledge_base_id > 0) {
                $knowledgeBaseIds[] = (int) $task->knowledge_base_id;
            }
        } elseif ($knowledgeBaseIds === []) {
            $knowledgeBaseIds = $article->aiQualityKnowledgeBases()
                ->pluck('knowledge_bases.id')
                ->map('intval')
                ->all();
        }

        $modelSelectionMode = (string) ($task?->model_selection_mode ?? ($current['model_selection_mode'] ?? 'fixed'));
        if (! in_array($modelSelectionMode, ['fixed', 'smart_failover'], true)) {
            $modelSelectionMode = 'fixed';
        }

        return [
            'required' => true,
            'source' => 'manual_article',
            'task' => $task,
            'prompt' => $prompt,
            'model' => $model,
            'model_selection_mode' => $modelSelectionMode,
            'knowledge_base_ids' => array_values(array_unique($knowledgeBaseIds)),
            'retrieval_mode' => $this->retrievalModeFor($article, $task, $current),
            'retrieval_mode_explicit' => $this->retrievalModeIsExplicit($article, $task, $current),
            'policy_version' => max(1, (int) ($article->ai_quality_policy_version ?? $task?->ai_quality_policy_version ?? 1)),
            'config_version' => max(1, (int) ($task?->ai_quality_config_version ?? $task?->ai_quality_policy_version ?? 1)),
            'pass_score' => (int) ($task?->ai_quality_pass_score ?: ($current['pass_score'] ?? 85)),
            'manual_override_min_score' => (int) ($task?->ai_quality_manual_override_min_score ?: ($current['manual_override_min_score'] ?? 70)),
            'timeout_sampling_enabled' => (bool) ($task?->ai_quality_timeout_sampling_enabled ?? ($current['timeout_sampling_enabled'] ?? false)),
            'manual_review_required' => (bool) ($task?->need_review ?? ($current['manual_review_required'] ?? true)),
            'publication_context' => array_replace(Arr::except(
                is_array($current['publication_context'] ?? null) ? $current['publication_context'] : [],
                ['ai_generated_label_status', 'is_ai_generated'],
            ), [
                'publish_scope' => (string) ($task?->publish_scope ?? data_get($current, 'publication_context.publish_scope', 'public')),
                'distribution_strategy' => (string) ($task?->distribution_strategy ?? data_get($current, 'publication_context.distribution_strategy', '')),
                'advertising_label_status' => 'unknown',
            ]),
        ];
    }

    /** @return array<string, mixed> */
    public function fromTask(Task $task, ?Article $article = null): array
    {
        return $this->taskPolicy($task, $article, false);
    }

    public function fromTaskForDetachment(Task $task, ?Article $article = null): array
    {
        return $this->taskPolicy($task, $article, true);
    }

    /** @return array<string,mixed> */
    private function taskPolicy(Task $task, ?Article $article, bool $includeDisabledConfiguration): array
    {
        if (! (bool) $task->ai_quality_enabled && ! $includeDisabledConfiguration) {
            return ['required' => false, 'source' => 'task', 'task' => $task];
        }

        $task->loadMissing(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
        $knowledgeBaseIds = $task->knowledgeBases->pluck('id')->map('intval')->all();
        if ((int) $task->knowledge_base_id > 0) {
            $knowledgeBaseIds[] = (int) $task->knowledge_base_id;
        }

        return [
            'required' => (bool) $task->ai_quality_enabled,
            'source' => $includeDisabledConfiguration ? 'task_detachment' : 'task',
            'task' => $task,
            'prompt' => $task->qualityPrompt,
            'model' => $task->qualityModel ?: $task->aiModel,
            'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
            'knowledge_base_ids' => array_values(array_unique($knowledgeBaseIds)),
            'retrieval_mode' => $this->retrievalModeFor($article, $task),
            'retrieval_mode_explicit' => $this->retrievalModeIsExplicit($article, $task),
            'policy_version' => max(1, (int) ($article?->ai_quality_policy_version ?? $task->ai_quality_policy_version ?? 1)),
            'config_version' => max(1, (int) ($task->ai_quality_config_version ?? $task->ai_quality_policy_version ?? 1)),
            'pass_score' => (int) ($task->ai_quality_pass_score ?: 85),
            'manual_override_min_score' => (int) ($task->ai_quality_manual_override_min_score ?: 70),
            'timeout_sampling_enabled' => (bool) $task->ai_quality_timeout_sampling_enabled,
            'manual_review_required' => (bool) $task->need_review,
            'publication_context' => [
                'publish_scope' => (string) ($task->publish_scope ?? 'public'),
                'distribution_strategy' => (string) ($task->distribution_strategy ?? ''),
                'advertising_label_status' => 'unknown',
            ],
        ];
    }

    /** @param array<string, mixed> $snapshot @return array<string, mixed> */
    public function fromArticleSnapshot(array $snapshot, string $source = 'article_snapshot'): array
    {
        $prompt = isset($snapshot['prompt_id']) ? Prompt::query()->find((int) $snapshot['prompt_id']) : null;
        $model = isset($snapshot['model_id']) ? AiModel::query()->find((int) $snapshot['model_id']) : null;
        $knowledgeBaseIds = collect($snapshot['knowledge_base_ids'] ?? [])->map('intval')->filter()->unique()->values()->all();

        return [
            'required' => true,
            'source' => $source,
            'task' => null,
            'prompt' => $prompt,
            'model' => $model,
            'model_selection_mode' => (string) ($snapshot['model_selection_mode'] ?? 'fixed'),
            'knowledge_base_ids' => $knowledgeBaseIds,
            'retrieval_mode' => AiQualityRetrievalMode::isValid($snapshot['retrieval_mode'] ?? null)
                ? (string) $snapshot['retrieval_mode']
                : AiQualityRetrievalMode::legacyDefault(),
            'retrieval_mode_explicit' => (bool) ($snapshot['retrieval_mode_explicit'] ?? false),
            'policy_version' => max(1, (int) ($snapshot['policy_version'] ?? 1)),
            'config_version' => max(1, (int) ($snapshot['config_version'] ?? $snapshot['policy_version'] ?? 1)),
            'pass_score' => (int) ($snapshot['pass_score'] ?? 85),
            'manual_override_min_score' => (int) ($snapshot['manual_override_min_score'] ?? 70),
            'timeout_sampling_enabled' => (bool) ($snapshot['timeout_sampling_enabled'] ?? false),
            'manual_review_required' => (bool) ($snapshot['manual_review_required'] ?? true),
            'publication_context' => Arr::except(
                is_array($snapshot['publication_context'] ?? null) ? $snapshot['publication_context'] : [],
                ['ai_generated_label_status', 'is_ai_generated'],
            ),
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function fromIndependentArticle(Article $article, array $snapshot): array
    {
        $policy = $this->fromArticleSnapshot(
            $snapshot,
            ($snapshot['source'] ?? null) === 'manual_article' ? 'manual_article' : 'article_current',
        );
        $currentKnowledgeBaseIds = $article->aiQualityKnowledgeBases()
            ->orderByPivot('sort_order')
            ->pluck('knowledge_bases.id')
            ->map('intval')
            ->all();
        if ($currentKnowledgeBaseIds !== []) {
            $policy['knowledge_base_ids'] = $currentKnowledgeBaseIds;
        }
        if (AiQualityRetrievalMode::isValid($article->ai_quality_retrieval_mode_override)) {
            $policy['retrieval_mode'] = (string) $article->ai_quality_retrieval_mode_override;
            $policy['retrieval_mode_explicit'] = true;
        }
        $policy['policy_version'] = max(1, (int) $article->ai_quality_policy_version);
        $policy['source'] = 'article_current';

        return $policy;
    }

    private function taskForArticle(Article $article): ?Task
    {
        if (! $article->task_id) {
            return null;
        }
        if ($article->relationLoaded('task')) {
            $task = $article->getRelation('task');
            if ($task instanceof Task) {
                $task->loadMissing(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
            }

            return $task instanceof Task ? $task : null;
        }

        return Task::withTrashed()
            ->with(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases'])
            ->find((int) $article->task_id);
    }

    /** @param array<string, mixed> $policy */
    public function assertExecutable(array $policy): void
    {
        if (! ($policy['required'] ?? false)) {
            return;
        }

        if (! ($policy['prompt'] ?? null) instanceof Prompt
            || (string) $policy['prompt']->type !== 'quality_check') {
            throw new RuntimeException('ai_quality_prompt_unavailable');
        }
        if (! ($policy['model'] ?? null) instanceof AiModel) {
            throw new RuntimeException('ai_quality_model_unavailable');
        }
        $knowledgeBaseIds = collect($policy['knowledge_base_ids'] ?? [])
            ->map('intval')
            ->filter()
            ->unique()
            ->values();
        if ($knowledgeBaseIds->isEmpty()
            || KnowledgeBase::query()->whereIn('id', $knowledgeBaseIds->all())->count() !== $knowledgeBaseIds->count()) {
            throw new RuntimeException('ai_quality_knowledge_unavailable');
        }
        $retrievalMode = (string) ($policy['retrieval_mode'] ?? AiQualityRetrievalMode::legacyDefault());
        if (! AiQualityRetrievalMode::isValid($retrievalMode)) {
            throw new RuntimeException('ai_quality_retrieval_mode_invalid');
        }
        if ((bool) ($policy['retrieval_mode_explicit'] ?? false)) {
            $readiness = $this->retrievalReadinessService->inspect($knowledgeBaseIds->all());
            if (! ($readiness['modes'][$retrievalMode]['available'] ?? false)) {
                throw new RuntimeException('ai_quality_retrieval_mode_unavailable');
            }
        }
        $modelSelectionMode = (string) ($policy['model_selection_mode'] ?? 'fixed');
        if (! in_array($modelSelectionMode, ['fixed', 'smart_failover'], true)
            || ($modelSelectionMode === 'fixed' && (
                (string) $policy['model']->status !== 'active'
                || ! $this->isChatModel($policy['model'])
            ))
            || collect($this->modelCandidates($policy))->doesntContain(
                fn (AiModel $model): bool => (string) $model->status === 'active' && $this->isChatModel($model)
            )) {
            throw new RuntimeException('ai_quality_model_unavailable');
        }

        $passScore = (int) ($policy['pass_score'] ?? 85);
        $manualScore = (int) ($policy['manual_override_min_score'] ?? 70);
        if ($manualScore < 0 || $manualScore >= $passScore || $passScore > 100) {
            throw new RuntimeException('ai_quality_thresholds_invalid');
        }
    }

    /** @param array<string, mixed> $policy */
    public function snapshot(array $policy): array
    {
        $prompt = $policy['prompt'] ?? null;
        $model = $policy['model'] ?? null;

        return [
            'required' => (bool) ($policy['required'] ?? false),
            'source' => (string) ($policy['source'] ?? 'unknown'),
            'prompt_id' => $prompt instanceof Prompt ? (int) $prompt->id : null,
            'prompt_system_key' => $prompt instanceof Prompt ? $prompt->system_key : null,
            'prompt_system_version' => $prompt instanceof Prompt ? $prompt->system_version : null,
            'model_id' => $model instanceof AiModel ? (int) $model->id : null,
            'model_selection_mode' => (string) ($policy['model_selection_mode'] ?? 'fixed'),
            'pass_score' => (int) ($policy['pass_score'] ?? 85),
            'manual_override_min_score' => (int) ($policy['manual_override_min_score'] ?? 70),
            'timeout_sampling_enabled' => (bool) ($policy['timeout_sampling_enabled'] ?? false),
            'manual_review_required' => (bool) ($policy['manual_review_required'] ?? true),
            'sampling_algorithm_version' => ArticleAiQualitySampleBuilder::ALGORITHM_VERSION,
            'sampling_max_characters' => (int) config('geoflow.ai_quality_sampled_max_characters', 6000),
            'sampling_max_ranges' => (int) config('geoflow.ai_quality_sampled_max_ranges', 12),
            'risk_scan_algorithm_version' => ArticleRiskScanner::SCAN_ALGORITHM_VERSION,
            'knowledge_base_ids' => array_values(array_map('intval', $policy['knowledge_base_ids'] ?? [])),
            'retrieval_mode' => (string) ($policy['retrieval_mode'] ?? AiQualityRetrievalMode::legacyDefault()),
            'retrieval_mode_explicit' => (bool) ($policy['retrieval_mode_explicit'] ?? false),
            'policy_version' => max(1, (int) ($policy['policy_version'] ?? 1)),
            'config_version' => max(1, (int) ($policy['config_version'] ?? $policy['policy_version'] ?? 1)),
            'publication_context' => Arr::except(
                is_array($policy['publication_context'] ?? null) ? $policy['publication_context'] : [],
                ['ai_generated_label_status', 'is_ai_generated'],
            ),
            'algorithm_version' => ArticleAiQualityFingerprint::ALGORITHM_VERSION,
        ];
    }

    /** @param array<string, mixed> $policy */
    public function fingerprintInput(Article $article, array $policy, array $rules): array
    {
        $prompt = $policy['prompt'] ?? null;
        $model = $policy['model'] ?? null;
        $retrievalMode = (string) ($policy['retrieval_mode'] ?? AiQualityRetrievalMode::legacyDefault());
        $orderedKnowledgeBaseIds = array_values(array_map('intval', $policy['knowledge_base_ids'] ?? []));
        $knowledge = KnowledgeBase::query()
            ->whereIn('id', $orderedKnowledgeBaseIds)
            ->when(
                $retrievalMode === AiQualityRetrievalMode::ATOMIC_FIRST,
                fn ($query) => $query->with('factLibrary.activeRevision:id,library_id,version,library_hash,source_hash'),
            )
            ->get([
                'id',
                'name',
                'ai_quality_content_hash',
                'chunk_source_hash',
                'chunk_serving_generation',
                'chunk_serving_source_hash',
                'chunk_manifest_hash',
                'review_status',
                'chunk_sync_status',
            ])
            ->sortBy(static fn (KnowledgeBase $base): int => array_search((int) $base->id, $orderedKnowledgeBaseIds, true))
            ->values()
            ->map(fn (KnowledgeBase $base): array => $this->knowledgeSourceProjection($base, $retrievalMode))
            ->all();

        $modelCandidates = $this->modelCandidates($policy);

        return [
            'article' => $this->articleSnapshot($article),
            'policy' => [
                'pass_score' => (int) ($policy['pass_score'] ?? 85),
                'manual_override_min_score' => (int) ($policy['manual_override_min_score'] ?? 70),
                'model_selection_mode' => (string) ($policy['model_selection_mode'] ?? 'fixed'),
                'manual_review_required' => (bool) ($policy['manual_review_required'] ?? true),
                'retrieval_mode' => (string) ($policy['retrieval_mode'] ?? AiQualityRetrievalMode::legacyDefault()),
                'policy_version' => max(1, (int) ($policy['policy_version'] ?? 1)),
            ],
            'prompt' => [
                'id' => $prompt instanceof Prompt ? (int) $prompt->id : null,
                'system_key' => $prompt instanceof Prompt ? $prompt->system_key : null,
                'system_version' => $prompt instanceof Prompt ? $prompt->system_version : null,
                'hash' => $prompt instanceof Prompt ? hash('sha256', (string) $prompt->content) : null,
            ],
            'model' => [
                'id' => $model instanceof AiModel ? (int) $model->id : null,
                'model_id' => $model instanceof AiModel ? (string) $model->model_id : null,
                'version' => $model instanceof AiModel ? (string) $model->version : null,
                'api_url' => $model instanceof AiModel ? (string) $model->api_url : null,
                'max_tokens' => $model instanceof AiModel ? (int) $model->max_tokens : null,
                'candidates' => array_map(fn (AiModel $candidate): array => $this->modelFingerprint($candidate), $modelCandidates),
            ],
            'knowledge' => $knowledge,
            'rules' => ['version' => $rules['version'] ?? null, 'hash' => hash('sha256', json_encode($rules, JSON_UNESCAPED_UNICODE))],
            'publication_context' => $policy['publication_context'] ?? [],
            'schema_version' => 'article-quality-schema-1.0.0',
            'segmentation_version' => 'article-quality-segments-1.0.0',
            'scoring_version' => 'article-quality-score-1.0.0',
        ];
    }

    /** @return array<string,mixed> */
    private function knowledgeSourceProjection(KnowledgeBase $base, string $retrievalMode): array
    {
        $projection = [
            'id' => (int) $base->id,
            'name' => (string) $base->name,
            'raw_content_hash' => (string) $base->ai_quality_content_hash,
            'review_status' => (string) ($base->review_status ?? 'unreviewed'),
        ];
        if ($retrievalMode === AiQualityRetrievalMode::KNOWLEDGE_BROAD) {
            return $projection;
        }

        $projection += [
            'chunk_source_hash' => $base->servingChunkSourceHash(),
            'chunk_serving_generation' => (string) ($base->chunk_serving_generation ?? ''),
            'chunk_manifest_hash' => (string) ($base->chunk_manifest_hash ?? ''),
            'chunk_sync_status' => (string) ($base->chunk_sync_status ?? ''),
        ];
        if ($retrievalMode === AiQualityRetrievalMode::ATOMIC_FIRST) {
            $projection['atomic_facts'] = [
                'revision_id' => $base->factLibrary?->active_revision_id,
                'revision_version' => $base->factLibrary?->activeRevision?->version,
                'library_hash' => $base->factLibrary?->active_hash,
                'source_hash' => $base->factLibrary?->source_hash,
                'serving_status' => $base->factLibrary?->serving_status,
            ];
        }

        return $projection;
    }

    /** @return array<string, mixed> */
    public function articleSnapshot(Article $article): array
    {
        return [
            'title' => (string) $article->title,
            'excerpt' => (string) ($article->excerpt ?? ''),
            'content' => (string) ($article->content ?? ''),
            'keywords' => (string) ($article->keywords ?? ''),
            'meta_description' => (string) ($article->meta_description ?? ''),
            'task_id' => $article->task_id ? (int) $article->task_id : null,
        ];
    }

    /** @param  array<string,mixed>  $fallback */
    private function retrievalModeFor(?Article $article, ?Task $task, array $fallback = []): string
    {
        if ($article instanceof Article && AiQualityRetrievalMode::isValid($article->ai_quality_retrieval_mode_override)) {
            return (string) $article->ai_quality_retrieval_mode_override;
        }
        if ($task instanceof Task && AiQualityRetrievalMode::isValid($task->ai_quality_retrieval_mode)) {
            return (string) $task->ai_quality_retrieval_mode;
        }
        if (AiQualityRetrievalMode::isValid($fallback['retrieval_mode'] ?? null)) {
            return (string) $fallback['retrieval_mode'];
        }

        return AiQualityRetrievalMode::legacyDefault();
    }

    /** @param  array<string,mixed>  $fallback */
    private function retrievalModeIsExplicit(?Article $article, ?Task $task, array $fallback = []): bool
    {
        return ($article instanceof Article && AiQualityRetrievalMode::isValid($article->ai_quality_retrieval_mode_override))
            || ($task instanceof Task && AiQualityRetrievalMode::isValid($task->ai_quality_retrieval_mode))
            || (bool) ($fallback['retrieval_mode_explicit'] ?? false);
    }

    /** @param array<string, mixed> $policy @return list<AiModel> */
    public function modelCandidates(array $policy): array
    {
        if (is_array($policy['model_candidates'] ?? null)
            && collect($policy['model_candidates'])->every(static fn (mixed $model): bool => $model instanceof AiModel)) {
            return array_values($policy['model_candidates']);
        }

        $primary = $policy['model'] ?? null;
        if (! $primary instanceof AiModel) {
            return [];
        }
        if ((string) ($policy['model_selection_mode'] ?? 'fixed') !== 'smart_failover') {
            return [$primary];
        }

        $maximumCandidates = max(1, min(10, (int) config('geoflow.ai_quality_max_model_candidates', 2)));
        $fallbacks = AiModel::query()
            ->whereKeyNot((int) $primary->id)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get()
            ->filter(fn (AiModel $candidate): bool => $this->sharesEndpointOrigin($primary, $candidate))
            ->take(max(0, $maximumCandidates - 1))
            ->values()
            ->all();

        return array_values(array_merge([$primary], $fallbacks));
    }

    /** @param array<string,mixed> $policy @return array<string,mixed> */
    public function forExecutionAdmin(array $policy, Admin $admin): array
    {
        $primary = $policy['model'] ?? null;
        if (! $primary instanceof AiModel) {
            throw new RuntimeException('ai_quality_model_unavailable');
        }
        $this->adminAiModelAccessResolver->assertUsable($admin, $primary);
        if ((string) ($policy['model_selection_mode'] ?? 'fixed') !== 'smart_failover') {
            $policy['model_candidates'] = [$primary];

            return $policy;
        }

        $candidates = $this->adminAiModelAccessResolver->resolveCandidates($admin, 'chat')->values();
        $requested = $candidates->firstWhere('id', (int) $primary->id);
        if (! $requested instanceof AiModel) {
            throw new RuntimeException('ai_quality_model_unavailable');
        }
        $personal = $candidates->filter(
            static fn (AiModel $model): bool => (int) $model->owner_admin_id === (int) $admin->id,
        );
        $shared = $candidates->reject(
            static fn (AiModel $model): bool => (int) $model->owner_admin_id === (int) $admin->id,
        );
        $maximumCandidates = max(1, min(10, (int) config('geoflow.ai_quality_max_model_candidates', 2)));
        $policy['model_candidates'] = collect([$requested])
            ->concat($personal->reject(static fn (AiModel $model): bool => (int) $model->id === (int) $requested->id))
            ->concat($shared->reject(static fn (AiModel $model): bool => (int) $model->id === (int) $requested->id))
            ->take($maximumCandidates)
            ->values()
            ->all();

        return $policy;
    }

    /** @return array<string, int|string|null> */
    private function modelFingerprint(AiModel $model): array
    {
        return [
            'id' => (int) $model->id,
            'model_id' => (string) $model->model_id,
            'version' => (string) $model->version,
            'status' => (string) $model->status,
            'api_url' => (string) $model->api_url,
            'max_tokens' => $model->max_tokens === null ? null : (int) $model->max_tokens,
            'failover_priority' => $model->failover_priority === null ? null : (int) $model->failover_priority,
        ];
    }

    private function isChatModel(AiModel $model): bool
    {
        return in_array((string) ($model->model_type ?? ''), ['', 'chat'], true);
    }

    private function sharesEndpointOrigin(AiModel $primary, AiModel $candidate): bool
    {
        $primaryOrigin = $this->endpointOrigin((string) $primary->api_url);
        $candidateOrigin = $this->endpointOrigin((string) $candidate->api_url);

        return $primaryOrigin !== null
            && $candidateOrigin !== null
            && hash_equals($primaryOrigin, $candidateOrigin);
    }

    private function endpointOrigin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return null;
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));

        return $scheme.'://'.$host.':'.$port;
    }
}
