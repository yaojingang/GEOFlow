<?php

namespace Tests\PostgreSQL;

use App\Models\SiteSetting;
use App\Services\AiWorkspace\AiWorkspaceRuntimeStatus;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AiWorkspaceRuntimeSettingsConcurrencyTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_concurrent_first_writes_keep_one_valid_runtime_preference(): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'pcntl is required to run the concurrency acceptance test.');
        $directory = sys_get_temp_dir().'/ai-workspace-runtime-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect('pgsql');

        try {
            foreach ([true, false] as $index => $enabled) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork test worker.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge('pgsql');
                        DB::reconnect('pgsql');
                        file_put_contents($directory.'/ready-'.$index, 'ready', LOCK_EX);
                        $otherWorker = $directory.'/ready-'.(1 - $index);
                        $deadline = microtime(true) + 10;
                        while (! is_file($otherWorker)) {
                            clearstatcache(true, $otherWorker);
                            if (microtime(true) >= $deadline) {
                                throw new RuntimeException('Concurrent write barrier timed out.');
                            }
                            usleep(1000);
                        }

                        app(AiWorkspaceRuntimeStatus::class)->save($enabled);
                        file_put_contents($directory.'/result-'.$index, 'saved');
                        DB::disconnect('pgsql');
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($directory.'/result-'.$index, $exception::class.': '.$exception->getMessage());
                        exit(1);
                    }
                }
                $children[$index] = $pid;
            }

            foreach ($children as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $result = (string) file_get_contents($directory.'/result-'.$index);
                $this->assertSame(0, pcntl_wexitstatus($status), $result);
                $this->assertSame('saved', $result);
            }

            DB::purge('pgsql');
            DB::reconnect('pgsql');
            $this->assertSame(1, SiteSetting::query()->where('setting_key', AiWorkspaceRuntimeStatus::SETTING_KEY)->count());
            $this->assertContains(
                SiteSetting::query()->where('setting_key', AiWorkspaceRuntimeStatus::SETTING_KEY)->value('setting_value'),
                ['0', '1'],
            );
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            foreach ((array) glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            DB::purge('pgsql');
            DB::reconnect('pgsql');
        }
    }
}
