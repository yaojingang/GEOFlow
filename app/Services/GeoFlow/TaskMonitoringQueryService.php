<?php

namespace App\Services\GeoFlow;

use App\Models\Task;
use App\Models\TaskRun;
use App\Models\WorkerHeartbeat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
     *     recent_runs:list<array<string,mixed>>,
     *     pagination:array{page:int,per_page:int,total:int,total_pages:int},
     *     task_summary:array{total_tasks:int,enabled_tasks:int,total_articles:int,published_articles:int}
     * }
     */
    public function buildAdminOverview(int $page = 1, int $perPage = 50): array
    {
        $paginatedTasks = $this->listTasksPaginated($page, $perPage);

        return [
            'tasks' => $paginatedTasks['items'],
            'queue_overview' => $this->horizonMetrics->queueOverview('geoflow'),
            'worker_overview' => $this->workerOverview(),
            'recent_runs' => $this->recentRuns(),
            'pagination' => $paginatedTasks['pagination'],
            'task_summary' => $this->taskSummary(),
        ];
    }

    /**
     * 用于前端状态刷新快照（兼容现有任务页按钮逻辑）。
     *
     * @return list<array<string,mixed>>
     */
    public function buildTaskSnapshot(): array
    {
        return $this->listTasksPaginated(1, 100)['items'];
    }

    /**
     * 管理后台任务回收站，过期记录即使等待定时物理清理也不再展示。
     *
     * @return array{
     *     items:list<array{id:int,name:string,created_at:?string,deleted_at:string,trash_sequence:int,requires_super_admin_restore:bool,expires_at:string}>,
     *     pagination:array{page:int,per_page:int,total:int,total_pages:int,snapshot_id:int}
     * }
     */
    public function trashedTaskHistory(
        int $page = 1,
        int $perPage = 50,
        ?int $snapshotId = null,
    ): array {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $retentionCutoff = now()
            ->subDays(Task::TRASH_RETENTION_DAYS)
            ->format('Y-m-d H:i:s.u');
        $baseQuery = Task::onlyTrashed()
            ->join('task_trash_entries as task_trash', 'task_trash.task_id', '=', 'tasks.id')
            ->where('task_trash.deleted_at', '>', $retentionCutoff);
        $snapshotSequence = $this->taskTrashSnapshot($baseQuery, $snapshotId);
        $query = (clone $baseQuery)
            ->where('task_trash.sequence', '<=', $snapshotSequence)
            ->orderByDesc('task_trash.sequence');
        $total = (clone $query)->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);

        $items = $query
            ->forPage($page, $perPage)
            ->get([
                'tasks.id',
                'tasks.name',
                'tasks.created_at',
                'task_trash.deleted_at as deleted_at',
                'task_trash.sequence as trash_sequence',
                'task_trash.requires_super_admin_restore',
            ])
            ->map(static fn (Task $task): array => [
                'id' => (int) $task->id,
                'name' => (string) $task->name,
                'created_at' => $task->created_at?->toDateTimeString(),
                'deleted_at' => $task->deleted_at?->toDateTimeString() ?? '',
                'trash_sequence' => (int) $task->trash_sequence,
                'requires_super_admin_restore' => (bool) $task->requires_super_admin_restore,
                'expires_at' => $task->deleted_at?->copy()
                    ->addDays(Task::TRASH_RETENTION_DAYS)
                    ->toDateTimeString() ?? '',
            ])
            ->values()
            ->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
                'snapshot_id' => $snapshotSequence,
            ],
        ];
    }

    /**
     * @param  Builder<Task>  $baseQuery
     */
    private function taskTrashSnapshot($baseQuery, ?int $snapshotId): int
    {
        $latestSequence = (int) ((clone $baseQuery)->max('task_trash.sequence') ?? 0);

        return is_int($snapshotId) && $snapshotId > 0
            ? min($snapshotId, $latestSequence)
            : $latestSequence;
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
            ->orderByDesc('created_at')
            ->orderByDesc('id');

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

        // 分发统计（文章维度）：用于任务列表快速暴露远程同步结果。
        $distributionStats = DB::table('article_distributions')
            ->join('articles', 'articles.id', '=', 'article_distributions.article_id')
            ->selectRaw("
                articles.task_id,
                COUNT(*) AS distribution_total_count,
                SUM(CASE WHEN article_distributions.status = 'synced' THEN 1 ELSE 0 END) AS distribution_synced_count,
                SUM(CASE WHEN article_distributions.status = 'failed' THEN 1 ELSE 0 END) AS distribution_failed_count
            ")
            ->whereIn('articles.task_id', $taskIds)
            ->whereNull('articles.deleted_at')
            ->groupBy('articles.task_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->task_id => [
                    'distribution_total_count' => (int) ($row->distribution_total_count ?? 0),
                    'distribution_synced_count' => (int) ($row->distribution_synced_count ?? 0),
                    'distribution_failed_count' => (int) ($row->distribution_failed_count ?? 0),
                ],
            ]);

        $qualityStats = collect();
        if (Schema::hasTable('article_ai_quality_checks')) {
            $latestQualityCheckIds = DB::table('article_ai_quality_checks')
                ->selectRaw('article_id, MAX(id) AS latest_id')
                ->where('gate_applied', true)
                ->groupBy('article_id');
            $qualityStats = DB::table('article_ai_quality_checks as quality_checks')
                ->joinSub($latestQualityCheckIds, 'latest_quality_checks', function ($join): void {
                    $join->on('quality_checks.id', '=', 'latest_quality_checks.latest_id');
                })
                ->join('articles', 'articles.id', '=', 'quality_checks.article_id')
                ->selectRaw("
                    articles.task_id,
                    COUNT(*) AS inspected_count,
                    SUM(CASE WHEN quality_checks.status = 'completed' AND quality_checks.decision = 'passed' THEN 1 ELSE 0 END) AS passed_count,
                    SUM(CASE WHEN quality_checks.status = 'completed' AND quality_checks.decision = 'needs_review' AND quality_checks.is_overridden IS FALSE THEN 1 ELSE 0 END) AS needs_review_count,
                    SUM(CASE WHEN quality_checks.status = 'completed' AND quality_checks.decision = 'blocked' THEN 1 ELSE 0 END) AS blocked_count,
                    SUM(CASE WHEN quality_checks.status IN ('queued','running') THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN quality_checks.status = 'failed' OR quality_checks.decision = 'error' THEN 1 ELSE 0 END) AS failed_count,
                    SUM(CASE WHEN quality_checks.status = 'stale' THEN 1 ELSE 0 END) AS stale_count
                ")
                ->whereIn('articles.task_id', $taskIds)
                ->whereNull('articles.deleted_at')
                ->groupBy('articles.task_id')
                ->get()
                ->mapWithKeys(fn ($row): array => [
                    (int) $row->task_id => [
                        'inspected_count' => (int) ($row->inspected_count ?? 0),
                        'passed_count' => (int) ($row->passed_count ?? 0),
                        'needs_review_count' => (int) ($row->needs_review_count ?? 0),
                        'blocked_count' => (int) ($row->blocked_count ?? 0),
                        'pending_count' => (int) ($row->pending_count ?? 0),
                        'failed_count' => (int) ($row->failed_count ?? 0),
                        'stale_count' => (int) ($row->stale_count ?? 0),
                    ],
                ]);
        }

        $optimizationStats = collect();
        if (Schema::hasTable('article_ai_optimization_runs')) {
            $latestOptimizationRuns = DB::table('article_ai_optimization_runs')
                ->selectRaw('article_id, MAX(id) AS latest_id')
                ->whereIn('task_id', $taskIds)
                ->groupBy('article_id');
            $optimizationStats = DB::table('article_ai_optimization_runs as optimization_runs')
                ->joinSub($latestOptimizationRuns, 'latest_optimization_runs', static function ($join): void {
                    $join->on('optimization_runs.id', '=', 'latest_optimization_runs.latest_id');
                })
                ->selectRaw("
                    optimization_runs.task_id,
                    SUM(CASE WHEN status IN ('awaiting_quality','queued','planning','rewriting','validating','evaluating','candidate_ready','applying') THEN 1 ELSE 0 END) AS active_count,
                    SUM(CASE WHEN status = 'needs_review' THEN 1 ELSE 0 END) AS needs_review_count,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_count
                ")
                ->groupBy('optimization_runs.task_id')
                ->get()
                ->mapWithKeys(static fn ($row): array => [
                    (int) $row->task_id => [
                        'active_count' => (int) ($row->active_count ?? 0),
                        'needs_review_count' => (int) ($row->needs_review_count ?? 0),
                        'failed_count' => (int) ($row->failed_count ?? 0),
                    ],
                ]);
        }

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
        $latestRunIds = TaskRun::query()
            ->selectRaw('task_id, MAX(id) AS latest_id')
            ->whereIn('task_id', $taskIds)
            ->groupBy('task_id');
        $latestRuns = TaskRun::query()
            ->joinSub($latestRunIds, 'latest_task_runs', function ($join): void {
                $join->on('task_runs.id', '=', 'latest_task_runs.latest_id');
            })
            ->get('task_runs.*')
            ->keyBy('task_id');

        // 显示名称映射：减少后续 map 内重复查询。
        $titleNames = DB::table('title_libraries')
            ->whereIn('id', $tasks->pluck('title_library_id')->filter()->all())
            ->pluck('name', 'id');

        $modelNames = DB::table('ai_models')
            ->whereIn('id', $tasks->pluck('ai_model_id')->filter()->all())
            ->pluck('name', 'id');

        $qualityPromptNames = DB::table('prompts')
            ->whereIn('id', $tasks->pluck('ai_quality_prompt_id')->filter()->all())
            ->pluck('name', 'id');

        $qualityModelNames = DB::table('ai_models')
            ->whereIn('id', $tasks->pluck('ai_quality_model_id')->filter()->all())
            ->pluck('name', 'id');

        $legacyKnowledgeBaseNames = DB::table('knowledge_bases')
            ->whereIn('id', $tasks->pluck('knowledge_base_id')->filter()->all())
            ->pluck('name', 'id');

        $taskKnowledgeBaseLinks = $this->loadTaskKnowledgeBaseLinks($taskIds);

        return $tasks->map(function (Task $task) use ($articleStats, $distributionStats, $qualityStats, $optimizationStats, $runStats, $latestRuns, $titleNames, $modelNames, $qualityPromptNames, $qualityModelNames, $legacyKnowledgeBaseNames, $taskKnowledgeBaseLinks): array {
            $taskId = (int) $task->id;
            $articles = $articleStats->get($taskId, ['total_articles' => 0, 'published_articles' => 0, 'draft_articles' => 0, 'publishable_drafts' => 0]);
            $distributions = $distributionStats->get($taskId, ['distribution_total_count' => 0, 'distribution_synced_count' => 0, 'distribution_failed_count' => 0]);
            $quality = $qualityStats->get($taskId, [
                'inspected_count' => 0,
                'passed_count' => 0,
                'needs_review_count' => 0,
                'blocked_count' => 0,
                'pending_count' => 0,
                'failed_count' => 0,
                'stale_count' => 0,
            ]);
            $optimization = $optimizationStats->get($taskId, [
                'active_count' => 0,
                'needs_review_count' => 0,
                'failed_count' => 0,
            ]);
            $runs = $runStats->get($taskId, ['pending_jobs' => 0, 'running_jobs' => 0, 'completed_jobs' => 0, 'failed_jobs' => 0]);
            /** @var TaskRun|null $latestRun */
            $latestRun = $latestRuns->get($taskId);
            $knowledgeBases = $taskKnowledgeBaseLinks->get($taskId, collect([]))->values()->all();
            $legacyKnowledgeBaseId = $this->nullableInt($task->knowledge_base_id);

            if (empty($knowledgeBases) && $legacyKnowledgeBaseId !== null) {
                $knowledgeBases = [[
                    'id' => $legacyKnowledgeBaseId,
                    'name' => (string) ($legacyKnowledgeBaseNames[$legacyKnowledgeBaseId] ?? ''),
                ]];
            }

            $knowledgeBaseIds = collect($knowledgeBases)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->values()
                ->all();

            // batch_status 是任务页按钮与状态徽标的关键字段：
            // running > pending > paused(idle) > failed/cancelled > waiting。
            $batchStatus = $this->resolveBatchStatus($task, $runs, $latestRun, $articles);
            // 错误信息优先取最近 run 的 error_message，其次退回 tasks.last_error_message。
            $batchErrorMessage = (string) ($latestRun?->error_message ?: ($task->last_error_message ?? ''));

            return [
                'id' => $taskId,
                'name' => (string) $task->name,
                'status' => (string) ($task->status ?? 'paused'),
                'publish_scope' => (string) ($task->publish_scope ?? 'local_and_distribution'),
                'distribution_strategy' => in_array((string) ($task->distribution_strategy ?? ''), TaskDistributionChannelSelector::strategies(), true)
                    ? (string) $task->distribution_strategy
                    : TaskDistributionChannelSelector::STRATEGY_BROADCAST,
                'distribution_cursor' => (int) ($task->distribution_cursor ?? 0),
                'title_library_id' => $this->nullableInt($task->title_library_id),
                'prompt_id' => $this->nullableInt($task->prompt_id),
                'ai_model_id' => $this->nullableInt($task->ai_model_id),
                'ai_quality_enabled' => (bool) ($task->ai_quality_enabled ?? false),
                'ai_quality_retrieval_mode' => (string) ($task->ai_quality_retrieval_mode ?: 'chunk'),
                'ai_quality_policy_version' => max(1, (int) ($task->ai_quality_policy_version ?? 1)),
                'ai_quality_config_version' => max(
                    1,
                    (int) ($task->ai_quality_config_version ?? 1),
                    (int) ($task->ai_quality_policy_version ?? 1),
                ),
                'config_version' => max(
                    1,
                    (int) ($task->ai_quality_config_version ?? 1),
                    (int) ($task->ai_quality_policy_version ?? 1),
                ),
                'ai_quality_timeout_sampling_enabled' => (bool) ($task->ai_quality_timeout_sampling_enabled ?? false),
                'ai_quality_auto_optimize_enabled' => (bool) ($task->ai_quality_auto_optimize_enabled ?? false),
                'ai_quality_optimization_level' => (string) ($task->ai_quality_optimization_level ?? ArticleAiOptimizationPolicy::STRATEGY_EXCELLENT_80),
                'ai_quality_prompt_id' => $this->nullableInt($task->ai_quality_prompt_id),
                'ai_quality_prompt_name' => (string) ($qualityPromptNames[(int) ($task->ai_quality_prompt_id ?? 0)] ?? ''),
                'ai_quality_model_id' => $this->nullableInt($task->ai_quality_model_id),
                'ai_quality_model_name' => (string) ($qualityModelNames[(int) ($task->ai_quality_model_id ?? 0)] ?? ''),
                'ai_quality_pass_score' => (int) ($task->ai_quality_pass_score ?? 85),
                'ai_quality_manual_override_min_score' => (int) ($task->ai_quality_manual_override_min_score ?? 70),
                'knowledge_base_id' => $legacyKnowledgeBaseId,
                'knowledge_base_ids' => $knowledgeBaseIds,
                'knowledge_bases' => $knowledgeBases,
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
                'distribution_total_count' => (int) $distributions['distribution_total_count'],
                'distribution_synced_count' => (int) $distributions['distribution_synced_count'],
                'distribution_failed_count' => (int) $distributions['distribution_failed_count'],
                'ai_quality_stats' => [
                    'inspected' => (int) $quality['inspected_count'],
                    'passed' => (int) $quality['passed_count'],
                    'needs_review' => (int) $quality['needs_review_count'],
                    'blocked' => (int) $quality['blocked_count'],
                    'pending' => (int) $quality['pending_count'],
                    'failed' => (int) $quality['failed_count'],
                    'stale' => (int) $quality['stale_count'],
                ],
                'ai_quality_optimization_stats' => [
                    'active' => (int) $optimization['active_count'],
                    'needs_review' => (int) $optimization['needs_review_count'],
                    'failed' => (int) $optimization['failed_count'],
                ],
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
     * @param  list<int>  $taskIds
     * @return Collection<int, Collection<int, array{id:int,name:string}>>
     */
    private function loadTaskKnowledgeBaseLinks(array $taskIds): Collection
    {
        if (empty($taskIds) || ! Schema::hasTable('task_knowledge_bases')) {
            return collect([]);
        }

        return DB::table('task_knowledge_bases')
            ->join('knowledge_bases', 'knowledge_bases.id', '=', 'task_knowledge_bases.knowledge_base_id')
            ->whereIn('task_knowledge_bases.task_id', $taskIds)
            ->orderBy('task_knowledge_bases.sort_order')
            ->orderBy('knowledge_bases.id')
            ->get([
                'task_knowledge_bases.task_id',
                'knowledge_bases.id',
                'knowledge_bases.name',
            ])
            ->groupBy(static fn ($row): int => (int) $row->task_id)
            ->map(static fn (Collection $rows): Collection => $rows
                ->map(static fn ($row): array => [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                ])
                ->values());
    }

    /**
     * @return array{total_tasks:int,enabled_tasks:int,total_articles:int,published_articles:int}
     */
    private function taskSummary(): array
    {
        $taskCounts = Task::query()
            ->selectRaw("COUNT(*) AS total_tasks, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS enabled_tasks")
            ->first();
        $articleCounts = DB::table('articles')
            ->whereNotNull('task_id')
            ->whereNull('deleted_at')
            ->selectRaw("COUNT(*) AS total_articles, SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS published_articles")
            ->first();

        return [
            'total_tasks' => (int) ($taskCounts?->total_tasks ?? 0),
            'enabled_tasks' => (int) ($taskCounts?->enabled_tasks ?? 0),
            'total_articles' => (int) ($articleCounts?->total_articles ?? 0),
            'published_articles' => (int) ($articleCounts?->published_articles ?? 0),
        ];
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
            $workers = WorkerHeartbeat::query()
                ->select(['worker_id', 'status', 'last_seen_at', 'meta'])
                ->orderByDesc('last_seen_at')
                ->limit(10)
                ->get();

            return $this->presentWorkers($workers)->all();
        } catch (\Throwable) {
            return [];
        }
    }

    public function paginateWorkers(int $page = 1, int $perPage = 10): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        if (! Schema::hasTable('worker_heartbeats')) {
            return new LengthAwarePaginator([], 0, $perPage, $page, [
                'path' => request()->url(),
                'query' => request()->query(),
            ]);
        }

        $workers = WorkerHeartbeat::query()
            ->select(['worker_id', 'status', 'last_seen_at', 'meta'])
            ->orderByDesc('last_seen_at')
            ->paginate($perPage, ['*'], 'page', $page);

        return $workers->setCollection($this->presentWorkers($workers->getCollection()));
    }

    /**
     * @param  Collection<int, WorkerHeartbeat>  $workers
     * @return Collection<int, array<string,mixed>>
     */
    private function presentWorkers(Collection $workers): Collection
    {
        $runIds = $workers
            ->map(static function (WorkerHeartbeat $worker): ?int {
                $meta = is_array($worker->meta) ? $worker->meta : [];

                return isset($meta['task_run_id']) ? (int) $meta['task_run_id'] : null;
            })
            ->filter()
            ->unique()
            ->values();
        $runs = TaskRun::query()
            ->whereIn('id', $runIds)
            ->with([
                'task' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'deleted_at']),
                'article' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'deleted_at']),
            ])
            ->get(['id', 'task_id', 'article_id', 'status'])
            ->keyBy('id');
        $staleAfterSeconds = max(30, (int) config('geoflow.worker_stale_seconds', 120));

        return $workers->map(static function (WorkerHeartbeat $row) use ($runs, $staleAfterSeconds): array {
            $meta = is_array($row->meta) ? $row->meta : [];
            $runId = isset($meta['task_run_id']) ? (int) $meta['task_run_id'] : null;
            /** @var TaskRun|null $run */
            $run = $runId ? $runs->get($runId) : null;
            $isStale = $row->last_seen_at === null
                || $row->last_seen_at->lessThan(now()->subSeconds($staleAfterSeconds));
            $status = $isStale ? 'stale' : (string) $row->status;
            $taskName = (string) ($run?->task?->name ?? '');
            $summary = match (true) {
                $isStale => __('admin.tasks.worker.summary_stale'),
                $run !== null && $taskName !== '' => __('admin.tasks.worker.summary_busy', ['task' => $taskName]),
                default => __('admin.tasks.worker.summary_idle'),
            };
            $statusLabel = match ($status) {
                'running', 'stale', 'idle' => __('admin.tasks.worker.status.'.$status),
                default => __('admin.tasks.worker.status.unknown'),
            };

            return [
                'worker_id' => (string) $row->worker_id,
                'status' => $status,
                'status_label' => $statusLabel,
                'summary' => $summary,
                'is_stale' => $isStale,
                'current_job_id' => $runId,
                'task_id' => $run?->task_id ? (int) $run->task_id : null,
                'task_name' => $taskName,
                'task_deleted' => $run?->task_id
                    ? $run->task === null || $run->task->trashed()
                    : false,
                'article_id' => $run?->article_id ? (int) $run->article_id : null,
                'article_title' => (string) ($run?->article?->title ?? ''),
                'article_deleted' => $run?->article_id
                    ? $run->article === null || $run->article->trashed()
                    : false,
                'memory_mb' => isset($meta['memory_mb']) ? (float) $meta['memory_mb'] : null,
                'peak_memory_mb' => isset($meta['peak_memory_mb']) ? (float) $meta['peak_memory_mb'] : null,
                'last_seen_at' => $row->last_seen_at?->toDateTimeString(),
                'last_seen_human' => $row->last_seen_at?->diffForHumans() ?? __('admin.tasks.worker.never_seen'),
            ];
        });
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function recentRuns(): array
    {
        $runs = TaskRun::query()
            ->select(['id', 'task_id', 'status', 'article_id', 'error_message', 'duration_ms', 'meta', 'started_at', 'finished_at', 'created_at'])
            ->with([
                'task' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'deleted_at']),
                'article' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'deleted_at']),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $this->presentRuns($runs)->all();
    }

    public function paginateRecentRuns(int $page = 1, int $perPage = 10, ?int $runId = null): LengthAwarePaginator
    {
        $page = max(1, $page);
        $perPage = max(1, min(50, $perPage));
        $runs = TaskRun::query()
            ->select(['id', 'task_id', 'status', 'article_id', 'error_message', 'duration_ms', 'meta', 'started_at', 'finished_at', 'created_at'])
            ->when($runId !== null, fn (Builder $query) => $query->whereKey($runId))
            ->with([
                'task' => fn ($query) => $query->withTrashed()->select(['id', 'name', 'deleted_at']),
                'article' => fn ($query) => $query->withTrashed()->select(['id', 'title', 'deleted_at']),
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return $runs->setCollection($this->presentRuns($runs->getCollection()));
    }

    /**
     * @param  Collection<int, TaskRun>  $runs
     * @return Collection<int, array<string,mixed>>
     */
    private function presentRuns(Collection $runs): Collection
    {
        return $runs->map(function (TaskRun $row): array {
            $status = (string) $row->status;
            $taskName = (string) ($row->task?->name ?? __('admin.tasks.jobs.unknown_task'));
            $articleTitle = (string) ($row->article?->title ?? '');
            $meta = is_array($row->meta) ? $row->meta : [];
            $statusLabel = in_array($status, ['pending', 'running', 'completed', 'failed', 'cancelled'], true)
                ? __('admin.tasks.jobs.status.'.$status)
                : __('admin.tasks.jobs.status.unknown');

            return [
                'id' => (int) $row->id,
                'task_id' => (int) $row->task_id,
                'task_name' => $taskName,
                'task_deleted' => $row->task_id
                    ? $row->task === null || $row->task->trashed()
                    : false,
                'status' => $status,
                'status_label' => $statusLabel,
                'summary' => $this->taskRunSummary($status, $taskName, $articleTitle),
                'explanation' => $this->taskRunExplanation(
                    $status,
                    (string) ($meta['error_code'] ?? ''),
                    (string) ($row->error_message ?? ''),
                    $articleTitle,
                ),
                'article_id' => $row->article_id ? (int) $row->article_id : null,
                'article_title' => $articleTitle,
                'article_deleted' => $row->article_id
                    ? $row->article === null || $row->article->trashed()
                    : false,
                'duration_ms' => (int) ($row->duration_ms ?? 0),
                'attempt_count' => (int) ($meta['attempt_count'] ?? 0),
                'max_attempts' => (int) ($meta['max_attempts'] ?? 0),
                'job_type' => (string) ($meta['job_type'] ?? 'generate_article'),
                'started_at' => $row->started_at?->toDateTimeString(),
                'finished_at' => $row->finished_at?->toDateTimeString(),
                'updated_at' => $row->created_at?->toDateTimeString(),
            ];
        });
    }

    private function taskRunSummary(string $status, string $taskName, string $articleTitle): string
    {
        if ($status === 'completed' && $articleTitle !== '') {
            return __('admin.tasks.jobs.summary.completed_with_article', [
                'task' => $taskName,
                'article' => $articleTitle,
            ]);
        }

        if (! in_array($status, ['pending', 'running', 'completed', 'failed', 'cancelled'], true)) {
            return __('admin.tasks.jobs.summary.unknown', ['task' => $taskName]);
        }

        return __('admin.tasks.jobs.summary.'.$status, ['task' => $taskName]);
    }

    private function taskRunExplanation(
        string $status,
        string $errorCode,
        string $errorMessage,
        string $articleTitle,
    ): string {
        if ($status === 'failed') {
            $reason = match ($errorCode) {
                'empty_content' => __('admin.tasks.failure.empty_content_detail'),
                'content_too_short' => __('admin.tasks.failure.content_too_short_detail'),
                'task_title_library_not_ready', 'title_library_exhausted' => __('admin.tasks.failure.title_exhausted_detail'),
                'provider_timeout', 'model_timeout', 'timeout' => __('admin.tasks.failure.timeout_plain'),
                default => $this->legacyFailureReason($errorMessage),
            };

            return $articleTitle !== ''
                ? __('admin.tasks.jobs.failed_article', ['article' => $articleTitle, 'reason' => $reason])
                : __('admin.tasks.jobs.failed_before_article', ['reason' => $reason]);
        }

        if (! in_array($status, ['pending', 'running', 'completed', 'cancelled'], true)) {
            return __('admin.tasks.jobs.explanation.unknown');
        }

        return __('admin.tasks.jobs.explanation.'.$status);
    }

    private function legacyFailureReason(string $errorMessage): string
    {
        return match (true) {
            str_contains($errorMessage, 'AI返回空正文') => __('admin.tasks.failure.empty_content_detail'),
            str_contains($errorMessage, '正文过短') => __('admin.tasks.failure.content_too_short_detail'),
            str_contains($errorMessage, '没有可用的标题') => __('admin.tasks.failure.title_exhausted_detail'),
            str_contains($errorMessage, 'Operation timed out'), str_contains($errorMessage, '请求超时') => __('admin.tasks.failure.timeout_plain'),
            default => __('admin.tasks.failure.generic_plain'),
        };
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
