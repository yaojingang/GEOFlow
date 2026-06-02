<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\SystemUpdateRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class SystemUpdateRunHealthService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $driver = (string) config('queue.default', 'sync');
        $connectionDriver = (string) config("queue.connections.{$driver}.driver", $driver);
        $staleRuns = $this->staleRuns();
        $activeRunCount = $this->activeRunCount();
        $items = [];

        $items[] = [
            'key' => 'queue_driver',
            'status' => $driver === 'sync' ? 'warn' : 'pass',
            'message_key' => $driver === 'sync' ? 'queue_driver_sync' : 'queue_driver_async',
            'context' => ['driver' => $driver],
        ];

        if ($connectionDriver === 'database') {
            $jobsTableReady = Schema::hasTable('jobs');
            $items[] = [
                'key' => 'jobs_table',
                'status' => $jobsTableReady ? 'pass' : 'fail',
                'message_key' => $jobsTableReady ? 'jobs_table_ready' : 'jobs_table_missing',
                'context' => [],
            ];
        }

        $items[] = [
            'key' => 'active_runs',
            'status' => $activeRunCount > 0 ? 'warn' : 'pass',
            'message_key' => $activeRunCount > 0 ? 'active_runs_found' : 'active_runs_clear',
            'context' => ['count' => $activeRunCount],
        ];

        $items[] = [
            'key' => 'stale_runs',
            'status' => $staleRuns->isNotEmpty() ? 'fail' : 'pass',
            'message_key' => $staleRuns->isNotEmpty() ? 'stale_runs_found' : 'stale_runs_clear',
            'context' => [
                'count' => $staleRuns->count(),
                'minutes' => $this->staleAfterMinutes(),
            ],
        ];

        $overallStatus = collect($items)->contains(fn (array $item): bool => $item['status'] === 'fail')
            ? 'fail'
            : (collect($items)->contains(fn (array $item): bool => $item['status'] === 'warn') ? 'warn' : 'pass');

        return [
            'status' => $overallStatus,
            'driver' => $driver,
            'connection_driver' => $connectionDriver,
            'stale_after_minutes' => $this->staleAfterMinutes(),
            'active_run_count' => $activeRunCount,
            'stale_run_count' => $staleRuns->count(),
            'pending_job_count' => $this->tableCount('jobs'),
            'failed_job_count' => $this->tableCount('failed_jobs'),
            'stale_runs' => $staleRuns,
            'items' => $items,
        ];
    }

    public function isStale(SystemUpdateRun $run): bool
    {
        if (! in_array((string) $run->status, ['queued', 'running'], true)) {
            return false;
        }

        $threshold = now()->subMinutes($this->staleAfterMinutes());
        $reference = (string) $run->status === 'running'
            ? ($run->started_at ?: $run->updated_at)
            : ($run->created_at ?: $run->updated_at);

        return $reference instanceof Carbon && $reference->lessThan($threshold);
    }

    public function canRetry(SystemUpdateRun $run): bool
    {
        return in_array((string) $run->action, ['apply', 'rollback', 'rollback_file'], true)
            && ((string) $run->status === 'failed' || $this->isStale($run));
    }

    public function canMarkFailed(SystemUpdateRun $run): bool
    {
        return in_array((string) $run->status, ['queued', 'running'], true) && $this->isStale($run);
    }

    public function markStaleRunFailed(SystemUpdateRun $run, Admin $admin): SystemUpdateRun
    {
        $run->refresh();
        if (! $this->canMarkFailed($run)) {
            throw new RuntimeException(__('admin.system_updates.error.run_not_stale'));
        }

        $payload = is_array($run->plan_json) ? $run->plan_json : [];
        $payload['progress'] = array_merge(is_array($payload['progress'] ?? null) ? $payload['progress'] : [], [[
            'key' => 'failed',
            'percent' => 100,
            'status' => 'failed',
            'at' => now()->toDateTimeString(),
        ]]);
        $payload['progress_percent'] = 100;
        $payload['progress_status'] = 'failed';
        $payload['recovery'] = array_merge(is_array($payload['recovery'] ?? null) ? $payload['recovery'] : [], [[
            'action' => 'mark_failed',
            'admin_id' => (int) $admin->id,
            'admin_name' => (string) ($admin->display_name ?: $admin->username),
            'at' => now()->toDateTimeString(),
        ]]);

        $run->forceFill([
            'status' => 'failed',
            'plan_json' => $payload,
            'error_message' => __('admin.system_updates.error.run_marked_failed_stale'),
            'finished_at' => now(),
        ])->save();

        return $run;
    }

    private function staleAfterMinutes(): int
    {
        return max(1, (int) config('geoflow.update_run_stale_minutes', 15));
    }

    private function activeRunCount(): int
    {
        if (! Schema::hasTable('system_update_runs')) {
            return 0;
        }

        return SystemUpdateRun::query()
            ->whereIn('action', ['apply', 'rollback', 'rollback_file'])
            ->whereIn('status', ['queued', 'running'])
            ->count();
    }

    /**
     * @return \Illuminate\Support\Collection<int, SystemUpdateRun>
     */
    private function staleRuns()
    {
        if (! Schema::hasTable('system_update_runs')) {
            return collect();
        }

        $threshold = now()->subMinutes($this->staleAfterMinutes());

        return SystemUpdateRun::query()
            ->with('startedBy')
            ->whereIn('action', ['apply', 'rollback', 'rollback_file'])
            ->where(function ($query) use ($threshold): void {
                $query->where(function ($query) use ($threshold): void {
                    $query->where('status', 'queued')
                        ->where('created_at', '<', $threshold);
                })->orWhere(function ($query) use ($threshold): void {
                    $query->where('status', 'running')
                        ->where(function ($query) use ($threshold): void {
                            $query->where('started_at', '<', $threshold)
                                ->orWhere(function ($query) use ($threshold): void {
                                    $query->whereNull('started_at')
                                        ->where('updated_at', '<', $threshold);
                                });
                        });
                });
            })
            ->latest('id')
            ->limit(5)
            ->get();
    }

    private function tableCount(string $table): ?int
    {
        if (! Schema::hasTable($table)) {
            return null;
        }

        return (int) DB::table($table)->count();
    }
}
