<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\WorkerHeartbeat;
use App\Services\GeoFlow\JobQueueService;
use App\Services\GeoFlow\WorkerExecutionService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * GEOFlow 常驻 worker（Laravel 版）：
 * - 领取 pending job
 * - 生成文章并回写任务统计
 * - 写入 worker 心跳供后台展示
 */
class GeoFlowWorkerCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'geoflow:worker {--once : 仅执行一个循环} {--sleep=5 : 空闲时休眠秒数}';

    /**
     * @var string
     */
    protected $description = 'Run GEOFlow queue worker loop';

    public function __construct(
        private readonly JobQueueService $queueService,
        private readonly WorkerExecutionService $workerExecutionService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->warn('geoflow:worker 已弃用。请使用 Laravel 队列: php artisan queue:work redis --queue=geoflow');

        return self::SUCCESS;
    }

    /**
     * 兼容旧 worker 的取消条件。
     */
    private function shouldCancel(int $taskId, string $message): bool
    {
        if (str_contains($message, '管理员手动停止') || str_contains($message, '任务未激活')) {
            return true;
        }

        $row = Task::query()
            ->whereKey($taskId)
            ->first(['status', 'schedule_enabled']);
        if (! $row) {
            return true;
        }

        return ($row->status ?? 'paused') !== 'active' || (int) ($row->schedule_enabled ?? 1) !== 1;
    }

    /**
     * 写 worker 心跳，供任务页「Worker 状态」卡片展示。
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
        } catch (Throwable $e) {
            throw new RuntimeException('写入 worker 心跳失败: '.$e->getMessage(), 0, $e);
        }
    }
}
