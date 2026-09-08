<?php

namespace Tests\Feature;

use App\Services\Deployment\UpgradeStepRunner;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class UpgradeStepRunnerTest extends TestCase
{
    #[DataProvider('unsafeCachePaths')]
    public function test_cache_path_aliases_are_rejected_before_directories_or_commands_are_created(string $target, string $shape): void
    {
        $root = sys_get_temp_dir().'/geoflow-cache-path-test-'.bin2hex(random_bytes(8));
        mkdir($root.'/bootstrap/cache', 0700, true);
        mkdir($root.'/storage/framework/views', 0700, true);
        file_put_contents($root.'/storage/framework/views/sentinel.php', 'keep');
        symlink($root.'/storage/framework/views', $root.'/bootstrap/cache/shared-link');
        $originalBase = $this->app->basePath();
        $originalStorage = $this->app->storagePath();
        $this->app->setBasePath($root);
        $this->app->useStoragePath($root.'/storage');
        $environmentKey = $target === 'routes' ? 'APP_ROUTES_CACHE' : 'APP_CONFIG_CACHE';
        $originalEnvironment = [$_SERVER[$environmentKey] ?? null, $_ENV[$environmentKey] ?? null, getenv($environmentKey)];
        $runner = new class extends UpgradeStepRunner
        {
            public int $commands = 0;

            protected function createProcess(array $command, float $timeout): Process
            {
                $this->commands++;

                return new Process([PHP_BINARY, '-r', 'exit(0);'], null, null, null, $timeout);
            }
        };
        try {
            $unsafe = match ($shape) {
                'dot' => $root.'/storage/framework/views/.',
                'traversal' => $root.'/bootstrap/cache/../../storage/framework/views',
                'symlink' => $root.'/bootstrap/cache/shared-link',
            };
            config()->set('view.compiled', $target === 'views' ? $unsafe : $root.'/bootstrap/cache/new-views');
            if ($target !== 'views') {
                $_SERVER[$environmentKey] = $_ENV[$environmentKey] = $unsafe.'/cache.php';
                putenv($environmentKey.'='.$unsafe.'/cache.php');
            }
            try {
                $runner->run(['id' => 'cache', 'kind' => 'cache_warmup', 'phase' => 'apply', 'timeout_seconds' => 10, 'online' => true], [], 'online');
                $this->fail('Unsafe cache aliases must fail before invoking cache commands.');
            } catch (RuntimeException $exception) {
                $this->assertSame($target === 'views' ? 'upgrade_isolated_view_cache_required' : 'upgrade_isolated_bootstrap_cache_required', $exception->getMessage());
            }
            $this->assertSame(0, $runner->commands);
            $this->assertDirectoryDoesNotExist($root.'/bootstrap/cache/new-views');
            $this->assertSame('keep', file_get_contents($root.'/storage/framework/views/sentinel.php'));
        } finally {
            unset($_SERVER[$environmentKey], $_ENV[$environmentKey]);
            putenv($environmentKey);
            if ($originalEnvironment[0] !== null) {
                $_SERVER[$environmentKey] = $originalEnvironment[0];
            }
            if ($originalEnvironment[1] !== null) {
                $_ENV[$environmentKey] = $originalEnvironment[1];
            }
            if ($originalEnvironment[2] !== false) {
                putenv($environmentKey.'='.$originalEnvironment[2]);
            }
            $this->app->setBasePath($originalBase);
            $this->app->useStoragePath($originalStorage);
            File::deleteDirectory($root);
        }
    }

    /** @return array<string,array{string,string}> */
    public static function unsafeCachePaths(): array
    {
        return [
            'views dot alias' => ['views', 'dot'],
            'views traversal alias' => ['views', 'traversal'],
            'views symlink escape' => ['views', 'symlink'],
            'config traversal escape' => ['config', 'traversal'],
            'config symlink parent escape' => ['config', 'symlink'],
            'routes traversal escape' => ['routes', 'traversal'],
            'routes symlink parent escape' => ['routes', 'symlink'],
        ];
    }

    public function test_online_cache_warmup_accepts_the_private_bootstrap_view_directory(): void
    {
        $compiled = base_path('bootstrap/cache/test-private-views-'.bin2hex(random_bytes(8)));
        config()->set('view.compiled', $compiled);
        $runner = new class extends UpgradeStepRunner
        {
            public int $commands = 0;

            protected function createProcess(array $command, float $timeout): Process
            {
                $this->commands++;

                return new Process([PHP_BINARY, '-r', 'exit(0);'], null, null, null, $timeout);
            }
        };
        try {
            $runner->run(['id' => 'cache', 'kind' => 'cache_warmup', 'phase' => 'apply', 'timeout_seconds' => 10, 'online' => true], [], 'online');
            $this->assertSame(3, $runner->commands);
            $this->assertDirectoryExists($compiled);
            $this->assertSame(realpath($compiled), config('view.compiled'));
        } finally {
            File::deleteDirectory($compiled);
        }
    }

    public function test_failing_child_exit_is_propagated_without_exposing_output(): void
    {
        $runner = new class extends UpgradeStepRunner
        {
            protected function createProcess(array $command, float $timeout): Process
            {
                return new Process([PHP_BINARY, '-r', 'echo "secret"; exit(23);'], null, null, null, $timeout);
            }
        };
        $this->expectExceptionMessage('upgrade_step_failed:audit:exit_23');
        $runner->run(['id' => 'audit', 'kind' => 'security_audit', 'phase' => 'apply', 'timeout_seconds' => 10, 'online' => true], [], 'maintenance');
    }

    public function test_step_deadline_is_enforced_on_a_real_child_process(): void
    {
        $runner = new class extends UpgradeStepRunner
        {
            protected function createProcess(array $command, float $timeout): Process
            {
                return new Process([PHP_BINARY, '-r', 'sleep(10);'], null, null, null, $timeout);
            }
        };
        $this->expectExceptionMessage('upgrade_step_timeout:audit');
        $runner->run(['id' => 'audit', 'kind' => 'security_audit', 'phase' => 'apply', 'timeout_seconds' => 1, 'online' => true], [], 'maintenance');
    }

    public function test_online_cache_warmup_rejects_shared_views_before_any_cache_command(): void
    {
        config()->set('view.compiled', storage_path('framework/views'));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('upgrade_isolated_view_cache_required');
        app(UpgradeStepRunner::class)->run(['id' => 'cache', 'kind' => 'cache_warmup', 'phase' => 'apply', 'timeout_seconds' => 10, 'online' => true], [], 'online');
    }
}
