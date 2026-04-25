<?php

namespace App\Console\Commands;

use App\Models\Article;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\GeoFlow\JobQueueService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * GeoFlow 任务调度命令（对齐 bak/bin/cron.php 的入队判定）。
 *
 * 目标：
 * 1. 按任务状态与时间窗口筛选“应执行任务”；
 * 2. 为每个任务最多创建一条待执行记录（避免重复入队）；
 * 3. 入队成功后推进 next_run_at，形成周期调度。
 */
class GeoFlowScheduleTasksCommand extends Command
{
    protected $signature = 'geoflow:schedule-tasks';

    protected $description = 'Scan active GeoFlow tasks and enqueue due jobs';

    public function __construct(
        private readonly JobQueueService $jobQueueService
    ) {
        parent::__construct();
    }

    /**
     * 扫描活跃任务并按条件入队。
     */
    public function handle(): int
    {
        $now = now();
        $recoveredCount = $this->jobQueueService->recoverStaleJobs();

        $queuedCount = 0;
        $skippedCount = 0;

        $tasks = Task::query()
            ->select(['id', 'name', 'publish_interval', 'draft_limit', 'next_run_at', 'schedule_enabled'])
            ->where('status', 'active')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        $taskIds = $tasks->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        // 批量聚合草稿数，避免在循环内逐任务 count 触发 N+1 查询。
        $draftCountMap = empty($taskIds)
            ? collect()
            : Article::query()
                ->selectRaw('task_id, COUNT(*) AS draft_count')
                ->whereIn('task_id', $taskIds)
                ->where('status', 'draft')
                ->whereNull('deleted_at')
                ->groupBy('task_id')
                ->pluck('draft_count', 'task_id');
        // 批量获取“已有 pending/running 执行记录”的任务集合，减少循环内 exists 查询。
        $busyTaskLookup = empty($taskIds)
            ? []
            : array_fill_keys(
                TaskRun::query()
                    ->whereIn('task_id', $taskIds)
                    ->whereIn('status', ['pending', 'running'])
                    ->groupBy('task_id')
                    ->pluck('task_id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all(),
                true
            );

        foreach ($tasks as $task) {
            $taskId = (int) $task->id;
            if ((int) ($task->schedule_enabled ?? 1) !== 1) {
                $skippedCount++;

                continue;
            }

            $draftCount = (int) ($draftCountMap->get($taskId) ?? 0);
            if ($draftCount >= (int) ($task->draft_limit ?? 10)) {
                $skippedCount++;

                continue;
            }

            // 首次无 next_run_at 时仅初始化，不在当前轮直接入队（与 bak 保持一致）。
            if (! $task->next_run_at instanceof Carbon) {
                $this->jobQueueService->initializeTaskSchedule($taskId);
                $skippedCount++;

                continue;
            }

            if ($task->next_run_at->greaterThan($now)) {
                $skippedCount++;

                continue;
            }

            if (isset($busyTaskLookup[$taskId])) {
                $skippedCount++;

                continue;
            }

            $taskRunId = $this->jobQueueService->enqueueTaskJob($taskId);
            if ($taskRunId === null) {
                $skippedCount++;

                continue;
            }

            $nextRunAt = $now->copy()->addSeconds(max(60, (int) ($task->publish_interval ?? 3600)));
            Task::query()->whereKey($taskId)->update([
                'next_run_at' => $nextRunAt,
                'updated_at' => now(),
            ]);
            $queuedCount++;
        }

        $this->info(sprintf(
            'GeoFlow scheduler done: queued=%d, skipped=%d, recovered=%d',
            $queuedCount,
            $skippedCount,
            $recoveredCount
        ));

        return self::SUCCESS;
    }
}
