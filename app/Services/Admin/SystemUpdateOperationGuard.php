<?php

namespace App\Services\Admin;

use App\Models\SystemUpdateRun;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SystemUpdateOperationGuard
{
    private const LOCK_NAME = 'geoflow:system-update:operation';

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $lock = $this->acquireLock();

        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    public function assertNoActiveExecution(?SystemUpdateRun $except = null): void
    {
        if (! Schema::hasTable('system_update_runs')) {
            return;
        }

        $query = SystemUpdateRun::query()
            ->whereIn('action', ['apply', 'rollback', 'rollback_file'])
            ->whereIn('status', ['queued', 'running']);

        if ($except) {
            $query->where($except->getKeyName(), '!=', $except->getKey());
        }

        if ($query->exists()) {
            throw new RuntimeException(__('admin.system_updates.error.operation_in_progress'));
        }
    }

    private function acquireLock(): Lock
    {
        $ttl = max(30, (int) config('geoflow.update_lock_ttl_seconds', 900));
        $lock = Cache::lock(self::LOCK_NAME, $ttl);

        if (! $lock->get()) {
            throw new RuntimeException(__('admin.system_updates.error.operation_in_progress'));
        }

        return $lock;
    }
}
