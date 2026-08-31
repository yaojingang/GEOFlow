<?php

namespace Tests\PostgreSQL;

use App\Models\ArticleAiQualityRollout;
use App\Services\GeoFlow\ArticleAiQualityRolloutPolicy;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

class AiQualityDistributionRolloutConcurrencyTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_distribution_rollout_shared_leases_do_not_serialize_independent_sends(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required.');
        }

        ArticleAiQualityRollout::query()->create(['id' => 1, 'epoch' => 7]);

        $holdMicros = 1_000_000;
        $startAt = microtime(true) + 0.35;
        $children = [];
        foreach ([1, 2] as $worker) {
            $resultPath = tempnam(sys_get_temp_dir(), 'geoflow-rollout-shared-');
            $this->assertIsString($resultPath);
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork PostgreSQL rollout worker.');
            }
            if ($pid === 0) {
                try {
                    $waitMicros = (int) max(0, ($startAt - microtime(true)) * 1_000_000);
                    if ($waitMicros > 0) {
                        usleep($waitMicros);
                    }
                    DB::purge('pgsql');
                    DB::reconnect('pgsql');
                    DB::statement("SET lock_timeout TO '5s'");
                    $startedAt = microtime(true);
                    $epoch = DB::transaction(function () use ($holdMicros): int {
                        $epoch = app(ArticleAiQualityRolloutPolicy::class)->acquireDistributionLeaseEpoch();
                        usleep($holdMicros);

                        return $epoch;
                    });
                    file_put_contents($resultPath, json_encode([
                        'epoch' => $epoch,
                        'started_at' => $startedAt,
                        'finished_at' => microtime(true),
                    ], JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($resultPath, $exception::class.': '.$exception->getMessage());
                    exit(1);
                }
            }
            $children[] = ['pid' => $pid, 'path' => $resultPath, 'worker' => $worker];
        }

        DB::disconnect('pgsql');
        $results = [];
        foreach ($children as $child) {
            $status = 0;
            pcntl_waitpid($child['pid'], $status);
            $raw = (string) file_get_contents($child['path']);
            unlink($child['path']);
            $this->assertSame(0, pcntl_wexitstatus($status), $raw);
            $results[] = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        }

        $this->assertSame([7, 7], array_column($results, 'epoch'));
        $firstStart = min(array_column($results, 'started_at'));
        $lastFinish = max(array_column($results, 'finished_at'));
        $this->assertLessThan(1.75, $lastFinish - $firstStart);
    }
}
