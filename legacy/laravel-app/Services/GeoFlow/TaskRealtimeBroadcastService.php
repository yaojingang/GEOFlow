<?php

namespace App\Services\GeoFlow;

use App\Events\Admin\TasksOverviewUpdated;
use Throwable;

/**
 * 任务页实时推送服务。
 *
 * 统一封装“构建总览并广播”的流程，避免在业务服务里重复拼装数据。
 */
class TaskRealtimeBroadcastService
{
    public function __construct(
        private readonly TaskMonitoringQueryService $taskMonitoringQueryService
    ) {}

    /**
     * 推送最新任务监控快照到 Reverb 频道。
     *
     * 这里吞掉广播异常，避免 WebSocket 抖动影响主业务流程（入队/完成/失败等）。
     */
    public function broadcastOverview(): void
    {
        try {
            $overview = $this->taskMonitoringQueryService->buildAdminOverview();
            broadcast(new TasksOverviewUpdated($overview));
        } catch (Throwable) {
            // Ignore broadcast failure and keep business flow stable.
        }
    }
}
