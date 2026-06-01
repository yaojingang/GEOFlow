<?php

namespace App\Services\Admin;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
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
