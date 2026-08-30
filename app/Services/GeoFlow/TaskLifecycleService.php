<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ApiException;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\ImageLibrary;
use App\Models\KnowledgeBase;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\TaskRun;
use App\Models\TaskSchedule;
use App\Models\TitleLibrary;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 任务生命周期服务。
 *
 * 该服务聚合任务在 API 场景下的完整生命周期能力：
 * - 任务的创建、查询、更新、启停；
 * - 入队前置校验与投递；
 * - 运行记录（task_runs）查询；
 * - 表单输入归一化与业务校验。
 *
 * 约束说明：
 * - 这里只做“生命周期编排”，真正的执行与重试由 JobQueueService/队列 Job 负责；
 * - 对外异常统一抛 ApiException，便于 API 层形成稳定响应契约。
 */
class TaskLifecycleService
{
    /**
     * @param  JobQueueService  $queueService  队列调度服务（负责 task_runs 入队、状态流转、重试）
     */
    public function __construct(
        private JobQueueService $queueService,
        private TaskMonitoringQueryService $taskMonitoringQueryService,
        private TaskRealtimeBroadcastService $taskRealtimeBroadcastService,
        private TaskTitleReadinessService $taskTitleReadinessService,
        private ArticleAiQualityInvalidationService $articleAiQualityInvalidationService,
    ) {}

    /**
     * 分页查询任务列表（含 pending/running 运行计数）。
     *
     * @param  int  $page  页码（最小 1）
     * @param  int  $perPage  每页数量（1~100）
     * @param  array<string,mixed>  $filters  支持 status/search 过滤
     * @return array{
     *     items:list<array<string,mixed>>,
     *     pagination:array{page:int,per_page:int,total:int,total_pages:int}
     * }
     */
    public function listTasks(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        return $this->taskMonitoringQueryService->listTasksPaginated($page, $perPage, $filters);
    }

    /**
     * 创建任务并初始化调度状态。
     *
     * 流程：
     * 1. 归一化并校验输入；
     * 2. 创建 tasks 主记录；
     * 3. 初始化调度字段；
     * 4. 若任务初始为 active，补一条 task_schedules，否则显式关闭 schedule。
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed> 新建后的任务详情（getTask 结构）
     */
    public function createTask(array $data): array
    {
        $normalized = $this->normalizeTaskInput($data, false);
        if ($normalized['status'] === 'active') {
            $this->taskTitleReadinessService->assertCanActivate(
                $this->taskTitleReadinessService->inspect(
                    (int) $normalized['title_library_id'],
                    (int) $normalized['article_limit'],
                    (bool) $normalized['is_loop'],
                    'active',
                ),
                422,
            );
        }

        $taskId = DB::transaction(function () use ($normalized): int {
            $task = Task::query()->create([
                'name' => $normalized['name'],
                'title_library_id' => $normalized['title_library_id'],
                'image_library_id' => $normalized['image_library_id'],
                'image_count' => $normalized['image_count'],
                'prompt_id' => $normalized['prompt_id'],
                'ai_model_id' => $normalized['ai_model_id'],
                'ai_quality_enabled' => $normalized['ai_quality_enabled'],
                'ai_quality_timeout_sampling_enabled' => $normalized['ai_quality_timeout_sampling_enabled'],
                'ai_quality_auto_optimize_enabled' => $normalized['ai_quality_auto_optimize_enabled'],
                'ai_quality_optimization_level' => $normalized['ai_quality_optimization_level'],
                'ai_quality_prompt_id' => $normalized['ai_quality_prompt_id'],
                'ai_quality_model_id' => $normalized['ai_quality_model_id'],
                'ai_quality_pass_score' => $normalized['ai_quality_pass_score'],
                'ai_quality_manual_override_min_score' => $normalized['ai_quality_manual_override_min_score'],
                'need_review' => $normalized['need_review'],
                'publish_interval' => $normalized['publish_interval'],
                'author_id' => $normalized['author_id'],
                'auto_keywords' => $normalized['auto_keywords'],
                'auto_description' => $normalized['auto_description'],
                'draft_limit' => $normalized['draft_limit'],
                'article_limit' => $normalized['article_limit'],
                'is_loop' => $normalized['is_loop'],
                'model_selection_mode' => $normalized['model_selection_mode'],
                'status' => $normalized['status'],
                'publish_scope' => $normalized['publish_scope'],
                'distribution_strategy' => $normalized['distribution_strategy'],
                'distribution_cursor' => 0,
                'knowledge_base_id' => $normalized['knowledge_base_id'],
                'category_mode' => $normalized['category_mode'],
                'fixed_category_id' => $normalized['fixed_category_id'],
            ]);

            $taskId = (int) $task->id;
            $this->syncTaskKnowledgeBases($taskId, $normalized['knowledge_base_ids'] ?? []);
            $this->queueService->initializeTaskSchedule($taskId);

            if ($normalized['status'] === 'active') {
                TaskSchedule::query()->create([
                    'task_id' => $taskId,
                    'next_run_time' => now()->addMinute(),
                ]);
            } else {
                Task::query()->whereKey($taskId)->update([
                    'schedule_enabled' => 0,
                    'next_run_at' => null,
                    'updated_at' => now(),
                ]);
            }

            return $taskId;
        });

        $task = $this->getTask($taskId);
        $this->broadcastOverviewAfterCommit();

        return $task;
    }

    /**
     * Create a deliberately incomplete task draft for later configuration.
     * Drafts stay paused and still receive the same scheduler initialization
     * and realtime refresh used by the regular task lifecycle.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function createDraftTask(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new ApiException('validation_failed', '任务名称不能为空', 422);
        }
        $articleLimit = max(1, min(100, (int) ($data['article_limit'] ?? 10)));
        $publishInterval = max(60, min(2592000, (int) ($data['publish_interval'] ?? 3600)));

        $taskId = DB::transaction(function () use ($name, $articleLimit, $publishInterval): int {
            $dependencies = $this->resolveDraftTaskDependencies();
            $task = Task::query()->create([
                'name' => $name,
                'title_library_id' => $dependencies['title_library_id'],
                'prompt_id' => $dependencies['prompt_id'],
                'ai_model_id' => $dependencies['ai_model_id'],
                'image_count' => 0,
                'need_review' => 1,
                'publish_interval' => $publishInterval,
                'auto_keywords' => 1,
                'auto_description' => 1,
                'draft_limit' => $articleLimit,
                'article_limit' => $articleLimit,
                'is_loop' => 0,
                'model_selection_mode' => 'fixed',
                'status' => 'paused',
                'schedule_enabled' => 0,
                'publish_scope' => 'local_only',
                'distribution_strategy' => TaskDistributionChannelSelector::STRATEGY_BROADCAST,
                'category_mode' => 'smart',
                'max_retry_count' => 3,
            ]);
            $this->queueService->initializeTaskSchedule((int) $task->id);
            Task::query()->whereKey($task->id)->update([
                'schedule_enabled' => 0,
                'next_run_at' => null,
                'updated_at' => now(),
            ]);

            return (int) $task->id;
        });

        $task = $this->getTask($taskId);
        $this->broadcastOverviewAfterCommit();

        return $task;
    }

    /** @return array{title_library_id:int,prompt_id:int,ai_model_id:int} */
    private function resolveDraftTaskDependencies(): array
    {
        $titleLibraryId = TitleLibrary::query()->orderByDesc('id')->value('id');
        $promptId = Prompt::query()
            ->where('type', 'content')
            ->orderByDesc('id')
            ->value('id');
        $aiModelId = AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderByDesc('id')
            ->value('id');

        $missing = [];
        if ($titleLibraryId === null) {
            $missing[] = '标题库';
        }
        if ($promptId === null) {
            $missing[] = '内容提示词';
        }
        if ($aiModelId === null) {
            $missing[] = '已启用的对话模型';
        }
        if ($missing !== []) {
            throw new ApiException(
                'configuration_required',
                '创建任务草稿前，请先配置'.implode('、', $missing).'。',
                422,
                ['missing' => $missing],
            );
        }

        return [
            'title_library_id' => (int) $titleLibraryId,
            'prompt_id' => (int) $promptId,
            'ai_model_id' => (int) $aiModelId,
        ];
    }

    /**
     * 获取单任务详情（含任务运行摘要与文章统计摘要）。
     *
     * @return array<string,mixed>
     *
     * @throws ApiException 当任务不存在时抛出 404
     */
    public function getTask(int $taskId): array
    {
        try {
            return $this->taskMonitoringQueryService->getTaskMonitoringDetail($taskId);
        } catch (ModelNotFoundException) {
            throw new ApiException('task_not_found', '任务不存在', 404);
        }
    }

    /**
     * 更新任务配置（支持局部更新）。
     *
     * 特殊规则：
     * - status 单独处理：active -> activateTask，paused -> pauseTask；
     * - 其余字段只更新传入字段。
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function updateTask(int $taskId, array $data, bool $canManageHostedTask = false): array
    {
        $this->ensureTaskExists($taskId);
        $normalized = $this->normalizeTaskInput($data, true);
        if (empty($normalized)) {
            throw new ApiException('validation_failed', '没有可更新的字段', 422);
        }

        $status = $normalized['status'] ?? null;
        unset($normalized['status']);
        $knowledgeBaseIdsProvided = array_key_exists('knowledge_base_ids', $normalized);
        $qualityConfigurationChanged = false;
        $optimizationLevelChanged = false;
        $optimizationWasDisabled = false;
        $knowledgeBaseIds = $knowledgeBaseIdsProvided ? $normalized['knowledge_base_ids'] : [];
        unset($normalized['knowledge_base_ids']);
        $samplingWasDisabled = false;

        DB::transaction(function () use (&$qualityConfigurationChanged, &$optimizationLevelChanged, &$optimizationWasDisabled, &$samplingWasDisabled, $normalized, $knowledgeBaseIdsProvided, $knowledgeBaseIds, $status, $taskId, $canManageHostedTask): void {
            Article::withTrashed()
                ->where('task_id', $taskId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $current = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->firstOrFail([
                    'id',
                    'title_library_id',
                    'article_limit',
                    'created_count',
                    'is_loop',
                    'status',
                    'knowledge_base_id',
                    'ai_quality_enabled',
                    'ai_quality_timeout_sampling_enabled',
                    'ai_quality_auto_optimize_enabled',
                    'ai_quality_optimization_level',
                    'ai_quality_prompt_id',
                    'ai_quality_model_id',
                    'ai_quality_pass_score',
                    'ai_quality_manual_override_min_score',
                    'ai_model_id',
                    'model_selection_mode',
                    'publish_scope',
                    'distribution_strategy',
                    'need_review',
                ]);
            $this->assertCanManageHostedTask($current, $canManageHostedTask);
            $effectiveStatus = $status ?? (string) $current->status;
            if ($effectiveStatus === 'active') {
                $effectiveTitleLibraryId = array_key_exists('title_library_id', $normalized)
                    ? (int) ($normalized['title_library_id'] ?? 0)
                    : (int) $current->title_library_id;
                $this->taskTitleReadinessService->assertCanActivate(
                    $this->taskTitleReadinessService->inspect(
                        $effectiveTitleLibraryId,
                        (int) ($normalized['article_limit'] ?? $current->article_limit),
                        (bool) ($normalized['is_loop'] ?? $current->is_loop),
                        'active',
                        $taskId,
                    ),
                    422,
                );
            }

            $effectiveAiQualityEnabled = array_key_exists('ai_quality_enabled', $normalized)
                ? (bool) $normalized['ai_quality_enabled']
                : (bool) $current->ai_quality_enabled;
            if (! $effectiveAiQualityEnabled
                && (array_key_exists('ai_quality_enabled', $normalized)
                    || array_key_exists('ai_quality_timeout_sampling_enabled', $normalized)
                    || array_key_exists('ai_quality_auto_optimize_enabled', $normalized))) {
                $normalized['ai_quality_timeout_sampling_enabled'] = 0;
                $normalized['ai_quality_auto_optimize_enabled'] = 0;
            }
            $effectiveSamplingEnabled = $effectiveAiQualityEnabled && (array_key_exists('ai_quality_timeout_sampling_enabled', $normalized)
                ? (bool) $normalized['ai_quality_timeout_sampling_enabled']
                : (bool) $current->ai_quality_timeout_sampling_enabled);
            $samplingWasDisabled = (bool) $current->ai_quality_timeout_sampling_enabled && ! $effectiveSamplingEnabled;
            $effectiveOptimizationEnabled = $effectiveAiQualityEnabled && (array_key_exists('ai_quality_auto_optimize_enabled', $normalized)
                ? (bool) $normalized['ai_quality_auto_optimize_enabled']
                : (bool) $current->ai_quality_auto_optimize_enabled);
            $optimizationWasDisabled = (bool) $current->ai_quality_auto_optimize_enabled && ! $effectiveOptimizationEnabled;
            $optimizationLevelChanged = array_key_exists('ai_quality_optimization_level', $normalized)
                && (string) $normalized['ai_quality_optimization_level'] !== (string) $current->ai_quality_optimization_level;

            $effectiveKnowledgeBaseIds = $knowledgeBaseIdsProvided
                ? (is_array($knowledgeBaseIds) ? $knowledgeBaseIds : [])
                : $this->currentTaskKnowledgeBaseIds($taskId, $current->knowledge_base_id);
            $currentKnowledgeBaseIds = $this->currentTaskKnowledgeBaseIds($taskId, $current->knowledge_base_id);
            $normalizedCurrentKnowledgeBaseIds = array_values(array_unique(array_map('intval', $currentKnowledgeBaseIds)));
            $normalizedEffectiveKnowledgeBaseIds = array_values(array_unique(array_map('intval', $effectiveKnowledgeBaseIds)));
            sort($normalizedCurrentKnowledgeBaseIds);
            sort($normalizedEffectiveKnowledgeBaseIds);
            $qualityConfigurationChanged = $normalizedCurrentKnowledgeBaseIds !== $normalizedEffectiveKnowledgeBaseIds;
            foreach ([
                'ai_quality_enabled',
                'ai_quality_prompt_id',
                'ai_quality_model_id',
                'ai_quality_pass_score',
                'ai_quality_manual_override_min_score',
                'ai_model_id',
                'model_selection_mode',
                'publish_scope',
                'distribution_strategy',
                'need_review',
            ] as $field) {
                if (array_key_exists($field, $normalized)
                    && (string) ($normalized[$field] ?? '') !== (string) ($current->{$field} ?? '')) {
                    $qualityConfigurationChanged = true;
                    break;
                }
            }
            $this->assertEffectiveAiQualityConfiguration($current, $normalized, $effectiveKnowledgeBaseIds);

            if (! empty($normalized)) {
                Task::query()->whereKey($taskId)->update($normalized);
            }

            if ($knowledgeBaseIdsProvided) {
                $this->syncTaskKnowledgeBases($taskId, is_array($knowledgeBaseIds) ? $knowledgeBaseIds : []);
            }

            if ($status === 'active') {
                $this->activateTask($taskId, false);
            } elseif ($status === 'paused') {
                $this->pauseTask($taskId, '任务已暂停');
            }

            if ($qualityConfigurationChanged) {
                $this->articleAiQualityInvalidationService->invalidateTask($taskId, '任务质检配置或知识依据已更新');
            } elseif ($optimizationWasDisabled) {
                $this->articleAiQualityInvalidationService->cancelTaskOptimization(
                    $taskId,
                    '任务已关闭自动优化',
                    recoverWorkflow: true,
                );
            } elseif ($optimizationLevelChanged) {
                $this->articleAiQualityInvalidationService->invalidateTaskOptimization(
                    $taskId,
                    '任务自动优化目标已更新',
                    stopReason: 'task_optimization_level_changed',
                    recoverWorkflow: true,
                    taskAutoOnly: true,
                );
            } elseif ($samplingWasDisabled) {
                $this->articleAiQualityInvalidationService->invalidateSampledTaskChecks(
                    $taskId,
                    '任务已关闭超时自动抽样，旧抽样结果不再作为当前发布依据',
                );
            }

        });

        if ($qualityConfigurationChanged) {
            $this->articleAiQualityInvalidationService->invalidateTask($taskId, '任务质检配置或知识依据已更新');
        }

        $task = $this->getTask($taskId);
        $this->broadcastOverviewAfterCommit();

        return $task;
    }

    /**
     * 删除任务：任务保留在回收站 90 天，关联文章进入内容回收站后解除 task_id 绑定。
     *
     * @return array{id:int,name:string,deleted:bool}
     */
    public function deleteTask(int $taskId, bool $canManageHostedTask = false): array
    {
        $taskName = DB::transaction(function () use ($taskId, $canManageHostedTask): string {
            Article::withTrashed()
                ->where('task_id', $taskId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id', 'name']);
            if (! $task) {
                throw new ApiException('task_not_found', '任务不存在', 404);
            }
            $requiresSuperAdminRestore = $this->assertCanManageHostedTask($task, $canManageHostedTask);

            $this->pauseTask($taskId, '任务已删除');
            TaskRun::query()
                ->where('task_id', $taskId)
                ->where('status', 'running')
                ->update([
                    'status' => 'cancelled',
                    'finished_at' => now(),
                    'error_message' => '任务已删除',
                ]);

            $articleIds = Article::withTrashed()
                ->where('task_id', $taskId)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
            $this->articleAiQualityInvalidationService->cancelArticles($articleIds, '任务已删除');
            ArticleDistribution::query()
                ->whereIn('article_id', $articleIds)
                ->where('status', 'queued')
                ->update([
                    'status' => 'failed',
                    'next_retry_at' => null,
                    'last_error_message' => '任务已删除，待执行分发已取消。',
                    'updated_at' => now(),
                ]);
            ArticleDistribution::query()
                ->whereIn('article_id', $articleIds)
                ->where('status', 'sending')
                ->update([
                    'status' => 'outcome_unknown',
                    'next_retry_at' => null,
                    'last_error_message' => '任务删除时外部分发已经开始，请人工核对远端结果。',
                    'updated_at' => now(),
                ]);

            Article::query()
                ->where('task_id', $taskId)
                ->whereNull('deleted_at')
                ->update([
                    'deleted_at' => now(),
                    'updated_at' => now(),
                ]);

            foreach (['article_queue', 'task_materials', 'task_schedules', 'task_distribution_channels'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('task_id', $taskId)->delete();
                }
            }

            Article::withTrashed()
                ->where('task_id', $taskId)
                ->update([
                    'task_id' => null,
                    'updated_at' => now(),
                ]);

            $task->delete();
            DB::table('task_trash_entries')
                ->where('task_id', $taskId)
                ->update([
                    'requires_super_admin_restore' => $requiresSuperAdminRestore,
                ]);

            return (string) $task->name;
        });

        $this->broadcastOverviewAfterCommit();

        return [
            'id' => $taskId,
            'name' => $taskName,
            'deleted' => true,
        ];
    }

    /**
     * 从任务垃圾箱恢复任务主记录，并保持安全的暂停状态。
     *
     * 删除任务时已清理的排期、渠道、素材和文章关联不会在这里重建。
     *
     * @return array{id:int,name:string,restored:bool}
     */
    public function restoreTask(
        int $taskId,
        int $trashSequence,
        bool $canManageProtectedTask = false,
    ): array {
        $restoredTask = DB::transaction(function () use ($taskId, $trashSequence, $canManageProtectedTask): array {
            $task = Task::onlyTrashed()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id', 'name', 'status', 'schedule_enabled', 'next_run_at', 'deleted_at']);
            if (! $task) {
                throw new ApiException('task_not_found', '任务不存在或已恢复', 404);
            }

            $retentionCutoff = now()
                ->subDays(Task::TRASH_RETENTION_DAYS)
                ->format('Y-m-d H:i:s.u');
            $trashEntry = DB::table('task_trash_entries')
                ->where('task_id', $taskId)
                ->where('sequence', $trashSequence)
                ->where('deleted_at', '>', $retentionCutoff)
                ->lockForUpdate()
                ->first(['task_id', 'requires_super_admin_restore']);
            if (! $trashEntry) {
                throw new ApiException('task_restore_unavailable', '任务已超过垃圾箱保留期限', 409);
            }
            if ((bool) $trashEntry->requires_super_admin_restore && ! $canManageProtectedTask) {
                throw new ApiException('forbidden', '该任务只能由超级管理员恢复', 403);
            }

            $task->forceFill([
                'status' => 'paused',
                'schedule_enabled' => 0,
                'next_run_at' => null,
            ])->save();

            if (! $task->restore()) {
                throw new ApiException('task_restore_failed', '任务恢复失败', 409);
            }

            return [
                'id' => (int) $task->id,
                'name' => (string) $task->name,
                'restored' => true,
            ];
        });

        $this->broadcastOverviewAfterCommit();

        return $restoredTask;
    }

    /**
     * 启动任务。
     *
     * @param  bool  $enqueueNow  是否立即投递一条执行任务（手动启动场景）
     * @return array<string,mixed>
     */
    public function startTask(int $taskId, bool $enqueueNow = false, bool $canManageHostedTask = false): array
    {
        $this->ensureTaskExists($taskId);
        $jobId = DB::transaction(function () use ($taskId, $enqueueNow, $canManageHostedTask): ?int {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->firstOrFail(['id', 'title_library_id', 'article_limit', 'created_count', 'is_loop']);
            $this->assertCanManageHostedTask($task, $canManageHostedTask);
            $this->taskTitleReadinessService->assertCanActivate(
                $this->taskTitleReadinessService->inspectTask($task),
                409,
            );

            // 手动“立即执行”场景下，不把 next_run_at 强行置为 now，
            // 避免与手动入队叠加导致一次点击触发两次执行。
            $this->activateTask($taskId, ! $enqueueNow);
            $jobId = null;
            if ($enqueueNow) {
                $jobId = $this->queueService->enqueueTaskJob($taskId, 'generate_article', ['source' => 'api_manual_start']);
                if ($jobId !== null) {
                    Task::query()->whereKey($taskId)->update([
                        'next_run_at' => now()->addSeconds(60),
                        'updated_at' => now(),
                    ]);
                }
            }

            return $jobId;
        });

        $task = $this->getTask($taskId);
        if ($jobId !== null) {
            $task['started_job_id'] = $jobId;
        }
        $this->broadcastOverviewAfterCommit();

        return $task;
    }

    /**
     * 停止任务。
     *
     * 行为：
     * - 关闭任务调度开关；
     * - 将当前任务下 pending 的执行记录批量标记 cancelled；
     * - 返回取消数量与当前 running 数量。
     *
     * @return array<string,mixed>
     */
    public function stopTask(int $taskId, bool $canManageHostedTask = false): array
    {
        $this->ensureTaskExists($taskId);
        [$cancelledJobs, $runningJobs] = DB::transaction(function () use ($taskId, $canManageHostedTask): array {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->firstOrFail(['id']);
            $this->assertCanManageHostedTask($task, $canManageHostedTask);
            $cancelledJobs = $this->pauseTask($taskId, '任务已暂停');
            $runningJobs = TaskRun::query()
                ->where('task_id', $taskId)
                ->where('status', 'running')
                ->count();

            return [$cancelledJobs, $runningJobs];
        });
        $task = $this->getTask($taskId);
        $task['cancelled_jobs'] = $cancelledJobs;
        $task['running_jobs'] = $runningJobs;
        $this->broadcastOverviewAfterCommit();

        return $task;
    }

    /**
     * 手动入队单个任务执行。
     *
     * 入队前会校验任务是否启用（status=active 且 schedule_enabled=1）。
     *
     * @param  string  $jobType  业务任务类型
     * @param  array<string,mixed>  $payload  任务执行载荷
     * @return array{task_id:int,job_id:int,status:string}
     *
     * @throws ApiException 任务不存在、任务未启用、或已有进行中任务时抛出
     */
    public function enqueueTask(
        int $taskId,
        string $jobType = 'generate_article',
        array $payload = [],
        bool $canManageHostedTask = false,
    ): array {
        $jobId = DB::transaction(function () use ($taskId, $jobType, $payload, $canManageHostedTask): ?int {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'title_library_id',
                    'article_limit',
                    'created_count',
                    'is_loop',
                    'status',
                    'schedule_enabled',
                ]);
            if (! $task) {
                throw new ApiException('task_not_found', '任务不存在', 404);
            }
            $this->assertCanManageHostedTask($task, $canManageHostedTask);

            if (($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
                throw new ApiException('task_not_active', '任务未启用，无法入队', 409);
            }

            $this->taskTitleReadinessService->assertCanActivate(
                $this->taskTitleReadinessService->inspectTask($task),
                409,
            );

            return $this->queueService->enqueueTaskJob($taskId, $jobType, $payload);
        });
        if ($jobId === null) {
            throw new ApiException('job_already_exists', '任务已处于排队或执行中', 409);
        }

        return [
            'task_id' => $taskId,
            'job_id' => $jobId,
            'status' => 'pending',
        ];
    }

    private function assertCanManageHostedTask(Task $task, bool $canManageHostedTask): bool
    {
        $isHostedTask = $task->distributionChannels()
            ->where('distribution_channels.channel_type', DistributionChannel::TYPE_HOSTED_SITE)
            ->exists();
        if ($isHostedTask && ! $canManageHostedTask) {
            throw new ApiException('forbidden', '托管站点任务只能由超级管理员管理', 403);
        }

        return $isHostedTask;
    }

    /**
     * 查询任务下的执行记录（task_runs）。
     *
     * @param  string|null  $status  可选状态过滤
     * @param  int  $limit  返回数量上限（1~100）
     * @return array{items:list<array<string,mixed>>}
     */
    public function listTaskJobs(int $taskId, ?string $status = null, int $limit = 20): array
    {
        $this->ensureTaskExists($taskId);
        $limit = max(1, min(100, $limit));

        $q = TaskRun::query()
            ->where('task_id', $taskId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit);

        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }

        return ['items' => $q->get()->map(fn (TaskRun $run) => $run->getAttributes())->all()];
    }

    /**
     * 查询单条执行记录详情（对外保持 job 语义）。
     *
     * 注意：当前 job_id 即 task_runs.id。
     *
     * @return array<string,mixed>
     *
     * @throws ApiException 当执行记录不存在时抛出 404
     */
    public function getJob(int $jobId): array
    {
        $run = TaskRun::query()->find($jobId);
        if (! $run) {
            throw new ApiException('job_not_found', 'Job 不存在', 404);
        }
        $meta = is_array($run->meta) ? $run->meta : [];
        $payload = is_array($meta['payload'] ?? null) ? $meta['payload'] : [];

        return [
            'id' => (int) $run->id,
            'task_id' => (int) $run->task_id,
            'job_type' => (string) ($meta['job_type'] ?? 'generate_article'),
            'status' => (string) $run->status,
            'attempt_count' => (int) ($meta['attempt_count'] ?? 0),
            'max_attempts' => (int) ($meta['max_attempts'] ?? 0),
            'worker_id' => is_string($meta['worker_id'] ?? null) ? $meta['worker_id'] : null,
            'claimed_at' => $run->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $run->finished_at?->format('Y-m-d H:i:s'),
            'error_message' => $run->error_message ?? '',
            'payload' => $payload,
            'task_run_summary' => [
                'article_id' => $run->article_id !== null ? (int) $run->article_id : null,
                'duration_ms' => (int) ($run->duration_ms ?? 0),
                'status' => $run->status ?? null,
                'error_message' => $run->error_message ?? '',
                'meta' => $meta,
            ],
        ];
    }

    /**
     * 归一化并校验任务输入。
     *
     * - create 场景：补默认值并强校验必填；
     * - update 场景：仅处理传入字段。
     *
     * @param  array<string,mixed>  $data
     * @param  bool  $isUpdate  true=更新，false=创建
     * @return array<string,mixed>
     *
     * @throws ApiException 字段校验失败时抛 422，并附带 field_errors
     */
    private function normalizeTaskInput(array $data, bool $isUpdate): array
    {
        $output = [];
        $fieldErrors = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                $fieldErrors['name'] = '任务名称不能为空';
            } else {
                $output['name'] = $name;
            }
        } elseif (! $isUpdate) {
            $fieldErrors['name'] = '任务名称不能为空';
        }

        $referenceMap = [
            'title_library_id' => ['model' => TitleLibrary::class, 'message' => '选择的标题库不存在', 'required' => ! $isUpdate],
            'image_library_id' => ['model' => ImageLibrary::class, 'message' => '选择的图片库不存在', 'required' => false],
            'prompt_id' => ['model' => Prompt::class, 'message' => '选择的内容提示词不存在', 'required' => ! $isUpdate, 'prompt_content' => true],
            'ai_model_id' => ['model' => AiModel::class, 'message' => '选择的AI模型不存在或未激活', 'required' => ! $isUpdate, 'ai_active_chat' => true],
            'ai_quality_prompt_id' => ['model' => Prompt::class, 'message' => '选择的 AI 质检方案不存在', 'required' => false, 'prompt_quality' => true],
            'ai_quality_model_id' => ['model' => AiModel::class, 'message' => '选择的 AI 质检模型不存在或未激活', 'required' => false, 'ai_active_chat' => true],
            'author_id' => ['model' => Author::class, 'message' => '选择的作者不存在', 'required' => false],
            'knowledge_base_id' => ['model' => KnowledgeBase::class, 'message' => '选择的知识库不存在', 'required' => false],
            'fixed_category_id' => ['model' => Category::class, 'message' => '固定分类不存在', 'required' => false],
        ];
        $knowledgeBaseIdsProvided = array_key_exists('knowledge_base_ids', $data);

        if ($knowledgeBaseIdsProvided) {
            $knowledgeBaseIds = $this->normalizeKnowledgeBaseIds($data['knowledge_base_ids'], $fieldErrors);
            $output['knowledge_base_ids'] = $knowledgeBaseIds;
            $output['knowledge_base_id'] = $knowledgeBaseIds[0] ?? null;
        }

        foreach ($referenceMap as $field => $config) {
            if ($field === 'knowledge_base_id' && $knowledgeBaseIdsProvided) {
                continue;
            }

            if (! array_key_exists($field, $data)) {
                if (! $isUpdate && $config['required']) {
                    $fieldErrors[$field] = '缺少必填字段';
                } elseif (! $isUpdate) {
                    $output[$field] = null;
                }

                continue;
            }

            $value = $data[$field];
            if ($value === null || $value === '' || (int) $value <= 0) {
                $output[$field] = null;
                if (! $isUpdate && $config['required']) {
                    $fieldErrors[$field] = '缺少必填字段';
                }

                continue;
            }

            $id = (int) $value;
            $modelClass = $config['model'];
            $exists = false;
            // prompt 与 ai_model 的校验规则与普通外键不同，这里单独处理业务约束。
            if (! empty($config['prompt_content'])) {
                $exists = Prompt::query()->whereKey($id)->where('type', 'content')->exists();
            } elseif (! empty($config['prompt_quality'])) {
                $exists = Prompt::query()->whereKey($id)->where('type', 'quality_check')->exists();
            } elseif (! empty($config['ai_active_chat'])) {
                $exists = AiModel::query()
                    ->whereKey($id)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('model_type')
                            ->orWhere('model_type', '')
                            ->orWhere('model_type', 'chat');
                    })
                    ->exists();
            } else {
                $exists = $modelClass::query()->whereKey($id)->exists();
            }

            if (! $exists) {
                $fieldErrors[$field] = $config['message'];
            } else {
                $output[$field] = $id;
            }
        }

        if (! $knowledgeBaseIdsProvided && array_key_exists('knowledge_base_id', $output)) {
            $knowledgeBaseId = (int) ($output['knowledge_base_id'] ?? 0);
            $output['knowledge_base_ids'] = $knowledgeBaseId > 0 ? [$knowledgeBaseId] : [];
        }

        $flagFields = [
            'need_review',
            'auto_keywords',
            'auto_description',
            'is_loop',
            'ai_quality_enabled',
            'ai_quality_timeout_sampling_enabled',
            'ai_quality_auto_optimize_enabled',
        ];
        foreach ($flagFields as $field) {
            if (array_key_exists($field, $data)) {
                $output[$field] = $this->toFlag($data[$field]);
            } elseif (! $isUpdate) {
                $output[$field] = in_array($field, ['need_review', 'auto_keywords', 'auto_description'], true) ? 1 : 0;
            }
        }

        if (! $isUpdate && empty($output['ai_quality_enabled'])) {
            $output['ai_quality_timeout_sampling_enabled'] = 0;
            $output['ai_quality_auto_optimize_enabled'] = 0;
        }

        if (array_key_exists('ai_quality_optimization_level', $data)) {
            $level = trim((string) $data['ai_quality_optimization_level']);
            if (! in_array($level, ArticleAiOptimizationPolicy::strategies(), true)) {
                $fieldErrors['ai_quality_optimization_level'] = 'AI 自动优化等级无效';
            } else {
                $output['ai_quality_optimization_level'] = $level;
            }
        } elseif (! $isUpdate) {
            $output['ai_quality_optimization_level'] = ArticleAiOptimizationPolicy::STRATEGY_EXCELLENT_80;
        }

        if (array_key_exists('ai_quality_pass_score', $data)) {
            $passScore = (int) $data['ai_quality_pass_score'];
            if ($passScore < 1 || $passScore > 100) {
                $fieldErrors['ai_quality_pass_score'] = 'AI 质检自动通过分必须在 1 到 100 之间';
            } else {
                $output['ai_quality_pass_score'] = $passScore;
            }
        } elseif (! $isUpdate) {
            $output['ai_quality_pass_score'] = 85;
        }

        if (array_key_exists('ai_quality_manual_override_min_score', $data)) {
            $manualScore = (int) $data['ai_quality_manual_override_min_score'];
            if ($manualScore < 0 || $manualScore > 99) {
                $fieldErrors['ai_quality_manual_override_min_score'] = 'AI 质检人工放行最低分必须在 0 到 99 之间';
            } else {
                $output['ai_quality_manual_override_min_score'] = $manualScore;
            }
        } elseif (! $isUpdate) {
            $output['ai_quality_manual_override_min_score'] = 70;
        }

        if (isset($output['ai_quality_pass_score'], $output['ai_quality_manual_override_min_score'])
            && $output['ai_quality_manual_override_min_score'] >= $output['ai_quality_pass_score']) {
            $fieldErrors['ai_quality_manual_override_min_score'] = '人工放行最低分必须低于自动通过分';
        }

        if (! $isUpdate && ! empty($output['ai_quality_enabled'])) {
            if (empty($output['ai_quality_prompt_id'])) {
                $fieldErrors['ai_quality_prompt_id'] = '开启 AI 质检后必须选择质检方案';
            }
            if (empty($output['knowledge_base_ids'])) {
                $fieldErrors['knowledge_base_ids'] = '开启 AI 质检后必须选择至少一个知识库';
            }
        }

        if (array_key_exists('image_count', $data)) {
            $output['image_count'] = max(0, (int) $data['image_count']);
        } elseif (! $isUpdate) {
            $output['image_count'] = 0;
        }

        if (array_key_exists('publish_interval', $data)) {
            $output['publish_interval'] = max(60, (int) $data['publish_interval']);
        } elseif (! $isUpdate) {
            $output['publish_interval'] = 3600;
        }

        if (array_key_exists('draft_limit', $data)) {
            $output['draft_limit'] = max(1, (int) $data['draft_limit']);
        } elseif (! $isUpdate) {
            $output['draft_limit'] = 10;
        }

        if (array_key_exists('article_limit', $data)) {
            $output['article_limit'] = max(1, (int) $data['article_limit']);
        } elseif (! $isUpdate) {
            $output['article_limit'] = max(10, (int) ($output['draft_limit'] ?? 10));
        }

        if (isset($output['article_limit'], $output['draft_limit']) && $output['draft_limit'] > $output['article_limit']) {
            $output['draft_limit'] = $output['article_limit'];
        }

        if (array_key_exists('category_mode', $data)) {
            $categoryMode = trim((string) $data['category_mode']);
            if (! in_array($categoryMode, ['smart', 'fixed'], true)) {
                $fieldErrors['category_mode'] = '分类模式无效';
            } else {
                $output['category_mode'] = $categoryMode;
            }
        } elseif (! $isUpdate) {
            $output['category_mode'] = 'smart';
        }

        if (array_key_exists('model_selection_mode', $data)) {
            $modelSelectionMode = trim((string) $data['model_selection_mode']);
            if (! in_array($modelSelectionMode, ['fixed', 'smart_failover'], true)) {
                $fieldErrors['model_selection_mode'] = '模型选择模式无效';
            } else {
                $output['model_selection_mode'] = $modelSelectionMode;
            }
        } elseif (! $isUpdate) {
            $output['model_selection_mode'] = 'fixed';
        }

        if (array_key_exists('status', $data)) {
            $status = trim((string) $data['status']);
            if (! in_array($status, ['active', 'paused'], true)) {
                $fieldErrors['status'] = '任务状态无效';
            } else {
                $output['status'] = $status;
            }
        } elseif (! $isUpdate) {
            $output['status'] = 'active';
        }

        if (array_key_exists('publish_scope', $data)) {
            $publishScope = trim((string) $data['publish_scope']);
            if (! in_array($publishScope, ['local_and_distribution', 'distribution_only', 'local_only'], true)) {
                $fieldErrors['publish_scope'] = '发布范围无效';
            } else {
                $output['publish_scope'] = $publishScope;
            }
        } elseif (! $isUpdate) {
            $output['publish_scope'] = 'local_and_distribution';
        }

        if (array_key_exists('distribution_strategy', $data)) {
            $strategy = trim((string) $data['distribution_strategy']);
            if (! in_array($strategy, TaskDistributionChannelSelector::strategies(), true)) {
                $fieldErrors['distribution_strategy'] = '分发策略无效';
            } else {
                $output['distribution_strategy'] = $strategy;
            }
        } elseif (! $isUpdate) {
            $output['distribution_strategy'] = TaskDistributionChannelSelector::STRATEGY_BROADCAST;
        }

        $effectiveCategoryMode = $output['category_mode'] ?? (($data['category_mode'] ?? 'smart') ?: 'smart');
        if ($effectiveCategoryMode === 'fixed') {
            $fixedCategoryId = $output['fixed_category_id'] ?? null;
            if ($fixedCategoryId === null || $fixedCategoryId <= 0) {
                $fieldErrors['fixed_category_id'] = '固定分类模式下必须选择一个分类';
            }
        }

        if (! empty($fieldErrors)) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => $fieldErrors,
            ]);
        }

        return $output;
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @param  list<int>  $knowledgeBaseIds
     */
    private function assertEffectiveAiQualityConfiguration(Task $current, array $normalized, array $knowledgeBaseIds): void
    {
        $enabled = array_key_exists('ai_quality_enabled', $normalized)
            ? (bool) $normalized['ai_quality_enabled']
            : (bool) $current->ai_quality_enabled;
        if (! $enabled) {
            return;
        }

        $promptId = array_key_exists('ai_quality_prompt_id', $normalized)
            ? $normalized['ai_quality_prompt_id']
            : $current->ai_quality_prompt_id;
        $passScore = (int) ($normalized['ai_quality_pass_score'] ?? $current->ai_quality_pass_score ?? 85);
        $manualScore = (int) ($normalized['ai_quality_manual_override_min_score'] ?? $current->ai_quality_manual_override_min_score ?? 70);
        $fieldErrors = [];

        if (empty($promptId)) {
            $fieldErrors['ai_quality_prompt_id'] = '开启 AI 质检后必须选择质检方案';
        }
        if ($knowledgeBaseIds === []) {
            $fieldErrors['knowledge_base_ids'] = '开启 AI 质检后必须选择至少一个知识库';
        }
        if ($manualScore >= $passScore) {
            $fieldErrors['ai_quality_manual_override_min_score'] = '人工放行最低分必须低于自动通过分';
        }

        if ($fieldErrors !== []) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => $fieldErrors,
            ]);
        }
    }

    /** @return list<int> */
    private function currentTaskKnowledgeBaseIds(int $taskId, mixed $legacyKnowledgeBaseId): array
    {
        $ids = Schema::hasTable('task_knowledge_bases')
            ? DB::table('task_knowledge_bases')
                ->where('task_id', $taskId)
                ->orderBy('sort_order')
                ->pluck('knowledge_base_id')
                ->map(static fn ($id): int => (int) $id)
                ->all()
            : [];

        if ($ids === [] && (int) $legacyKnowledgeBaseId > 0) {
            return [(int) $legacyKnowledgeBaseId];
        }

        return $ids;
    }

    /**
     * 激活任务并确保调度配置就绪。
     *
     * @param  bool  $resetNextRun  true 时 next_run_at 立即置为 now（手动启动场景）
     */
    private function activateTask(int $taskId, bool $resetNextRun): void
    {
        $task = Task::query()->whereKey($taskId)->first(['id', 'next_run_at']);
        $updates = [
            'status' => 'active',
            'schedule_enabled' => 1,
            'updated_at' => now(),
        ];

        if ($resetNextRun || $task?->next_run_at === null) {
            $updates['next_run_at'] = now();
        }

        Task::query()->whereKey($taskId)->update($updates);
        $this->queueService->initializeTaskSchedule($taskId);
    }

    /**
     * 暂停任务并取消未开始执行（pending）的记录。
     *
     * @return int 被取消的 pending 记录数
     */
    private function pauseTask(int $taskId, string $reason): int
    {
        Task::query()->whereKey($taskId)->update([
            'status' => 'paused',
            'schedule_enabled' => 0,
            'next_run_at' => null,
            'updated_at' => now(),
        ]);
        $this->articleAiQualityInvalidationService->cancelTaskOptimization($taskId, $reason);

        return TaskRun::query()
            ->where('task_id', $taskId)
            ->where('status', 'pending')
            ->update([
                'status' => 'cancelled',
                'finished_at' => now(),
                'error_message' => $reason,
            ]);
    }

    /**
     * 断言任务存在，否则抛 404。
     *
     * @throws ApiException
     */
    private function ensureTaskExists(int $taskId): void
    {
        if (! Task::query()->whereKey($taskId)->exists()) {
            throw new ApiException('task_not_found', '任务不存在', 404);
        }
    }

    private function broadcastOverviewAfterCommit(): void
    {
        DB::afterCommit(fn () => $this->taskRealtimeBroadcastService->broadcastOverview());
    }

    /**
     * @param  array<string,string>  $fieldErrors
     * @return list<int>
     */
    private function normalizeKnowledgeBaseIds(mixed $value, array &$fieldErrors): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (! is_array($value)) {
            $fieldErrors['knowledge_base_ids'] = '知识库选择格式无效';

            return [];
        }

        $ids = collect($value)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->count() > 5) {
            $fieldErrors['knowledge_base_ids'] = '一个任务最多选择 5 个知识库';

            return $ids->take(5)->all();
        }

        if ($ids->isEmpty()) {
            return [];
        }

        $existingIds = KnowledgeBase::query()
            ->whereIn('id', $ids->all())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if (count($existingIds) !== $ids->count()) {
            $fieldErrors['knowledge_base_ids'] = '选择的知识库不存在';
        }

        return $ids->all();
    }

    /**
     * @param  list<int>  $knowledgeBaseIds
     */
    private function syncTaskKnowledgeBases(int $taskId, array $knowledgeBaseIds): void
    {
        if (! Schema::hasTable('task_knowledge_bases')) {
            return;
        }

        $task = Task::query()->whereKey($taskId)->first();
        if (! $task) {
            return;
        }

        $syncPayload = [];
        foreach (array_values($knowledgeBaseIds) as $index => $knowledgeBaseId) {
            $id = (int) $knowledgeBaseId;
            if ($id <= 0) {
                continue;
            }

            $syncPayload[$id] = ['sort_order' => $index];
        }

        $task->knowledgeBases()->sync($syncPayload);
    }

    /**
     * 将混合输入（bool/int/string）归一化为 0/1 标记位。
     */
    private function toFlag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_numeric($value)) {
            return (int) $value > 0 ? 1 : 0;
        }

        $value = strtolower(trim((string) $value));

        return in_array($value, ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }
}
