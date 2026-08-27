<?php

namespace App\Services\Admin;

use App\Models\SystemUpdateRun;
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
    public function run(callable $callback): mixed
    {
        if (Schema::hasTable('system_update_runs')
            && SystemUpdateRun::query()
                ->whereIn('action', ['apply', 'rollback', 'rollback_file'])
                ->whereIn('status', ['queued', 'running'])
                ->exists()) {
            throw new RuntimeException(__('admin.system_updates.error.operation_in_progress'));
        }

        return $callback();
    }
}
