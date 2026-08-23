<?php

namespace App\Services\AiWorkspace;

use App\Events\Admin\AiWorkspaceAnswerDelta;
use App\Events\Admin\AiWorkspaceRunUpdated;
use App\Models\AiWorkspaceRun;
use Throwable;

final readonly class AiWorkspaceRealtimeService
{
    public function broadcast(AiWorkspaceRun $run): void
    {
        if ($run->admin_id === null) {
            return;
        }

        try {
            $fresh = $run->fresh();
            broadcast(new AiWorkspaceRunUpdated(
                (int) $fresh->admin_id,
                (string) $fresh->id,
                (string) $fresh->conversation_id,
                (string) $fresh->state,
                (int) $fresh->state_version,
                (int) $fresh->event_sequence,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    public function broadcastAnswerDelta(AiWorkspaceRun $run, int $sequence, string $delta): void
    {
        if ($run->admin_id === null || $delta === '') {
            return;
        }

        try {
            broadcast(new AiWorkspaceAnswerDelta(
                (int) $run->admin_id,
                (string) $run->id,
                (string) $run->conversation_id,
                (int) $run->state_version,
                (int) $run->event_sequence,
                $sequence,
                $delta,
            ));
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
