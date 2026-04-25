<?php

namespace App\Events\Admin;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 后台任务总览实时推送事件。
 *
 * 用于把任务列表、队列统计、最近运行记录通过 Reverb 推送给管理页面，
 * 以替代前端定时轮询。
 */
class TasksOverviewUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array{
     *     tasks:list<array<string,mixed>>,
     *     queue_overview:array<string,int>,
     *     worker_overview:list<array<string,mixed>>,
     *     recent_runs:list<array<string,mixed>>
     * }  $overview
     */
    public function __construct(
        public array $overview
    ) {}

    public function broadcastOn(): Channel
    {
        return new Channel('admin.tasks');
    }

    public function broadcastAs(): string
    {
        return 'tasks.overview.updated';
    }

    /**
     * @return array{
     *     tasks:list<array<string,mixed>>,
     *     queue_overview:array<string,int>,
     *     worker_overview:list<array<string,mixed>>,
     *     recent_runs:list<array<string,mixed>>
     * }
     */
    public function broadcastWith(): array
    {
        return $this->overview;
    }
}
