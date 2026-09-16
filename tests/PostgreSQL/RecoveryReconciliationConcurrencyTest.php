<?php

namespace Tests\PostgreSQL;

use App\Models\Admin;
use App\Services\SystemUpdater\RecoveryPreparation;
use App\Services\SystemUpdater\RecoveryReconciliation;
use App\Services\SystemUpdater\RecoveryState;
use Closure;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class RecoveryReconciliationConcurrencyTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/geoflow-reconciliation-pg-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['geoflow.recovery_contract_required' => true, 'geoflow.recovery_control_directory' => $this->directory, 'hashing.bcrypt.rounds' => 4]);
        $this->state('validating');
    }

    protected function tearDown(): void
    {
        config(['geoflow.recovery_contract_required' => false]);
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_concurrent_empty_proofs_and_activation_allow_each_new_job_once(): void
    {
        $this->prepare();
        DB::statement('ALTER TABLE recovery_reconciliations ALTER COLUMN report TYPE jsonb USING report::jsonb');
        $results = $this->concurrently(fn (int $index): array => app(RecoveryReconciliation::class)->proveEmpty('recovery-pg-0001', '20260916T120000Z-1234abcd', str_repeat('c', 64)));
        $this->assertSame(['pass', 'pass'], array_column($results, 'result'));
        $this->assertEquals($results[0]['value'], $results[1]['value']);
        $this->assertSame(1, DB::table('recovery_reconciliations')->count());
        $this->assertNull(DB::table('recovery_reconciliations')->value('activated_at'));

        // The host transition is simulated; Core never changes the host file.
        $this->state('ready');
        $results = $this->concurrently(function (int $index): array {
            app(RecoveryState::class)->assertBackgroundReady();
            $this->newJob('fresh-'.$index);
            app(RecoveryState::class)->assertBackgroundReady();

            return ['new_job_created' => true];
        });
        $this->assertSame(['pass', 'pass'], array_column($results, 'result'));
        $this->assertSame(2, DB::table('jobs')->count());
        $this->assertSame(1, DB::table('recovery_reconciliations')->whereNotNull('activated_at')->count());
    }

    public function test_competing_proofs_cannot_bind_two_different_recovery_points(): void
    {
        $this->prepare();
        $results = $this->concurrently(fn (int $index): array => app(RecoveryReconciliation::class)->proveEmpty('recovery-pg-0001', $index === 0 ? '20260916T120000Z-1234abcd' : '20260916T120001Z-5678abcd', str_repeat('c', 64)));
        $this->assertCount(1, array_filter($results, fn (array $result): bool => $result['result'] === 'pass'));
        $errors = array_values(array_filter($results, fn (array $result): bool => $result['result'] === 'rejected'));
        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('recovery_', $errors[0]['error']);
        $this->assertSame(1, DB::table('recovery_reconciliations')->count());
    }

    public function test_concurrent_identical_decisions_keep_one_audit_record_with_jsonb_reports(): void
    {
        $this->newJob('preserved-source');
        $this->prepare();
        DB::statement('ALTER TABLE recovery_reconciliation_decisions ALTER COLUMN report TYPE jsonb USING report::jsonb');
        $entry = DB::table('recovery_quarantines')->first();
        $decision = [
            'schema_version' => 1, 'decision_id' => 'd2c93f1d-1cbc-4160-9807-9b92ad18e953',
            'source_table' => 'jobs', 'source_id' => (string) $entry->source_id, 'source_sha256' => $entry->source_sha256,
            'disposition' => 'hold', 'evidence_sha256' => str_repeat('d', 64), 'reviewer_sha256' => str_repeat('e', 64),
            'source_recovery_point_id' => '20260916T120000Z-1234abcd', 'source_recovery_point_sha256' => str_repeat('c', 64),
        ];
        $results = $this->concurrently(fn (int $index): array => app(RecoveryReconciliation::class)->record('recovery-pg-0001', $index === 0 ? $decision : array_reverse($decision, true)));
        $this->assertSame(['pass', 'pass'], array_column($results, 'result'));
        $this->assertEquals($results[0]['value'], $results[1]['value']);
        $this->assertSame(1, DB::table('recovery_reconciliation_decisions')->count());
        $this->assertSame(1, DB::table('jobs')->count());
        $this->assertSame('{}', DB::table('jobs')->value('payload'));
        $this->assertSame('held', DB::table('recovery_quarantines')->value('status'));
    }

    private function prepare(): void
    {
        Admin::query()->create(['username' => 'recovery-pg-admin', 'password' => 'temporary-test-password', 'role' => 'super_admin', 'status' => 'active']);
        $service = app(RecoveryPreparation::class);
        $service->prepare('recovery-pg-0001', $service->inspect()['admin_digest']);
        $this->state('http_ready');
    }

    private function newJob(string $queue): void
    {
        DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => 1, 'created_at' => 1]);
    }

    private function state(string $phase): void
    {
        file_put_contents($this->directory.'/state.json', json_encode([
            'schema_version' => 1, 'instance_id' => 'primary', 'host_id' => str_repeat('b', 32), 'epoch' => str_repeat('a', 32),
            'transaction_id' => 'recovery-pg-0001', 'phase' => $phase, 'minimum_updater_protocol' => 5,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<array{result: string, value?: array, error?: string}> */
    private function concurrently(Closure $action): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'pcntl is required for the recovery concurrency gate.');
        $run = bin2hex(random_bytes(4));
        $children = [];
        DB::disconnect('pgsql');
        try {
            foreach ([0, 1] as $index) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork recovery test worker.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge('pgsql');
                        DB::reconnect('pgsql');
                        DB::statement("SET statement_timeout TO '10s'");
                        file_put_contents($this->directory.'/'.$run.'-ready-'.$index, 'ready');
                        $deadline = microtime(true) + 10;
                        while (! is_file($this->directory.'/'.$run.'-ready-'.(1 - $index))) {
                            if (microtime(true) >= $deadline) {
                                throw new RuntimeException('Recovery test barrier timed out.');
                            }
                            usleep(1000);
                        }
                        $result = ['result' => 'pass', 'value' => $action($index)];
                    } catch (Throwable $exception) {
                        $result = ['result' => 'rejected', 'error' => $exception->getMessage()];
                    }
                    file_put_contents($this->directory.'/'.$run.'-result-'.$index, json_encode($result, JSON_THROW_ON_ERROR));
                    DB::disconnect('pgsql');
                    exit(0);
                }
                $children[$index] = $pid;
            }
            $results = [];
            foreach ($children as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $this->assertTrue(pcntl_wifexited($status));
                $this->assertSame(0, pcntl_wexitstatus($status));
                $results[] = json_decode(file_get_contents($this->directory.'/'.$run.'-result-'.$index), true, flags: JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            DB::purge('pgsql');
            DB::reconnect('pgsql');
        }

    }
}
