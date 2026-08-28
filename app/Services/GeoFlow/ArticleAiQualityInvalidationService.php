<?php

namespace App\Services\GeoFlow;

use App\Jobs\ReconcileArticleAiQualityJob;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleAiQualitySegment;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ArticleAiQualityInvalidationService
{
    public function invalidateArticle(Article|int $article, string $reason, bool $reconcile = true): int
    {
        $articleId = $article instanceof Article ? (int) $article->id : $article;
        [$updated] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()->where('article_id', $articleId),
            'input_changed',
            $reason,
        );

        if ($reconcile) {
            ReconcileArticleAiQualityJob::dispatch($articleId, $articleId)->onQueue('geoflow')->afterCommit();
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
        if ($checkIds->isEmpty()) {
            return 0;
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

        return $updated;
    }

    public function invalidateTask(int $taskId, string $reason): int
    {
        $articleIds = Article::withTrashed()->where('task_id', $taskId)->pluck('id');
        [$updated, $affectedArticleIds] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()->where(function (Builder $query) use ($taskId, $articleIds): void {
                $query->where('task_id', $taskId);
                if ($articleIds->isNotEmpty()) {
                    $query->orWhereIn('article_id', $articleIds->all());
                }
            }),
            'policy_changed',
            $reason,
        );
        $this->dispatchReconcile($articleIds->merge($affectedArticleIds));

        return $updated;
    }

    public function invalidateKnowledgeBase(int $knowledgeBaseId, string $reason): int
    {
        $taskIds = collect();
        if (Schema::hasTable('task_knowledge_bases')) {
            $taskIds = DB::table('task_knowledge_bases')
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->pluck('task_id');
        }
        $legacyTaskIds = Schema::hasColumn('tasks', 'knowledge_base_id')
            ? DB::table('tasks')->where('knowledge_base_id', $knowledgeBaseId)->pluck('id')
            : collect();
        $taskIds = $taskIds
            ->merge($legacyTaskIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();
        $articleIds = $taskIds->isEmpty()
            ? collect()
            : Article::withTrashed()->whereIn('task_id', $taskIds->all())->pluck('id');
        [$updated, $affectedArticleIds] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()->where(function (Builder $query) use ($articleIds, $knowledgeBaseId): void {
                if ($articleIds->isNotEmpty()) {
                    $query->whereIn('article_id', $articleIds->all())
                        ->orWhereJsonContains('execution_meta->knowledge_base_ids', $knowledgeBaseId);

                    return;
                }

                $query->whereJsonContains('execution_meta->knowledge_base_ids', $knowledgeBaseId);
            }),
            'knowledge_changed',
            $reason,
        );
        $this->dispatchReconcile($articleIds->merge($affectedArticleIds));

        return $updated;
    }

    public function invalidatePrompt(int $promptId, string $reason): int
    {
        $taskIds = Task::withTrashed()->where('ai_quality_prompt_id', $promptId)->pluck('id');
        $articleIds = $taskIds->isEmpty()
            ? collect()
            : Article::withTrashed()->whereIn('task_id', $taskIds->all())->pluck('id');
        [$updated, $affectedArticleIds] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()->where(function (Builder $query) use ($promptId, $articleIds): void {
                $query->where('prompt_id', $promptId);
                if ($articleIds->isNotEmpty()) {
                    $query->orWhereIn('article_id', $articleIds->all());
                }
            }),
            'prompt_changed',
            $reason,
        );
        $this->dispatchReconcile($articleIds->merge($affectedArticleIds));

        return $updated;
    }

    public function invalidateModel(int $modelId, string $reason): int
    {
        $taskIds = Task::withTrashed()
            ->where('ai_quality_enabled', true)
            ->where(function (Builder $query) use ($modelId): void {
                $query->where('ai_quality_model_id', $modelId)
                    ->orWhere(function (Builder $fallback) use ($modelId): void {
                        $fallback->whereNull('ai_quality_model_id')
                            ->where('ai_model_id', $modelId);
                    })
                    ->orWhere('model_selection_mode', 'smart_failover');
            })
            ->pluck('id');
        $articleIds = $taskIds->isEmpty()
            ? collect()
            : Article::withTrashed()->whereIn('task_id', $taskIds->all())->pluck('id');
        [$updated, $affectedArticleIds] = $this->invalidateChecks(
            ArticleAiQualityCheck::query()->where(function (Builder $query) use ($modelId, $articleIds): void {
                $query->where('ai_model_id', $modelId)
                    ->orWhereJsonContains('execution_meta->model_candidate_ids', $modelId);
                if ($articleIds->isNotEmpty()) {
                    $query->orWhereIn('article_id', $articleIds->all());
                }
            }),
            'model_changed',
            $reason,
        );
        $this->dispatchReconcile($articleIds->merge($affectedArticleIds));

        return $updated;
    }

    /** @return array{int, Collection<int, int>} */
    private function invalidateChecks(Builder $query, string $errorCode, string $reason): array
    {
        $checkIds = (clone $query)
            ->whereIn('status', ['queued', 'running', 'completed', 'failed'])
            ->pluck('id');
        if ($checkIds->isEmpty()) {
            return [0, collect()];
        }

        $articleIds = ArticleAiQualityCheck::query()
            ->whereIn('id', $checkIds->all())
            ->pluck('article_id')
            ->map(static fn ($id): int => (int) $id)
            ->unique()
            ->values();
        $updated = ArticleAiQualityCheck::query()
            ->whereIn('id', $checkIds->all())
            ->whereIn('status', ['queued', 'running', 'completed', 'failed'])
            ->update([
                'status' => 'stale',
                'active_dedupe_key' => null,
                'error_code' => $errorCode,
                'error_message' => mb_substr($reason, 0, 500, 'UTF-8'),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        ArticleAiQualitySegment::query()
            ->whereIn('article_ai_quality_check_id', $checkIds->all())
            ->whereIn('status', ['queued', 'running', 'failed'])
            ->update([
                'status' => 'stale',
                'error_code' => $errorCode,
                'error_message' => mb_substr($reason, 0, 500, 'UTF-8'),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
        Article::query()
            ->whereIn('id', $articleIds->all())
            ->where('status', '!=', 'published')
            ->where('review_status', '!=', 'rejected')
            ->update([
                'status' => 'draft',
                'review_status' => 'pending',
                'published_at' => null,
                'updated_at' => now(),
            ]);

        return [$updated, $articleIds];
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

        ReconcileArticleAiQualityJob::dispatch((int) $articleIds->min(), (int) $articleIds->max())
            ->onQueue('geoflow')
            ->afterCommit();
    }
}
