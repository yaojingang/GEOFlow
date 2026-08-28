<?php

namespace App\Jobs;

use App\Models\SystemUpdateRun;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessSystemUpdateRollbackJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $runId) {}

    public function handle(): void
    {
        $this->retireRun();
    }

    public function failed(): void
    {
        $this->retireRun();
    }

    private function retireRun(): void
    {
        $run = SystemUpdateRun::query()->whereKey($this->runId)->first();
        if (! $run || in_array((string) $run->status, ['succeeded', 'failed'], true)) {
            return;
        }

        $run->forceFill([
            'status' => 'failed',
            'error_message' => 'legacy_executor_retired',
            'finished_at' => now(),
        ])->save();
    }
}
