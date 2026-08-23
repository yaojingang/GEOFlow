<?php

namespace App\Services\AiWorkspace;

use App\Models\AiWorkspaceRun;
use Illuminate\Support\Facades\DB;
use LogicException;

final class AiWorkspaceStateMachine
{
    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'received' => ['clarifying', 'answering', 'planning', 'cancelled', 'failed', 'rejected'],
        'clarifying' => ['planning', 'answering', 'cancelled', 'failed'],
        'answering' => ['completed', 'failed', 'cancelled'],
        'planning' => ['validating_plan', 'clarifying', 'failed', 'cancelled', 'rejected'],
        'validating_plan' => ['awaiting_approval', 'queued', 'clarifying', 'failed', 'cancelled', 'rejected'],
        'awaiting_approval' => ['queued', 'planning', 'rejected', 'cancelled'],
        'awaiting_step_approval' => ['queued', 'planning', 'rejected', 'cancelled'],
        'queued' => ['running', 'cancel_requested', 'cancelled', 'partially_completed', 'failed'],
        'running' => ['queued', 'completed', 'partially_completed', 'failed', 'cancel_requested', 'cancelled', 'outcome_unknown', 'awaiting_step_approval'],
        'cancel_requested' => ['completed', 'partially_completed', 'cancelled', 'failed', 'outcome_unknown'],
        'failed' => ['queued', 'planning'],
        'partially_completed' => ['queued'],
    ];

    /** @param array<string,mixed> $attributes */
    public function transition(AiWorkspaceRun $run, string $state, array $attributes = []): AiWorkspaceRun
    {
        $current = (string) $run->state;
        if ($current !== $state && ! in_array($state, self::TRANSITIONS[$current] ?? [], true)) {
            throw new LogicException(sprintf('Invalid AI workspace state transition: %s -> %s', $current, $state));
        }

        $terminal = in_array($state, AiWorkspaceRun::TERMINAL_STATES, true);
        $run->forceFill($attributes + [
            'state' => $state,
            'state_version' => (int) $run->state_version + 1,
            'event_sequence' => (int) $run->event_sequence + 1,
            'finished_at' => $terminal ? ($run->finished_at ?? now()) : null,
        ])->save();

        return $run->refresh();
    }

    /** @param array<string,mixed> $attributes */
    public function transitionLocked(string $runId, string $state, array $attributes = []): AiWorkspaceRun
    {
        return DB::transaction(function () use ($runId, $state, $attributes): AiWorkspaceRun {
            $run = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($runId);

            return $this->transition($run, $state, $attributes);
        });
    }

    /**
     * Advance the ordered event stream after a step or artifact changes while
     * keeping the run in its current state.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function touchEvent(AiWorkspaceRun $run, array $attributes = []): AiWorkspaceRun
    {
        return $this->transition($run, (string) $run->state, $attributes);
    }

    /** @param array<string,mixed> $attributes */
    public function touchEventLocked(string $runId, array $attributes = []): AiWorkspaceRun
    {
        return DB::transaction(function () use ($runId, $attributes): AiWorkspaceRun {
            $run = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($runId);

            return $this->touchEvent($run, $attributes);
        });
    }

    public function recoverResolution(AiWorkspaceRun $run): AiWorkspaceRun
    {
        if (! in_array((string) $run->state, ['received', 'planning', 'answering'], true)) {
            throw new LogicException('Only an interrupted resolution run can be recovered.');
        }

        $run->forceFill([
            'state' => 'received',
            'state_version' => (int) $run->state_version + 1,
            'event_sequence' => (int) $run->event_sequence + 1,
            'resolution_lease_owner' => null,
            'resolution_lease_expires_at' => null,
            'status_message' => '请求理解执行器中断，已恢复到交互队列。',
            'failure_code' => null,
            'failure_message' => null,
            'finished_at' => null,
        ])->save();

        return $run->refresh();
    }
}
