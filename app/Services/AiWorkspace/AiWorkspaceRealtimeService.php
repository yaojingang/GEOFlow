<?php

namespace App\Services\AiWorkspace;

use App\Events\Admin\AiWorkspaceAnswerDelta;
use App\Events\Admin\AiWorkspaceRunUpdated;
use App\Models\Admin;
use App\Models\AiWorkspaceRun;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class AiWorkspaceRealtimeService
{
    /** @var array<string,true> */
    private array $answerDeltaCircuits = [];

    public function broadcast(AiWorkspaceRun $run): void
    {
        $fresh = $this->persistableRun($run);
        if (! $fresh instanceof AiWorkspaceRun) {
            return;
        }

        $authorized = $this->authorizedRun($fresh);
        if (! $authorized instanceof AiWorkspaceRun) {
            return;
        }

        try {
            broadcast(new AiWorkspaceRunUpdated(
                (int) $authorized->admin_id,
                (int) $authorized->admin_auth_version,
                (string) $authorized->id,
                (string) $authorized->conversation_id,
                (string) $authorized->state,
                (int) $authorized->state_version,
                (int) $authorized->event_sequence,
            ));
        } catch (Throwable $exception) {
            $this->recordFailure('snapshot_broadcast');
            report($exception);
        }
    }

    public function broadcastAnswerDelta(AiWorkspaceRun $run, int $sequence, string $delta): bool
    {
        if ($run->admin_id === null || $delta === '' || isset($this->answerDeltaCircuits[(string) $run->id])) {
            return false;
        }

        $fresh = $this->authorizedRun($run);
        if (! $fresh instanceof AiWorkspaceRun) {
            return false;
        }

        try {
            broadcast(new AiWorkspaceAnswerDelta(
                (int) $fresh->admin_id,
                (int) $fresh->admin_auth_version,
                (string) $fresh->id,
                (string) $fresh->conversation_id,
                (int) $fresh->state_version,
                (int) $fresh->event_sequence,
                $sequence,
                $delta,
            ));

            return true;
        } catch (Throwable $exception) {
            $this->answerDeltaCircuits[(string) $run->id] = true;
            $this->recordFailure('answer_delta_circuit');
            report($exception);

            return false;
        }
    }

    private function authorizedRun(AiWorkspaceRun $run): ?AiWorkspaceRun
    {
        $fresh = $this->persistableRun($run);
        if (! $fresh instanceof AiWorkspaceRun) {
            return null;
        }
        $admin = Admin::query()->whereKey($fresh->admin_id)->where('status', 'active')->first();
        if (! $admin instanceof Admin || (int) $admin->auth_version !== (int) $fresh->admin_auth_version) {
            return null;
        }

        return $fresh;
    }

    private function persistableRun(AiWorkspaceRun $run): ?AiWorkspaceRun
    {
        $fresh = $run->fresh();
        if (! $fresh instanceof AiWorkspaceRun || $fresh->admin_id === null || (int) $fresh->admin_auth_version <= 0) {
            return null;
        }

        return $fresh;
    }

    private function recordFailure(string $type): void
    {
        try {
            $key = 'ai-workspace:realtime-failure:'.$type.':'.now()->toDateString();
            Cache::add($key, 0, now()->addDays(100));
            Cache::increment($key);
        } catch (Throwable) {
            // Realtime recovery must continue even when metrics storage is unavailable.
        }
    }
}
