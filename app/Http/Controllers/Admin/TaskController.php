<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\DistributionTaskRevisionMismatch;
use App\Exceptions\TaskTitleReadinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\TaskTitleReadinessRequest;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\GeoFlow\AiQualityRetrievalReadinessService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\TaskDistributionChannelSelector;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Services\GeoFlow\TaskTitleReadinessService;
use App\Support\AdminWeb;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

/**
 * 任务管理页（按 bak/admin/tasks.php 行为迁移）：
 * - GET 展示任务列表与运行态信息
 * - POST 处理切换状态、删除任务
 * - JSON 接口提供批量启动/停止与状态轮询
 */
class TaskController extends Controller
{
    /**
     * @param  TaskLifecycleService  $taskLifecycleService  任务生命周期服务（创建/启动/停止任务）
     */
    public function __construct(
        private readonly TaskLifecycleService $taskLifecycleService,
        private readonly TaskMonitoringQueryService $taskMonitoringQueryService,
        private readonly DistributionOrchestrator $distributionOrchestrator,
        private readonly TaskTitleReadinessService $taskTitleReadinessService,
        private readonly AiQualityRetrievalReadinessService $aiQualityRetrievalReadinessService,
        private readonly AdminAiModelAccessResolver $adminAiModelAccessResolver,
    ) {}

    public function titleReadiness(TaskTitleReadinessRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $taskId = isset($payload['task_id']) ? (int) $payload['task_id'] : null;
        if ($taskId !== null) {
            $this->assertCanManageHostedTask($taskId);
        }

        $report = $this->taskTitleReadinessService->inspect(
            (int) $payload['title_library_id'],
            (int) $payload['article_limit'],
            (bool) $payload['is_loop'],
            (string) $payload['status'],
            $taskId,
        );

        return response()->json($this->presentTitleReadiness($report));
    }

    /**
     * 任务管理首页：渲染列表与运行面板。
     */
    public function index(Request $request): View
    {
        $page = $this->positiveIntegerQuery($request, 'page');
        $trashPage = $this->positiveIntegerQuery($request, 'trash_page');
        $trashSnapshotId = $this->taskTrashSnapshotQuery($request);

        try {
            $overview = $this->taskMonitoringQueryService->buildAdminOverview(
                $page,
                50,
            );
            $tasks = $this->decorateTaskManageability($overview['tasks']);
            $workers = $overview['worker_overview'];
            $queueStats = $overview['queue_overview'];
            $recentJobs = $overview['recent_runs'];
            $pagination = $overview['pagination'];
            $taskSummary = $overview['task_summary'];
            $trashHistory = $this->taskMonitoringQueryService->trashedTaskHistory(
                $trashPage,
                50,
                $trashSnapshotId,
            );
            $trashedTasks = $this->decorateTaskTrashManageability($trashHistory['items']);
            $trashPagination = $trashHistory['pagination'];
            $error = null;
        } catch (Throwable $e) {
            $tasks = [];
            $workers = [];
            $queueStats = ['pending' => 0, 'running' => 0, 'failed' => 0, 'completed' => 0];
            $recentJobs = [];
            $pagination = ['page' => 1, 'per_page' => 50, 'total' => 0, 'total_pages' => 1];
            $taskSummary = ['total_tasks' => 0, 'enabled_tasks' => 0, 'total_articles' => 0, 'published_articles' => 0];
            $trashedTasks = [];
            $trashPagination = [
                'page' => 1,
                'per_page' => 50,
                'total' => 0,
                'total_pages' => 1,
                'snapshot_id' => 0,
            ];
            $error = __('admin.tasks.message.query_failed', ['message' => $e->getMessage()]);
        }

        return view('admin.tasks.index', [
            'pageTitle' => __('admin.tasks.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'tasks' => $tasks,
            'workers' => $workers,
            'queueStats' => $queueStats,
            'recentJobs' => $recentJobs,
            'pagination' => $pagination,
            'taskSummary' => $taskSummary,
            'trashedTasks' => $trashedTasks,
            'trashPagination' => $trashPagination,
            'taskTrashOpen' => $request->has('trash_page'),
            'taskTrashRetentionDays' => Task::TRASH_RETENTION_DAYS,
            'legacyError' => $error,
            'taskI18n' => $this->taskI18n(),
        ]);
    }

    public function workers(Request $request): View
    {
        return view('admin.tasks.workers', [
            'pageTitle' => __('admin.tasks.worker.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'workers' => $this->taskMonitoringQueryService->paginateWorkers(
                $this->positiveIntegerQuery($request, 'page'),
                10,
            ),
        ]);
    }

    public function jobs(Request $request): View
    {
        $focusedRunId = $this->optionalPositiveIntegerQuery($request, 'run_id');

        return view('admin.tasks.jobs', [
            'pageTitle' => __('admin.tasks.jobs.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'jobs' => $this->taskMonitoringQueryService->paginateRecentRuns(
                $this->positiveIntegerQuery($request, 'page'),
                10,
                $focusedRunId,
            ),
            'focusedRunId' => $focusedRunId,
        ]);
    }

    private function positiveIntegerQuery(Request $request, string $key): int
    {
        $value = $request->query($key, 1);
        if (! is_int($value) && ! is_string($value)) {
            return 1;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $validated === false ? 1 : $validated;
    }

    private function optionalPositiveIntegerQuery(Request $request, string $key): ?int
    {
        if (! $request->has($key)) {
            return null;
        }

        $value = $request->query($key);
        if (! is_int($value) && ! is_string($value)) {
            return null;
        }

        $validated = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $validated === false ? null : $validated;
    }

    private function taskTrashSnapshotQuery(Request $request): ?int
    {
        $snapshotId = $request->query('trash_snapshot_id');
        if (! is_int($snapshotId) && ! is_string($snapshotId)) {
            return null;
        }

        $validatedId = filter_var($snapshotId, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $validatedId === false ? null : $validatedId;
    }

    private function taskTrashReturnUrl(Request $request): string
    {
        $parameters = [
            'page' => $this->positiveIntegerQuery($request, 'page'),
            'trash_page' => $this->positiveIntegerQuery($request, 'trash_page'),
        ];
        $snapshotId = $this->taskTrashSnapshotQuery($request);
        if ($snapshotId !== null) {
            $parameters['trash_snapshot_id'] = $snapshotId;
        }

        return route('admin.tasks.index', $parameters).'#task-trash';
    }

    /**
     * 切换任务启停状态（active -> stop，paused -> start）。
     */
    public function toggleStatus(Request $request, int $taskId): RedirectResponse
    {
        if ($taskId <= 0) {
            return back()->withErrors(__('admin.tasks.message.status_update_failed'));
        }
        $this->assertCanManageHostedTask($taskId);

        try {
            $currentStatus = (string) $request->input('status', 'paused');
            if ($currentStatus === 'active') {
                $this->taskLifecycleService->stopTask($taskId, $this->canManageHostedTask());

                return back()->with('message', __('admin.tasks.message.paused_stopped'));
            }

            $this->taskLifecycleService->startTask(
                $taskId,
                false,
                $this->canManageHostedTask(),
                $this->authenticatedAdmin(),
            );

            return back()->with('message', __('admin.tasks.message.activated'));
        } catch (TaskTitleReadinessException $e) {
            $report = $e->getDetails()['title_readiness'] ?? [];

            return back()
                ->with('title_readiness_report', $this->presentTitleReadiness($report))
                ->withErrors($e->getMessage());
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.tasks.message.status_update_failed'));
        }
    }

    /**
     * 删除单个任务（含关联数据级联清理）。
     */
    public function destroyTask(int $taskId): RedirectResponse
    {
        if ($taskId <= 0) {
            return back()->withErrors(__('admin.tasks.message.status_update_failed'));
        }
        $this->assertCanManageHostedTask($taskId);

        try {
            $this->taskLifecycleService->deleteTask(
                $taskId,
                $this->canManageHostedTask(),
                (int) auth('admin')->id(),
            );

            return back()->with('message', __('admin.tasks.message.delete_success'));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.tasks.message.delete_failed', ['message' => $e->getMessage()]));
        }
    }

    /**
     * 从垃圾箱恢复任务，并返回当前垃圾箱位置。
     */
    public function restoreTask(Request $request, int $taskId): RedirectResponse
    {
        $returnUrl = $this->taskTrashReturnUrl($request);
        $trashSequence = $this->optionalPositiveIntegerQuery($request, 'trash_sequence');
        if ($taskId <= 0 || $trashSequence === null) {
            return redirect()->to($returnUrl)->withErrors(__('admin.tasks.message.restore_failed'));
        }

        try {
            $restoredTask = $this->taskLifecycleService->restoreTask(
                $taskId,
                $trashSequence,
                $this->canManageHostedTask(),
                (int) auth('admin')->id(),
            );

            return redirect()->to($returnUrl)->with('message', __('admin.tasks.message.restore_success', [
                'name' => $restoredTask['name'],
            ]));
        } catch (Throwable) {
            return redirect()->to($returnUrl)->withErrors(__('admin.tasks.message.restore_failed'));
        }
    }

    /**
     * 任务创建页（先接入可用创建链路，后续继续做 1:1 细节对齐）。
     */
    public function create(): View
    {
        $formOptions = $this->loadTaskFormOptions($this->authenticatedAdmin());

        // 创建页选项与 tasks.php 数据口径一致（库/模型/作者/分类）。
        return view('admin.tasks.form', [
            'pageTitle' => __('admin.task_create.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'formOptions' => $formOptions,
            'hasCategories' => ! empty($formOptions['categories']),
            'categoryCreateUrl' => route('admin.categories.create'),
            'isEdit' => false,
            'taskForm' => null,
            'taskId' => null,
            'canManageProtectedWorkflows' => auth('admin')->user()?->canManageProtectedWorkflows() === true,
        ]);
    }

    /**
     * 创建任务（对应上游 task-create.php 的提交逻辑）。
     */
    public function store(Request $request): RedirectResponse
    {
        if (! Category::query()->exists()) {
            return redirect()
                ->route('admin.categories.create')
                ->withErrors(__('admin.task_create.error.no_categories_configured'));
        }

        $payload = $this->validateTaskForm($request);
        $taskData = $this->buildTaskPayload($request, $payload);
        $channelIds = $this->selectedDistributionChannelIds($request);
        $this->validateHostedChannelContract($taskData, $channelIds);

        try {
            DB::transaction(function () use ($taskData, $channelIds): void {
                $this->distributionOrchestrator->lockTaskChannelSelection(null, $channelIds);
                $createdTask = $this->taskLifecycleService->createTask(
                    $taskData,
                    (int) auth('admin')->id(),
                );
                $createdTaskId = (int) ($createdTask['id'] ?? 0);
                if ($createdTaskId) {
                    $this->distributionOrchestrator->syncTaskChannels(
                        Task::query()->whereKey($createdTaskId)->firstOrFail(),
                        $channelIds
                    );
                }
            });
        } catch (TaskTitleReadinessException $e) {
            $report = $e->getDetails()['title_readiness'] ?? [];

            return back()
                ->withInput()
                ->with('title_readiness_report', $this->presentTitleReadiness($report))
                ->withErrors($e->getMessage());
        } catch (Throwable $e) {
            // 保留输入并回显服务层错误，便于在页面直接修正。
            return back()->withInput()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('admin.tasks.index')
            ->with('message', __('admin.task_create.message.created'));
    }

    /**
     * 任务编辑页：与创建页共用同一 Blade 模板。
     */
    public function edit(int $taskId): View|RedirectResponse
    {
        $this->assertCanManageHostedTask($taskId);

        try {
            $task = $this->taskLifecycleService->getTask($taskId);
        } catch (Throwable $e) {
            return redirect()->route('admin.tasks.index')->withErrors($e->getMessage());
        }

        $taskModel = Task::query()->whereKey($taskId)->firstOrFail();
        $formOptions = $this->loadTaskFormOptions($this->authenticatedAdmin(), $taskModel);

        return view('admin.tasks.form', [
            'pageTitle' => __('admin.task_edit.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'formOptions' => $formOptions,
            'hasCategories' => ! empty($formOptions['categories']),
            'categoryCreateUrl' => route('admin.categories.create'),
            'isEdit' => true,
            'taskId' => $taskId,
            'canManageProtectedWorkflows' => auth('admin')->user()?->canManageProtectedWorkflows() === true,
            'taskForm' => [
                'task_name' => (string) ($task['name'] ?? ''),
                'title_library_id' => (string) ($task['title_library_id'] ?? ''),
                'prompt_id' => (string) ($task['prompt_id'] ?? ''),
                'ai_model_id' => (string) ($task['ai_model_id'] ?? ''),
                'author_id' => (string) (($task['author_id'] ?? 0) ?: 0),
                'image_library_id' => (string) (($task['image_library_id'] ?? '') ?: ''),
                'image_count' => (string) ($task['image_count'] ?? 0),
                'knowledge_base_id' => (string) (($task['knowledge_base_id'] ?? '') ?: ''),
                'knowledge_base_ids' => $this->taskKnowledgeBaseIds($taskId, isset($task['knowledge_base_id']) ? (int) $task['knowledge_base_id'] : null),
                'fixed_category_id' => (string) (($task['fixed_category_id'] ?? '') ?: ''),
                'status' => (string) $taskModel->status,
                'article_limit' => (string) ($task['article_limit'] ?? 10),
                'created_count' => (int) ($task['created_count'] ?? 0),
                'draft_limit' => (string) ($task['draft_limit'] ?? 10),
                'publish_interval' => (string) max(1, (int) (($task['publish_interval'] ?? 3600) / 60)),
                'category_mode' => (string) ($task['category_mode'] ?? 'smart'),
                'model_selection_mode' => (string) ($task['model_selection_mode'] ?? 'fixed'),
                'need_review' => (int) ($task['need_review'] ?? 0),
                'ai_quality_enabled' => (bool) ($task['ai_quality_enabled'] ?? false),
                'ai_quality_retrieval_mode' => (string) ($task['ai_quality_retrieval_mode'] ?? ''),
                'ai_quality_timeout_sampling_enabled' => (bool) ($task['ai_quality_timeout_sampling_enabled'] ?? false),
                'ai_quality_auto_optimize_enabled' => (bool) ($task['ai_quality_auto_optimize_enabled'] ?? false),
                'ai_quality_optimization_level' => (string) ($task['ai_quality_optimization_level'] ?? 'excellent_80'),
                'ai_quality_prompt_id' => (string) (($task['ai_quality_prompt_id'] ?? '') ?: ''),
                'ai_quality_model_id' => (string) (($task['ai_quality_model_id'] ?? '') ?: ''),
                'ai_quality_pass_score' => (string) ($task['ai_quality_pass_score'] ?? 85),
                'ai_quality_manual_override_min_score' => (string) ($task['ai_quality_manual_override_min_score'] ?? 70),
                'ai_quality_policy_version' => (int) ($task['ai_quality_policy_version'] ?? 1),
                'is_loop' => (int) ($task['is_loop'] ?? 1),
                'auto_keywords' => (int) ($task['auto_keywords'] ?? 1),
                'auto_description' => (int) ($task['auto_description'] ?? 1),
                'publish_scope' => (string) $taskModel->publish_scope,
                'distribution_strategy' => (string) ($task['distribution_strategy'] ?? TaskDistributionChannelSelector::STRATEGY_BROADCAST),
                'distribution_channel_ids' => $this->taskDistributionChannelIds($taskId),
                'task_revision' => $this->distributionOrchestrator->taskRevision($taskModel),
            ],
        ]);
    }

    /**
     * 更新任务：与创建流程共享同一套字段校验与映射逻辑。
     */
    public function update(Request $request, int $taskId): RedirectResponse
    {
        $this->assertCanManageHostedTask($taskId);

        if (! Category::query()->exists()) {
            return redirect()
                ->route('admin.categories.create')
                ->withErrors(__('admin.task_create.error.no_categories_configured'));
        }

        $payload = $this->validateTaskForm($request);
        $taskData = $this->buildTaskPayload($request, $payload);
        $channelIds = $this->selectedDistributionChannelIds($request);
        $this->validateHostedChannelContract($taskData, $channelIds);
        $taskRevision = (string) $payload['task_revision'];

        try {
            DB::transaction(function () use ($taskId, $taskData, $channelIds, $taskRevision): void {
                $this->distributionOrchestrator->lockTaskChannelSelection($taskId, $channelIds);
                $this->distributionOrchestrator->assertTaskRevision($taskId, $taskRevision);
                $this->taskLifecycleService->updateTask(
                    $taskId,
                    $taskData,
                    $this->canManageHostedTask(),
                    (int) auth('admin')->id(),
                );
                $task = Task::query()->whereKey($taskId)->firstOrFail();
                $this->distributionOrchestrator->syncTaskChannels($task, $channelIds);
            });
        } catch (DistributionTaskRevisionMismatch $e) {
            return redirect()
                ->route('admin.tasks.edit', ['taskId' => $taskId])
                ->withErrors($e->getMessage());
        } catch (TaskTitleReadinessException $e) {
            $report = $e->getDetails()['title_readiness'] ?? [];

            return back()
                ->withInput()
                ->with('title_readiness_report', $this->presentTitleReadiness($report))
                ->withErrors($e->getMessage());
        } catch (Throwable $e) {
            return back()->withInput()->withErrors($e->getMessage());
        }

        return redirect()
            ->route('admin.tasks.index')
            ->with('message', __('admin.task_edit.message.update_success'));
    }

    /**
     * 任务监控快照接口：返回任务状态与队列面板数据。
     */
    public function healthCheck(Request $request): JsonResponse
    {
        try {
            $overview = $this->taskMonitoringQueryService->buildAdminOverview(
                max(1, $request->integer('page', 1)),
                50,
            );

            return response()->json([
                'success' => true,
                'tasks' => $this->decorateTaskManageability($overview['tasks']),
                'queue_overview' => $overview['queue_overview'],
                'worker_overview' => $overview['worker_overview'],
                'recent_runs' => $overview['recent_runs'],
                'worker_overview_html' => view('admin.tasks.partials.worker-overview', [
                    'workers' => $overview['worker_overview'],
                ])->render(),
                'recent_runs_html' => view('admin.tasks.partials.recent-runs', [
                    'recentJobs' => $overview['recent_runs'],
                ])->render(),
                'pagination' => $overview['pagination'],
                'task_summary' => $overview['task_summary'],
            ]);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => __('admin.tasks.message.status_update_failed'),
            ], 500);
        }
    }

    /**
     * 兼容旧接口：批量启动/停止单任务。
     */
    public function batchAction(Request $request): JsonResponse
    {
        // 批量接口仅允许 start/stop 两个动作，避免无效写入。
        $payload = $request->validate([
            'task_id' => ['required', 'integer', 'min:1'],
            'action' => ['required', 'string', 'in:start,stop'],
        ]);
        $this->assertCanManageHostedTask((int) $payload['task_id']);

        try {
            $taskId = (int) $payload['task_id'];
            $result = $payload['action'] === 'start'
                ? $this->taskLifecycleService->startTask(
                    $taskId,
                    true,
                    $this->canManageHostedTask(),
                    $this->authenticatedAdmin(),
                )
                : $this->taskLifecycleService->stopTask($taskId, $this->canManageHostedTask());

            return response()->json([
                'success' => true,
                'message' => 'ok',
                'data' => $result,
            ]);
        } catch (TaskTitleReadinessException $e) {
            $details = $e->getDetails();
            if (is_array($details['title_readiness'] ?? null)) {
                $details['title_readiness'] = $this->presentTitleReadiness($details['title_readiness']);
            }

            return response()->json([
                'success' => false,
                'error' => $e->getErrorCode(),
                'message' => $e->getMessage(),
                'details' => $details,
            ], 409);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => __('admin.tasks.message.status_update_failed'),
            ], 422);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadTasks(): array
    {
        return $this->taskMonitoringQueryService->buildTaskSnapshot();
    }

    /**
     * @return array{0: list<array<string, mixed>>, 1: array<string,int>, 2: list<array<string,mixed>>}
     */
    private function loadRuntimePanels(): array
    {
        $overview = $this->taskMonitoringQueryService->buildAdminOverview();

        return [
            $overview['worker_overview'],
            $overview['queue_overview'],
            $overview['recent_runs'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function taskI18n(): array
    {
        // 将页面所需文案统一下发给前端脚本，避免 JS 内硬编码文本。
        return [
            'stopBatch' => __('admin.tasks.action.stop_batch'),
            'startBatch' => __('admin.tasks.action.start_batch'),
            'createdOfLimitLabel' => __('admin.tasks.label.created_of_limit', ['created' => '__CREATED__', 'limit' => '__LIMIT__']),
            'draftArticlesLabel' => __('admin.tasks.label.draft_articles', ['count' => '__COUNT__']),
            'createdArticlesLabel' => __('admin.tasks.label.created_articles', ['count' => '__COUNT__']),
            'publishedArticlesLabel' => __('admin.tasks.label.published_articles', ['count' => '__COUNT__']),
            'loopTimesLabel' => __('admin.tasks.label.loop_times', ['count' => '__COUNT__']),
            'secondsSuffix' => __('admin.common.seconds'),
            'minutesSuffix' => __('admin.common.minutes'),
            'hoursSuffix' => __('admin.common.hours'),
            'daysSuffix' => __('admin.common.days'),
            'completed' => __('admin.tasks.status.completed'),
            'waiting' => __('admin.tasks.status.waiting'),
            'waitingPublish' => __('admin.tasks.status.waiting_publish'),
            'draftPoolFull' => __('admin.tasks.status.draft_pool_full'),
            'limitReached' => __('admin.tasks.status.limit_reached'),
            'queued' => __('admin.tasks.status.pending'),
            'running' => __('admin.tasks.status.running'),
            'nextRunAt' => __('admin.tasks.label.next_run_at', ['time' => '__TIME__']),
            'publishIntervalMinutes' => __('admin.tasks.label.publish_interval_minutes', ['count' => '__COUNT__']),
            'retryingWithAttempts' => __('admin.tasks.label.retrying_with_attempts', ['current' => '__CURRENT__', 'max' => '__MAX__']),
            'pendingRunning' => __('admin.tasks.label.pending_running', ['pending' => '__PENDING__', 'running' => '__RUNNING__']),
            'estimated' => __('admin.tasks.label.estimated', ['time' => '__TIME__']),
            'latestReason' => __('admin.tasks.label.latest_reason', ['message' => '__MESSAGE__']),
            'emptyContent' => __('admin.tasks.failure.empty_content'),
            'emptyContentDetail' => __('admin.tasks.failure.empty_content_detail'),
            'contentTooShort' => __('admin.tasks.failure.content_too_short'),
            'contentTooShortDetail' => __('admin.tasks.failure.content_too_short_detail'),
            'titleExhausted' => __('admin.tasks.failure.title_exhausted'),
            'titleExhaustedDetail' => __('admin.tasks.failure.title_exhausted_detail'),
            'taskPaused' => __('admin.tasks.failure.paused'),
            'taskPausedDetail' => __('admin.tasks.failure.paused_detail'),
            'modelTimeout' => __('admin.tasks.failure.model_timeout'),
            'modelTimeoutDetail' => __('admin.tasks.failure.model_timeout_detail', ['seconds' => '__SECONDS__']),
            'recentFailed' => __('admin.tasks.failure.recent_failed'),
            'syncFailed' => __('admin.tasks.message.status_update_failed'),
            'confirmStart' => __('admin.tasks.confirm.start', ['name' => '__NAME__']),
            'confirmStop' => __('admin.tasks.confirm.stop', ['name' => '__NAME__']),
            'starting' => __('admin.tasks.action.starting'),
            'stopping' => __('admin.tasks.action.stopping'),
            'startFailed' => __('admin.tasks.message.start_failed', ['message' => '__MESSAGE__']),
            'stopFailed' => __('admin.tasks.message.stop_failed', ['message' => '__MESSAGE__']),
            'requestFailed' => __('admin.tasks.message.request_failed', ['message' => '__MESSAGE__']),
            'taskQueued' => __('admin.tasks.message.task_queued', ['name' => '__NAME__']),
            'taskStopped' => __('admin.tasks.message.task_stopped', ['name' => '__NAME__']),
            'enabledStatus' => __('admin.tasks.status.enabled'),
            'disabledStatus' => __('admin.tasks.status.disabled'),
            'noRunnable' => __('admin.tasks.message.no_runnable'),
            'confirmRunAll' => __('admin.tasks.confirm.run_all'),
            'bulkSubmitted' => __('admin.tasks.message.bulk_submitted', ['success' => '__SUCCESS__', 'total' => '__TOTAL__']),
            'bulkSubmittedPartial' => __('admin.tasks.message.bulk_submitted_partial', ['success' => '__SUCCESS__', 'total' => '__TOTAL__']),
            'activating' => __('admin.tasks.action.activating'),
            'pausing' => __('admin.tasks.action.pausing'),
            'confirmActivate' => __('admin.tasks.confirm.activate'),
            'confirmPause' => __('admin.tasks.confirm.pause'),
        ];
    }

    /**
     * @return array{
     *     titleLibraries: list<array{id:int,name:string}>,
     *     prompts: list<array{id:int,name:string}>,
     *     aiModels: list<array{id:int,name:string,disabled?:bool,current_inaccessible?:bool,current_inaccessible_for?:list<string>}>,
     *     imageLibraries: list<array{id:int,name:string,count:int}>,
     *     knowledgeBases: list<array{id:int,name:string}>,
     *     authors: list<array{id:int,name:string}>,
     *     categories: list<array{id:int,name:string}>,
     *     distributionChannels: list<array{id:int,name:string,domain:string}>
     * }
     */
    private function loadTaskFormOptions(Admin $actor, ?Task $task = null): array
    {
        // 直接附带标题总数与可用数，避免 Blade 层再次查询。
        $titleLibraries = TitleLibrary::query()
            ->select(['id', 'name'])
            ->selectRaw('(SELECT COUNT(*) FROM titles WHERE titles.library_id = title_libraries.id) AS title_count')
            ->selectRaw('(SELECT COUNT(*) FROM titles WHERE titles.library_id = title_libraries.id AND (titles.used_count IS NULL OR titles.used_count <= 0)) AS available_title_count')
            ->orderByDesc('id')
            ->get()
            ->map(static function (TitleLibrary $row): array {
                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'count' => (int) ($row->title_count ?? 0),
                    'used' => max(0, (int) ($row->title_count ?? 0) - (int) ($row->available_title_count ?? 0)),
                    'available' => (int) ($row->available_title_count ?? 0),
                    'manage_url' => route('admin.title-libraries.detail', ['libraryId' => (int) $row->id]),
                ];
            })
            ->all();

        $prompts = Prompt::query()
            ->select(['id', 'name'])
            ->where('type', 'content')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Prompt $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $qualityPrompts = Prompt::query()
            ->select(['id', 'name', 'system_key', 'system_version'])
            ->where('type', 'quality_check')
            ->orderByRaw('system_key IS NULL')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (Prompt $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'system_managed' => filled($row->system_key),
                'version' => (string) ($row->system_version ?? ''),
            ])
            ->all();

        $aiModels = $this->adminAiModelAccessResolver
            ->usableQuery($actor)
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->get()
            ->map(static fn ($row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();
        $inaccessibleCurrentFields = collect([
            'ai_model_id' => (int) ($task?->ai_model_id ?? 0),
            'ai_quality_model_id' => (int) ($task?->ai_quality_model_id ?? 0),
        ])->filter(static fn (int $modelId): bool => $modelId > 0)
            ->reject(static fn (int $modelId): bool => collect($aiModels)->contains('id', $modelId))
            ->groupBy(static fn (int $modelId): int => $modelId);
        foreach ($inaccessibleCurrentFields->keys() as $modelId) {
            $currentModel = AiModel::query()->select(['id', 'name'])->find((int) $modelId);
            if (! $currentModel instanceof AiModel) {
                continue;
            }
            $fields = collect(['ai_model_id', 'ai_quality_model_id'])
                ->filter(static fn (string $field): bool => (int) ($task?->{$field} ?? 0) === (int) $modelId)
                ->values()
                ->all();
            $aiModels[] = [
                'id' => (int) $currentModel->id,
                'name' => (string) $currentModel->name,
                'disabled' => true,
                'current_inaccessible' => true,
                'current_inaccessible_for' => $fields,
            ];
        }

        // 兼容上游展示：图库名称 + 图片数量。
        $imageLibraries = ImageLibrary::query()
            ->select(['id', 'name'])
            ->selectRaw('(SELECT COUNT(*) FROM images WHERE images.library_id = image_libraries.id) AS image_count')
            ->orderBy('name')
            ->get()
            ->map(static function (ImageLibrary $row): array {
                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'count' => (int) ($row->image_count ?? 0),
                ];
            })
            ->all();

        $knowledgeBases = KnowledgeBase::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(static fn (KnowledgeBase $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();
        $retrievalReadiness = $this->aiQualityRetrievalReadinessService->inspect(
            array_column($knowledgeBases, 'id'),
        );
        $retrievalReadinessByKnowledgeBase = collect($retrievalReadiness['knowledge_bases'] ?? [])
            ->mapWithKeys(static fn (array $row): array => [(string) $row['id'] => $row])
            ->all();

        $authors = Author::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(static fn (Author $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $categories = Category::query()
            ->select(['id', 'name'])
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Category $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

        $distributionChannels = DistributionChannel::query()
            ->select(['id', 'name', 'domain'])
            ->where('status', 'active')
            ->when(
                auth('admin')->user()?->isSuperAdmin() !== true,
                fn ($query) => $query->where('channel_type', '!=', DistributionChannel::TYPE_HOSTED_SITE)
            )
            ->orderBy('name')
            ->get()
            ->map(static fn (DistributionChannel $row): array => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'domain' => (string) $row->domain,
            ])
            ->all();

        return [
            'titleLibraries' => $titleLibraries,
            'prompts' => $prompts,
            'qualityPrompts' => $qualityPrompts,
            'aiModels' => $aiModels,
            'imageLibraries' => $imageLibraries,
            'knowledgeBases' => $knowledgeBases,
            'aiQualityRetrievalReadinessByKnowledgeBase' => $retrievalReadinessByKnowledgeBase,
            'authors' => $authors,
            'categories' => $categories,
            'distributionChannels' => $distributionChannels,
        ];
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function presentTitleReadiness(array $report): array
    {
        $report = $this->redactProtectedTitleReadinessConflicts($report);
        $library = is_array($report['library'] ?? null) ? $report['library'] : [];
        $task = is_array($report['task'] ?? null) ? $report['task'] : [];
        $replace = [
            'name' => (string) ($library['name'] ?? ''),
            'total' => (int) ($library['total'] ?? 0),
            'used' => (int) ($library['used'] ?? 0),
            'available' => (int) ($library['available'] ?? 0),
            'remaining' => (int) ($task['remaining'] ?? 0),
            'shortage' => (int) ($report['shortage'] ?? 0),
            'max' => (int) ($report['suggested_article_limit'] ?? 0),
        ];

        $report['issues'] = collect($report['issues'] ?? [])->map(
            static function (array $issue) use ($replace): array {
                $code = (string) ($issue['code'] ?? 'request_failed');
                $key = 'admin.task_create.readiness.issue.'.$code;
                $suggestionIndexes = [1, 2, 3];
                if ($replace['max'] < 1 && $code === 'title_library_exhausted') {
                    $suggestionIndexes = [1, 2];
                } elseif ($replace['max'] < 1 && $code === 'title_library_shortage') {
                    $suggestionIndexes = [1, 3];
                }

                return $issue + [
                    'title' => __($key.'.title', $replace),
                    'message' => __($key.'.message', $replace),
                    'impact' => __($key.'.impact', $replace),
                    'suggestions' => collect($suggestionIndexes)
                        ->map(static fn (int $index): string => __($key.'.suggestion_'.$index, $replace))
                        ->filter(static fn (string $value): bool => $value !== '' && ! str_contains($value, 'admin.task_create.readiness.'))
                        ->values()
                        ->all(),
                ];
            }
        )->values()->all();
        $libraryId = (int) ($library['id'] ?? 0);
        $taskId = (int) ($task['id'] ?? 0);
        $report['manage_url'] = $libraryId > 0
            ? route('admin.title-libraries.detail', ['libraryId' => $libraryId])
            : null;
        $report['edit_url'] = $taskId > 0
            ? route('admin.tasks.edit', ['taskId' => $taskId])
            : null;
        $report['summary'] = __('admin.task_create.readiness.summary', $replace);
        $report['recommendation'] = (int) ($report['suggested_article_limit'] ?? 0) >= 1
            ? __('admin.task_create.readiness.recommendation', $replace)
            : __('admin.task_create.readiness.recommendation_without_limit', $replace);
        $report['paused_hint'] = ($task['status'] ?? '') === 'paused'
            ? __('admin.task_create.readiness.paused_hint')
            : null;

        return $report;
    }

    /** @param array<string,mixed> $report @return array<string,mixed> */
    private function redactProtectedTitleReadinessConflicts(array $report): array
    {
        $conflicts = is_array($report['conflicts'] ?? null) ? $report['conflicts'] : [];
        if ($conflicts === [] || auth('admin')->user()?->isSuperAdmin() === true) {
            return $report;
        }

        $taskIds = collect($conflicts)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->values();
        $protectedTaskIds = Task::query()
            ->whereIn('id', $taskIds)
            ->whereHas('distributionChannels', fn ($query) => $query->where(
                'channel_type',
                DistributionChannel::TYPE_HOSTED_SITE
            ))
            ->pluck('id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true]);

        $report['conflicts'] = collect($conflicts)
            ->reject(static fn (array $conflict): bool => $protectedTaskIds->has((int) ($conflict['id'] ?? 0)))
            ->values()
            ->all();
        $report['redacted_conflict_count'] = $protectedTaskIds->count();

        return $report;
    }

    /**
     * @return array{
     *     task_name: string,
     *     title_library_id: int,
     *     prompt_id: int,
     *     ai_model_id: int,
     *     author_id: int|null,
     *     image_library_id: int|null,
     *     image_count: int|null,
     *     knowledge_base_id: int|null,
     *     knowledge_base_ids: list<int>,
     *     fixed_category_id: int|null,
     *     status: string,
     *     article_limit: int|null,
     *     draft_limit: int|null,
     *     publish_interval: int|null,
     *     category_mode: string|null,
     *     model_selection_mode: string|null,
     *     distribution_strategy: string|null
     * }
     */
    private function validateTaskForm(Request $request): array
    {
        return $request->validate([
            'task_name' => ['required', 'string', 'max:200'],
            'title_library_id' => ['required', 'integer', 'min:1'],
            'prompt_id' => ['required', 'integer', 'min:1'],
            'ai_model_id' => ['required', 'integer', 'min:1'],
            'author_id' => ['nullable', 'integer', 'min:0'],
            'image_library_id' => ['nullable', 'integer', 'min:1'],
            'image_count' => ['nullable', 'integer', 'min:0', 'max:5'],
            'knowledge_base_id' => ['nullable', 'integer', 'min:1', 'exists:knowledge_bases,id'],
            'knowledge_base_ids' => ['nullable', 'array', 'max:5'],
            'knowledge_base_ids.*' => ['integer', 'min:1', 'distinct', 'exists:knowledge_bases,id'],
            'fixed_category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:active,paused'],
            'article_limit' => ['required', 'integer', 'min:1', 'max:99999'],
            'draft_limit' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'publish_interval' => ['nullable', 'integer', 'min:1'],
            'category_mode' => ['nullable', 'string', 'in:smart,fixed,random'],
            'model_selection_mode' => ['nullable', 'string', 'in:fixed,smart_failover'],
            'publish_scope' => ['nullable', 'string', 'in:local_and_distribution,distribution_only,local_only'],
            'distribution_strategy' => ['nullable', 'string', 'in:'.implode(',', TaskDistributionChannelSelector::strategies())],
            'distribution_channel_ids' => ['nullable', 'array'],
            'distribution_channel_ids.*' => ['integer', 'min:1'],
            'ai_quality_enabled' => ['nullable', 'boolean'],
            'ai_quality_retrieval_mode' => [
                Rule::requiredIf(fn (): bool => $request->boolean('ai_quality_enabled')
                    && $request->boolean('ai_quality_retrieval_mode_touched')),
                'nullable',
                'string',
                'in:'.implode(',', AiQualityRetrievalMode::values()),
            ],
            'ai_quality_retrieval_mode_touched' => ['nullable', 'boolean'],
            'ai_quality_timeout_sampling_enabled' => ['nullable', 'boolean'],
            'ai_quality_auto_optimize_enabled' => ['nullable', 'boolean'],
            'ai_quality_optimization_level' => ['nullable', 'string', 'in:pass,excellent_80,excellent_90'],
            'ai_quality_prompt_id' => ['nullable', 'integer', 'min:1', 'exists:prompts,id'],
            'ai_quality_model_id' => ['nullable', 'integer', 'min:1', 'exists:ai_models,id'],
            'ai_quality_pass_score' => ['nullable', 'integer', 'min:1', 'max:100'],
            'ai_quality_manual_override_min_score' => ['nullable', 'integer', 'min:0', 'max:99', 'lt:ai_quality_pass_score'],
            'task_revision' => [$request->routeIs('admin.tasks.update') ? 'required' : 'nullable', 'string', 'size:64'],
            'config_version' => [$request->routeIs('admin.tasks.update') ? 'required' : 'nullable', 'integer', 'min:1'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, int|string|null>
     */
    private function buildTaskPayload(Request $request, array $payload): array
    {
        $categoryMode = (string) ($payload['category_mode'] ?? 'smart');
        if ($categoryMode === 'random') {
            $categoryMode = 'smart';
        }

        $knowledgeBaseIds = $this->selectedKnowledgeBaseIds($payload);

        return [
            'name' => (string) $payload['task_name'],
            'title_library_id' => (int) $payload['title_library_id'],
            'image_library_id' => isset($payload['image_library_id']) ? (int) $payload['image_library_id'] : null,
            'image_count' => (int) ($payload['image_count'] ?? 0),
            'prompt_id' => (int) $payload['prompt_id'],
            'ai_model_id' => (int) $payload['ai_model_id'],
            'author_id' => isset($payload['author_id']) && (int) $payload['author_id'] > 0 ? (int) $payload['author_id'] : null,
            'knowledge_base_id' => $knowledgeBaseIds[0] ?? null,
            'knowledge_base_ids' => $knowledgeBaseIds,
            'fixed_category_id' => isset($payload['fixed_category_id']) ? (int) $payload['fixed_category_id'] : null,
            'status' => (string) $payload['status'],
            'publish_scope' => (string) ($payload['publish_scope'] ?? 'local_and_distribution'),
            'distribution_strategy' => (string) ($payload['distribution_strategy'] ?? TaskDistributionChannelSelector::STRATEGY_BROADCAST),
            'article_limit' => (int) ($payload['article_limit'] ?? 10),
            'draft_limit' => (int) ($payload['draft_limit'] ?? 10),
            'publish_interval' => max(1, (int) ($payload['publish_interval'] ?? 60)) * 60,
            'need_review' => $request->boolean('need_review') ? 1 : 0,
            'is_loop' => $request->boolean('is_loop') ? 1 : 0,
            'category_mode' => $categoryMode,
            'model_selection_mode' => (string) ($payload['model_selection_mode'] ?? 'fixed'),
            'auto_keywords' => $request->boolean('auto_keywords') ? 1 : 0,
            'auto_description' => $request->boolean('auto_description') ? 1 : 0,
            'ai_quality_enabled' => $request->boolean('ai_quality_enabled'),
            ...isset($payload['ai_quality_retrieval_mode'])
                ? ['ai_quality_retrieval_mode' => (string) $payload['ai_quality_retrieval_mode']]
                : [],
            'ai_quality_timeout_sampling_enabled' => $request->boolean('ai_quality_enabled')
                && $request->boolean('ai_quality_timeout_sampling_enabled'),
            'ai_quality_auto_optimize_enabled' => $request->boolean('ai_quality_enabled')
                && $request->boolean('ai_quality_auto_optimize_enabled'),
            'ai_quality_optimization_level' => (string) ($payload['ai_quality_optimization_level'] ?? 'excellent_80'),
            'ai_quality_prompt_id' => isset($payload['ai_quality_prompt_id']) ? (int) $payload['ai_quality_prompt_id'] : null,
            'ai_quality_model_id' => isset($payload['ai_quality_model_id']) ? (int) $payload['ai_quality_model_id'] : null,
            'ai_quality_pass_score' => (int) ($payload['ai_quality_pass_score'] ?? 85),
            'ai_quality_manual_override_min_score' => (int) ($payload['ai_quality_manual_override_min_score'] ?? 70),
            ...isset($payload['config_version']) ? ['config_version' => (int) $payload['config_version']] : [],
        ];
    }

    /**
     * @return list<int>
     */
    private function selectedKnowledgeBaseIds(array $payload): array
    {
        $ids = isset($payload['knowledge_base_ids']) && is_array($payload['knowledge_base_ids'])
            ? $payload['knowledge_base_ids']
            : [];

        if (empty($ids) && isset($payload['knowledge_base_id'])) {
            $ids = [(int) $payload['knowledge_base_id']];
        }

        return collect($ids)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function selectedDistributionChannelIds(Request $request): array
    {
        if ((string) $request->input('publish_scope', 'local_and_distribution') === 'local_only') {
            return [];
        }

        return collect($request->input('distribution_channel_ids', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string,int|string|null> $taskData @param list<int> $channelIds */
    private function validateHostedChannelContract(array $taskData, array $channelIds): void
    {
        if ($channelIds === []) {
            return;
        }

        $hostedCount = DistributionChannel::query()
            ->whereIn('id', $channelIds)
            ->where('channel_type', DistributionChannel::TYPE_HOSTED_SITE)
            ->count();
        if ($hostedCount > 0 && auth('admin')->user()?->isSuperAdmin() !== true) {
            throw ValidationException::withMessages([
                'distribution_channel_ids' => '托管站点只能由超级管理员绑定到任务。',
            ]);
        }
        if ($hostedCount > 1) {
            throw ValidationException::withMessages([
                'distribution_channel_ids' => '阶段一任务最多只能绑定一个托管站点。',
            ]);
        }
        if ($hostedCount === 1 && (string) ($taskData['publish_scope'] ?? '') !== 'distribution_only') {
            throw ValidationException::withMessages([
                'publish_scope' => '绑定托管站点的任务必须使用仅发布到渠道站点。',
            ]);
        }
    }

    private function assertCanManageHostedTask(int $taskId): void
    {
        if (auth('admin')->user()?->isSuperAdmin() === true) {
            return;
        }

        $hasHostedChannel = Task::query()
            ->whereKey($taskId)
            ->whereHas('distributionChannels', fn ($query) => $query->where(
                'channel_type',
                DistributionChannel::TYPE_HOSTED_SITE
            ))
            ->exists();
        abort_if($hasHostedChannel, 403);
    }

    private function canManageHostedTask(): bool
    {
        return auth('admin')->user()?->isSuperAdmin() === true;
    }

    private function authenticatedAdmin(): Admin
    {
        $admin = auth('admin')->user();
        abort_unless($admin instanceof Admin, 401);

        return $admin;
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     * @return list<array<string,mixed>>
     */
    private function decorateTaskManageability(array $tasks): array
    {
        if ($tasks === [] || auth('admin')->user()?->isSuperAdmin() === true) {
            return array_map(static function (array $task): array {
                $task['can_manage'] = true;

                return $task;
            }, $tasks);
        }

        $taskIds = collect($tasks)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->filter()
            ->values();
        $hostedTaskIds = Task::query()
            ->whereIn('id', $taskIds)
            ->whereHas('distributionChannels', fn ($query) => $query->where(
                'channel_type',
                DistributionChannel::TYPE_HOSTED_SITE
            ))
            ->pluck('id')
            ->mapWithKeys(static fn ($id): array => [(int) $id => true]);

        return array_map(static function (array $task) use ($hostedTaskIds): array {
            $task['can_manage'] = ! $hostedTaskIds->has((int) ($task['id'] ?? 0));

            return $task;
        }, $tasks);
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     * @return list<array<string,mixed>>
     */
    private function decorateTaskTrashManageability(array $tasks): array
    {
        $canManageProtectedTask = $this->canManageHostedTask();

        return array_map(static function (array $task) use ($canManageProtectedTask): array {
            $task['can_restore'] = $canManageProtectedTask
                || ! (bool) ($task['requires_super_admin_restore'] ?? false);

            return $task;
        }, $tasks);
    }

    /**
     * @return list<int>
     */
    private function taskDistributionChannelIds(int $taskId): array
    {
        $task = Task::query()->whereKey($taskId)->first();
        if (! $task) {
            return [];
        }

        return $task->distributionChannels()
            ->pluck('distribution_channels.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function taskKnowledgeBaseIds(int $taskId, ?int $legacyKnowledgeBaseId = null): array
    {
        if (Schema::hasTable('task_knowledge_bases')) {
            $ids = Task::query()
                ->whereKey($taskId)
                ->first()
                ?->knowledgeBases()
                ->pluck('knowledge_bases.id')
                ->map(static fn ($id): int => (int) $id)
                ->all() ?? [];

            if (! empty($ids)) {
                return $ids;
            }
        }

        return $legacyKnowledgeBaseId && $legacyKnowledgeBaseId > 0
            ? [$legacyKnowledgeBaseId]
            : [];
    }
}
