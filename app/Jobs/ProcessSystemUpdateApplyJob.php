<?php

namespace App\Jobs;

use App\Models\SystemUpdateRun;
use App\Services\Admin\SystemUpdateApplyService;
use App\Services\Admin\SystemUpdateOperationGuard;
use App\Services\Admin\SystemUpdateRunProgressService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessSystemUpdateApplyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $runId) {}

    public function handle(SystemUpdateApplyService $applyService, SystemUpdateOperationGuard $operationGuard): void
    {
        $run = SystemUpdateRun::query()->whereKey($this->runId)->first();
        if (! $run) {
            return;
        }

        $operationGuard->run(fn () => $applyService->executeQueued($run));
    }

    public function failed(?Throwable $exception = null): void
    {
        $run = SystemUpdateRun::query()->whereKey($this->runId)->first();
        if (! $run || in_array((string) $run->status, ['succeeded', 'failed'], true)) {
            return;
        }

        try {
            app(SystemUpdateRunProgressService::class)->record($run, 'failed', 100, 'failed');
        } catch (Throwable) {
            // Keep the failure callback resilient; the run status is the source of truth.
        }

        $run->forceFill([
            'status' => 'failed',
            'error_message' => $exception?->getMessage() ?: __('admin.system_updates.error.job_failed'),
            'finished_at' => now(),
        ])->save();
    }
}
