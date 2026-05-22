<?php

namespace App\Services\Admin\Analytics;

use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyticsOverviewService
{
    /**
     * @return array<string, int|float>
     */
    public function globalOverview(): array
    {
        $totalArticles = (int) Article::query()->whereNull('deleted_at')->count();
        $publishedArticles = (int) Article::query()
            ->where('status', 'published')
            ->whereNull('deleted_at')
            ->count();
        $aiGeneratedArticles = (int) Article::query()
            ->where('is_ai_generated', 1)
            ->whereNull('deleted_at')
            ->count();
        $today = Carbon::today();
        $runningJobs = (int) TaskRun::query()->where('status', 'running')->count();
        $pendingJobs = (int) TaskRun::query()->where('status', 'pending')->count();

        return [
            'total_articles' => $totalArticles,
            'today_articles' => (int) Article::query()
                ->whereNull('deleted_at')
                ->whereDate('created_at', $today)
                ->count(),
            'published_articles' => $publishedArticles,
            'publish_rate' => $totalArticles > 0 ? round(($publishedArticles * 100) / $totalArticles, 1) : 0.0,
            'ai_generated_articles' => $aiGeneratedArticles,
            'ai_generated_ratio' => $totalArticles > 0 ? round(($aiGeneratedArticles * 100) / $totalArticles, 1) : 0.0,
            'total_views' => (int) (Article::query()->whereNull('deleted_at')->sum('view_count') ?? 0),
            'today_views' => $this->todayViews($today),
            'running_jobs' => $runningJobs,
            'pending_jobs' => $pendingJobs,
            'total_tasks' => (int) Task::query()->count(),
            'active_tasks' => (int) Task::query()->where('status', 'active')->count(),
            'active_ai_models' => (int) AiModel::query()->where('status', 'active')->count(),
            'material_total' => (int) Keyword::query()->count() + (int) Title::query()->count() + (int) Image::query()->count(),
            'pending_review' => (int) Article::query()
                ->where('review_status', 'pending')
                ->whereNull('deleted_at')
                ->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function kpis(AnalyticsFilter $filter): array
    {
        $articles = $this->filteredArticles($filter);
        $published = $this->filteredArticles($filter)
            ->where('status', 'published')
            ->whereBetween('published_at', [$filter->start(), $filter->end()]);
        $taskRuns = $this->filteredTaskRuns($filter);
        $distributions = $this->filteredDistributions($filter);

        return [
            'articles' => (int) $articles->count(),
            'published' => (int) $published->count(),
            'running_tasks' => (int) (clone $taskRuns)->where('status', 'running')->count(),
            'failed_tasks' => (int) (clone $taskRuns)->where('status', 'failed')->count(),
            'ai_calls' => (int) AiModel::query()->sum('used_today'),
            'distribution_failed' => (int) (clone $distributions)->where('status', 'failed')->count(),
            'distribution_pending' => (int) (clone $distributions)->whereIn('status', ['queued', 'sending'])->count(),
            'total_views' => (int) $this->filteredArticles($filter)->sum('view_count'),
        ];
    }

    /**
     * @return list<array{date: string, created: int, published: int}>
     */
    public function publicationTrend(AnalyticsFilter $filter): array
    {
        $days = $this->days($filter);

        return array_map(function (Carbon $day) use ($filter): array {
            $start = $day->copy()->startOfDay();
            $end = $day->copy()->endOfDay();

            return [
                'date' => $day->toDateString(),
                'created' => (int) $this->filteredArticles($filter)->whereBetween('created_at', [$start, $end])->count(),
                'published' => (int) $this->filteredArticles($filter)->whereBetween('published_at', [$start, $end])->count(),
            ];
        }, $days);
    }

    /**
     * @return list<array{date: string, completed: int, failed: int, running: int, pending: int}>
     */
    public function taskTrend(AnalyticsFilter $filter): array
    {
        return array_map(function (Carbon $day) use ($filter): array {
            $start = $day->copy()->startOfDay();
            $end = $day->copy()->endOfDay();
            $base = $this->filteredTaskRuns($filter)->whereBetween('created_at', [$start, $end]);

            return [
                'date' => $day->toDateString(),
                'completed' => (int) (clone $base)->where('status', 'completed')->count(),
                'failed' => (int) (clone $base)->where('status', 'failed')->count(),
                'running' => (int) (clone $base)->where('status', 'running')->count(),
                'pending' => (int) (clone $base)->where('status', 'pending')->count(),
            ];
        }, $this->days($filter));
    }

    /**
     * @return array{max: int, stages: list<array{key: string, label: string, count: int, tone: string}>}
     */
    public function contentFunnel(AnalyticsFilter $filter): array
    {
        $stages = [
            [
                'key' => 'created',
                'label' => __('admin.analytics.funnel.created'),
                'count' => (int) $this->filteredArticles($filter)->count(),
                'tone' => 'blue',
            ],
            [
                'key' => 'draft',
                'label' => __('admin.analytics.funnel.draft'),
                'count' => (int) $this->filteredArticles($filter)->where('status', 'draft')->count(),
                'tone' => 'amber',
            ],
            [
                'key' => 'review',
                'label' => __('admin.analytics.funnel.review'),
                'count' => (int) $this->filteredArticles($filter)->where('review_status', 'pending')->count(),
                'tone' => 'purple',
            ],
            [
                'key' => 'published',
                'label' => __('admin.analytics.funnel.published'),
                'count' => (int) $this->filteredArticles($filter)->where('status', 'published')->count(),
                'tone' => 'green',
            ],
            [
                'key' => 'viewed',
                'label' => __('admin.analytics.funnel.viewed'),
                'count' => (int) $this->filteredArticles($filter)->where('view_count', '>', 0)->count(),
                'tone' => 'slate',
            ],
        ];

        return [
            'max' => max(1, ...array_column($stages, 'count')),
            'stages' => $stages,
        ];
    }

    /**
     * @return array{total: int, synced: int, failed: int, pending: int, rows: list<array{name: string, total: int, synced: int, failed: int, pending: int}>}
     */
    public function distributionSummary(AnalyticsFilter $filter): array
    {
        $query = $this->filteredDistributions($filter);
        $rows = (clone $query)
            ->join('distribution_channels as dc', 'article_distributions.distribution_channel_id', '=', 'dc.id')
            ->selectRaw('dc.name as name, COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN article_distributions.status = 'synced' THEN 1 ELSE 0 END) as synced")
            ->selectRaw("SUM(CASE WHEN article_distributions.status = 'failed' THEN 1 ELSE 0 END) as failed")
            ->selectRaw("SUM(CASE WHEN article_distributions.status IN ('queued', 'sending') THEN 1 ELSE 0 END) as pending")
            ->groupBy('dc.id', 'dc.name')
            ->orderByDesc('total')
            ->limit(8)
            ->get()
            ->map(fn ($row): array => [
                'name' => (string) $row->name,
                'total' => (int) $row->total,
                'synced' => (int) $row->synced,
                'failed' => (int) $row->failed,
                'pending' => (int) $row->pending,
            ])
            ->all();

        return [
            'total' => (int) $this->filteredDistributions($filter)->count(),
            'synced' => (int) $this->filteredDistributions($filter)->where('status', 'synced')->count(),
            'failed' => (int) $this->filteredDistributions($filter)->where('status', 'failed')->count(),
            'pending' => (int) $this->filteredDistributions($filter)->whereIn('status', ['queued', 'sending'])->count(),
            'rows' => $rows,
        ];
    }

    /**
     * @return list<object>
     */
    public function topContent(AnalyticsFilter $filter, int $limit = 10): array
    {
        return $this->filteredArticles($filter)
            ->leftJoin('categories as c', 'articles.category_id', '=', 'c.id')
            ->orderByDesc('articles.view_count')
            ->orderByDesc('articles.created_at')
            ->select('articles.id', 'articles.title', 'articles.slug', 'articles.status', 'articles.view_count', 'articles.created_at', 'c.name as category_name')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return array{used_today: int, total_used: int, active_models: int, model_rows: list<object>}
     */
    public function aiUsageSummary(AnalyticsFilter $filter): array
    {
        return [
            'used_today' => (int) AiModel::query()->sum('used_today'),
            'total_used' => (int) AiModel::query()->sum('total_used'),
            'active_models' => (int) AiModel::query()->where('status', 'active')->count(),
            'model_rows' => AiModel::query()
                ->where('status', 'active')
                ->orderByDesc('used_today')
                ->select('id', 'name', 'model_id', 'model_type', 'used_today', 'total_used')
                ->limit(5)
                ->get()
                ->all(),
        ];
    }

    /**
     * @return list<array{name: string, count: int}>
     */
    public function categoryDistribution(AnalyticsFilter $filter): array
    {
        return $this->filteredArticles($filter)
            ->leftJoin('categories as c', 'articles.category_id', '=', 'c.id')
            ->select('c.name')
            ->selectRaw('COUNT(articles.id) as count')
            ->groupBy('c.id', 'c.name')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'name' => (string) ($row->name ?: __('admin.dashboard.uncategorized')),
                'count' => (int) $row->count,
            ])
            ->all();
    }

    /**
     * @return array{avg_generation_time: float, success_rate: float, daily_quota_used: int}
     */
    public function performanceStats(AnalyticsFilter $filter): array
    {
        $taskRuns = $this->filteredTaskRuns($filter);
        $completed = (int) (clone $taskRuns)->where('status', 'completed')->count();
        $failed = (int) (clone $taskRuns)->where('status', 'failed')->count();
        $totalFinished = $completed + $failed;
        $avg = (clone $taskRuns)
            ->where('duration_ms', '>', 0)
            ->selectRaw('AVG(duration_ms) / 1000.0 as avg_time')
            ->value('avg_time');

        return [
            'avg_generation_time' => (float) ($avg ?? 0),
            'success_rate' => $totalFinished > 0 ? round(($completed * 100.0) / $totalFinished, 2) : 0.0,
            'daily_quota_used' => (int) $this->filteredArticles($filter)->where('is_ai_generated', 1)->count(),
        ];
    }

    /**
     * @return list<object>
     */
    public function latestArticles(AnalyticsFilter $filter, int $limit = 5): array
    {
        return $this->filteredArticles($filter)
            ->leftJoin('categories as c', 'articles.category_id', '=', 'c.id')
            ->orderByDesc('articles.created_at')
            ->select('articles.id', 'articles.title', 'articles.status', 'articles.is_ai_generated', 'articles.created_at', 'c.name as category_name')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return array{
     *   active_tasks: int,
     *   paused_tasks: int,
     *   running_jobs: int,
     *   pending_jobs: int,
     *   failed_jobs: int,
     *   recent_failures: list<object>
     * }
     */
    public function taskHealth(AnalyticsFilter $filter): array
    {
        $taskQuery = Task::query();
        if ($filter->taskId !== null) {
            $taskQuery->where('id', $filter->taskId);
        }

        $taskStatusCounts = (clone $taskQuery)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();
        $jobStatusCounts = $this->filteredTaskRuns($filter)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();
        $recentFailures = DB::table('task_runs as tr')
            ->leftJoin('tasks as t', 'tr.task_id', '=', 't.id')
            ->where('tr.status', 'failed')
            ->whereBetween('tr.created_at', [$filter->start(), $filter->end()])
            ->when($filter->taskId !== null, fn ($query) => $query->where('tr.task_id', $filter->taskId))
            ->orderByDesc('tr.created_at')
            ->select('tr.id', 'tr.error_message', 'tr.created_at', 't.name as task_name')
            ->limit(4)
            ->get()
            ->all();

        return [
            'active_tasks' => (int) ($taskStatusCounts['active'] ?? 0),
            'paused_tasks' => (int) (($taskStatusCounts['paused'] ?? 0) + ($taskStatusCounts['inactive'] ?? 0)),
            'running_jobs' => (int) ($jobStatusCounts['running'] ?? 0),
            'pending_jobs' => (int) ($jobStatusCounts['pending'] ?? 0),
            'failed_jobs' => (int) ($jobStatusCounts['failed'] ?? 0),
            'recent_failures' => $recentFailures,
        ];
    }

    /**
     * @return array<string, int>
     */
    public function materialHealth(): array
    {
        $knowledgeChunks = (int) KnowledgeChunk::query()->count();
        $vectorizedChunks = (int) KnowledgeChunk::query()
            ->where(function ($query): void {
                $query->whereNotNull('embedding_json')
                    ->orWhereNotNull('embedding_model_id')
                    ->orWhereNotNull('embedding_vector');
            })
            ->count();

        return [
            'keyword_libraries' => (int) KeywordLibrary::query()->count(),
            'title_libraries' => (int) TitleLibrary::query()->count(),
            'knowledge_bases' => (int) KnowledgeBase::query()->count(),
            'image_libraries' => (int) ImageLibrary::query()->count(),
            'authors' => (int) Author::query()->count(),
            'knowledge_chunks' => $knowledgeChunks,
            'vectorized_chunks' => $vectorizedChunks,
            'unvectorized_chunks' => max(0, $knowledgeChunks - $vectorizedChunks),
        ];
    }

    /**
     * @return array{chat_models: int, embedding_models: int, used_today: int, total_used: int, active_models: list<object>}
     */
    public function aiHealth(): array
    {
        $activeModels = AiModel::query()->where('status', 'active');

        return [
            'chat_models' => (int) (clone $activeModels)
                ->where(function ($query): void {
                    $query->whereNull('model_type')
                        ->orWhere('model_type', '')
                        ->orWhere('model_type', 'chat');
                })
                ->count(),
            'embedding_models' => (int) (clone $activeModels)
                ->where('model_type', 'embedding')
                ->count(),
            'used_today' => (int) AiModel::query()->sum('used_today'),
            'total_used' => (int) AiModel::query()->sum('total_used'),
            'active_models' => AiModel::query()
                ->where('status', 'active')
                ->orderBy('failover_priority')
                ->orderBy('id')
                ->select('id', 'name', 'model_id', 'model_type', 'used_today', 'daily_limit')
                ->limit(5)
                ->get()
                ->all(),
        ];
    }

    /**
     * @return array{total: int, running: int, completed: int, failed: int, waiting_import: int, recent_jobs: list<object>}
     */
    public function urlImportHealth(AnalyticsFilter $filter): array
    {
        $query = UrlImportJob::query()->whereBetween('created_at', [$filter->start(), $filter->end()]);
        $statusCounts = (clone $query)
            ->selectRaw('status, COUNT(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();

        return [
            'total' => (int) array_sum($statusCounts),
            'running' => (int) (($statusCounts['running'] ?? 0) + ($statusCounts['queued'] ?? 0)),
            'completed' => (int) ($statusCounts['completed'] ?? 0),
            'failed' => (int) ($statusCounts['failed'] ?? 0),
            'waiting_import' => (int) (clone $query)
                ->where('status', 'completed')
                ->where('current_step', '!=', 'imported')
                ->count(),
            'recent_jobs' => (clone $query)
                ->orderByDesc('created_at')
                ->select('id', 'source_domain', 'page_title', 'status', 'current_step', 'progress_percent', 'created_at')
                ->limit(5)
                ->get()
                ->all(),
        ];
    }

    /**
     * @return Builder<Article>
     */
    private function filteredArticles(AnalyticsFilter $filter): Builder
    {
        $query = Article::query()
            ->whereNull('deleted_at')
            ->whereBetween('articles.created_at', [$filter->start(), $filter->end()]);

        if ($filter->taskId !== null) {
            $query->where('task_id', $filter->taskId);
        }
        if ($filter->categoryId !== null) {
            $query->where('articles.category_id', $filter->categoryId);
        }
        if ($filter->articleId !== null) {
            $query->where('articles.id', $filter->articleId);
        }

        return $query;
    }

    private function todayViews(Carbon $today): int
    {
        if (! Schema::hasTable('view_logs')) {
            return 0;
        }

        return (int) DB::table('view_logs')
            ->whereDate('created_at', $today)
            ->count();
    }

    /**
     * @return Builder<TaskRun>
     */
    private function filteredTaskRuns(AnalyticsFilter $filter): Builder
    {
        $query = TaskRun::query()->whereBetween('created_at', [$filter->start(), $filter->end()]);

        if ($filter->taskId !== null) {
            $query->where('task_id', $filter->taskId);
        }

        return $query;
    }

    /**
     * @return Builder<ArticleDistribution>
     */
    private function filteredDistributions(AnalyticsFilter $filter): Builder
    {
        $query = ArticleDistribution::query()->whereBetween('article_distributions.created_at', [$filter->start(), $filter->end()]);

        if ($filter->channelId !== null) {
            $query->where('distribution_channel_id', $filter->channelId);
        }

        if ($filter->articleId !== null) {
            $query->where('article_id', $filter->articleId);
        }

        return $query;
    }

    /**
     * @return list<Carbon>
     */
    private function days(AnalyticsFilter $filter): array
    {
        $days = [];
        $cursor = $filter->dateFrom->copy()->startOfDay();
        $end = $filter->dateTo->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        return $days;
    }
}
