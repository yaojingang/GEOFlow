<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TitleLibrary;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\GeoFlow\TaskMonitoringQueryService;
use App\Support\AdminWeb;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    ) {}

    /**
     * 任务管理首页：渲染列表与运行面板。
     */
    public function index(): View
    {
        try {
            $overview = $this->taskMonitoringQueryService->buildAdminOverview();
            $tasks = $overview['tasks'];
            $workers = $overview['worker_overview'];
            $queueStats = $overview['queue_overview'];
            $recentJobs = $overview['recent_runs'];
            $error = null;
        } catch (Throwable $e) {
            $tasks = [];
            $workers = [];
            $queueStats = ['pending' => 0, 'running' => 0, 'failed' => 0, 'completed' => 0];
            $recentJobs = [];
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
            'legacyError' => $error,
            'taskI18n' => $this->taskI18n(),
            'taskRealtime' => $this->taskRealtimeConfig(),
        ]);
    }

    /**
     * 切换任务启停状态（active -> stop，paused -> start）。
     */
    public function toggleStatus(Request $request, int $taskId): RedirectResponse
    {
        if ($taskId <= 0) {
            return back()->withErrors(__('admin.tasks.message.status_update_failed'));
        }

        try {
            $currentStatus = (string) $request->input('status', 'paused');
            if ($currentStatus === 'active') {
                $this->taskLifecycleService->stopTask($taskId);

                return back()->with('message', __('admin.tasks.message.paused_stopped'));
            }

            $this->taskLifecycleService->startTask($taskId, false);

            return back()->with('message', __('admin.tasks.message.activated'));
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

        try {
            $this->deleteTaskCascade($taskId);

            return back()->with('message', __('admin.tasks.message.delete_success'));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.tasks.message.delete_failed', ['message' => $e->getMessage()]));
        }
    }

    /**
     * 任务创建页（先接入可用创建链路，后续继续做 1:1 细节对齐）。
     */
    public function create(): View
    {
        $formOptions = $this->loadTaskFormOptions();

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

        try {
            $this->taskLifecycleService->createTask($taskData);
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
        try {
            $task = $this->taskLifecycleService->getTask($taskId);
        } catch (Throwable $e) {
            return redirect()->route('admin.tasks.index')->withErrors($e->getMessage());
        }

        $formOptions = $this->loadTaskFormOptions();

        return view('admin.tasks.form', [
            'pageTitle' => __('admin.task_edit.page_title'),
            'activeMenu' => 'tasks',
            'adminSiteName' => AdminWeb::siteName(),
            'formOptions' => $formOptions,
            'hasCategories' => ! empty($formOptions['categories']),
            'categoryCreateUrl' => route('admin.categories.create'),
            'isEdit' => true,
            'taskId' => $taskId,
            'taskForm' => [
                'task_name' => (string) ($task['name'] ?? ''),
                'title_library_id' => (string) ($task['title_library_id'] ?? ''),
                'prompt_id' => (string) ($task['prompt_id'] ?? ''),
                'ai_model_id' => (string) ($task['ai_model_id'] ?? ''),
                'author_id' => (string) (($task['author_id'] ?? 0) ?: 0),
                'image_library_id' => (string) (($task['image_library_id'] ?? '') ?: ''),
                'image_count' => (string) ($task['image_count'] ?? 0),
                'knowledge_base_id' => (string) (($task['knowledge_base_id'] ?? '') ?: ''),
                'fixed_category_id' => (string) (($task['fixed_category_id'] ?? '') ?: ''),
                'status' => (string) ($task['status'] ?? 'active'),
                'article_limit' => (string) ($task['article_limit'] ?? 10),
                'draft_limit' => (string) ($task['draft_limit'] ?? 10),
                'publish_interval' => (string) max(1, (int) (($task['publish_interval'] ?? 3600) / 60)),
                'category_mode' => (string) ($task['category_mode'] ?? 'smart'),
                'model_selection_mode' => (string) ($task['model_selection_mode'] ?? 'fixed'),
                'need_review' => (int) ($task['need_review'] ?? 0),
                'is_loop' => (int) ($task['is_loop'] ?? 1),
                'auto_keywords' => (int) ($task['auto_keywords'] ?? 1),
                'auto_description' => (int) ($task['auto_description'] ?? 1),
            ],
        ]);
    }

    /**
     * 更新任务：与创建流程共享同一套字段校验与映射逻辑。
     */
    public function update(Request $request, int $taskId): RedirectResponse
    {
        if (! Category::query()->exists()) {
            return redirect()
                ->route('admin.categories.create')
                ->withErrors(__('admin.task_create.error.no_categories_configured'));
        }

        $payload = $this->validateTaskForm($request);
        $taskData = $this->buildTaskPayload($request, $payload);

        try {
            $this->taskLifecycleService->updateTask($taskId, $taskData);
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
            $overview = $this->taskMonitoringQueryService->buildAdminOverview();

            return response()->json([
                'success' => true,
                'tasks' => $overview['tasks'],
                'queue_overview' => $overview['queue_overview'],
                'worker_overview' => $overview['worker_overview'],
                'recent_runs' => $overview['recent_runs'],
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
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

        try {
            $taskId = (int) $payload['task_id'];
            $result = $payload['action'] === 'start'
                ? $this->taskLifecycleService->startTask($taskId, true)
                : $this->taskLifecycleService->stopTask($taskId);

            return response()->json([
                'success' => true,
                'message' => 'ok',
                'data' => $result,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * 与 bak 一致的关联删除顺序，避免外键阻塞。
     */
    private function deleteTaskCascade(int $taskId): void
    {
        DB::transaction(function () use ($taskId): void {
            // 先软删任务文章，保持删除任务时文章进入回收站。
            Article::query()
                ->where('task_id', $taskId)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('article_queue')->where('task_id', $taskId)->delete();
            DB::table('task_materials')->where('task_id', $taskId)->delete();
            DB::table('task_schedules')->where('task_id', $taskId)->delete();

            // SoftDeletes 默认会排除刚软删的文章；这里必须包含回收站文章，否则 FK 会阻止删除任务。
            Article::withTrashed()
                ->where('task_id', $taskId)
                ->update([
                    'task_id' => null,
                    'updated_at' => now(),
                ]);
            Task::query()->whereKey($taskId)->delete();
        });
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
     * @return array{enabled:bool,key:string,host:string,port:int,scheme:string}
     */
    private function taskRealtimeConfig(): array
    {
        $reverbApp = config('reverb.apps.apps.0', []);
        $host = (string) (config('reverb.servers.reverb.hostname') ?: config('app.url'));
        $parsedHost = parse_url($host, PHP_URL_HOST);

        return [
            'enabled' => (string) config('broadcasting.default') === 'reverb',
            'key' => (string) ($reverbApp['key'] ?? ''),
            'host' => $parsedHost ? (string) $parsedHost : (string) $host,
            'port' => (int) (config('reverb.apps.apps.0.options.port') ?: 443),
            'scheme' => (string) (config('reverb.apps.apps.0.options.scheme') ?: 'https'),
        ];
    }

    /**
     * @return array{
     *     titleLibraries: list<array{id:int,name:string}>,
     *     prompts: list<array{id:int,name:string}>,
     *     aiModels: list<array{id:int,name:string}>,
     *     imageLibraries: list<array{id:int,name:string,count:int}>,
     *     knowledgeBases: list<array{id:int,name:string}>,
     *     authors: list<array{id:int,name:string}>,
     *     categories: list<array{id:int,name:string}>
     * }
     */
    private function loadTaskFormOptions(): array
    {
        // 直接附带标题数，避免 Blade 层再次查询。
        $titleLibraries = TitleLibrary::query()
            ->select(['id', 'name'])
            ->selectRaw('(SELECT COUNT(*) FROM titles WHERE titles.library_id = title_libraries.id) AS title_count')
            ->orderByDesc('id')
            ->get()
            ->map(static function (TitleLibrary $row): array {
                return [
                    'id' => (int) $row->id,
                    'name' => (string) $row->name,
                    'count' => (int) ($row->title_count ?? 0),
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

        $aiModels = AiModel::query()
            ->select(['id', 'name'])
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderByDesc('id')
            ->get()
            ->map(static fn (AiModel $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name])
            ->all();

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

        return [
            'titleLibraries' => $titleLibraries,
            'prompts' => $prompts,
            'aiModels' => $aiModels,
            'imageLibraries' => $imageLibraries,
            'knowledgeBases' => $knowledgeBases,
            'authors' => $authors,
            'categories' => $categories,
        ];
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
     *     fixed_category_id: int|null,
     *     status: string,
     *     article_limit: int|null,
     *     draft_limit: int|null,
     *     publish_interval: int|null,
     *     category_mode: string|null,
     *     model_selection_mode: string|null
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
            'knowledge_base_id' => ['nullable', 'integer', 'min:1'],
            'fixed_category_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:active,paused'],
            'article_limit' => ['nullable', 'integer', 'min:1', 'max:99999'],
            'draft_limit' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'publish_interval' => ['nullable', 'integer', 'min:1'],
            'category_mode' => ['nullable', 'string', 'in:smart,fixed,random'],
            'model_selection_mode' => ['nullable', 'string', 'in:fixed,smart_failover'],
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

        return [
            'name' => (string) $payload['task_name'],
            'title_library_id' => (int) $payload['title_library_id'],
            'image_library_id' => isset($payload['image_library_id']) ? (int) $payload['image_library_id'] : null,
            'image_count' => (int) ($payload['image_count'] ?? 0),
            'prompt_id' => (int) $payload['prompt_id'],
            'ai_model_id' => (int) $payload['ai_model_id'],
            'author_id' => isset($payload['author_id']) && (int) $payload['author_id'] > 0 ? (int) $payload['author_id'] : null,
            'knowledge_base_id' => isset($payload['knowledge_base_id']) ? (int) $payload['knowledge_base_id'] : null,
            'fixed_category_id' => isset($payload['fixed_category_id']) ? (int) $payload['fixed_category_id'] : null,
            'status' => (string) $payload['status'],
            'article_limit' => (int) ($payload['article_limit'] ?? 10),
            'draft_limit' => (int) ($payload['draft_limit'] ?? 10),
            'publish_interval' => max(1, (int) ($payload['publish_interval'] ?? 60)) * 60,
            'need_review' => $request->boolean('need_review') ? 1 : 0,
            'is_loop' => $request->boolean('is_loop') ? 1 : 0,
            'category_mode' => $categoryMode,
            'model_selection_mode' => (string) ($payload['model_selection_mode'] ?? 'fixed'),
            'auto_keywords' => $request->boolean('auto_keywords') ? 1 : 0,
            'auto_description' => $request->boolean('auto_description') ? 1 : 0,
        ];
    }
}
