<?php

namespace App\Console\Commands;

use App\Jobs\ProcessAiWorkspaceRunJob;
use App\Jobs\ResolveAiWorkspaceRunJob;
use App\Models\AiWorkspaceRun;
use App\Models\AiWorkspaceStep;
use App\Services\AiWorkspace\AiWorkflowEngine;
use App\Services\AiWorkspace\AiWorkspaceRealtimeService;
use App\Services\AiWorkspace\AiWorkspaceStateMachine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class RecoverAiWorkspaceRunsCommand extends Command
{
    protected $signature = 'geoflow:recover-ai-workspace {--limit=50}';

    protected $description = 'Recover queued or leased AI workspace runs';

    public function handle(AiWorkspaceStateMachine $states, AiWorkspaceRealtimeService $realtime, AiWorkflowEngine $engine): int
    {
        $runtimeEnabled = (bool) config('ai-workspace.runtime_enabled', false);
        $limit = max(1, min(200, (int) $this->option('limit')));
        $queue = (string) config('ai-workspace.queue', 'ai-workspace');
        $interactiveQueue = (string) config('ai-workspace.interactive_queue', 'ai-workspace-interactive');
        $recovered = 0;

        if (! $runtimeEnabled) {
            $this->info('AI workspace runtime is disabled; settling active runs and reconciling external outcomes.');
            AiWorkspaceRun::query()
                ->whereNotIn('state', AiWorkspaceRun::TERMINAL_STATES)
                ->oldest('updated_at')
                ->limit($limit)
                ->get()
                ->each(function (AiWorkspaceRun $run) use ($engine, &$recovered): void {
                    if ($engine->stopForDisabledRuntime((string) $run->id) instanceof AiWorkspaceRun) {
                        $recovered++;
                    }
                });
        } else {
            AiWorkspaceRun::query()
                ->whereIn('state', ['received', 'planning', 'answering'])
                ->where(function ($query): void {
                    $query->where('resolution_lease_expires_at', '<=', now())
                        ->orWhere(function ($query): void {
                            $query->whereNull('resolution_lease_expires_at')
                                ->where('updated_at', '<', now()->subMinutes(2));
                        });
                })
                ->oldest('updated_at')
                ->limit($limit)
                ->get()
                ->each(function (AiWorkspaceRun $run) use ($states, $realtime, $interactiveQueue, &$recovered): void {
                    $received = DB::transaction(function () use ($run, $states): ?AiWorkspaceRun {
                        $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
                        if (! in_array((string) $locked->state, ['received', 'planning', 'answering'], true)) {
                            return null;
                        }
                        if ($locked->resolution_lease_expires_at?->isFuture()) {
                            return null;
                        }

                        return $states->recoverResolution($locked);
                    });
                    if ($received instanceof AiWorkspaceRun) {
                        $realtime->broadcast($received);
                        $recovered++;
                        try {
                            ResolveAiWorkspaceRunJob::dispatch((string) $received->id)->onQueue($interactiveQueue);
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    }
                });

            AiWorkspaceRun::query()
                ->where('state', 'queued')
                ->where('updated_at', '<', now()->subMinutes(2))
                ->oldest('updated_at')
                ->limit($limit)
                ->get()
                ->each(function (AiWorkspaceRun $run) use ($queue, &$recovered): void {
                    try {
                        ProcessAiWorkspaceRunJob::dispatch((string) $run->id)->onQueue($queue);
                    } catch (Throwable $exception) {
                        report($exception);
                    }
                    $recovered++;
                });

            AiWorkspaceRun::query()
                ->where('state', 'running')
                ->where('updated_at', '<=', now()->subMinutes((int) config('ai-workspace.step_lease_minutes', 20)))
                ->whereDoesntHave('steps', static fn ($query) => $query->where('state', 'running'))
                ->oldest('updated_at')
                ->limit($limit)
                ->get()
                ->each(function (AiWorkspaceRun $run) use ($states, $realtime, $queue, &$recovered): void {
                    $queued = DB::transaction(function () use ($run, $states): ?AiWorkspaceRun {
                        $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
                        if ($locked->state !== 'running' || $locked->steps()->where('state', 'running')->exists()) {
                            return null;
                        }

                        return $states->transition($locked, 'queued', ['status_message' => '执行器中断，已恢复到安全队列。']);
                    });
                    if ($queued instanceof AiWorkspaceRun) {
                        try {
                            ProcessAiWorkspaceRunJob::dispatch((string) $queued->id)->onQueue($queue);
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                        $realtime->broadcast($queued);
                        $recovered++;
                    }
                });
        }

        AiWorkspaceRun::query()
            ->where('state', 'cancel_requested')
            ->whereDoesntHave('steps', static fn ($query) => $query->where('state', 'running'))
            ->oldest('updated_at')
            ->limit($limit)
            ->get()
            ->each(function (AiWorkspaceRun $run) use ($states, $realtime, &$recovered): void {
                $cancelled = DB::transaction(function () use ($run, $states): ?AiWorkspaceRun {
                    $locked = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
                    if ($locked->state !== 'cancel_requested' || $locked->steps()->where('state', 'running')->exists()) {
                        return null;
                    }
                    $locked->steps()->whereIn('state', ['pending', 'failed'])->update([
                        'state' => 'skipped',
                        'error_message' => '运行已取消，步骤未执行。',
                        'finished_at' => now(),
                        'updated_at' => now(),
                    ]);
                    $terminal = $locked->steps()->where('state', 'completed')->exists()
                        ? 'partially_completed'
                        : 'cancelled';

                    return $states->transition($locked, $terminal, ['status_message' => '运行已按取消请求停止。']);
                });
                if ($cancelled instanceof AiWorkspaceRun) {
                    $realtime->broadcast($cancelled);
                    $recovered++;
                }
            });

        AiWorkspaceStep::query()
            ->with('run')
            ->where('state', 'running')
            ->where('lease_expires_at', '<=', now())
            ->oldest('lease_expires_at')
            ->limit($limit)
            ->get()
            ->each(function (AiWorkspaceStep $step) use ($states, $realtime, $engine, $queue, $runtimeEnabled, &$recovered): void {
                $run = $step->run;
                if (! $run instanceof AiWorkspaceRun || ! in_array((string) $run->state, ['running', 'cancel_requested'], true)) {
                    return;
                }
                if (! $runtimeEnabled && $run->state === 'running') {
                    $stopped = $engine->stopForDisabledRuntime((string) $run->id);
                    if (! $stopped instanceof AiWorkspaceRun) {
                        return;
                    }
                    $run = $stopped;
                }
                if ($step->external_operation) {
                    try {
                        $reconciled = $engine->reconcileExpiredExternalStep($step);
                    } catch (Throwable $exception) {
                        report($exception);
                        $reconciled = null;
                    }
                    if ($reconciled instanceof AiWorkspaceRun) {
                        $realtime->broadcast($reconciled);
                        $recovered++;

                        return;
                    }
                    try {
                        $requeued = $engine->recoverPreparedExternalStep($step);
                    } catch (Throwable $exception) {
                        report($exception);
                        $requeued = null;
                    }
                    if ($requeued instanceof AiWorkspaceRun) {
                        $realtime->broadcast($requeued);
                        $recovered++;

                        return;
                    }
                }

                $updatedRun = DB::transaction(function () use ($step, $run, $states, $queue, $runtimeEnabled, &$recovered): ?AiWorkspaceRun {
                    $lockedStep = AiWorkspaceStep::query()->lockForUpdate()->findOrFail($step->id);
                    $lockedRun = AiWorkspaceRun::query()->lockForUpdate()->findOrFail($run->id);
                    if ($lockedStep->state !== 'running'
                        || ! in_array((string) $lockedRun->state, ['running', 'cancel_requested'], true)
                        || $lockedStep->lease_expires_at?->isFuture()) {
                        return null;
                    }
                    if ($lockedStep->external_operation) {
                        $lockedStep->forceFill([
                            'state' => 'outcome_unknown',
                            'lease_owner' => null,
                            'lease_expires_at' => null,
                            'finished_at' => now(),
                            'error_message' => '执行器租约过期，远程结果需要人工对账。',
                        ])->save();
                        $recovered++;

                        return $states->transition($lockedRun, 'outcome_unknown', [
                            'failure_code' => 'outcome_unknown',
                            'failure_message' => '外部步骤租约过期，远程结果需要人工对账。',
                            'status_message' => '外部结果无法确认，已停止自动重试。',
                        ]);
                    }
                    if ($lockedRun->state === 'cancel_requested' || ! $runtimeEnabled) {
                        $lockedStep->forceFill([
                            'state' => 'skipped',
                            'error_message' => '运行已取消，内部步骤已停止。',
                            'lease_owner' => null,
                            'lease_expires_at' => null,
                            'finished_at' => now(),
                        ])->save();
                        $terminal = $lockedRun->steps()->where('state', 'completed')->exists()
                            ? 'partially_completed'
                            : 'cancelled';
                        $recovered++;

                        return $states->transition($lockedRun, $terminal, ['status_message' => '运行已取消，内部步骤已安全停止。']);
                    }
                    $lockedStep->forceFill(['state' => 'pending', 'lease_owner' => null, 'lease_expires_at' => null])->save();
                    $queued = $states->transition($lockedRun, 'queued', ['status_message' => '内部步骤租约已恢复，等待重新执行。']);
                    DB::afterCommit(function () use ($queued, $queue): void {
                        try {
                            ProcessAiWorkspaceRunJob::dispatch((string) $queued->id)->onQueue($queue);
                        } catch (Throwable $exception) {
                            report($exception);
                        }
                    });
                    $recovered++;

                    return $queued;
                });
                if ($updatedRun instanceof AiWorkspaceRun) {
                    $realtime->broadcast($updatedRun);
                }
            });

        $this->info(sprintf('Recovered AI workspace runs: %d', $recovered));

        return self::SUCCESS;
    }
}
