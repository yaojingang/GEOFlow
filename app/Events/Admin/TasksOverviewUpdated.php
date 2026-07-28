<?php

namespace App\Events\Admin;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 后台任务状态变更信号。
 */
class TasksOverviewUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public string $changedAt
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.tasks');
    }

    public function broadcastAs(): string
    {
        return 'tasks.overview.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'refresh_required' => true,
            'changed_at' => $this->changedAt,
        ];
    }
}
