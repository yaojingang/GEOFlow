<?php

namespace App\Services\AiWorkspace;

use App\Jobs\ResolveAiWorkspaceRunJob;
use App\Models\AiWorkspaceRun;
use Illuminate\Support\Facades\DB;
use LogicException;

final class AiWorkspaceStateMachine
{
    public function __construct(private readonly AiWorkspaceTraceRecorder $traces) {}

    /** @var array<string,list<string>> */
    private const TRANSITIONS = [
        'received' => ['clarifying', 'answering', 'planning', 'cancelled', 'failed', 'rejected'],
        'clarifying' => ['planning', 'answering', 'cancelled', 'failed'],
        'answering' => ['completed', 'failed', 'cancel_requested', 'cancelled'],
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

    /** @param array<string,mixed> $attributes @param array<string,mixed>|null $trace */
    public function transition(AiWorkspaceRun $run, string $state, array $attributes = [], ?array $trace = null): AiWorkspaceRun
    {
        return DB::transaction(function () use ($run, $state, $attributes, $trace): AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            $current = (string) $locked->state;
            if ($current === $state && in_array($current, AiWorkspaceRun::TERMINAL_STATES, true)) {
                return $locked->refresh();
            }
            if ($current !== $state && ! in_array($state, self::TRANSITIONS[$current] ?? [], true)) {
                throw new LogicException(sprintf('Invalid AI workspace state transition: %s -> %s', $current, $state));
            }
            $terminal = in_array($state, AiWorkspaceRun::TERMINAL_STATES, true);
            $leaseAttributes = match (true) {
                $terminal => [
                    'execution_lease_token' => null,
                    'execution_lease_expires_at' => null,
                ],
                $state === 'queued' => [
                    'execution_lease_token' => null,
                    'execution_lease_expires_at' => null,
                ],
                default => [],
            };
            $locked->forceFill($leaseAttributes + $attributes + [
                'state' => $state,
                'state_version' => (int) $locked->state_version + 1,
                'event_sequence' => (int) $locked->event_sequence + 1,
                'finished_at' => $terminal ? ($locked->finished_at ?? now()) : null,
            ])->save();
            $this->traces->recordTransition($locked, $trace);
            if ($terminal) {
                $this->releaseFollowups($locked);
                $this->notifyParent($locked);
            }

            return $locked->refresh();
        });
    }

    /** @param array<string,mixed> $attributes @param array<string,mixed>|null $trace */
    public function transitionLocked(string $runId, string $state, array $attributes = [], ?array $trace = null): AiWorkspaceRun
    {
        return DB::transaction(function () use ($runId, $state, $attributes, $trace): AiWorkspaceRun {
            $run = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($runId);

            return $this->transition($run, $state, $attributes, $trace);
        });
    }

    /**
     * Advance the ordered event stream after a step or artifact changes while
     * keeping the run in its current state.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function touchEvent(AiWorkspaceRun $run, array $attributes = [], ?array $trace = null): AiWorkspaceRun
    {
        return $this->transition($run, (string) $run->state, $attributes, $trace);
    }

    /** @param array<string,mixed> $attributes @param array<string,mixed>|null $trace */
    public function touchEventLocked(string $runId, array $attributes = [], ?array $trace = null): AiWorkspaceRun
    {
        return DB::transaction(function () use ($runId, $attributes, $trace): AiWorkspaceRun {
            $run = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($runId);

            return $this->touchEvent($run, $attributes, $trace);
        });
    }

    public function recoverResolution(AiWorkspaceRun $run): AiWorkspaceRun
    {
        if (! in_array((string) $run->state, ['received', 'planning', 'answering'], true)) {
            throw new LogicException('Only an interrupted resolution run can be recovered.');
        }

        return DB::transaction(function () use ($run): AiWorkspaceRun {
            $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array((string) $locked->state, ['received', 'planning', 'answering'], true)) {
                throw new LogicException('Only an interrupted resolution run can be recovered.');
            }
            $locked->forceFill([
                'state' => 'received',
                'state_version' => (int) $locked->state_version + 1,
                'event_sequence' => (int) $locked->event_sequence + 1,
                'resolution_lease_owner' => null,
                'resolution_lease_expires_at' => null,
                'status_message' => '请求理解执行器中断，已恢复到交互队列。',
                'failure_code' => null,
                'failure_message' => null,
                'finished_at' => null,
                'answer' => null,
                'answer_chunk_sequence' => 0,
                'answer_is_partial' => false,
                'first_token_at' => null,
            ])->save();
            $this->traces->recordTransition($locked, [
                'event_type' => 'run.queued',
                'kind' => 'queue',
                'title' => '恢复运行',
                'summary' => '执行器中断后已安全恢复到交互队列。',
                'status' => 'running',
            ]);

            return $locked->refresh();
        });
    }

    private function releaseFollowups(AiWorkspaceRun $parent): void
    {
        $children = AiWorkspaceRun::query()
            ->where('parent_run_id', $parent->id)
            ->where('state', 'received')
            ->where('mode', 'followup')
            ->whereNull('queued_at')
            ->lockForUpdate()
            ->get();
        foreach ($children as $child) {
            $child->forceFill(['queued_at' => now()])->save();
            DB::afterCommit(static fn () => ResolveAiWorkspaceRunJob::dispatch((string) $child->id)
                ->onConnection((string) config('ai-workspace.interactive_connection', config('queue.default')))
                ->onQueue((string) config('ai-workspace.interactive_queue', 'ai-workspace-interactive')));
        }
    }

    private function notifyParent(AiWorkspaceRun $child): void
    {
        if ($child->parent_run_id === null) {
            return;
        }
        $parent = AiWorkspaceRun::query()->whereKey($child->parent_run_id)->lockForUpdate()->first();
        if (! $parent instanceof AiWorkspaceRun || $parent->mode !== 'multi_agent' || $parent->isTerminal()) {
            return;
        }

        DB::afterCommit(static fn () => ResolveAiWorkspaceRunJob::dispatch((string) $parent->id)
            ->onConnection((string) config('ai-workspace.interactive_connection', config('queue.default')))
            ->onQueue((string) config('ai-workspace.interactive_queue', 'ai-workspace-interactive')));
    }
}
