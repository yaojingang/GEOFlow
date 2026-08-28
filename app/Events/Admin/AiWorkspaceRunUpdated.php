<?php

namespace App\Events\Admin;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AiWorkspaceRunUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $adminId,
        public readonly int $adminAuthVersion,
        public readonly string $runId,
        public readonly string $conversationId,
        public readonly string $state,
        public readonly int $version,
        public readonly int $sequence,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.ai-workspace.'.$this->adminId.'.'.$this->adminAuthVersion);
    }

    public function broadcastAs(): string
    {
        return 'ai-workspace.run.updated';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'conversation_id' => $this->conversationId,
            'state' => $this->state,
            'version' => $this->version,
            'sequence' => $this->sequence,
        ];
    }
}
