<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskRun;
use App\Models\WorkerHeartbeat;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Throwable;

/**
 * Laravel 队列任务：执行一条 task_runs 记录。
 *
 * 设计目标：
 * 1. 完全使用 Laravel Queue + Redis 作为调度执行链路；
 * 2. 用 Laravel Queue 承担调度与执行，不再依赖 while(true) 扫描；
 * 3. 继续回写 task_runs / tasks / worker_heartbeats，保证后台面板可追踪。
 */
class ProcessGeoFlowTaskJob implements ShouldQueue
{
    use Queueable;

    /**
     * 为避免与业务重试策略双重重试冲突，Laravel 层固定单次执行；
     * 业务重试由 JobQueueService::failJob 回写并二次 dispatch。
     */
    public int $tries = 1;

    /**
     * 单次执行超时（秒）。
     */
    public int $timeout = 300;

    public function __construct(
        public readonly int $taskRunId
    ) {}

    /**
     * 为 Horizon 监控提供稳定标签，便于按任务维度聚合队列表现。
     *
     * @return array<int,string>
     */
    public function tags(): array
    {
        $run = TaskRun::query()->whereKey($this->taskRunId)->first(['task_id']);
        $taskId = (int) ($run?->task_id ?? 0);

        return array_values(array_filter([
            'geoflow',
            'task_run:'.$this->taskRunId,
            $taskId > 0 ? 'task:'.$taskId : null,
        ]));
    }

    public function handle(JobQueueService $queueService, WorkerExecutionService $workerExecutionService): void
    {
        $workerId = gethostname().':queue:'.getmypid();
        $job = $queueService->claimPendingJobById($this->taskRunId, $workerId);
        if (! is_array($job)) {
            return;
        }

        $taskId = (int) Arr::get($job, 'task_id', 0);
        if ($taskId <= 0) {
            return;
        }

        $this->heartbeat($workerId, 'running', [
            'pid' => getmypid(),
            'task_id' => $taskId,
            'stage' => 'claimed',
            'task_run_id' => $this->taskRunId,
        ]);

        $startedAt = microtime(true);
        try {
            $result = $workerExecutionService->executeTask($taskId);
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            $queueService->completeJob(
                jobId: $this->taskRunId,
                taskId: $taskId,
                articleId: Arr::get($result, 'article_id') !== null ? (int) Arr::get($result, 'article_id') : null,
                durationMs: $durationMs,
                meta: is_array(Arr::get($result, 'meta')) ? Arr::get($result, 'meta') : []
            );
        } catch (Throwable $exception) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $message = $exception->getMessage();

            if ($this->shouldCancel($taskId, $message)) {
                $queueService->cancelJob($this->taskRunId, $taskId, '管理员手动停止');
            } else {
                $queueService->failJob($this->taskRunId, $taskId, $message, $durationMs);
            }
        } finally {
            $this->heartbeat($workerId, 'idle', [
                'pid' => getmypid(),
                'last_task_run_id' => $this->taskRunId,
            ]);
        }
    }

    /**
     * Worker 超时、进程被杀死或 handle 未捕获的异常时由框架回调，避免 task_runs 永久停在 running。
     *
     * 与 handle() 内 catch 中的 failJob 互斥：仅当记录仍为 running 时才回写，防止重复扣减重试次数。
     */
    public function failed(?Throwable $exception = null): void
    {
        try {
            $run = TaskRun::query()->whereKey($this->taskRunId)->first(['id', 'task_id', 'status']);
            if (! $run || ($run->status ?? '') !== 'running') {
                return;
            }

            $message = $exception !== null ? trim($exception->getMessage()) : '';
            if ($message === '') {
                $message = '队列任务异常退出';
            }

            app(JobQueueService::class)->failJob(
                (int) $run->id,
                (int) $run->task_id,
                '队列中断: '.$message,
                0
            );
        } catch (Throwable) {
            // 避免失败回调自身再抛错导致 Horizon 日志刷屏
        }
    }

    /**
     * 取消判定：
     * - 任务已停用；
     * - 异常文本明确为手动停止/任务未激活。
     */
    private function shouldCancel(int $taskId, string $message): bool
    {
        if (str_contains($message, '管理员手动停止') || str_contains($message, '任务未激活')) {
            return true;
        }

        $task = Task::query()->whereKey($taskId)->first(['status', 'schedule_enabled']);
        if (! $task) {
            return true;
        }

        return ($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1;
    }

    /**
     * 写队列 worker 心跳（兼容原任务页运行面板）。
     *
     * @param  array<string,mixed>  $meta
     */
    private function heartbeat(string $workerId, string $status, array $meta): void
    {
        try {
            WorkerHeartbeat::query()->updateOrCreate(
                ['worker_id' => $workerId],
                [
                    'status' => $status,
                    'last_seen_at' => now(),
                    'meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                ]
            );
        } catch (Throwable) {
            // 心跳表异常不阻塞生成链路，否则 task_runs 会卡在 running 且无法进入 fail/complete
        }
    }
}
