<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
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
use App\Support\Analytics\TrafficClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * 管理首页：为自动化工作流面板提供真实运行状态。
 */
class DashboardController extends Controller
{
    public function index(): View
    {
        return view('admin.dashboard', [
            'pageTitle' => __('admin.dashboard.page_title'),
            'activeMenu' => 'dashboard',
            'adminSiteName' => AdminWeb::siteName(),
            'dashboardStats' => $this->buildStats(),
            'dashboardTodayStats' => $this->buildTodayStats(),
            'taskHealth' => $this->buildTaskHealth(),
            'materialHealth' => $this->buildMaterialHealth(),
            'aiHealth' => $this->buildAiHealth(),
            'distributionHealth' => $this->buildDistributionHealth(),
            'urlImportHealth' => $this->buildUrlImportHealth(),
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
            'body_prompts' => 0,
            'special_prompts' => 0,
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
            $defaults['body_prompts'] = (int) Prompt::query()->where('type', 'content')->count();
            $defaults['special_prompts'] = (int) Prompt::query()->whereIn('type', ['keyword', 'description'])->count();
        } catch (\Throwable) {
            return $defaults;
        }

        return $defaults;
    }

    /**
     * @return array{today_articles: int, today_tasks: int, today_views: int, today_ai_bot_views: int}
     */
    private function buildTodayStats(): array
    {
        $out = ['today_articles' => 0, 'today_tasks' => 0, 'today_views' => 0, 'today_ai_bot_views' => 0];

        try {
            $today = Carbon::today();
            $out['today_articles'] = (int) Article::query()
                ->whereNull('deleted_at')
                ->whereDate('created_at', $today)
                ->count();
            $out['today_tasks'] = (int) Task::query()
                ->whereDate('created_at', $today)
                ->count();
            $todayViewQuery = DB::table('view_logs')->whereDate('created_at', $today);
            if (Schema::hasColumn('view_logs', 'method')) {
                $todayViewQuery->where('method', 'GET');
            }
            $out['today_views'] = (int) $todayViewQuery->count();
            if (Schema::hasColumn('view_logs', 'user_agent')) {
                $todayAiBotQuery = DB::table('view_logs')->whereDate('created_at', $today);
                if (Schema::hasColumn('view_logs', 'method')) {
                    $todayAiBotQuery->where('method', 'GET');
                }
                $out['today_ai_bot_views'] = (int) $todayAiBotQuery
                    ->where(function ($query): void {
                        foreach (TrafficClassifier::aiBotPatterns() as $pattern) {
                            $query->orWhereRaw("LOWER(COALESCE(user_agent, '')) LIKE ?", ['%'.$pattern.'%']);
                        }
                    })
                    ->count();
            }
        } catch (\Throwable) {
            // Empty or partially migrated installations should still render the dashboard.
        }

        return $out;
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
     * @return array{
     *   keyword_libraries: int,
     *   title_libraries: int,
     *   knowledge_bases: int,
     *   image_libraries: int,
     *   authors: int,
     *   knowledge_chunks: int,
     *   vectorized_chunks: int,
     *   unvectorized_chunks: int
     * }
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
     * @return array{channels_total: int, channels_active: int, pending: int, sending: int, failed: int, synced: int, deleted: int, total: int}
     */
    private function buildDistributionHealth(): array
    {
        $out = [
            'channels_total' => 0,
            'channels_active' => 0,
            'pending' => 0,
            'sending' => 0,
            'failed' => 0,
            'synced' => 0,
            'deleted' => 0,
            'total' => 0,
        ];

        try {
            $out['channels_total'] = (int) DistributionChannel::query()->count();
            $out['channels_active'] = (int) DistributionChannel::query()->where('status', 'active')->count();

            $statusCounts = ArticleDistribution::query()
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->map(fn ($count) => (int) $count)
                ->all();

            $out['pending'] = (int) (($statusCounts['queued'] ?? 0) + ($statusCounts['pending'] ?? 0));
            $out['sending'] = (int) ($statusCounts['sending'] ?? 0);
            $out['failed'] = (int) ($statusCounts['failed'] ?? 0);
            $out['synced'] = (int) ($statusCounts['synced'] ?? 0);
            $out['deleted'] = (int) ($statusCounts['deleted'] ?? 0);
            $out['total'] = (int) array_sum($statusCounts);
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
                ->map(fn ($count) => (int) $count)
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
}
