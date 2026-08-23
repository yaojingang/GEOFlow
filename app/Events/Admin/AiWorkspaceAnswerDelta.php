<?php

namespace App\Events\Admin;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class AiWorkspaceAnswerDelta implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly int $adminId,
        public readonly string $runId,
        public readonly string $conversationId,
        public readonly int $runVersion,
        public readonly int $runSequence,
        public readonly int $chunkSequence,
        public readonly string $delta,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.ai-workspace.'.$this->adminId);
    }

    public function broadcastAs(): string
    {
        return 'ai-workspace.answer.delta';
    }

    /** @return array<string,mixed> */
    public function broadcastWith(): array
    {
        return [
            'run_id' => $this->runId,
            'conversation_id' => $this->conversationId,
            'run_version' => $this->runVersion,
            'run_sequence' => $this->runSequence,
            'chunk_sequence' => $this->chunkSequence,
            'delta' => $this->delta,
        ];
    }
}
