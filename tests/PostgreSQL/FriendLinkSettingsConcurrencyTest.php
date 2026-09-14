<?php

namespace Tests\PostgreSQL;

use App\Models\SiteSetting;
use App\Support\Site\FriendLinkSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class FriendLinkSettingsConcurrencyTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    #[DataProvider('initialStates')]
    public function test_only_one_writer_can_save_the_same_snapshot(?string $initial, bool $exists): void
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'pcntl is required to run the concurrency acceptance test.');
        if ($exists) {
            SiteSetting::query()->create(['setting_key' => 'friend_links', 'setting_value' => $initial]);
        }
        $settings = app(FriendLinkSettings::class);
        $revision = $settings->snapshot()['revision'];
        $directory = sys_get_temp_dir().'/friend-links-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect('pgsql');
        try {
            foreach (['First writer', 'Second writer'] as $index => $name) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork test worker.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge('pgsql');
                        DB::reconnect('pgsql');
                        // Both reads must complete before either write. Removing the CAS
                        // condition or first-insert conflict handling makes this test fail.
                        DB::listen(function ($query) use ($directory, $index): void {
                            if (! str_starts_with($query->sql, 'select') || ! in_array('friend_links', $query->bindings, true)) {
                                return;
                            }
                            file_put_contents($directory.'/ready-'.$index, 'ready');
                            $deadline = microtime(true) + 10;
                            while (! is_file($directory.'/ready-'.(1 - $index))) {
                                if (microtime(true) >= $deadline) {
                                    throw new RuntimeException('Concurrent read barrier timed out.');
                                }
                                usleep(1000);
                            }
                        });
                        $payload = $settings->validateInput([
                            'enabled' => true, 'link_count' => 1, 'expected_revision' => $revision, 'replace_invalid' => true,
                            'links' => [['name' => $name, 'url' => 'https://example.com', 'sort_order' => 0, 'enabled' => true, 'target' => '_blank', 'relationship' => 'regular']],
                        ]);
                        try {
                            $settings->save($payload);
                            $result = ['result' => 'saved', 'name' => $name];
                        } catch (ValidationException $exception) {
                            $result = ['result' => array_key_exists('friend_links.expected_revision', $exception->errors()) ? 'conflict' : 'unexpected-validation'];
                        }
                        file_put_contents($directory.'/result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                        DB::disconnect('pgsql');
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($directory.'/result-'.$index, json_encode(['error' => $exception::class, 'message' => $exception->getMessage()]));
                        exit(1);
                    }
                }
                $children[$index] = $pid;
            }
            $results = [];
            foreach ($children as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $result = file_get_contents($directory.'/result-'.$index);
                $this->assertSame(0, pcntl_wexitstatus($status), $result);
                $results[] = json_decode($result, true, 512, JSON_THROW_ON_ERROR);
            }
            $outcomes = array_column($results, 'result');
            sort($outcomes);
            $this->assertSame(['conflict', 'saved'], $outcomes);
            DB::purge('pgsql');
            DB::reconnect('pgsql');
            $this->assertSame(1, SiteSetting::query()->where('setting_key', 'friend_links')->count());
            $winner = collect($results)->firstWhere('result', 'saved');
            $this->assertSame($winner['name'], $settings->snapshot()['config']['links'][0]['name']);
            // PostgreSQL counts matched rows even when the stored content is unchanged.
            $same = $settings->validateInput($settings->snapshot()['config'] + ['link_count' => 1, 'expected_revision' => $settings->snapshot()['revision']]);
            $settings->save($same);
            $this->assertSame($winner['name'], $settings->visibleLinks()[0]['name']);
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            DB::purge('pgsql');
            DB::reconnect('pgsql');
        }
    }

    public static function initialStates(): array
    {
        return [
            'first creation' => [null, false],
            'existing config' => ['{"enabled":true,"links":[]}', true],
            'null recovery' => [null, true],
        ];
    }
}
