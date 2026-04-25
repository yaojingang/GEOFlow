<?php

namespace App\Services\GeoFlow;

use App\Models\Task;
use App\Models\TaskRun;
use App\Models\WorkerHeartbeat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 任务监控查询编排服务。
 *
 * 双层真相源：
 * - 业务真相：task_runs / tasks（任务进度、错误语义、业务完成结果）
 * - 监控真相：Horizon/Redis（队列 pending/running/failed）
 */
class TaskMonitoringQueryService
{
    public function __construct(
        private readonly HorizonMetricsAdapter $horizonMetrics
    ) {}

    /**
     * 管理后台任务页完整数据。
     *
     * @return array{
     *     tasks:list<array<string,mixed>>,
     *     queue_overview:array{pending:int,running:int,failed:int,completed:int},
     *     worker_overview:list<array<string,mixed>>,
     *     recent_runs:list<array<string,mixed>>
     * }
     */
    public function buildAdminOverview(): array
    {
        $tasks = $this->listTaskMonitoringRows();

        return [
            'tasks' => $tasks,
            'queue_overview' => $this->horizonMetrics->queueOverview('geoflow'),
            'worker_overview' => $this->workerOverview(),
            'recent_runs' => $this->recentRuns(),
        ];
    }

    /**
     * 用于前端状态刷新快照（兼容现有任务页按钮逻辑）。
     *
     * @return list<array<string,mixed>>
     */
    public function buildTaskSnapshot(): array
    {
        return $this->listTaskMonitoringRows();
    }

    /**
     * API 场景：分页任务列表（包含 task_progress/queue_overview）。
     *
     * @param  array<string,mixed>  $filters
     * @return array{
     *     items:list<array<string,mixed>>,
     *     pagination:array{page:int,per_page:int,total:int,total_pages:int}
     * }
     */
    public function listTasksPaginated(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $query = Task::query()
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', (string) $filters['status']))
            ->when(! empty($filters['search']), fn ($q) => $q->where('name', 'like', '%'.trim((string) $filters['search']).'%'))
            ->orderByDesc('created_at');

        $total = (clone $query)->count();
        /** @var Collection<int, Task> $rows */
        $rows = $query->forPage($page, $perPage)->get();

        return [
            'items' => $this->decorateTasks($rows)->values()->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil(max(1, $total) / $perPage),
            ],
        ];
    }

    /**
     * API 场景：单任务监控详情。
     *
     * @return array<string,mixed>
     */
    public function getTaskMonitoringDetail(int $taskId): array
    {
        $task = Task::query()->whereKey($taskId)->firstOrFail();
        $decorated = $this->decorateTasks(collect([$task]))->first();

        return is_array($decorated) ? $decorated : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function listTaskMonitoringRows(): array
    {
        /** @var Collection<int, Task> $tasks */
        $tasks = Task::query()
            ->orderByDesc('created_at')
            ->get();

        return $this->decorateTasks($tasks)->values()->all();
    }

    /**
     * @param  Collection<int, Task>  $tasks
     * @return Collection<int, array<string,mixed>>
     */
    private function decorateTasks(Collection $tasks): Collection
    {
        if ($tasks->isEmpty()) {
            return collect([]);
        }

        // 一次性收集 task_id，后续所有聚合都基于该集合批量查询，避免 N+1。
        $taskIds = $tasks->pluck('id')->map(fn ($id) => (int) $id)->all();

        // 文章统计（业务真相）：总文章数 + 已发布数。
        $articleStats = DB::table('articles')
            ->selectRaw("
                task_id,
                COUNT(*) AS total_articles,
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_articles,
                SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_articles,
                SUM(CASE WHEN status = 'draft' AND review_status IN ('approved','auto_approved') THEN 1 ELSE 0 END) AS publishable_drafts
            ")
            ->whereIn('task_id', $taskIds)
            ->whereNull('deleted_at')
            ->groupBy('task_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->task_id => [
                    'total_articles' => (int) ($row->total_articles ?? 0),
                    'published_articles' => (int) ($row->published_articles ?? 0),
                    'draft_articles' => (int) ($row->draft_articles ?? 0),
                    'publishable_drafts' => (int) ($row->publishable_drafts ?? 0),
                ],
            ]);

        // 运行统计（业务真相）：pending/running/completed/failed+cancelled 数量。
        // 说明：这里把 cancelled 归入 failed_jobs，用于任务页“失败”概览展示。
        $runStats = TaskRun::query()
            ->selectRaw("
                task_id,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_jobs,
                SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running_jobs,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_jobs,
                SUM(CASE WHEN status IN ('failed','cancelled') THEN 1 ELSE 0 END) AS failed_jobs
            ")
            ->whereIn('task_id', $taskIds)
            ->groupBy('task_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->task_id => [
                    'pending_jobs' => (int) ($row->pending_jobs ?? 0),
                    'running_jobs' => (int) ($row->running_jobs ?? 0),
                    'completed_jobs' => (int) ($row->completed_jobs ?? 0),
                    'failed_jobs' => (int) ($row->failed_jobs ?? 0),
                ],
            ]);

        // 最近一条执行记录：用于回填最新状态、错误信息、重试次数等字段。
        $latestRuns = TaskRun::query()
            ->whereIn('task_id', $taskIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('task_id')
            ->map(static fn (Collection $group): ?TaskRun => $group->first());

        // 显示名称映射：减少后续 map 内重复查询。
        $titleNames = DB::table('title_libraries')
            ->whereIn('id', $tasks->pluck('title_library_id')->filter()->all())
            ->pluck('name', 'id');

        $modelNames = DB::table('ai_models')
            ->whereIn('id', $tasks->pluck('ai_model_id')->filter()->all())
            ->pluck('name', 'id');

        return $tasks->map(function (Task $task) use ($articleStats, $runStats, $latestRuns, $titleNames, $modelNames): array {
            $taskId = (int) $task->id;
            $articles = $articleStats->get($taskId, ['total_articles' => 0, 'published_articles' => 0, 'draft_articles' => 0, 'publishable_drafts' => 0]);
            $runs = $runStats->get($taskId, ['pending_jobs' => 0, 'running_jobs' => 0, 'completed_jobs' => 0, 'failed_jobs' => 0]);
            /** @var TaskRun|null $latestRun */
            $latestRun = $latestRuns->get($taskId);

            // batch_status 是任务页按钮与状态徽标的关键字段：
            // running > pending > paused(idle) > failed/cancelled > waiting。
            $batchStatus = $this->resolveBatchStatus($task, $runs, $latestRun, $articles);
            // 错误信息优先取最近 run 的 error_message，其次退回 tasks.last_error_message。
            $batchErrorMessage = (string) ($latestRun?->error_message ?: ($task->last_error_message ?? ''));

            return [
                'id' => $taskId,
                'name' => (string) $task->name,
                'status' => (string) ($task->status ?? 'paused'),
                'title_library_id' => $this->nullableInt($task->title_library_id),
                'prompt_id' => $this->nullableInt($task->prompt_id),
                'ai_model_id' => $this->nullableInt($task->ai_model_id),
                'knowledge_base_id' => $this->nullableInt($task->knowledge_base_id),
                'author_id' => $this->nullableInt($task->author_id),
                'image_library_id' => $this->nullableInt($task->image_library_id),
                'image_count' => (int) ($task->image_count ?? 0),
                'need_review' => (int) ($task->need_review ?? 1),
                'auto_keywords' => (int) ($task->auto_keywords ?? 1),
                'auto_description' => (int) ($task->auto_description ?? 1),
                'is_loop' => (int) ($task->is_loop ?? 0),
                'category_mode' => (string) ($task->category_mode ?? 'smart'),
                'fixed_category_id' => $this->nullableInt($task->fixed_category_id),
                'title_library_name' => (string) ($titleNames[(int) ($task->title_library_id ?? 0)] ?? ''),
                'ai_model_name' => (string) ($modelNames[(int) ($task->ai_model_id ?? 0)] ?? ''),
                'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                'created_at' => $task->created_at?->toDateTimeString(),
                'updated_at' => $task->updated_at?->toDateTimeString(),
                'loop_count' => (int) ($task->loop_count ?? 0),
                'created_count' => (int) ($task->created_count ?? 0),
                'published_count' => (int) ($task->published_count ?? 0),
                'article_limit' => (int) ($task->article_limit ?? $task->draft_limit ?? 10),
                'draft_limit' => (int) ($task->draft_limit ?? 10),
                'publish_interval' => (int) ($task->publish_interval ?? 3600),
                'batch_status' => $batchStatus,
                'batch_error_message' => trim($batchErrorMessage),
                'batch_last_run' => $task->last_run_at?->toDateTimeString(),
                'last_error_at' => $task->last_error_at?->toDateTimeString(),
                'next_run_at' => $task->next_run_at?->toDateTimeString(),
                'next_publish_at' => $task->next_publish_at?->toDateTimeString(),
                'schedule_enabled' => (int) ($task->schedule_enabled ?? 1),
                'total_articles' => (int) $articles['total_articles'],
                'published_articles' => (int) $articles['published_articles'],
                'draft_articles' => (int) $articles['draft_articles'],
                'publishable_drafts' => (int) $articles['publishable_drafts'],
                'pending_jobs' => (int) $runs['pending_jobs'],
                'running_jobs' => (int) $runs['running_jobs'],
                'batch_success_count' => (int) $runs['completed_jobs'],
                'batch_error_count' => (int) $runs['failed_jobs'],
                'latest_job_status' => (string) ($latestRun?->status ?? 'idle'),
                'latest_attempt_count' => (int) (($latestRun?->meta['attempt_count'] ?? 0)),
                'latest_max_attempts' => (int) (($latestRun?->meta['max_attempts'] ?? 0)),
                // 新契约字段：业务层进度（文章维度），用于“任务成果”视图。
                'task_progress' => [
                    'created_articles' => (int) $articles['total_articles'],
                    'published_articles' => (int) $articles['published_articles'],
                    'draft_articles' => (int) $articles['draft_articles'],
                    'article_limit' => (int) ($task->article_limit ?? $task->draft_limit ?? 10),
                    'draft_limit' => (int) ($task->draft_limit ?? 10),
                    'last_run_at' => $task->last_run_at?->toDateTimeString(),
                    'last_error_message' => trim((string) ($task->last_error_message ?? '')),
                ],
                // 新契约字段：任务级队列视图（来自 task_runs 聚合，不是全局 Redis 队列长度）。
                'queue_overview' => [
                    'pending' => (int) $runs['pending_jobs'],
                    'running' => (int) $runs['running_jobs'],
                    'failed' => (int) $runs['failed_jobs'],
                    'completed' => (int) $runs['completed_jobs'],
                    'latest_status' => (string) ($latestRun?->status ?? 'idle'),
                ],
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $runStats
     */
    private function resolveBatchStatus(Task $task, array $runStats, ?TaskRun $latestRun, array $articleStats): string
    {
        if ((int) ($runStats['running_jobs'] ?? 0) > 0) {
            return 'running';
        }

        if ((int) ($runStats['pending_jobs'] ?? 0) > 0) {
            return 'pending';
        }

        if (($task->status ?? 'paused') === 'paused') {
            return 'idle';
        }

        $articleLimit = (int) ($task->article_limit ?? $task->draft_limit ?? 10);
        $createdCount = (int) ($task->created_count ?? 0);
        $draftLimit = (int) ($task->draft_limit ?? 10);
        $draftCount = (int) ($articleStats['draft_articles'] ?? 0);
        $publishableDrafts = (int) ($articleStats['publishable_drafts'] ?? 0);

        if ($createdCount >= $articleLimit && $draftCount <= 0) {
            return 'limit_reached';
        }

        if ($publishableDrafts > 0) {
            return 'waiting_publish';
        }

        if ($createdCount < $articleLimit && $draftCount >= $draftLimit) {
            return 'draft_pool_full';
        }

        if ($createdCount >= $articleLimit) {
            return 'limit_reached';
        }

        $latestStatus = (string) ($latestRun?->status ?? '');
        $latestError = trim((string) ($latestRun?->error_message ?: ($task->last_error_message ?? '')));
        if (in_array($latestStatus, ['failed', 'cancelled'], true) && $latestError !== '') {
            return $latestStatus;
        }

        return 'waiting';
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function workerOverview(): array
    {
        try {
            return WorkerHeartbeat::query()
                ->select(['worker_id', 'status', 'last_seen_at'])
                ->orderByDesc('last_seen_at')
                ->limit(5)
                ->get()
                ->map(static fn (WorkerHeartbeat $row): array => [
                    'worker_id' => (string) $row->worker_id,
                    'status' => (string) $row->status,
                    'current_job_id' => null,
                    'last_seen_at' => $row->last_seen_at?->toDateTimeString(),
                ])
                ->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recentRuns(): array
    {
        return TaskRun::query()
            ->select(['id', 'task_id', 'status', 'error_message', 'created_at'])
            ->with(['task:id,name'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get()
            ->map(static fn (TaskRun $row): array => [
                'id' => (int) $row->id,
                'task_id' => (int) $row->task_id,
                'status' => (string) $row->status,
                'error_message' => (string) ($row->error_message ?? ''),
                'updated_at' => $row->created_at?->toDateTimeString(),
                'task_name' => (string) ($row->task?->name ?? ''),
            ])
            ->all();
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
