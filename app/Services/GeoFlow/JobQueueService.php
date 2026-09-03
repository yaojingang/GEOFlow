<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Article;
use App\Models\Task;
use App\Models\TaskRun;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * GeoFlow 任务调度服务（基于 Laravel Queue + Redis）。
 *
 * 职责边界：
 * 1. 管理任务执行记录（`task_runs`）的状态流转：pending -> running -> completed/failed/cancelled。
 * 2. 负责把可执行任务投递到 Laravel 队列（`geoflow` queue），并在失败时按重试策略再次投递。
 * 3. 同步回写 `tasks` 的运行态字段（最近成功/失败时间、错误信息等），供后台面板展示。
 *
 * 设计说明：
 * - 这里不再依赖 legacy 的 `job_queue` 表，`task_runs.id` 即当前执行链路中的 job 标识。
 * - 重试次数、可执行时间等临时调度信息放在 `task_runs.meta` 中，避免新增表结构。
 */
class JobQueueService
{
    private const ALLOWED_JOB_TYPES = ['generate_article'];

    private const ALLOWED_PAYLOAD_SOURCES = ['api_enqueue', 'api_manual_start', 'follow_up_generation'];

    public function __construct(
        private readonly AiExecutionContextFactory $aiExecutionContextFactory,
        private readonly AiExecutionAccessGuard $aiExecutionAccessGuard,
        private readonly AiExecutionErrorSanitizer $aiExecutionErrorSanitizer,
        private readonly ArticleAiQualityGate $articleAiQualityGate,
    ) {}

    /**
     * 初始化任务调度字段。
     *
     * 仅在字段为空时写入默认值，避免覆盖人工配置：
     * - `next_run_at`: 首次可执行时间
     * - `schedule_enabled`: 调度开关（默认 1）
     * - `max_retry_count`: 最大重试次数（默认 3）
     */
    public function initializeTaskSchedule(int $taskId, int $delaySeconds = 60): void
    {
        DB::transaction(function () use ($taskId, $delaySeconds): void {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id', 'next_run_at', 'next_publish_at', 'schedule_enabled', 'max_retry_count', 'publish_interval']);

            if (! $task) {
                return;
            }

            $now = now();
            $updates = ['updated_at' => $now];

            if ($task->next_run_at === null) {
                $updates['next_run_at'] = $now->copy()->addSeconds(max(1, $delaySeconds));
            }

            if ($task->next_publish_at === null) {
                $updates['next_publish_at'] = $now->copy()->addSeconds(max(60, (int) ($task->publish_interval ?? 3600)));
            }

            if ($task->schedule_enabled === null) {
                $updates['schedule_enabled'] = 1;
            }

            if ($task->max_retry_count === null) {
                $updates['max_retry_count'] = 3;
            }

            Task::query()->whereKey($taskId)->update($updates);
        });
    }

    /**
     * 判断任务是否已有未完成执行（pending/running）。
     *
     * 用于保证同一 task 不会被重复入队，避免并发重复生成内容。
     */
    public function hasPendingOrRunningJob(int $taskId): bool
    {
        return TaskRun::query()
            ->where('task_id', $taskId)
            ->whereIn('status', ['pending', 'running'])
            ->exists();
    }

    /**
     * 创建一条执行记录并投递到 Laravel 队列。
     *
     * @param  int  $taskId  任务 ID
     * @param  string  $jobType  业务类型（如 generate_article）
     * @param  array<string,mixed>  $payload  执行上下文参数
     * @param  string|null  $availableAt  可执行时间（为空则立即）
     * @return int|null 返回 `task_runs.id`；若任务不存在或已有进行中执行，则返回 null
     */
    public function enqueueTaskJob(int $taskId, string $jobType = 'generate_article', array $payload = [], ?string $availableAt = null): ?int
    {
        $jobType = in_array($jobType, self::ALLOWED_JOB_TYPES, true) ? $jobType : 'generate_article';
        $run = DB::transaction(function () use ($taskId, $jobType, $payload, $availableAt): ?TaskRun {
            $taskRow = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first([
                    'id',
                    'status',
                    'schedule_enabled',
                    'max_retry_count',
                    'ai_model_id',
                    'model_access_admin_id',
                    'model_access_admin_role',
                    'model_access_policy_version',
                ]);
            if (! $taskRow
                || ($taskRow->status ?? 'paused') !== 'active'
                || (int) ($taskRow->schedule_enabled ?? 1) !== 1) {
                return null;
            }

            // 任务级串行保护：事务内复查，避免并发请求重复入队。
            $exists = TaskRun::query()
                ->where('task_id', $taskId)
                ->whereIn('status', ['pending', 'running'])
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                return null;
            }

            $maxAttempts = max(1, (int) ($taskRow->max_retry_count ?? 3));
            $availableAtValue = $availableAt ? Carbon::parse($availableAt) : now();
            $executionIdentity = $this->aiExecutionContextFactory->taskRunIdentity($taskRow);

            // 建立“待执行记录”，作为后续状态流转的唯一主记录。
            $run = TaskRun::query()->create([
                'task_id' => $taskId,
                'status' => 'pending',
                'meta' => [
                    'job_type' => $jobType,
                    'payload' => $this->sanitizeQueuePayload($payload),
                    'attempt_count' => 0,
                    'max_attempts' => $maxAttempts,
                    'available_at' => $availableAtValue->toDateTimeString(),
                ],
                'started_at' => null,
                'finished_at' => null,
            ]);
            $run->forceFill($executionIdentity)->save();

            return $run;
        });
        if (! $run) {
            return null;
        }

        // 完全使用 Laravel Queue 执行。
        $runMeta = $this->normalizeMeta($run->meta);
        $this->dispatchLaravelQueueJob((int) $run->id, $runMeta['available_at'] ?? null);
        $this->broadcastOverviewUpdate();

        return (int) $run->id;
    }

    /**
     * 领取指定 ID 的 pending 任务执行记录（供 Laravel 队列 Job 执行时使用）。
     *
     * @return array<string,mixed>|null
     */
    public function claimPendingJobById(
        int $jobId,
        string $workerId,
        ?string $claimLeaseToken = null,
    ): ?array {
        $claimedJob = DB::transaction(function () use ($jobId, $workerId, $claimLeaseToken): ?array {
            $taskId = (int) (TaskRun::query()
                ->whereKey($jobId)
                ->where('status', 'pending')
                ->value('task_id') ?? 0);
            if ($taskId <= 0) {
                return null;
            }

            // 所有 Worker 写事务固定按 Task -> TaskRun -> Article 获取行锁。
            $task = $this->lockTaskForClaim($taskId);
            $run = $this->lockRunForClaim($jobId, $taskId);

            if (! $run) {
                return null;
            }
            // 任务未激活或调度被关闭时，不允许执行。
            if (! $task || ($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
                TaskRun::query()
                    ->whereKey((int) $run->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'cancelled',
                        'finished_at' => now(),
                        'error_message' => '任务未启用，已取消待执行记录',
                        'execution_lease_token' => null,
                    ]);

                return null;
            }

            $meta = $this->normalizeMeta($run->meta);
            $availableAt = (string) ($meta['available_at'] ?? '');
            // 尚未到可执行时间，直接跳过（由队列 delay 机制在后续触发）。
            if ($availableAt !== '' && Carbon::parse($availableAt)->greaterThan(now())) {
                return null;
            }

            $executionLeaseToken = $this->normalizeClaimLeaseToken($claimLeaseToken);
            $affected = TaskRun::query()
                ->whereKey($jobId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'execution_lease_token' => $executionLeaseToken,
                    'meta' => array_merge($meta, ['worker_id' => $workerId]),
                ]);

            if ($affected !== 1) {
                return null;
            }

            $run->forceFill([
                'status' => 'running',
                'started_at' => now(),
                'execution_lease_token' => $executionLeaseToken,
                'meta' => array_merge($meta, ['worker_id' => $workerId]),
            ]);
            if (! $this->executionIdentityAllowsDispatch($run)) {
                return null;
            }

            // 返回轻量执行上下文，供 ProcessGeoFlowTaskJob 使用。
            $row = $run->getAttributes();
            $row['status'] = 'running';
            unset($row['execution_lease_token']);
            $row['worker_id'] = $workerId;
            $row['publish_interval'] = (int) ($task->publish_interval ?? 0);
            $row['task_status'] = (string) ($task->status ?? 'paused');

            return $row;
        });

        if (is_array($claimedJob)) {
            $this->broadcastOverviewUpdate();
        }

        return $claimedJob;
    }

    /**
     * 兼容旧常驻 worker 的入口，现已废弃。
     *
     * 当前执行链路为“按 taskRunId 精确投递并 claim”，不再需要全局扫描 claimNext。
     *
     * @deprecated
     *
     * @return null 固定返回 null
     */
    public function claimNextJob(string $workerId): ?array
    {
        return null;
    }

    public function lockRunningJobForWorker(AiExecutionContext $executionContext, int $taskId): void
    {
        $runQuery = TaskRun::query()
            ->whereKey($executionContext->taskRunId)
            ->where('task_id', $taskId)
            ->where('status', 'running');
        $this->constrainRunToExecutionContext(
            $runQuery,
            $executionContext,
            $executionContext->executionLeaseToken(),
        );
        if (! $runQuery->lockForUpdate()->first() instanceof TaskRun) {
            throw AiModelAccessException::configAccessRevokedForAdminId($executionContext->modelAccessAdminId);
        }
    }

    /**
     * 处理成功完成：回写执行记录 + 任务最近成功状态。
     *
     * @param  array<string,mixed>  $meta  执行产物元数据（如模型信息、trace 信息等）
     */
    public function completeJob(
        int $jobId,
        int $taskId,
        ?int $articleId,
        int $durationMs,
        array $meta = [],
        ?AiExecutionContext $executionContext = null,
        ?string $executionLeaseToken = null,
        bool $rejectStaleExecution = false,
    ): void {
        $completed = DB::transaction(function () use ($jobId, $taskId, $articleId, $durationMs, $meta, $executionContext, $executionLeaseToken): bool {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id']);
            if (! $task) {
                return false;
            }

            $runQuery = TaskRun::query()
                ->whereKey($jobId)
                ->where('task_id', $taskId)
                ->where('status', 'running');
            $this->constrainRunToExecutionContext($runQuery, $executionContext, $executionLeaseToken);
            $run = $runQuery->lockForUpdate()->first();
            if (! $run instanceof TaskRun) {
                return false;
            }

            $run->forceFill([
                'status' => 'completed',
                'finished_at' => now(),
                'article_id' => $articleId,
                'duration_ms' => $durationMs,
                'meta' => $this->aiExecutionErrorSanitizer->sanitizeMeta($meta),
                'error_message' => '',
                'error_code' => null,
                'execution_lease_token' => null,
            ])->save();

            Task::query()->whereKey($taskId)->update([
                'last_run_at' => now(),
                'last_success_at' => now(),
                'last_error_at' => null,
                'last_error_message' => '',
                'updated_at' => now(),
            ]);

            return true;
        });
        if (! $completed) {
            if ($rejectStaleExecution && $executionContext instanceof AiExecutionContext) {
                throw AiModelAccessException::configAccessRevokedForAdminId($executionContext->modelAccessAdminId);
            }

            return;
        }

        $this->broadcastOverviewUpdate();
        $this->enqueueFollowUpGenerationIfNeeded($taskId, $meta);
    }

    /**
     * 处理执行失败：根据重试策略决定“重新排队”或“最终失败”。
     *
     * 策略：
     * - attempt_count < max_attempts: 状态重置为 pending，写入下次 available_at，并再次 dispatch；
     * - 否则：状态置为 failed，结束本次执行生命周期。
     */
    public function failJob(
        int $jobId,
        int $taskId,
        string $errorMessage,
        int $durationMs,
        int $retryDelaySeconds = 60,
        ?AiExecutionContext $executionContext = null,
        ?string $executionLeaseToken = null,
    ): void {
        $errorMessage = $this->aiExecutionErrorSanitizer->sanitize($errorMessage);
        $result = DB::transaction(function () use ($jobId, $taskId, $errorMessage, $durationMs, $retryDelaySeconds, $executionContext, $executionLeaseToken): array {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id', 'status', 'schedule_enabled']);

            $runQuery = TaskRun::query()
                ->whereKey($jobId)
                ->where('task_id', $taskId)
                ->where('status', 'running');
            $this->constrainRunToExecutionContext($runQuery, $executionContext, $executionLeaseToken);
            $run = $runQuery->lockForUpdate()->first();
            if (! $run instanceof TaskRun) {
                return ['changed' => false, 'retry' => false, 'available_at' => null];
            }
            if (! $task || ($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
                $run->update([
                    'status' => 'cancelled',
                    'finished_at' => now(),
                    'error_message' => '任务已删除或停用',
                    'duration_ms' => $durationMs,
                    'execution_lease_token' => null,
                ]);

                return ['changed' => true, 'retry' => false, 'available_at' => null];
            }

            $runMeta = $this->normalizeMeta($run->meta);
            $attemptCount = (int) ($runMeta['attempt_count'] ?? 0) + 1;
            $maxAttempts = max(1, (int) ($runMeta['max_attempts'] ?? 3));
            $shouldRetry = $attemptCount < $maxAttempts;
            $nextAvailableAt = now()->addSeconds(max(1, $retryDelaySeconds));

            $newMeta = array_merge($runMeta, [
                'attempt_count' => $attemptCount,
                'max_attempts' => $maxAttempts,
                'last_error' => $errorMessage,
                'available_at' => $shouldRetry ? $nextAvailableAt->toDateTimeString() : ($runMeta['available_at'] ?? ''),
            ]);

            $runUpdate = [
                'status' => $shouldRetry ? 'pending' : 'failed',
                'error_message' => $errorMessage,
                'duration_ms' => $durationMs,
                'finished_at' => $shouldRetry ? null : now(),
                'execution_lease_token' => null,
                'meta' => $newMeta,
            ];
            if ($shouldRetry) {
                $runUpdate['resolved_ai_model_id'] = null;
                $runUpdate['resolved_model_source'] = null;
                $runUpdate['model_resolved_at'] = null;
                $runUpdate['error_code'] = null;
            }
            $run->forceFill($runUpdate)->save();

            Task::query()->whereKey($taskId)->update([
                'last_run_at' => now(),
                'last_error_at' => now(),
                'last_error_message' => $errorMessage,
                'updated_at' => now(),
            ]);

            return ['changed' => true, 'retry' => $shouldRetry, 'available_at' => $nextAvailableAt];
        });

        if (! $result['changed']) {
            return;
        }

        if ($result['retry'] && $result['available_at'] instanceof Carbon) {
            $this->dispatchLaravelQueueJob($jobId, $result['available_at']);
        }

        $this->broadcastOverviewUpdate();
    }

    public function failForAiAuthorization(
        int $jobId,
        int $taskId,
        string $errorCode,
        int $durationMs,
        ?AiExecutionContext $executionContext = null,
        ?string $executionLeaseToken = null,
    ): void {
        $changed = DB::transaction(function () use ($jobId, $taskId, $errorCode, $durationMs, $executionContext, $executionLeaseToken): bool {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id']);
            if (! $task instanceof Task) {
                return false;
            }

            $runQuery = TaskRun::query()
                ->whereKey($jobId)
                ->where('task_id', $taskId)
                ->where('status', 'running');
            $this->constrainRunToExecutionContext($runQuery, $executionContext, $executionLeaseToken);
            $run = $runQuery->lockForUpdate()->first();
            if (! $run) {
                return false;
            }

            $this->permanentlyFailAuthorizationRun($run, $errorCode, $durationMs);

            Task::query()->whereKey($taskId)->update([
                'last_run_at' => now(),
                'last_error_at' => now(),
                'last_error_message' => $errorCode,
                'updated_at' => now(),
            ]);

            return true;
        });

        if ($changed) {
            $this->broadcastOverviewUpdate();
        }
    }

    public function failForPermanentAiProviderError(
        int $jobId,
        int $taskId,
        string $errorCode,
        int $durationMs,
        ?AiExecutionContext $executionContext = null,
        ?string $executionLeaseToken = null,
    ): void {
        $errorCode = $errorCode === PermanentAiProviderException::ERROR_CODE
            ? $errorCode
            : PermanentAiProviderException::ERROR_CODE;
        $changed = DB::transaction(function () use ($jobId, $taskId, $errorCode, $durationMs, $executionContext, $executionLeaseToken): bool {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id']);
            if (! $task instanceof Task) {
                return false;
            }

            $runQuery = TaskRun::query()
                ->whereKey($jobId)
                ->where('task_id', $taskId)
                ->where('status', 'running');
            $this->constrainRunToExecutionContext($runQuery, $executionContext, $executionLeaseToken);
            $run = $runQuery->lockForUpdate()->first();
            if (! $run instanceof TaskRun) {
                return false;
            }

            $meta = $this->normalizeMeta($run->meta);
            $meta['retryable'] = false;
            $meta['failure_class'] = 'provider_permanent';
            $meta['error_code'] = $errorCode;
            $meta['last_error'] = $errorCode;
            $run->forceFill([
                'status' => 'failed',
                'error_code' => $errorCode,
                'error_message' => $errorCode,
                'duration_ms' => $durationMs,
                'finished_at' => now(),
                'execution_lease_token' => null,
                'meta' => $meta,
            ])->save();

            Task::query()->whereKey($taskId)->update([
                'last_run_at' => now(),
                'last_error_at' => now(),
                'last_error_message' => $errorCode,
                'updated_at' => now(),
            ]);

            return true;
        });

        if ($changed) {
            $this->broadcastOverviewUpdate();
        }
    }

    /**
     * 将不可重试的标题库配置错误标记为终态，并暂停任务阻止后续无效调度。
     *
     * @param  array<string,mixed>  $details
     */
    public function failForTaskConfiguration(
        int $jobId,
        int $taskId,
        string $errorMessage,
        int $durationMs,
        array $details = [],
        ?AiExecutionContext $executionContext = null,
        ?string $executionLeaseToken = null,
    ): void {
        $errorMessage = $this->aiExecutionErrorSanitizer->sanitize($errorMessage, 'Task configuration failed');
        $details = $this->aiExecutionErrorSanitizer->sanitizeMeta($details);
        $changed = DB::transaction(function () use ($jobId, $taskId, $errorMessage, $durationMs, $details, $executionContext, $executionLeaseToken): bool {
            $task = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id']);
            if (! $task instanceof Task) {
                return false;
            }

            $runQuery = TaskRun::query()
                ->whereKey($jobId)
                ->where('task_id', $taskId)
                ->where('status', 'running');
            $this->constrainRunToExecutionContext($runQuery, $executionContext, $executionLeaseToken);
            $run = $runQuery->lockForUpdate()->first();
            if (! $run instanceof TaskRun) {
                return false;
            }

            $meta = $this->normalizeMeta($run->meta);
            $meta['attempt_count'] = (int) ($meta['attempt_count'] ?? 0) + 1;
            $meta['retryable'] = false;
            $meta['failure_class'] = 'configuration';
            $meta['error_code'] = 'task_title_library_not_ready';
            $meta['last_error'] = $errorMessage;
            $meta['title_readiness'] = $details;

            TaskRun::query()->whereKey($jobId)->update([
                'status' => 'failed',
                'error_message' => $errorMessage,
                'duration_ms' => $durationMs,
                'finished_at' => now(),
                'execution_lease_token' => null,
                'meta' => $meta,
            ]);
            TaskRun::query()
                ->where('task_id', $taskId)
                ->whereKeyNot($jobId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'cancelled',
                    'finished_at' => now(),
                    'error_message' => '任务因标题库配置问题自动暂停',
                ]);
            Task::query()->whereKey($taskId)->update([
                'status' => 'paused',
                'schedule_enabled' => 0,
                'next_run_at' => null,
                'last_run_at' => now(),
                'last_error_at' => now(),
                'last_error_message' => $errorMessage,
                'updated_at' => now(),
            ]);

            return true;
        });

        if ($changed) {
            $this->broadcastOverviewUpdate();
        }
    }

    /**
     * 主动取消执行（如管理员手动停止任务）。
     */
    public function cancelJob(
        int $jobId,
        int $taskId,
        string $reason = '管理员手动停止',
        ?AiExecutionContext $executionContext = null,
        ?string $executionLeaseToken = null,
    ): void {
        $reason = $this->aiExecutionErrorSanitizer->sanitize($reason, '任务已取消');
        $query = TaskRun::query()
            ->whereKey($jobId)
            ->where('task_id', $taskId)
            ->where('status', 'running');
        if ($executionContext instanceof AiExecutionContext) {
            $this->constrainRunToExecutionContext($query, $executionContext, $executionLeaseToken);
        } elseif (is_string($executionLeaseToken) && $executionLeaseToken !== '') {
            $query->where('execution_lease_token', $executionLeaseToken);
        }
        $cancelled = $query->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'error_message' => $reason,
            'duration_ms' => 0,
            'execution_lease_token' => null,
        ]);
        if ($cancelled !== 1) {
            return;
        }

        Task::query()->whereKey($taskId)->update([
            'last_run_at' => now(),
            'last_error_at' => now(),
            'last_error_message' => $reason,
            'updated_at' => now(),
        ]);

        $this->broadcastOverviewUpdate();
    }

    /**
     * 恢复失去队列消息的超时记录。
     *
     * - running：worker 异常退出、超时杀进程、心跳抛错等导致 `handle()` 未回写完成态；
     * - pending：数据库已提交，但 after-commit 队列发布失败或 Redis 消息丢失。
     *
     * @return int 成功重新投递的记录数
     */
    public function recoverStaleJobs(int $timeoutSeconds = 600, int $limit = 100): int
    {
        $threshold = now()->subSeconds(max(60, $timeoutSeconds));
        $limit = max(1, min(500, $limit));
        $recovered = $this->recoverStalePendingJobs($threshold, $limit);
        $remainingLimit = max(0, $limit - $recovered);
        if ($remainingLimit === 0) {
            return $recovered;
        }

        $candidateIds = TaskRun::query()
            ->where('status', 'running')
            ->where('started_at', '<', $threshold)
            ->orderBy('id')
            ->limit($remainingLimit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        foreach ($candidateIds as $jobId) {
            $dispatchToken = (string) Str::uuid();
            try {
                $redispatched = DB::transaction(function () use ($jobId, $threshold, $dispatchToken): bool {
                    $taskId = (int) TaskRun::query()
                        ->whereKey($jobId)
                        ->where('status', 'running')
                        ->where('started_at', '<', $threshold)
                        ->value('task_id');
                    if ($taskId <= 0) {
                        return false;
                    }

                    $task = $this->lockTaskForRecovery($taskId);
                    $run = TaskRun::query()
                        ->whereKey($jobId)
                        ->where('task_id', $taskId)
                        ->where('status', 'running')
                        ->where('started_at', '<', $threshold)
                        ->lockForUpdate()
                        ->first();
                    if (! $run) {
                        return false;
                    }

                    if (! $task || ($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
                        TaskRun::query()
                            ->whereKey($jobId)
                            ->where('status', 'running')
                            ->where('started_at', '<', $threshold)
                            ->update([
                                'status' => 'cancelled',
                                'finished_at' => now(),
                                'error_message' => '任务未启用，已取消超时执行记录',
                                'execution_lease_token' => null,
                            ]);

                        return false;
                    }

                    if (! $this->executionIdentityAllowsRecovery($run)) {
                        return false;
                    }

                    $meta = $this->normalizeMeta($run->meta);
                    $affected = TaskRun::query()
                        ->whereKey($jobId)
                        ->where('status', 'running')
                        ->where('started_at', '<', $threshold)
                        ->update([
                            'status' => 'pending',
                            'finished_at' => null,
                            'error_message' => '',
                            'error_code' => null,
                            'execution_lease_token' => null,
                            'resolved_ai_model_id' => null,
                            'resolved_model_source' => null,
                            'model_resolved_at' => null,
                            'meta' => array_merge($meta, [
                                'recovery_dispatched_at' => now()->toDateTimeString(),
                                'recovery_dispatch_token' => $dispatchToken,
                            ]),
                        ]);
                    if ($affected !== 1) {
                        return false;
                    }

                    $this->dispatchLaravelQueueJob($jobId);

                    return true;
                });
            } catch (Throwable $exception) {
                $this->clearFailedPendingRecoveryAttempt($jobId, $dispatchToken);
                $this->logRecoveryFailure($jobId, $exception);

                continue;
            }

            if ($redispatched) {
                $recovered++;
            }
        }

        return $recovered;
    }

    private function recoverStalePendingJobs(Carbon $threshold, int $limit): int
    {
        $candidateIds = TaskRun::query()
            ->where('status', 'pending')
            ->where('created_at', '<', $threshold)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $recovered = 0;
        foreach ($candidateIds as $jobId) {
            $dispatchToken = (string) Str::uuid();
            try {
                $redispatched = DB::transaction(function () use ($jobId, $threshold, $dispatchToken): bool {
                    $taskId = (int) TaskRun::query()
                        ->whereKey($jobId)
                        ->where('status', 'pending')
                        ->where('created_at', '<', $threshold)
                        ->value('task_id');
                    if ($taskId <= 0) {
                        return false;
                    }

                    $task = $this->lockTaskForRecovery($taskId);
                    $run = TaskRun::query()
                        ->whereKey($jobId)
                        ->where('task_id', $taskId)
                        ->where('status', 'pending')
                        ->where('created_at', '<', $threshold)
                        ->lockForUpdate()
                        ->first();
                    if (! $run) {
                        return false;
                    }

                    if (! $task || ($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
                        TaskRun::query()
                            ->whereKey($jobId)
                            ->where('status', 'pending')
                            ->update([
                                'status' => 'cancelled',
                                'finished_at' => now(),
                                'error_message' => '任务未启用，已取消待执行记录',
                                'execution_lease_token' => null,
                            ]);

                        return false;
                    }

                    if (! $this->executionIdentityAllowsRecovery($run)) {
                        return false;
                    }

                    $meta = $this->normalizeMeta($run->meta);
                    $availableAt = $this->parseMetaDate($meta['available_at'] ?? null);
                    if ($availableAt instanceof Carbon && $availableAt->greaterThan(now())) {
                        return false;
                    }

                    $staleReference = collect([
                        $run->created_at,
                        $availableAt,
                        $this->parseMetaDate($meta['recovery_dispatched_at'] ?? null),
                    ])->filter()->max();
                    if (! $staleReference instanceof Carbon || ! $staleReference->lessThan($threshold)) {
                        return false;
                    }

                    $affected = TaskRun::query()
                        ->whereKey($jobId)
                        ->where('status', 'pending')
                        ->update([
                            'execution_lease_token' => null,
                            'meta' => array_merge($meta, [
                                'recovery_dispatched_at' => now()->toDateTimeString(),
                                'recovery_dispatch_token' => $dispatchToken,
                            ]),
                        ]);
                    if ($affected !== 1) {
                        return false;
                    }

                    $this->dispatchLaravelQueueJob($jobId);

                    return true;
                });
            } catch (Throwable $exception) {
                $this->clearFailedPendingRecoveryAttempt($jobId, $dispatchToken);
                $this->logRecoveryFailure($jobId, $exception);

                continue;
            }

            if ($redispatched) {
                $recovered++;
            }
        }

        return $recovered;
    }

    private function clearFailedPendingRecoveryAttempt(int $jobId, string $dispatchToken): void
    {
        try {
            DB::transaction(function () use ($jobId, $dispatchToken): void {
                $run = TaskRun::query()
                    ->whereKey($jobId)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();
                if (! $run) {
                    return;
                }

                $meta = $this->normalizeMeta($run->meta);
                if (! hash_equals($dispatchToken, (string) ($meta['recovery_dispatch_token'] ?? ''))) {
                    return;
                }

                unset($meta['recovery_dispatched_at'], $meta['recovery_dispatch_token']);
                TaskRun::query()
                    ->whereKey($jobId)
                    ->where('status', 'pending')
                    ->update(['meta' => $meta]);
            });
        } catch (Throwable $exception) {
            $this->logRecoveryFailure($jobId, $exception);
        }
    }

    private function parseMetaDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function logRecoveryFailure(int $jobId, Throwable $exception): void
    {
        report(new RuntimeException(
            $this->aiExecutionErrorSanitizer->sanitize(
                $exception,
                'Task recovery failed for run '.$jobId,
            ),
        ));
    }

    /**
     * 将 task_runs 执行记录投递到 Laravel 队列。
     */
    private function dispatchLaravelQueueJob(int $taskRunId, mixed $availableAt = null): void
    {
        $claimLeaseToken = (string) Str::uuid();
        DB::afterCommit(function () use ($taskRunId, $availableAt, $claimLeaseToken): void {
            $dispatch = ProcessGeoFlowTaskJob::dispatch($taskRunId, $claimLeaseToken)
                ->onQueue('geoflow')
                ->afterCommit();

            if ($availableAt instanceof Carbon) {
                $dispatch->delay($availableAt);

                return;
            }

            if (is_string($availableAt) && trim($availableAt) !== '') {
                try {
                    $dispatch->delay(Carbon::parse($availableAt));
                } catch (Throwable) {
                    // ignore invalid datetime
                }
            }
        });
    }

    /**
     * 草稿生成成功后立即串行补投下一轮生成，使“生成草稿”和“按间隔发布”解耦。
     *
     * 发布动作不在这里补投：发布节奏由 next_publish_at + geoflow:schedule-tasks 控制。
     *
     * @param  array<string,mixed>  $meta
     */
    private function enqueueFollowUpGenerationIfNeeded(int $taskId, array $meta): void
    {
        if (($meta['action'] ?? '') !== 'generate_draft') {
            return;
        }

        if ((string) config('queue.default') === 'sync') {
            return;
        }

        $task = Task::query()
            ->whereKey($taskId)
            ->first(['id', 'status', 'schedule_enabled', 'created_count', 'article_limit', 'draft_limit']);
        if (! $task || ($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
            return;
        }

        $articleLimit = max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 10));
        if ((int) ($task->created_count ?? 0) >= $articleLimit) {
            return;
        }

        $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
        $draftCount = DB::table('articles')
            ->where('task_id', $taskId)
            ->where('status', 'draft')
            ->whereNull('deleted_at')
            ->count();
        if ($draftCount >= $draftLimit) {
            return;
        }

        $this->enqueueTaskJob($taskId, 'generate_article', [
            'source' => 'follow_up_generation',
        ]);
    }

    /**
     * 统一把 meta 解析为数组，屏蔽历史字符串/空值等差异。
     *
     * @return array<string,mixed>
     */
    private function normalizeMeta(mixed $meta): array
    {
        if (is_array($meta)) {
            return $meta;
        }

        if (is_string($meta) && trim($meta) !== '') {
            $decoded = json_decode($meta, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Keep queue metadata free of credentials, provider endpoints, prompts, and forged identity fields.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizeQueuePayload(array $payload): array
    {
        $source = $payload['source'] ?? null;
        if (! is_string($source)) {
            return [];
        }

        $source = trim($source);
        if (! in_array($source, self::ALLOWED_PAYLOAD_SOURCES, true)) {
            return [];
        }

        return ['source' => $source];
    }

    private function normalizeClaimLeaseToken(?string $claimLeaseToken): string
    {
        $claimLeaseToken = trim((string) $claimLeaseToken);

        return Str::isUuid($claimLeaseToken)
            ? $claimLeaseToken
            : (string) Str::uuid();
    }

    private function executionBoundariesEnforced(): bool
    {
        return (bool) config('geoflow.admin_ai_access.access_enforce_enabled', false)
            || (bool) config('geoflow.admin_ai_access.revocation_enforce_enabled', false);
    }

    /** @return array{requested_model_required:bool,quality_model_id:?int} */
    private function taskRunAiRequirement(TaskRun $run): array
    {
        $task = Task::query()->whereKey((int) $run->task_id)->first(['id', 'next_publish_at']);
        if (! $task instanceof Task || ($task->next_publish_at !== null && $task->next_publish_at->isFuture())) {
            return ['requested_model_required' => true, 'quality_model_id' => null];
        }

        $dueDraft = Article::query()
            ->where('task_id', (int) $task->getKey())
            ->where('status', 'draft')
            ->whereIn('review_status', ['approved', 'auto_approved'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->first();
        if (! $dueDraft instanceof Article) {
            return ['requested_model_required' => true, 'quality_model_id' => null];
        }

        try {
            return [
                'requested_model_required' => false,
                'quality_model_id' => $this->articleAiQualityGate->modelIdThatWouldBeDispatched($dueDraft),
            ];
        } catch (Throwable) {
            return ['requested_model_required' => true, 'quality_model_id' => null];
        }
    }

    private function executionIdentityAllowsDispatch(TaskRun $run): bool
    {
        $requirement = $this->taskRunAiRequirement($run);
        $requiresAiModel = $requirement['requested_model_required'] || $requirement['quality_model_id'] !== null;
        if (! $requiresAiModel && ! $this->executionBoundariesEnforced()) {
            return true;
        }

        try {
            $context = $this->aiExecutionContextFactory->fromTaskRun($run);
            if ($requirement['requested_model_required'] && $context->requestedModelId === null) {
                throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
            }
            $admin = $this->aiExecutionAccessGuard->assertCurrent(
                $context,
                validateRequestedModel: $requirement['requested_model_required'],
            );
            if ($requirement['quality_model_id'] !== null) {
                $this->aiExecutionAccessGuard->assertModelCurrent(
                    $context,
                    $requirement['quality_model_id'],
                    $admin,
                );
            }
        } catch (AiModelAccessException $exception) {
            $this->permanentlyFailAuthorizationRun($run, $exception->getErrorCode());

            return false;
        }

        return true;
    }

    private function executionIdentityAllowsRecovery(TaskRun $run): bool
    {
        $requirement = $this->taskRunAiRequirement($run);
        $requiresAiModel = $requirement['requested_model_required'] || $requirement['quality_model_id'] !== null;
        if (! $requiresAiModel && ! $this->executionBoundariesEnforced()) {
            return true;
        }

        try {
            if ((string) $run->status === 'pending') {
                $this->assertPendingRunIdentityComplete($run, $requirement['requested_model_required']);
            } else {
                $context = $this->aiExecutionContextFactory->fromTaskRun($run);
                if ($requirement['requested_model_required'] && $context->requestedModelId === null) {
                    throw AiModelAccessException::configAccessRevokedForAdminId($context->modelAccessAdminId);
                }
            }
        } catch (AiModelAccessException $exception) {
            $this->permanentlyFailAuthorizationRun($run, $exception->getErrorCode());

            return false;
        }

        return true;
    }

    private function assertPendingRunIdentityComplete(TaskRun $run, bool $requestedModelRequired): void
    {
        $adminId = (int) ($run->model_access_admin_id ?? 0);
        if ($adminId <= 0
            || ! in_array((string) ($run->model_access_admin_role ?? ''), ['admin', 'super_admin'], true)
            || (int) ($run->ai_config_access_version ?? 0) <= 0
            || (int) ($run->resolver_policy_version ?? 0) <= 0
            || ($requestedModelRequired && (int) ($run->requested_ai_model_id ?? 0) <= 0)) {
            throw AiModelAccessException::configAccessRevokedForAdminId($adminId);
        }
    }

    private function lockTaskForClaim(int $taskId): ?Task
    {
        return Task::query()
            ->whereKey($taskId)
            ->lockForUpdate()
            ->first(['id', 'status', 'schedule_enabled', 'publish_interval']);
    }

    private function lockRunForClaim(int $jobId, int $taskId): ?TaskRun
    {
        return TaskRun::query()
            ->whereKey($jobId)
            ->where('task_id', $taskId)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->first();
    }

    private function lockTaskForRecovery(int $taskId): ?Task
    {
        return Task::query()
            ->whereKey($taskId)
            ->lockForUpdate()
            ->first(['id', 'status', 'schedule_enabled']);
    }

    private function permanentlyFailAuthorizationRun(
        TaskRun $run,
        string $errorCode,
        int $durationMs = 0,
    ): void {
        $errorCode = $this->normalizeAuthorizationErrorCode($errorCode);
        $meta = $this->normalizeMeta($run->meta);
        $meta['retryable'] = false;
        $meta['failure_class'] = 'authorization';
        $meta['error_code'] = $errorCode;
        $meta['last_error'] = $errorCode;

        $run->forceFill([
            'status' => 'failed',
            'error_code' => $errorCode,
            'error_message' => $errorCode,
            'duration_ms' => $durationMs,
            'finished_at' => now(),
            'execution_lease_token' => null,
            'meta' => $meta,
        ])->save();

        Task::query()->whereKey((int) $run->task_id)->update([
            'status' => 'paused',
            'schedule_enabled' => 0,
            'next_run_at' => null,
            'last_run_at' => now(),
            'last_error_at' => now(),
            'last_error_message' => $errorCode,
            'updated_at' => now(),
        ]);
    }

    private function normalizeAuthorizationErrorCode(string $errorCode): string
    {
        return in_array($errorCode, [
            AiModelAccessException::AI_MODEL_NOT_ACCESSIBLE,
            AiModelAccessException::AI_EXECUTION_ADMIN_INACTIVE,
            AiModelAccessException::AI_CONFIG_ACCESS_REVOKED,
            AiModelAccessException::AI_CONFIG_OWNER_INACTIVE,
            AiModelAccessException::AI_MODEL_UNAVAILABLE,
            AiModelAccessException::AI_EMBEDDING_INCOMPATIBLE,
        ], true)
            ? $errorCode
            : AiModelAccessException::AI_CONFIG_ACCESS_REVOKED;
    }

    private function constrainRunToExecutionContext(
        Builder $query,
        ?AiExecutionContext $executionContext,
        ?string $executionLeaseToken = null,
    ): void {
        if (! $executionContext instanceof AiExecutionContext) {
            if (is_string($executionLeaseToken) && $executionLeaseToken !== '') {
                $query->where('execution_lease_token', $executionLeaseToken);
            } elseif ($this->executionBoundariesEnforced()) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereNull('execution_lease_token');
            }

            return;
        }

        $query
            ->whereKey($executionContext->taskRunId)
            ->where('model_access_admin_id', $executionContext->modelAccessAdminId)
            ->where('model_access_admin_role', $executionContext->modelAccessAdminRole)
            ->where('ai_config_access_version', $executionContext->aiConfigAccessVersion)
            ->where('resolver_policy_version', $executionContext->resolverPolicyVersion)
            ->where('execution_lease_token', $executionContext->executionLeaseToken());
    }

    /**
     * 广播最新任务面板快照（失败不影响主流程）。
     */
    private function broadcastOverviewUpdate(): void
    {
        DB::afterCommit(function (): void {
            try {
                app(TaskRealtimeBroadcastService::class)->broadcastOverview();
            } catch (Throwable) {
                // ignore
            }
        });
    }
}
