<?php

namespace App\Services\Admin;

use App\Models\SystemUpdateRun;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SystemUpdateOperationGuard
{
    /**
     * Temporary cutover gate for rows created before the independent updater became authoritative.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(callable $callback, array $updaterStatus): mixed
    {
        if (! Schema::hasTable('system_update_runs')) {
            return $callback();
        }

        if ($this->retiredWorkerAbsent($updaterStatus)) {
            $this->activeRuns()->update([
                'status' => 'failed',
                'error_message' => 'legacy_executor_retired',
                'finished_at' => now(),
            ]);
        }

        if ($this->activeRuns()->exists()) {
            throw new RuntimeException(__('admin.system_updates.error.operation_in_progress'));
        }

        return $callback();
    }

    public function retiredWorkerAbsent(array $updaterStatus): bool
    {
        $checks = is_array($updaterStatus['checks'] ?? null) ? $updaterStatus['checks'] : [];
        foreach ($checks as $check) {
            if (is_array($check)
                && ($check['id'] ?? null) === 'retired-update-worker'
                && ($check['status'] ?? null) === 'pass') {
                return true;
            }
        }

        return false;
    }

    private function activeRuns(): Builder
    {
        return SystemUpdateRun::query()
            ->whereIn('action', ['apply', 'rollback', 'rollback_file'])
            ->whereIn('status', ['queued', 'running']);
    }
}
