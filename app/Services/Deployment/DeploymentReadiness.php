<?php

namespace App\Services\Deployment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use RuntimeException;
use Throwable;

class DeploymentReadiness
{
    /** @return array<string,array{status:string,error?:string}> */
    public function check(string $version, bool $probeStorage): array
    {
        $checks = [];
        $this->checkOne($checks, 'database', static function (): void {
            DB::connection()->select('SELECT 1');
        });
        $this->checkOne($checks, 'version_identity', static function () use ($version): void {
            if (! hash_equals($version, (string) config('geoflow.app_version'))) {
                throw new RuntimeException('version_mismatch');
            }
        });
        foreach ($this->redisConnections() as $connection) {
            $this->checkOne($checks, 'redis_'.$connection, static function () use ($connection): void {
                if (! Redis::connection($connection)->ping()) {
                    throw new RuntimeException('redis_unavailable');
                }
            });
        }
        foreach (['framework', 'app/public', 'app/private'] as $directory) {
            $this->checkOne($checks, 'storage_'.str_replace('/', '_', $directory), function () use ($directory, $probeStorage): void {
                $path = storage_path($directory);
                if (! is_dir($path) || ! is_readable($path) || ! is_writable($path)) {
                    throw new RuntimeException('storage_unavailable');
                }
                if ($probeStorage) {
                    $this->probe($path);
                }
            });
        }

        return $checks;
    }

    /** @return list<string> */
    private function redisConnections(): array
    {
        $connections = [];
        $cache = config('cache.stores.'.config('cache.default'), []);
        if (($cache['driver'] ?? null) === 'redis') {
            $connections[] = $cache['connection'] ?? 'default';
        }
        $queue = config('queue.connections.'.config('queue.default'), []);
        if (($queue['driver'] ?? null) === 'redis') {
            $connections[] = $queue['connection'] ?? 'default';
        }
        if (config('session.driver') === 'redis') {
            $connections[] = config('session.connection') ?: 'default';
        }

        return array_values(array_unique($connections));
    }

    /** @param array<string,array{status:string,error?:string}> $checks */
    private function checkOne(array &$checks, string $name, callable $check): void
    {
        try {
            $check();
            $checks[$name] = ['status' => 'pass'];
        } catch (Throwable) {
            $checks[$name] = ['status' => 'fail', 'error' => 'upgrade_readiness_failed:'.$name];
        }
    }

    private function probe(string $directory): void
    {
        $path = $directory.'/.geoflow-readiness-'.bin2hex(random_bytes(16));
        $bytes = bin2hex(random_bytes(32));
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new RuntimeException('storage_probe_failed');
        }
        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || file_get_contents($path) !== $bytes) {
                throw new RuntimeException('storage_probe_failed');
            }
        } finally {
            fclose($handle);
            if (! unlink($path)) {
                throw new RuntimeException('storage_probe_cleanup_failed');
            }
        }
    }
}
