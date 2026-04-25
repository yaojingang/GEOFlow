<?php

namespace App\Console\Commands;

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
            ->select(['id', 'name', 'publish_interval', 'draft_limit', 'article_limit', 'created_count', 'next_run_at', 'next_publish_at', 'schedule_enabled'])
            ->where('status', 'active')
            ->orderBy('updated_at')
            ->orderBy('id')
            ->get();

        $taskIds = $tasks->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
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

        $articleStats = empty($taskIds)
            ? collect()
            : \Illuminate\Support\Facades\DB::table('articles')
                ->selectRaw("
                    task_id,
                    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) AS draft_articles,
                    SUM(CASE WHEN status = 'draft' AND review_status IN ('approved','auto_approved') THEN 1 ELSE 0 END) AS publishable_drafts
                ")
                ->whereIn('task_id', $taskIds)
                ->whereNull('deleted_at')
                ->groupBy('task_id')
                ->get()
                ->mapWithKeys(static fn (object $row): array => [
                    (int) $row->task_id => [
                        'draft_articles' => (int) ($row->draft_articles ?? 0),
                        'publishable_drafts' => (int) ($row->publishable_drafts ?? 0),
                    ],
                ]);

        foreach ($tasks as $task) {
            $taskId = (int) $task->id;
            if ((int) ($task->schedule_enabled ?? 1) !== 1) {
                $skippedCount++;

                continue;
            }

            $articleLimit = max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 10));
            $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
            $createdCount = (int) ($task->created_count ?? 0);
            $stats = $articleStats->get($taskId, ['draft_articles' => 0, 'publishable_drafts' => 0]);
            $draftCount = (int) ($stats['draft_articles'] ?? 0);
            $publishableDrafts = (int) ($stats['publishable_drafts'] ?? 0);
            $nextPublishAt = $task->next_publish_at instanceof Carbon ? $task->next_publish_at : null;
            $canGenerate = $createdCount < $articleLimit && $draftCount < $draftLimit;
            $canPublishNow = $publishableDrafts > 0 && ($nextPublishAt === null || ! $nextPublishAt->greaterThan($now));

            if (! $canGenerate && ! $canPublishNow) {
                if ($publishableDrafts > 0 && $nextPublishAt instanceof Carbon) {
                    Task::query()->whereKey($taskId)->update([
                        'next_run_at' => $nextPublishAt,
                        'updated_at' => now(),
                    ]);
                }
                $skippedCount++;

                continue;
            }

            // 首次无 next_run_at 时仅初始化，不在当前轮直接入队（与 bak 保持一致）。
            if (! $task->next_run_at instanceof Carbon) {
                $this->jobQueueService->initializeTaskSchedule($taskId);
                $skippedCount++;

                continue;
            }

            if ($task->next_run_at->greaterThan($now) && ! $canPublishNow) {
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

            // 生成与发布解耦：调度器保持分钟级扫描，Worker 内部按 next_publish_at 控制发布。
            $nextRunAt = $now->copy()->addSeconds(60);
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
