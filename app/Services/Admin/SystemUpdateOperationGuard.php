<?php

namespace App\Services\Admin;

use App\Contracts\SystemUpdater\AgentClient;
use App\Models\SystemUpdateRun;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class SystemUpdateOperationGuard
{
    private const LOCK_NAME = 'geoflow:system-update:operation';

    public function __construct(private readonly AgentClient $agentClient) {}

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

    public function assertNoUpdaterExecution(): void
    {
        try {
            $operation = $this->agentClient->currentOperation();
        } catch (Throwable $exception) {
            if ($this->installedUpdaterSocketExists()) {
                throw new RuntimeException(__('admin.system_updates.error.operation_in_progress'), 0, $exception);
            }

            return;
        }

        if (is_array($operation) && in_array($operation['status'] ?? null, ['queued', 'running', 'recovery_required'], true)) {
            throw new RuntimeException(__('admin.system_updates.error.operation_in_progress'));
        }
    }

    private function installedUpdaterSocketExists(): bool
    {
        $socketPath = (string) config('geoflow.updater_socket');
        $stat = $socketPath !== '' ? @lstat($socketPath) : false;

        return is_array($stat) && (($stat['mode'] ?? 0) & 0170000) === 0140000;
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
