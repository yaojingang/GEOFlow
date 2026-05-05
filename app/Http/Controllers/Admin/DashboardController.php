<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\ImageLibrary;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Models\UrlImportJob;
use App\Support\AdminWeb;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * 管理首页仪表盘：汇总文章、任务队列（task_runs）、素材与性能指标，并输出趋势图数据。
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        $stats = $this->buildStats();
        $todayStats = $this->buildTodayStats();
        $weekStats = $this->buildWeekStats();
        $categoryDistribution = $this->buildCategoryDistribution();
        $latestArticles = $this->buildLatestArticles();
        $articleTrend = $this->buildArticleTrendSeries();
        $trendChart = $this->buildArticleTrendChartPaths($articleTrend);
        $performanceStats = $this->buildPerformanceStats(
            (int) ($stats['completed_tasks'] ?? 0),
            (int) ($stats['failed_jobs'] ?? 0)
        );
        $contentFunnel = $this->buildContentFunnel($stats);
        $taskHealth = $this->buildTaskHealth();
        $materialHealth = $this->buildMaterialHealth();
        $aiHealth = $this->buildAiHealth();
        $urlImportHealth = $this->buildUrlImportHealth();
        $popularArticles = $this->buildPopularArticles();
        $todoItems = $this->buildTodoItems($stats, $materialHealth, $aiHealth, $urlImportHealth);

        return view('admin.dashboard', [
            'pageTitle' => __('admin.dashboard.page_title'),
            'activeMenu' => 'dashboard',
            'adminSiteName' => AdminWeb::siteName(),
            'stats' => $stats,
            'today_stats' => $todayStats,
            'week_stats' => $weekStats,
            'category_distribution' => $categoryDistribution,
            'latest_articles' => $latestArticles,
            'article_trend' => $articleTrend,
            'trend_chart' => $trendChart,
            'performance_stats' => $performanceStats,
            'content_funnel' => $contentFunnel,
            'task_health' => $taskHealth,
            'material_health' => $materialHealth,
            'ai_health' => $aiHealth,
            'url_import_health' => $urlImportHealth,
            'popular_articles' => $popularArticles,
            'todo_items' => $todoItems,
        ]);
    }

    /**
     * @return array<string, int|float>
     */
    private function buildStats(): array
    {
        $defaults = [
            'total_articles' => 0,
            'published_articles' => 0,
            'draft_articles' => 0,
            'ai_generated_articles' => 0,
            'total_tasks' => 0,
            'active_tasks' => 0,
            'completed_tasks' => 0,
            'running_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'total_keywords' => 0,
            'total_titles' => 0,
            'total_images' => 0,
            'total_categories' => 0,
            'active_ai_models' => 0,
            'total_prompts' => 0,
            'pending_review' => 0,
            'approved_articles' => 0,
            'total_views' => 0,
            'total_likes' => 0,
        ];

        try {
            $jobStatusCounts = TaskRun::query()
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->all();
            $defaults['running_jobs'] = (int) ($jobStatusCounts['running'] ?? 0);
            $defaults['pending_jobs'] = (int) ($jobStatusCounts['pending'] ?? 0);
            $defaults['failed_jobs'] = (int) ($jobStatusCounts['failed'] ?? 0);
            $defaults['completed_tasks'] = (int) ($jobStatusCounts['completed'] ?? 0);

            $defaults['total_articles'] = (int) Article::query()->whereNull('deleted_at')->count();
            $defaults['published_articles'] = (int) Article::query()->where('status', 'published')->whereNull('deleted_at')->count();
            $defaults['draft_articles'] = (int) Article::query()->where('status', 'draft')->whereNull('deleted_at')->count();
            $defaults['ai_generated_articles'] = (int) Article::query()->where('is_ai_generated', 1)->whereNull('deleted_at')->count();
            $defaults['pending_review'] = (int) Article::query()->where('review_status', 'pending')->whereNull('deleted_at')->count();
            $defaults['approved_articles'] = (int) Article::query()->where('review_status', 'approved')->whereNull('deleted_at')->count();
            $defaults['total_views'] = (int) (Article::query()->whereNull('deleted_at')->sum('view_count') ?? 0);
            if (Schema::hasColumn('articles', 'like_count')) {
                $defaults['total_likes'] = (int) (Article::query()->whereNull('deleted_at')->sum('like_count') ?? 0);
            }

            $defaults['total_tasks'] = (int) Task::query()->count();
            $defaults['active_tasks'] = (int) Task::query()->where('status', 'active')->count();
            $defaults['total_keywords'] = (int) Keyword::query()->count();
            $defaults['total_titles'] = (int) Title::query()->count();
            $defaults['total_images'] = (int) Image::query()->count();
            $defaults['total_categories'] = (int) Category::query()->count();
            $defaults['active_ai_models'] = (int) AiModel::query()->where('status', 'active')->count();
            $defaults['total_prompts'] = (int) Prompt::query()->count();
        } catch (\Throwable) {
            return $defaults;
        }

        return $defaults;
    }

    /**
     * @return array<string, int>
     */
    private function buildTodayStats(): array
    {
        $out = ['today_articles' => 0, 'today_tasks' => 0, 'today_views' => 0];
        try {
            $today = Carbon::today();
            $out['today_articles'] = (int) Article::query()
                ->whereNull('deleted_at')
                ->whereDate('created_at', $today)
                ->count();
            $out['today_tasks'] = (int) Task::query()
                ->whereDate('created_at', $today)
                ->count();
            $out['today_views'] = (int) DB::table('view_logs')
                ->whereDate('created_at', $today)
                ->count();
        } catch (\Throwable) {
            // ignore
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function buildWeekStats(): array
    {
        $out = ['week_articles' => 0, 'week_tasks' => 0];
        try {
            $since = now()->subDays(7);
            $out['week_articles'] = (int) Article::query()
                ->whereNull('deleted_at')
                ->where('created_at', '>=', $since)
                ->count();
            $out['week_tasks'] = (int) Task::query()->where('created_at', '>=', $since)->count();
        } catch (\Throwable) {
            // ignore
        }

        return $out;
    }

    /**
     * 分类维度文章分布：分类左连未软删文章，按篇数降序取前 10。
     *
     * @return list<array{name: string, count: int}>
     */
    private function buildCategoryDistribution(): array
    {
        try {
            return DB::table('categories as c')
                ->leftJoin('articles as a', function ($join): void {
                    $join->on('c.id', '=', 'a.category_id')
                        ->whereNull('a.deleted_at');
                })
                ->select('c.name', DB::raw('COUNT(a.id) as count'))
                ->groupBy('c.id', 'c.name')
                ->orderByDesc('count')
                ->limit(10)
                ->get()
                ->map(fn ($r) => ['name' => (string) $r->name, 'count' => (int) $r->count])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 最新文章列表：按创建时间降序 5 条，分类左连（无分类亦可）。
     *
     * @return list<object>
     */
    private function buildLatestArticles(): array
    {
        try {
            return DB::table('articles as a')
                ->leftJoin('categories as c', 'a.category_id', '=', 'c.id')
                ->whereNull('a.deleted_at')
                ->orderByDesc('a.created_at')
                ->select('a.*', 'c.name as category_name')
                ->limit(5)
                ->get()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * 内容生产漏斗：从素材供给到草稿、审核、发布和产生浏览的转化概览。
     *
     * @param  array<string, int|float>  $stats
     * @return array{max: int, stages: list<array{key: string, label: string, count: int, tone: string}>}
     */
    private function buildContentFunnel(array $stats): array
    {
        $viewedArticles = 0;
        try {
            $viewedArticles = (int) Article::query()
                ->whereNull('deleted_at')
                ->where('view_count', '>', 0)
                ->count();
        } catch (\Throwable) {
            // ignore
        }

        $stages = [
            [
                'key' => 'titles',
                'label' => __('admin.dashboard.funnel_titles'),
                'count' => (int) ($stats['total_titles'] ?? 0),
                'tone' => 'blue',
            ],
            [
                'key' => 'drafts',
                'label' => __('admin.dashboard.funnel_drafts'),
                'count' => (int) ($stats['draft_articles'] ?? 0),
                'tone' => 'amber',
            ],
            [
                'key' => 'pending_review',
                'label' => __('admin.dashboard.funnel_pending_review'),
                'count' => (int) ($stats['pending_review'] ?? 0),
                'tone' => 'purple',
            ],
            [
                'key' => 'published',
                'label' => __('admin.dashboard.funnel_published'),
                'count' => (int) ($stats['published_articles'] ?? 0),
                'tone' => 'green',
            ],
            [
                'key' => 'viewed',
                'label' => __('admin.dashboard.funnel_viewed'),
                'count' => $viewedArticles,
                'tone' => 'slate',
            ],
        ];

        return [
            'max' => max(1, ...array_column($stages, 'count')),
            'stages' => $stages,
        ];
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
    private function buildTaskHealth(): array
    {
        $out = [
            'active_tasks' => 0,
            'paused_tasks' => 0,
            'running_jobs' => 0,
            'pending_jobs' => 0,
            'failed_jobs' => 0,
            'recent_failures' => [],
        ];

        try {
            $taskStatusCounts = Task::query()
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->all();
            $out['active_tasks'] = (int) ($taskStatusCounts['active'] ?? 0);
            $out['paused_tasks'] = (int) (($taskStatusCounts['paused'] ?? 0) + ($taskStatusCounts['inactive'] ?? 0));

            $jobStatusCounts = TaskRun::query()
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->all();
            $out['running_jobs'] = (int) ($jobStatusCounts['running'] ?? 0);
            $out['pending_jobs'] = (int) ($jobStatusCounts['pending'] ?? 0);
            $out['failed_jobs'] = (int) ($jobStatusCounts['failed'] ?? 0);

            $out['recent_failures'] = DB::table('task_runs as tr')
                ->leftJoin('tasks as t', 'tr.task_id', '=', 't.id')
                ->where('tr.status', 'failed')
                ->orderByDesc('tr.created_at')
                ->select('tr.id', 'tr.error_message', 'tr.created_at', 't.name as task_name')
                ->limit(4)
                ->get()
                ->all();
        } catch (\Throwable) {
            // ignore
        }

        return $out;
    }

    /**
     * @return array<string, int>
     */
    private function buildMaterialHealth(): array
    {
        $out = [
            'keyword_libraries' => 0,
            'title_libraries' => 0,
            'knowledge_bases' => 0,
            'image_libraries' => 0,
            'authors' => 0,
            'knowledge_chunks' => 0,
            'vectorized_chunks' => 0,
            'unvectorized_chunks' => 0,
        ];

        try {
            $out['keyword_libraries'] = (int) KeywordLibrary::query()->count();
            $out['title_libraries'] = (int) TitleLibrary::query()->count();
            $out['knowledge_bases'] = (int) KnowledgeBase::query()->count();
            $out['image_libraries'] = (int) ImageLibrary::query()->count();
            $out['authors'] = (int) Author::query()->count();
            $out['knowledge_chunks'] = (int) KnowledgeChunk::query()->count();
            $out['vectorized_chunks'] = (int) KnowledgeChunk::query()
                ->where(function ($query): void {
                    $query->whereNotNull('embedding_json')
                        ->orWhereNotNull('embedding_model_id')
                        ->orWhereNotNull('embedding_vector');
                })
                ->count();
            $out['unvectorized_chunks'] = max(0, $out['knowledge_chunks'] - $out['vectorized_chunks']);
        } catch (\Throwable) {
            // ignore
        }

        return $out;
    }

    /**
     * @return array{chat_models: int, embedding_models: int, used_today: int, total_used: int, active_models: list<object>}
     */
    private function buildAiHealth(): array
    {
        $out = [
            'chat_models' => 0,
            'embedding_models' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'active_models' => [],
        ];

        try {
            $activeModels = AiModel::query()->where('status', 'active');
            $out['chat_models'] = (int) (clone $activeModels)
                ->where(function ($query): void {
                    $query->whereNull('model_type')
                        ->orWhere('model_type', '')
                        ->orWhere('model_type', 'chat');
                })
                ->count();
            $out['embedding_models'] = (int) (clone $activeModels)
                ->where('model_type', 'embedding')
                ->count();
            $out['used_today'] = (int) AiModel::query()->sum('used_today');
            $out['total_used'] = (int) AiModel::query()->sum('total_used');
            $out['active_models'] = AiModel::query()
                ->where('status', 'active')
                ->orderBy('failover_priority')
                ->orderBy('id')
                ->select('id', 'name', 'model_id', 'model_type', 'used_today', 'daily_limit')
                ->limit(5)
                ->get()
                ->all();
        } catch (\Throwable) {
            // ignore
        }

        return $out;
    }

    /**
     * @return array{total: int, running: int, completed: int, failed: int, waiting_import: int, recent_jobs: list<object>}
     */
    private function buildUrlImportHealth(): array
    {
        $out = [
            'total' => 0,
            'running' => 0,
            'completed' => 0,
            'failed' => 0,
            'waiting_import' => 0,
            'recent_jobs' => [],
        ];

        try {
            $statusCounts = UrlImportJob::query()
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->all();
            $out['total'] = (int) array_sum($statusCounts);
            $out['running'] = (int) (($statusCounts['running'] ?? 0) + ($statusCounts['queued'] ?? 0));
            $out['completed'] = (int) ($statusCounts['completed'] ?? 0);
            $out['failed'] = (int) ($statusCounts['failed'] ?? 0);
            $out['waiting_import'] = (int) UrlImportJob::query()
                ->where('status', 'completed')
                ->where('current_step', '!=', 'imported')
                ->count();
            $out['recent_jobs'] = UrlImportJob::query()
                ->orderByDesc('created_at')
                ->select('id', 'source_domain', 'page_title', 'status', 'current_step', 'progress_percent', 'created_at')
                ->limit(5)
                ->get()
                ->all();
        } catch (\Throwable) {
            // ignore
        }

        return $out;
    }

    /**
     * @return list<object>
     */
    private function buildPopularArticles(): array
    {
        try {
            return DB::table('articles as a')
                ->leftJoin('categories as c', 'a.category_id', '=', 'c.id')
                ->whereNull('a.deleted_at')
                ->orderByDesc('a.view_count')
                ->orderByDesc('a.created_at')
                ->select('a.id', 'a.title', 'a.view_count', 'a.status', 'c.name as category_name')
                ->limit(5)
                ->get()
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<string, int|float>  $stats
     * @param  array<string, int>  $materialHealth
     * @param  array{chat_models: int, embedding_models: int, used_today: int, total_used: int, active_models: list<object>}  $aiHealth
     * @param  array{total: int, running: int, completed: int, failed: int, waiting_import: int, recent_jobs: list<object>}  $urlImportHealth
     * @return list<array{label: string, value: int, href: string, tone: string}>
     */
    private function buildTodoItems(array $stats, array $materialHealth, array $aiHealth, array $urlImportHealth): array
    {
        $items = [];

        if ((int) ($stats['failed_jobs'] ?? 0) > 0) {
            $items[] = [
                'label' => __('admin.dashboard.todo_failed_jobs'),
                'value' => (int) ($stats['failed_jobs'] ?? 0),
                'href' => route('admin.tasks.index'),
                'tone' => 'red',
            ];
        }
        if ((int) ($stats['pending_review'] ?? 0) > 0) {
            $items[] = [
                'label' => __('admin.dashboard.todo_pending_review'),
                'value' => (int) ($stats['pending_review'] ?? 0),
                'href' => route('admin.articles.index', ['review_status' => 'pending']),
                'tone' => 'amber',
            ];
        }
        if ((int) ($aiHealth['chat_models'] ?? 0) === 0) {
            $items[] = [
                'label' => __('admin.dashboard.todo_no_chat_model'),
                'value' => 0,
                'href' => route('admin.ai-models.index'),
                'tone' => 'red',
            ];
        }
        if ((int) ($aiHealth['embedding_models'] ?? 0) === 0 && (int) ($materialHealth['knowledge_bases'] ?? 0) > 0) {
            $items[] = [
                'label' => __('admin.dashboard.todo_no_embedding_model'),
                'value' => 0,
                'href' => route('admin.ai-models.index'),
                'tone' => 'amber',
            ];
        }
        if ((int) ($materialHealth['unvectorized_chunks'] ?? 0) > 0) {
            $items[] = [
                'label' => __('admin.dashboard.todo_unvectorized_chunks'),
                'value' => (int) ($materialHealth['unvectorized_chunks'] ?? 0),
                'href' => route('admin.knowledge-bases.index'),
                'tone' => 'blue',
            ];
        }
        if ((int) ($stats['total_titles'] ?? 0) < 20) {
            $items[] = [
                'label' => __('admin.dashboard.todo_low_titles'),
                'value' => (int) ($stats['total_titles'] ?? 0),
                'href' => route('admin.title-libraries.index'),
                'tone' => 'slate',
            ];
        }
        if ((int) ($urlImportHealth['failed'] ?? 0) > 0) {
            $items[] = [
                'label' => __('admin.dashboard.todo_url_import_failed'),
                'value' => (int) ($urlImportHealth['failed'] ?? 0),
                'href' => route('admin.url-import.history'),
                'tone' => 'red',
            ];
        }

        return array_slice($items, 0, 6);
    }

    /**
     * 最近 7 个自然日（含今天）每日新增文章数，用于趋势图横轴。
     *
     * @return list<array{date: string, count: int}>
     */
    private function buildArticleTrendSeries(): array
    {
        $series = [];
        for ($i = 6; $i >= 0; $i--) {
            $day = now()->subDays($i)->startOfDay();
            $key = $day->format('Y-m-d');
            try {
                $count = (int) Article::query()
                    ->whereNull('deleted_at')
                    ->whereBetween('created_at', [$day, $day->copy()->endOfDay()])
                    ->count();
            } catch (\Throwable) {
                $count = 0;
            }
            $series[] = ['date' => $key, 'count' => $count];
        }

        return $series;
    }

    /**
     * 根据每日发文数生成 SVG 折线、面积填充路径及纵轴刻度等绘图数据。
     *
     * @param  list<array{date: string, count: int}>  $articleTrend
     * @return array{
     *   chart_height: int,
     *   chart_width: int,
     *   points: list<array{x: float, y: float, count: int, date: string}>,
     *   y_max: float,
     *   y_ticks: list<float|int>,
     *   line_path: string,
     *   area_path: string,
     *   peak_index: int,
     *   max_count: int,
     *   total_trend_count: int,
     *   avg_articles: float
     * }
     */
    private function buildArticleTrendChartPaths(array $articleTrend): array
    {
        $chartHeight = 148;
        $chartWidth = 600;
        $dataMaxCount = $articleTrend === [] ? 0 : (int) max(array_column($articleTrend, 'count'));
        $scaleMaxCount = $dataMaxCount === 0 ? 10 : $dataMaxCount;
        $yMax = ceil($scaleMaxCount * 1.2);
        if ($yMax < 5) {
            $yMax = 5;
        }

        $pointCount = count($articleTrend);
        $xStep = $pointCount > 1 ? ($chartWidth / ($pointCount - 1)) : $chartWidth;

        $points = [];
        foreach ($articleTrend as $index => $day) {
            $x = $index * $xStep;
            $y = $chartHeight - (($day['count'] / $yMax) * $chartHeight);
            $points[] = ['x' => $x, 'y' => $y, 'count' => (int) $day['count'], 'date' => (string) $day['date']];
        }

        $linePath = '';
        if ($points !== []) {
            $linePath = 'M'.$points[0]['x'].','.$points[0]['y'];
            $totalPoints = count($points);
            for ($i = 0; $i < $totalPoints - 1; $i++) {
                $p0 = $points[max($i - 1, 0)];
                $p1 = $points[$i];
                $p2 = $points[$i + 1];
                $p3 = $points[min($i + 2, $totalPoints - 1)];
                $cp1x = $p1['x'] + (($p2['x'] - $p0['x']) / 6);
                $cp1y = $p1['y'] + (($p2['y'] - $p0['y']) / 6);
                $cp2x = $p2['x'] - (($p3['x'] - $p1['x']) / 6);
                $cp2y = $p2['y'] - (($p3['y'] - $p1['y']) / 6);
                $linePath .= " C{$cp1x},{$cp1y} {$cp2x},{$cp2y} {$p2['x']},{$p2['y']}";
            }
        }

        $areaPath = '';
        if ($points !== []) {
            $firstPoint = $points[0];
            $lastPoint = $points[count($points) - 1];
            $areaPath = $linePath
                .' L'.$lastPoint['x'].','.$chartHeight
                .' L'.$firstPoint['x'].','.$chartHeight
                .' Z';
        }

        $peakIndex = 0;
        foreach ($points as $index => $point) {
            if ($dataMaxCount > 0 && $point['count'] === $dataMaxCount) {
                $peakIndex = $index;
                break;
            }
        }

        $yTicks = [];
        for ($i = 0; $i <= 4; $i++) {
            $yTicks[] = round($yMax - ($yMax / 4) * $i);
        }

        $totalTrendCount = array_sum(array_column($articleTrend, 'count'));
        $avgArticles = $pointCount > 0 ? round($totalTrendCount / $pointCount, 1) : 0.0;

        return [
            'chart_height' => $chartHeight,
            'chart_width' => $chartWidth,
            'points' => $points,
            'y_max' => $yMax,
            'y_ticks' => $yTicks,
            'line_path' => $linePath,
            'area_path' => $areaPath,
            'peak_index' => $peakIndex,
            'max_count' => $dataMaxCount,
            'total_trend_count' => $totalTrendCount,
            'avg_articles' => $avgArticles,
        ];
    }

    /**
     * 仪表盘性能区：任务平均耗时、队列成功率（已完成 / (已完成+失败)）、当日 AI 发文数。
     *
     * @return array{avg_generation_time: float, success_rate: float, daily_quota_used: int}
     */
    private function buildPerformanceStats(int $completedJobs, int $failedJobs): array
    {
        $totalFinished = $completedJobs + $failedJobs;
        $successRate = $totalFinished > 0 ? round(($completedJobs * 100.0) / $totalFinished, 2) : 0.0;
        $avg = 0.0;
        $daily = 0;
        try {
            $raw = TaskRun::query()
                ->where('duration_ms', '>', 0)
                ->selectRaw('AVG(duration_ms) / 1000.0 as avg_time')
                ->value('avg_time');
            $avg = (float) ($raw ?? 0);
        } catch (\Throwable) {
            // ignore
        }
        try {
            $daily = (int) Article::query()
                ->whereNull('deleted_at')
                ->whereDate('created_at', Carbon::today())
                ->where('is_ai_generated', 1)
                ->count();
        } catch (\Throwable) {
            // ignore
        }

        return [
            'avg_generation_time' => $avg,
            'success_rate' => $successRate,
            'daily_quota_used' => $daily,
        ];
    }
}
