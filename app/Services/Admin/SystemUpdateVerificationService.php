<?php

namespace App\Services\Admin;

use App\Models\SystemUpdateRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class SystemUpdateVerificationService
{
    /**
     * @return array{status: string, pass: int, warn: int, fail: int, verified_at: string, items: array<int, array{key: string, status: string, detail?: string}>}
     */
    public function verify(SystemUpdateRun $run): array
    {
        $items = [
            $this->writableItem('storage_writable', Storage::disk('local')->path('')),
            $this->writableItem('bootstrap_cache_writable', base_path('bootstrap/cache')),
            $this->routeItem('admin_dashboard_route', 'admin.dashboard'),
            $this->routeItem('system_updates_route', 'admin.system-updates.index'),
            $this->databaseItem(),
            $this->versionItem($run),
        ];

        $fail = $this->countStatus($items, 'fail');
        $warn = $this->countStatus($items, 'warn');
        $status = $fail > 0 ? 'fail' : ($warn > 0 ? 'warn' : 'pass');

        return [
            'status' => $status,
            'pass' => $this->countStatus($items, 'pass'),
            'warn' => $warn,
            'fail' => $fail,
            'verified_at' => now()->toDateTimeString(),
            'items' => $items,
        ];
    }

    /**
     * @return array{key: string, status: string, detail?: string}
     */
    private function writableItem(string $key, string $path): array
    {
        if ($path === '' || ! is_dir($path)) {
            return ['key' => $key, 'status' => 'fail', 'detail' => $path];
        }

        return [
            'key' => $key,
            'status' => is_writable($path) ? 'pass' : 'fail',
            'detail' => $path,
        ];
    }

    /**
     * @return array{key: string, status: string, detail?: string}
     */
    private function routeItem(string $key, string $routeName): array
    {
        return [
            'key' => $key,
            'status' => Route::has($routeName) ? 'pass' : 'fail',
            'detail' => $routeName,
        ];
    }

    /**
     * @return array{key: string, status: string, detail?: string}
     */
    private function databaseItem(): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'key' => 'database_available',
                'status' => Schema::hasTable('migrations') ? 'pass' : 'warn',
            ];
        } catch (\Throwable $e) {
            return [
                'key' => 'database_available',
                'status' => 'fail',
                'detail' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{key: string, status: string, detail?: string}
     */
    private function versionItem(SystemUpdateRun $run): array
    {
        $currentVersion = trim((string) config('geoflow.app_version', ''));
        $targetVersion = trim((string) $run->target_version);

        if ($targetVersion === '') {
            return ['key' => 'target_version_recorded', 'status' => 'warn'];
        }

        return [
            'key' => 'target_version_recorded',
            'status' => $currentVersion === $targetVersion ? 'pass' : 'warn',
            'detail' => $targetVersion,
        ];
    }

    /**
     * @param  array<int, array{key: string, status: string, detail?: string}>  $items
     */
    private function countStatus(array $items, string $status): int
    {
        return count(array_filter($items, static fn (array $item): bool => ($item['status'] ?? '') === $status));
    }
}
