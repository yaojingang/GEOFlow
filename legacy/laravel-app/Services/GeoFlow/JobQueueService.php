<?php

namespace App\Services\GeoFlow;

use App\Jobs\ProcessGeoFlowTaskJob;
use App\Models\Task;
use App\Models\TaskRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
        $run = DB::transaction(function () use ($taskId, $jobType, $payload, $availableAt): ?TaskRun {
            $taskRow = Task::query()
                ->whereKey($taskId)
                ->lockForUpdate()
                ->first(['id', 'max_retry_count']);
            if (! $taskRow) {
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

            // 建立“待执行记录”，作为后续状态流转的唯一主记录。
            return TaskRun::query()->create([
                'task_id' => $taskId,
                'status' => 'pending',
                'meta' => [
                    'job_type' => $jobType,
                    'payload' => $payload,
                    'attempt_count' => 0,
                    'max_attempts' => $maxAttempts,
                    'available_at' => $availableAtValue->toDateTimeString(),
                ],
                'started_at' => $availableAtValue,
                'finished_at' => null,
            ]);
        });
        if (! $run) {
            return null;
        }

        // 完全使用 Laravel Queue 执行。
        $this->dispatchLaravelQueueJob((int) $run->id, $run->started_at);
        $this->broadcastOverviewUpdate();

        return (int) $run->id;
    }

    /**
     * 领取指定 ID 的 pending 任务执行记录（供 Laravel 队列 Job 执行时使用）。
     *
     * @return array<string,mixed>|null
     */
    public function claimPendingJobById(int $jobId, string $workerId): ?array
    {
        $claimedJob = DB::transaction(function () use ($jobId, $workerId): ?array {
            // 使用悲观锁 + 状态条件，确保同一条记录只会被一个 worker 成功 claim。
            $run = TaskRun::query()
                ->with('task:id,status,schedule_enabled,publish_interval')
                ->whereKey($jobId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            if (! $run) {
                return null;
            }
            $task = $run->task;
            // 任务未激活或调度被关闭时，不允许执行。
            if (! $task || ($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
                TaskRun::query()
                    ->whereKey((int) $run->id)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'cancelled',
                        'finished_at' => now(),
                        'error_message' => '任务未启用，已取消待执行记录',
                    ]);

                return null;
            }

            $meta = $this->normalizeMeta($run->meta);
            $availableAt = (string) ($meta['available_at'] ?? '');
            // 尚未到可执行时间，直接跳过（由队列 delay 机制在后续触发）。
            if ($availableAt !== '' && Carbon::parse($availableAt)->greaterThan(now())) {
                return null;
            }

            $affected = TaskRun::query()
                ->whereKey($jobId)
                ->where('status', 'pending')
                ->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'meta' => array_merge($meta, ['worker_id' => $workerId]),
                ]);

            if ($affected !== 1) {
                return null;
            }

            // 返回轻量执行上下文，供 ProcessGeoFlowTaskJob 使用。
            $row = $run->getAttributes();
            $row['status'] = 'running';
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

    /**
     * 处理成功完成：回写执行记录 + 任务最近成功状态。
     *
     * @param  array<string,mixed>  $meta  执行产物元数据（如模型信息、trace 信息等）
     */
    public function completeJob(int $jobId, int $taskId, ?int $articleId, int $durationMs, array $meta = []): void
    {
        TaskRun::query()->whereKey($jobId)->update([
            'status' => 'completed',
            'finished_at' => now(),
            'article_id' => $articleId,
            'duration_ms' => $durationMs,
            'meta' => $meta,
            'error_message' => '',
        ]);

        Task::query()->whereKey($taskId)->update([
            'last_run_at' => now(),
            'last_success_at' => now(),
            'last_error_at' => null,
            'last_error_message' => '',
            'updated_at' => now(),
        ]);

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
    public function failJob(int $jobId, int $taskId, string $errorMessage, int $durationMs, int $retryDelaySeconds = 60): void
    {
        $run = TaskRun::query()->whereKey($jobId)->first();
        if (! $run) {
            return;
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

        TaskRun::query()->whereKey($jobId)->update([
            'status' => $shouldRetry ? 'pending' : 'failed',
            'error_message' => $errorMessage,
            'duration_ms' => $durationMs,
            'finished_at' => $shouldRetry ? null : now(),
            'meta' => $newMeta,
        ]);

        Task::query()->whereKey($taskId)->update([
            'last_run_at' => now(),
            'last_error_at' => now(),
            'last_error_message' => $errorMessage,
            'updated_at' => now(),
        ]);

        if ($shouldRetry) {
            $this->dispatchLaravelQueueJob($jobId, $nextAvailableAt);
        }

        $this->broadcastOverviewUpdate();
    }

    /**
     * 主动取消执行（如管理员手动停止任务）。
     */
    public function cancelJob(int $jobId, int $taskId, string $reason = '管理员手动停止'): void
    {
        TaskRun::query()->whereKey($jobId)->update([
            'status' => 'cancelled',
            'finished_at' => now(),
            'error_message' => $reason,
            'duration_ms' => 0,
        ]);

        Task::query()->whereKey($taskId)->update([
            'last_run_at' => now(),
            'last_error_at' => now(),
            'last_error_message' => $reason,
            'updated_at' => now(),
        ]);

        $this->broadcastOverviewUpdate();
    }

    /**
     * 恢复超时未完成的 running 记录。
     *
     * 兜底场景：worker 异常退出、超时杀进程、心跳抛错等导致 `handle()` 未回写完成态。
     * 处理方式：将仍卡在 running 的记录回退为 pending，并立即重新投递队列，避免「面板显示待执行但 Redis 里已无对应 Job」。
     *
     * @return int 成功回退并重新投递的记录数
     */
    public function recoverStaleJobs(int $timeoutSeconds = 600): int
    {
        $threshold = now()->subSeconds(max(60, $timeoutSeconds));
        $candidateIds = TaskRun::query()
            ->where('status', 'running')
            ->where('started_at', '<', $threshold)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        $recovered = 0;
        foreach ($candidateIds as $jobId) {
            /** @var TaskRun|null $run */
            $run = TaskRun::query()
                ->with('task:id,status,schedule_enabled')
                ->whereKey($jobId)
                ->where('status', 'running')
                ->first();

            if (! $run) {
                continue;
            }

            $task = $run->task;
            if (! $task || ($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
                TaskRun::query()
                    ->whereKey($jobId)
                    ->where('status', 'running')
                    ->update([
                        'status' => 'cancelled',
                        'finished_at' => now(),
                        'error_message' => '任务未启用，已取消超时执行记录',
                    ]);

                continue;
            }

            $affected = TaskRun::query()
                ->whereKey($jobId)
                ->where('status', 'running')
                ->update([
                    'status' => 'pending',
                    'finished_at' => null,
                    'error_message' => '',
                ]);

            if ($affected === 1) {
                $this->dispatchLaravelQueueJob($jobId);
                $recovered++;
            }
        }

        return $recovered;
    }

    /**
     * 将 task_runs 执行记录投递到 Laravel 队列。
     */
    private function dispatchLaravelQueueJob(int $taskRunId, mixed $availableAt = null): void
    {
        $dispatch = ProcessGeoFlowTaskJob::dispatch($taskRunId)->onQueue('geoflow');

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
     * 广播最新任务面板快照（失败不影响主流程）。
     */
    private function broadcastOverviewUpdate(): void
    {
        try {
            app(TaskRealtimeBroadcastService::class)->broadcastOverview();
        } catch (Throwable) {
            // ignore
        }
    }
}
