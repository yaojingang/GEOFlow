<?php

namespace App\Services\GeoFlow;

use App\Models\TaskRun;
use Throwable;

/**
 * Horizon / Redis 监控指标适配器。
 *
 * 目标：
 * - 不依赖 Horizon UI 页面；
 * - 在任务管理页直接消费队列监控数据；
 * - 对外提供统一结构，后续可平滑切换到更多 Horizon 指标源。
 */
class HorizonMetricsAdapter
{
    /**
     * 获取队列概览。
     *
     * 指标口径：
     * - pending: Redis 列表长度（queues:{queue}）
     * - running: Redis reserved zset 数量（queues:{queue}:reserved）
     * - failed: failed_jobs 中的队列失败数
     * - completed: task_runs 中 completed 数（业务完成量）
     *
     * @return array{pending:int,running:int,failed:int,completed:int}
     */
    public function queueOverview(string $queueName = 'geoflow'): array
    {
        $overview = [
            'pending' => 0,
            'running' => 0,
            'failed' => 0,
            'completed' => 0,
        ];

        try {
            // 管理页口径统一到 task_runs，避免 Redis reserved 列表短时抖动导致“执行中”假阳性。
            $stats = TaskRun::query()
                ->selectRaw("
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count,
                    SUM(CASE WHEN status = 'running' THEN 1 ELSE 0 END) AS running_count,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                    SUM(CASE WHEN status IN ('failed','cancelled') THEN 1 ELSE 0 END) AS failed_count
                ")
                ->first();
            if ($stats) {
                $overview['pending'] = (int) ($stats->pending_count ?? 0);
                $overview['running'] = (int) ($stats->running_count ?? 0);
                $overview['completed'] = (int) ($stats->completed_count ?? 0);
                $overview['failed'] = (int) ($stats->failed_count ?? 0);
            }
        } catch (Throwable) {
            // task_runs 查询异常时降级为 0，避免阻塞任务页渲染。
        }

        return $overview;
    }
}
