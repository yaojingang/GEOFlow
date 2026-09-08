<?php

namespace App\Services\Deployment;

use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class UpgradeStepRunner
{
    /**
     * @param  array{id:string,kind:string,phase:string,timeout_seconds:int,online:bool}  $step
     * @param  list<array{name:string,sha256:string,online:bool}>  $pending
     */
    public function run(array $step, array $pending, string $strategy): void
    {
        $commands = match ($step['kind']) {
            'migrate' => $pending === [] ? [] : [[
                'migrate', '--force', '--realpath',
                ...array_map(static fn (array $migration): string => '--path='.database_path('migrations/'.$migration['name'].'.php'), $pending),
            ]],
            'retrieval_backfill' => [
                ['geoflow:backfill-ai-quality-retrieval', '--json'],
                ['geoflow:backfill-ai-quality-retrieval', '--verify', '--json'],
            ],
            'managed_images' => [['geoflow:managed-images:readiness', '--json']],
            'security_audit' => [['geoflow:security-audit', '--json']],
            'system_knowledge' => [['geoflow:sync-system-knowledge', '--media']],
            'cache_warmup' => $this->cacheCommands($strategy),
            default => throw new RuntimeException('upgrade_step_kind_invalid'),
        };
        $deadline = microtime(true) + $step['timeout_seconds'];
        foreach ($commands as $command) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                throw new RuntimeException('upgrade_step_timeout:'.$step['id']);
            }
            $process = $this->createProcess($command, $remaining);
            $process->disableOutput();
            try {
                $exit = $process->run();
            } catch (ProcessTimedOutException) {
                throw new RuntimeException('upgrade_step_timeout:'.$step['id']);
            }
            if ($exit !== 0) {
                throw new RuntimeException('upgrade_step_failed:'.$step['id'].':exit_'.$exit);
            }
        }
    }

    /** @param list<string> $command */
    protected function createProcess(array $command, float $timeout): Process
    {
        return new Process([PHP_BINARY, base_path('artisan'), ...$command, '--no-interaction', '--no-ansi'], base_path(), [
            'VIEW_COMPILED_PATH' => (string) config('view.compiled'),
        ], null, $timeout);
    }

    /** @return array<string,array<string,mixed>> */
    public function verifyData(): array
    {
        $checks = [];
        foreach ([
            'retrieval_backfill' => ['geoflow:backfill-ai-quality-retrieval', ['--verify' => true, '--json' => true]],
            'managed_images' => ['geoflow:managed-images:readiness', ['--dry-run' => true, '--json' => true]],
            'security_audit' => ['geoflow:security-audit', ['--json' => true]],
            'system_knowledge' => ['geoflow:sync-system-knowledge', ['--verify' => true, '--json' => true]],
        ] as $name => [$command, $arguments]) {
            try {
                $exit = Artisan::call($command, [...$arguments, '--no-interaction' => true]);
                $report = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
                $checks[$name] = ['status' => $exit === 0 ? 'pass' : 'fail', 'report' => $report];
            } catch (\Throwable) {
                $checks[$name] = ['status' => 'fail', 'error' => 'upgrade_data_verification_failed:'.$name];
            }
        }

        return $checks;
    }

    /** @return list<list<string>> */
    private function cacheCommands(string $strategy): array
    {
        $cacheRoot = $this->canonicalPath(base_path('bootstrap/cache'), 'upgrade_isolated_bootstrap_cache_required');
        $baseRoot = realpath(base_path());
        if ($baseRoot === false || $cacheRoot !== $baseRoot.'/bootstrap/cache') {
            throw new RuntimeException('upgrade_isolated_bootstrap_cache_required');
        }
        $compiled = $this->canonicalPath((string) config('view.compiled'), 'upgrade_isolated_view_cache_required');
        if ($strategy === 'online' && ! str_starts_with($compiled, $cacheRoot.'/')) {
            throw new RuntimeException('upgrade_isolated_view_cache_required');
        }
        foreach ([app()->getCachedConfigPath(), app()->getCachedRoutesPath()] as $cachePath) {
            $canonical = $this->canonicalPath($cachePath, 'upgrade_isolated_bootstrap_cache_required');
            if (! str_starts_with($canonical, $cacheRoot.'/') || is_dir($canonical)) {
                throw new RuntimeException('upgrade_isolated_bootstrap_cache_required');
            }
        }
        if (! is_dir($compiled) && ! mkdir($compiled, 0755, true) && ! is_dir($compiled)) {
            throw new RuntimeException('upgrade_view_cache_directory_failed');
        }
        config()->set('view.compiled', $compiled);

        return [['config:cache'], ['route:cache'], ['view:cache']];
    }

    private function canonicalPath(string $path, string $error): string
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')
            || array_intersect(explode('/', $path), ['.', '..']) !== []) {
            throw new RuntimeException($error);
        }
        $existing = rtrim($path, '/');
        $suffix = [];
        while (($resolved = realpath($existing)) === false) {
            if (is_link($existing) || file_exists($existing) || dirname($existing) === $existing) {
                throw new RuntimeException($error);
            }
            array_unshift($suffix, basename($existing));
            $existing = dirname($existing);
        }
        if ($suffix !== [] && ! is_dir($resolved)) {
            throw new RuntimeException($error);
        }

        return rtrim($resolved, '/').($suffix === [] ? '' : '/'.implode('/', $suffix));
    }
}
