<?php

namespace App\Services\GeoFlow;

use App\Jobs\ReconcileArticleAiOptimizationJob;
use App\Jobs\ReconcileArticleAiQualityJob;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiOptimizationStep;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleAiQualitySegment;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArticleAiQualityInvalidationService
{
    /** @param iterable<int> $articleIds */
    public function invalidateArticles(iterable $articleIds, string $reason): int
    {
        $ids = collect($articleIds)
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        [$updated, $affectedArticleIds] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()->whereIn('article_id', $ids->all()),
            'rollout_changed',
            $reason,
        );
        $this->dispatchReconcile($ids->merge($affectedArticleIds));
        $this->invalidateOptimizationArticles($ids, $reason);

        return $updated;
    }

    public function invalidateArticle(Article|int $article, string $reason, bool $reconcile = true): int
    {
        $articleId = $article instanceof Article ? (int) $article->id : $article;
        [$updated] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()->where('article_id', $articleId),
            'input_changed',
            $reason,
        );
        $this->invalidateOptimizationArticles([$articleId], $reason);

        if ($reconcile) {
            ReconcileArticleAiQualityJob::dispatch($articleId, $articleId)
                ->onConnection('redis')
                ->onQueue((string) config('geoflow.ai_quality_backfill_queue', 'ai-quality-backfill'))
                ->afterCommit();
        }

        return $updated;
    }

    public function cancelArticle(Article|int $article, string $reason = 'article_deleted'): int
    {
        $articleId = $article instanceof Article ? (int) $article->id : $article;

        return $this->cancelArticles([$articleId], $reason);
    }

    /** @param iterable<Article|int> $articles */
    public function cancelArticles(iterable $articles, string $reason = 'article_deleted'): int
    {
        $articleIds = collect($articles)
            ->map(static fn (Article|int $article): int => $article instanceof Article ? (int) $article->id : (int) $article)
            ->filter(static fn (int $articleId): bool => $articleId > 0)
            ->unique()
            ->values();
        if ($articleIds->isEmpty()) {
            return 0;
        }

        $checkIds = ArticleAiQualityCheck::query()
            ->whereIn('article_id', $articleIds->all())
            ->whereIn('status', ['queued', 'running'])
            ->orderBy('id')
            ->pluck('id');
        $optimizationUpdated = $this->cancelOptimizationArticles($articleIds, $reason);
        if ($checkIds->isEmpty()) {
            return $optimizationUpdated;
        }

        $updated = ArticleAiQualityCheck::query()
            ->whereIn('id', $checkIds->all())
            ->whereIn('article_id', $articleIds->all())
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'cancelled',
                'active_dedupe_key' => null,
                'error_code' => 'article_unavailable',
                'error_message' => mb_substr($reason, 0, 500, 'UTF-8'),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        ArticleAiQualitySegment::query()
            ->whereIn('article_ai_quality_check_id', $checkIds->all())
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'cancelled',
                'error_code' => 'article_unavailable',
                'error_message' => mb_substr($reason, 0, 500, 'UTF-8'),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        return $updated + $optimizationUpdated;
    }

    public function invalidateTask(int $taskId, string $reason): int
    {
        $articles = Article::withTrashed()->where('task_id', $taskId);
        [$updated, $affectedArticleIds] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()->where(function (Builder $query) use ($taskId, $articles): void {
                $query->where('task_id', $taskId);
                $query->orWhereIn('article_id', (clone $articles)->select('id'));
            }),
            'policy_changed',
            $reason,
        );
        $this->dispatchReconcile($this->articleIds($articles)->merge($affectedArticleIds));
        $this->invalidateTaskOptimization($taskId, $reason);

        return $updated;
    }

    public function invalidateTaskOptimization(
        int $taskId,
        string $reason,
        string $stopReason = 'task_configuration_changed',
        bool $recoverWorkflow = false,
        bool $taskAutoOnly = false,
    ): int {
        $query = ArticleAiOptimizationRun::query()
            ->where('task_id', $taskId)
            ->when($taskAutoOnly, static function (Builder $query): void {
                $query->where('trigger', ArticleAiOptimizationRun::TRIGGER_TASK_AUTO);
            })
            ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES);
        $updated = $this->finishOptimizationRuns(
            $query,
            ArticleAiOptimizationRun::STATUS_STALE,
            $stopReason,
            $reason,
        );
        if ($recoverWorkflow && $updated > 0) {
            $this->dispatchOptimizationReconcile();
        }

        return $updated;
    }

    public function cancelTaskOptimization(int $taskId, string $reason, bool $recoverWorkflow = false): int
    {
        $query = ArticleAiOptimizationRun::query()
            ->where('task_id', $taskId)
            ->where('trigger', ArticleAiOptimizationRun::TRIGGER_TASK_AUTO)
            ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES);
        $updated = $this->finishOptimizationRuns(
            $query,
            ArticleAiOptimizationRun::STATUS_CANCELLED,
            $recoverWorkflow ? 'task_auto_optimization_disabled' : 'task_auto_optimization_cancelled',
            $reason,
        );
        if ($recoverWorkflow && $updated > 0) {
            $this->dispatchOptimizationReconcile();
        }

        return $updated;
    }

    public function invalidateSampledTaskChecks(int $taskId, string $reason): int
    {
        $articles = Article::withTrashed()->where('task_id', $taskId);
        [$updated, $affectedArticleIds] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()
                ->where('inspection_scope', 'fallback_sampled')
                ->where(function (Builder $query) use ($taskId, $articles): void {
                    $query->where('task_id', $taskId)
                        ->orWhereIn('article_id', (clone $articles)->select('id'));
                }),
            'sampling_policy_disabled',
            $reason,
        );
        $affected = $this->articleIds($articles)->merge($affectedArticleIds)->unique()->values();
        $this->dispatchReconcile($affected);
        $this->invalidateOptimizationArticles($affected, $reason);

        return $updated;
    }

    public function invalidateKnowledgeBase(int $knowledgeBaseId, string $reason): int
    {
        $hasPivot = Schema::hasTable('task_knowledge_bases');
        $hasLegacyColumn = Schema::hasColumn('tasks', 'knowledge_base_id');
        $tasks = Task::withTrashed()->where(function (Builder $query) use ($knowledgeBaseId, $hasPivot, $hasLegacyColumn): void {
            if ($hasPivot) {
                $query->whereIn('id', DB::table('task_knowledge_bases')
                    ->select('task_id')
                    ->where('knowledge_base_id', $knowledgeBaseId));
            }
            if ($hasLegacyColumn) {
                $method = $hasPivot ? 'orWhere' : 'where';
                $query->{$method}('knowledge_base_id', $knowledgeBaseId);
            }
            if (! $hasPivot && ! $hasLegacyColumn) {
                $query->whereRaw('1 = 0');
            }
        });
        $articles = Article::withTrashed()->whereIn('task_id', (clone $tasks)->select('id'));
        [$updated, $affectedArticleIds] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()->where(function (Builder $query) use ($articles, $knowledgeBaseId): void {
                $query->whereIn('article_id', (clone $articles)->select('id'))
                    ->orWhereJsonContains('execution_meta->knowledge_base_ids', $knowledgeBaseId);
            }),
            'knowledge_changed',
            $reason,
        );
        $affected = $this->articleIds($articles)->merge($affectedArticleIds)->unique()->values();
        $this->dispatchReconcile($affected);
        $this->invalidateOptimizationArticles($affected, $reason);

        return $updated;
    }

    public function invalidatePrompt(int $promptId, string $reason): int
    {
        $tasks = Task::withTrashed()->where('ai_quality_prompt_id', $promptId);
        $articles = Article::withTrashed()->whereIn('task_id', (clone $tasks)->select('id'));
        [$updated, $affectedArticleIds] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()->where(function (Builder $query) use ($promptId, $articles): void {
                $query->where('prompt_id', $promptId);
                $query->orWhereIn('article_id', (clone $articles)->select('id'));
            }),
            'prompt_changed',
            $reason,
        );
        $affected = $this->articleIds($articles)->merge($affectedArticleIds)->unique()->values();
        $this->dispatchReconcile($affected);
        $this->invalidateOptimizationArticles($affected, $reason);

        return $updated;
    }

    public function invalidateModel(int $modelId, string $reason): int
    {
        $tasks = Task::withTrashed()
            ->where('ai_quality_enabled', true)
            ->where(function (Builder $query) use ($modelId): void {
                $query->where('ai_quality_model_id', $modelId)
                    ->orWhere(function (Builder $fallback) use ($modelId): void {
                        $fallback->whereNull('ai_quality_model_id')
                            ->where('ai_model_id', $modelId);
                    })
                    ->orWhere('model_selection_mode', 'smart_failover');
            });
        $articles = Article::withTrashed()->whereIn('task_id', (clone $tasks)->select('id'));
        [$updated, $affectedArticleIds] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()->where(function (Builder $query) use ($modelId, $articles): void {
                $query->where('ai_model_id', $modelId)
                    ->orWhereJsonContains('execution_meta->model_candidate_ids', $modelId)
                    ->orWhereIn('article_id', (clone $articles)->select('id'));
            }),
            'model_changed',
            $reason,
        );
        $affected = $this->articleIds($articles)->merge($affectedArticleIds)->unique()->values();
        $this->dispatchReconcile($affected);
        $this->invalidateOptimizationArticles($affected, $reason);

        return $updated;
    }

    /** @return array{int, Collection<int, int>} */
    private function invalidateChecks(Builder $query, string $errorCode, string $reason): array
    {
        $updated = 0;
        $affectedArticleIds = [];
        (clone $query)
            ->whereIn('status', ['queued', 'running', 'completed', 'failed'])
            ->select(['id', 'article_id'])
            ->chunkById(500, function (Collection $checks) use (
                $errorCode,
                $reason,
                &$updated,
                &$affectedArticleIds,
            ): void {
                $checkIds = $checks->pluck('id')->map('intval')->all();
                $articleIds = $checks->pluck('article_id')->map('intval')->filter()->unique()->values();
                if ($checkIds === []) {
                    return;
                }

                $timestamp = now();
                $updated += ArticleAiQualityCheck::query()
                    ->whereIn('id', $checkIds)
                    ->whereIn('status', ['queued', 'running', 'completed', 'failed'])
                    ->update([
                        'status' => 'stale',
                        'active_dedupe_key' => null,
                        'error_code' => $errorCode,
                        'error_message' => mb_substr($reason, 0, 500, 'UTF-8'),
                        'finished_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                ArticleAiQualitySegment::query()
                    ->whereIn('article_ai_quality_check_id', $checkIds)
                    ->whereIn('status', ['queued', 'running', 'failed'])
                    ->update([
                        'status' => 'stale',
                        'error_code' => $errorCode,
                        'error_message' => mb_substr($reason, 0, 500, 'UTF-8'),
                        'finished_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                if ($articleIds->isNotEmpty()) {
                    Article::query()
                        ->whereIn('id', $articleIds->all())
                        ->where('status', '!=', 'published')
                        ->where('review_status', '!=', 'rejected')
                        ->update([
                            'status' => 'draft',
                            'review_status' => 'pending',
                            'published_at' => null,
                            'updated_at' => $timestamp,
                        ]);
                    $articleIds->each(static function (int $articleId) use (&$affectedArticleIds): void {
                        $affectedArticleIds[$articleId] = true;
                    });
                }
            });

        return [$updated, collect(array_keys($affectedArticleIds))->values()];
    }

    /** @param iterable<int> $articleIds */
    private function invalidateOptimizationArticles(iterable $articleIds, string $reason): int
    {
        $ids = collect($articleIds)->map('intval')->filter()->unique()->values();
        if ($ids->isEmpty()) {
            return 0;
        }

        return $this->finishOptimizationRuns(
            ArticleAiOptimizationRun::query()
                ->whereIn('article_id', $ids->all())
                ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES),
            ArticleAiOptimizationRun::STATUS_STALE,
            'article_changed',
            $reason,
        );
    }

    /** @param Collection<int,int> $articleIds */
    private function cancelOptimizationArticles(Collection $articleIds, string $reason): int
    {
        if ($articleIds->isEmpty()) {
            return 0;
        }

        return $this->finishOptimizationRuns(
            ArticleAiOptimizationRun::query()
                ->whereIn('article_id', $articleIds->all())
                ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES),
            ArticleAiOptimizationRun::STATUS_CANCELLED,
            'article_unavailable',
            $reason,
        );
    }

    private function finishOptimizationRuns(
        Builder $query,
        string $status,
        string $stopReason,
        string $message,
    ): int {
        if (! Schema::hasTable((new ArticleAiOptimizationRun)->getTable())
            || ! Schema::hasTable((new ArticleAiOptimizationStep)->getTable())) {
            return 0;
        }

        $timestamp = now();
        $runIds = (clone $query)->orderBy('id')->pluck('id');
        if ($runIds->isEmpty()) {
            return 0;
        }
        $updated = ArticleAiOptimizationRun::query()
            ->whereIn('id', $runIds->all())
            ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES)
            ->update([
                'status' => $status,
                'stop_reason' => $stopReason,
                'error_message' => mb_substr($message, 0, 500, 'UTF-8'),
                'active_dedupe_key' => null,
                'lease_owner' => null,
                'lease_expires_at' => null,
                'cancelled_at' => $status === ArticleAiOptimizationRun::STATUS_CANCELLED ? $timestamp : null,
                'finished_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        $candidateIds = ArticleAiOptimizationStep::query()
            ->whereIn('run_id', $runIds->all())
            ->whereNotNull('output_check_id')
            ->pluck('output_check_id');
        if ($candidateIds->isNotEmpty()) {
            ArticleAiQualityCheck::query()
                ->whereIn('id', $candidateIds->all())
                ->whereIn('status', ['queued', 'running'])
                ->update([
                    'status' => 'cancelled',
                    'active_dedupe_key' => null,
                    'error_code' => 'optimization_cancelled',
                    'error_message' => mb_substr($message, 0, 500, 'UTF-8'),
                    'finished_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);
        }

        return $updated;
    }

    /** @return Collection<int, int> */
    private function articleIds(Builder $query): Collection
    {
        return (clone $query)->orderBy('id')->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
    }

    /** @param Collection<int, int> $articleIds */
    private function dispatchReconcile(Collection $articleIds): void
    {
        $articleIds = $articleIds
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        if ($articleIds->isEmpty()) {
            return;
        }

        $articleIds->chunk(100)->each(function (Collection $chunk): void {
            ReconcileArticleAiQualityJob::dispatch(0, 0, 100, $chunk->values()->all())
                ->onConnection('redis')
                ->onQueue((string) config('geoflow.ai_quality_backfill_queue', 'ai-quality-backfill'))
                ->afterCommit();
        });
    }

    private function dispatchOptimizationReconcile(): void
    {
        ReconcileArticleAiOptimizationJob::dispatch()
            ->onConnection('redis')
            ->onQueue((string) config('geoflow.ai_quality_optimization_bulk_queue', 'ai-content-optimization-bulk'))
            ->afterCommit();
    }
}
