<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use Illuminate\Support\Arr;
use RuntimeException;

class ArticleAiQualityPolicyResolver
{
    private const DEFAULT_PROMPT_SYSTEM_KEY = 'article_quality.cn_ads_knowledge.v1';

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

        return $this->fromArticleSnapshot(
            $snapshot,
            ($snapshot['source'] ?? null) === 'manual_article' ? 'manual_article' : 'article_snapshot',
        );
    }

    /** @return array<string, mixed> */
    public function resolveForManualInspection(Article $article): array
    {
        $current = $this->resolve($article);
        $task = $this->taskForArticle($article);
        if ((! $task instanceof Task || $task->trashed()) && ($current['required'] ?? false)) {
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
        if (! (bool) $task->ai_quality_enabled) {
            return ['required' => false, 'source' => 'task', 'task' => $task];
        }

        $task->loadMissing(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
        $knowledgeBaseIds = $task->knowledgeBases->pluck('id')->map('intval')->all();
        if ((int) $task->knowledge_base_id > 0) {
            $knowledgeBaseIds[] = (int) $task->knowledge_base_id;
        }

        return [
            'required' => true,
            'source' => 'task',
            'task' => $task,
            'prompt' => $task->qualityPrompt,
            'model' => $task->qualityModel ?: $task->aiModel,
            'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
            'knowledge_base_ids' => array_values(array_unique($knowledgeBaseIds)),
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
        $knowledge = KnowledgeBase::query()
            ->whereIn('id', $policy['knowledge_base_ids'] ?? [])
            ->orderBy('id')
            ->get(['id', 'chunk_source_hash', 'review_status', 'chunk_sync_status', 'updated_at'])
            ->map(fn (KnowledgeBase $base): array => [
                'id' => (int) $base->id,
                'chunk_source_hash' => (string) ($base->chunk_source_hash ?? ''),
                'review_status' => (string) ($base->review_status ?? 'unreviewed'),
                'chunk_sync_status' => (string) ($base->chunk_sync_status ?? ''),
                'updated_at' => $base->updated_at?->toISOString(),
            ])->all();

        $modelCandidates = $this->modelCandidates($policy);

        return [
            'article' => $this->articleSnapshot($article),
            'policy' => [
                'pass_score' => (int) ($policy['pass_score'] ?? 85),
                'manual_override_min_score' => (int) ($policy['manual_override_min_score'] ?? 70),
                'model_selection_mode' => (string) ($policy['model_selection_mode'] ?? 'fixed'),
                'manual_review_required' => (bool) ($policy['manual_review_required'] ?? true),
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
