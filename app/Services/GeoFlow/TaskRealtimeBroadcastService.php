<?php

namespace App\Services\GeoFlow;

use App\Events\Admin\TasksOverviewUpdated;
use Illuminate\Support\Carbon;

/**
 * 任务页实时推送服务。
 *
 * 广播只发送轻量刷新信号，任务页按当前分页拉取有界快照。
 */
class TaskRealtimeBroadcastService
{
    /**
     * 推送最新任务监控快照到 Reverb 频道。
     *
     * 这里吞掉广播异常，避免 WebSocket 抖动影响主业务流程（入队/完成/失败等）。
     */
    public function broadcastOverview(): void
    {
        try {
            broadcast(new TasksOverviewUpdated(Carbon::now()->toIso8601String()));
        } catch (\Throwable) {
            // Ignore broadcast failure and keep business flow stable.
        }
    }
}
