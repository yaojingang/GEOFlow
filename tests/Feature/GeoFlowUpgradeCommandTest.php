<?php

namespace Tests\Feature;

use App\Services\Deployment\DeploymentReadiness;
use App\Services\Deployment\UpgradeJournal;
use App\Services\Deployment\UpgradePlan;
use App\Services\Deployment\UpgradeStepRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GeoFlowUpgradeCommandTest extends TestCase
{
    private string $temporary;

    private string $planPath;

    private UpgradeJournal $journal;

    /** @var array{server:mixed,env:mixed,process:string|false} */
    private array $originalDrainEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $drainKey = 'GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED';
        $this->originalDrainEnvironment = [
            'server' => $_SERVER[$drainKey] ?? null,
            'env' => $_ENV[$drainKey] ?? null,
            'process' => getenv($drainKey),
        ];
        $this->setDrainConfirmation('true');
        $this->temporary = sys_get_temp_dir().'/geoflow-upgrade-test-'.bin2hex(random_bytes(8));
        mkdir($this->temporary, 0700);
        $this->planPath = $this->temporary.'/plan.json';
        $this->journal = new class($this->temporary) extends UpgradeJournal
        {
            public function __construct(private readonly string $root) {}

            public function directory(): string
            {
                return $this->root.'/journal';
            }
        };
        $this->app->instance(UpgradeJournal::class, $this->journal);
        Schema::create('migrations', function (Blueprint $table): void {
            $table->id();
            $table->string('migration');
            $table->integer('batch');
        });
        foreach (array_keys(app(UpgradePlan::class)->migrationFiles()) as $name) {
            DB::table('migrations')->insert(['migration' => $name, 'batch' => 1]);
        }
        $this->app->instance(DeploymentReadiness::class, Mockery::mock(DeploymentReadiness::class)
            ->shouldReceive('check')->andReturn(['database' => ['status' => 'pass']])->getMock());
        $this->writePlan();
    }

    protected function tearDown(): void
    {
        $drainKey = 'GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED';
        $this->setDrainConfirmation(null);
        if ($this->originalDrainEnvironment['server'] !== null) {
            $_SERVER[$drainKey] = $this->originalDrainEnvironment['server'];
        }
        if ($this->originalDrainEnvironment['env'] !== null) {
            $_ENV[$drainKey] = $this->originalDrainEnvironment['env'];
        }
        if ($this->originalDrainEnvironment['process'] !== false) {
            putenv($drainKey.'='.$this->originalDrainEnvironment['process']);
        }
        File::deleteDirectory($this->temporary);
        parent::tearDown();
    }

    public function test_inspect_reads_only_old_migration_schema_and_reports_exact_pending_hashes(): void
    {
        $name = '2026_07_17_000400_security_upgrade_preflight';
        DB::table('migrations')->where('migration', $name)->delete();
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $report = $this->invoke('inspect');
        $this->assertSame('pass', $report['status']);
        $this->assertSame(1, $report['schema_version']);
        $this->assertSame('3.0.0', $report['version']);
        $this->assertSame(hash_file('sha256', $this->planPath), $report['plan_sha256']);
        $this->assertSame([$name], array_column($report['pending_migrations'], 'name'));
        $this->assertFalse($report['eligible_online']);
        $this->assertDirectoryDoesNotExist($this->journal->directory());
        foreach ($queries as $query) {
            $this->assertDoesNotMatchRegularExpression('/\b(insert|update|delete|create|alter|drop)\b/i', $query);
        }
    }

    public function test_tampered_plan_and_missing_or_modified_manifest_entries_fail_before_journal_creation(): void
    {
        $report = $this->invoke('apply', ['--plan-sha256' => str_repeat('0', 64)]);
        $this->assertContains('upgrade_plan_hash_mismatch', $report['errors']);
        $this->writePlan(static function (array &$plan): void {
            array_pop($plan['migrations']);
        });
        $this->assertContains('upgrade_migration_manifest_coverage_mismatch', $this->invoke('inspect')['errors']);
        $this->writePlan(static function (array &$plan): void {
            $plan['migrations'][0]['sha256'] = str_repeat('0', 64);
        });
        $this->assertStringStartsWith('upgrade_migration_hash_mismatch:', $this->invoke('inspect')['errors'][0]);
        $this->assertDirectoryDoesNotExist($this->journal->directory());
    }

    public function test_unlisted_database_migration_and_unknown_plan_command_fail_closed(): void
    {
        DB::table('migrations')->insert(['migration' => '2099_unknown', 'batch' => 2]);
        $this->assertContains('upgrade_database_has_unknown_migrations', $this->invoke('inspect')['errors']);
        $this->writePlan(static function (array &$plan): void {
            $plan['steps'][1]['kind'] = 'shell';
        });
        $this->assertContains('upgrade_plan_step_invalid', $this->invoke('inspect')['errors']);
    }

    public function test_historical_security_migration_is_blocked_online_even_if_manifest_marks_it_online(): void
    {
        DB::table('migrations')->where('migration', UpgradePlan::PROTECTED_MIGRATIONS[0])->delete();
        $this->writePlan($this->onlinePlan(...));
        $report = $this->invoke('apply', ['--strategy' => 'online']);
        $this->assertContains('upgrade_online_ineligible', $report['errors']);
        $this->assertDirectoryDoesNotExist($this->journal->directory());
    }

    public function test_maintenance_requires_host_drain_and_ignores_fresh_install_bypass(): void
    {
        $this->setDrainConfirmation(null);
        DB::table('migrations')->where('migration', UpgradePlan::PROTECTED_MIGRATIONS[0])->delete();
        $report = $this->invoke('apply');
        $this->assertContains('upgrade_host_drain_confirmation_required', $report['errors']);
        $this->assertFileDoesNotExist($this->journal->directory().'/operation-1.json');
    }

    public function test_maintenance_without_drain_blocks_nonprotected_migrations_and_data_only_apply(): void
    {
        $this->setDrainConfirmation(null);
        $runner = Mockery::mock(UpgradeStepRunner::class);
        $runner->shouldNotReceive('run');
        $this->app->instance(UpgradeStepRunner::class, $runner);
        $this->assertContains('upgrade_host_drain_confirmation_required', $this->invoke('apply')['errors']);
        $this->assertDirectoryDoesNotExist($this->journal->directory());

        $migration = array_key_last(app(UpgradePlan::class)->migrationFiles());
        $this->assertNotContains($migration, UpgradePlan::PROTECTED_MIGRATIONS);
        DB::table('migrations')->where('migration', $migration)->delete();
        $report = $this->invoke('apply');
        $this->assertContains('upgrade_host_drain_confirmation_required', $report['errors']);
        $this->assertSame([$migration], array_column($report['pending_migrations'], 'name'));
        $this->assertDirectoryDoesNotExist($this->journal->directory());
    }

    public function test_online_eligibility_requires_source_compatibility_migrations_and_steps(): void
    {
        $this->writePlan($this->onlinePlan(...));
        $this->assertTrue($this->invoke('inspect', ['--strategy' => 'online'])['eligible_online']);
        $this->assertSame('fail', $this->invoke('inspect', ['--strategy' => 'online', '--source-sequence' => 9])['status']);
        $this->writePlan(function (array &$plan): void {
            $this->onlinePlan($plan);
            $plan['compatibility']['queue'] = false;
        });
        $this->assertSame('fail', $this->invoke('inspect', ['--strategy' => 'online'])['status']);
        $this->writePlan(function (array &$plan): void {
            $this->onlinePlan($plan);
            $plan['steps'][1]['online'] = false;
        });
        $this->assertSame('fail', $this->invoke('inspect', ['--strategy' => 'online'])['status']);
        $this->writePlan(function (array &$plan): void {
            $this->onlinePlan($plan);
            $plan['migrations'][0]['online'] = false;
            DB::table('migrations')->where('migration', $plan['migrations'][0]['name'])->delete();
        });
        $this->assertSame('fail', $this->invoke('inspect', ['--strategy' => 'online'])['status']);
    }

    public function test_failed_step_is_durable_and_retry_resumes_without_repeating_completed_steps(): void
    {
        $runner = Mockery::mock(UpgradeStepRunner::class);
        $runner->shouldReceive('run')->once()->withArgs(fn (array $step): bool => $step['kind'] === 'migrate');
        $runner->shouldReceive('run')->once()->withArgs(fn (array $step): bool => $step['kind'] === 'security_audit')
            ->andThrow(new RuntimeException('upgrade_step_failed:security_audit:exit_1'));
        $this->app->instance(UpgradeStepRunner::class, $runner);
        $this->assertSame('fail', $this->invoke('apply')['status']);
        $journal = json_decode(file_get_contents($this->journal->directory().'/operation-1.json'), true);
        $this->assertSame('completed', $journal['steps']['migrate']['status']);
        $this->assertSame('failed', $journal['steps']['security_audit']['status']);

        $retry = Mockery::mock(UpgradeStepRunner::class);
        $retry->shouldReceive('run')->once()->withArgs(fn (array $step): bool => $step['kind'] === 'security_audit');
        $retry->shouldReceive('verifyData')->twice()->andReturn(['security_audit' => ['status' => 'pass']]);
        $this->app->instance(UpgradeStepRunner::class, $retry);
        $this->assertSame('pass', $this->invoke('apply')['status']);
        $this->assertSame('pass', $this->invoke('apply')['status']);
        $journal = json_decode(file_get_contents($this->journal->directory().'/operation-1.json'), true);
        $this->assertSame('completed', $journal['status']);
    }

    public function test_explicit_lock_contention_returns_failure_instead_of_silent_migration_success(): void
    {
        $lock = $this->journal->acquire();
        try {
            $this->assertContains('upgrade_lock_held', $this->invoke('apply')['errors']);
            $this->assertFileDoesNotExist($this->journal->directory().'/operation-1.json');
        } finally {
            $this->journal->release($lock);
        }
    }

    public function test_successful_migrate_exit_with_pending_migrations_is_failure(): void
    {
        $migration = array_key_last(app(UpgradePlan::class)->migrationFiles());
        DB::table('migrations')->where('migration', $migration)->delete();
        $runner = Mockery::mock(UpgradeStepRunner::class);
        $runner->shouldReceive('run')->once()->andReturnNull();
        $this->app->instance(UpgradeStepRunner::class, $runner);
        $this->assertContains('upgrade_migrations_incomplete', $this->invoke('apply')['errors']);
    }

    public function test_verify_fails_pending_migrations_or_incomplete_data_and_never_acquires_a_lock(): void
    {
        $runner = Mockery::mock(UpgradeStepRunner::class);
        $runner->shouldReceive('verifyData')->once()->andReturn(['retrieval_backfill' => ['status' => 'fail', 'counts' => ['tasks_deferred' => 1]]]);
        $this->app->instance(UpgradeStepRunner::class, $runner);
        $this->assertContains('upgrade_check_failed:retrieval_backfill', $this->invoke('verify', ['--operation' => ''])['errors']);
        DB::table('migrations')->where('id', 1)->delete();
        $this->assertContains('upgrade_migrations_incomplete', $this->invoke('verify', ['--operation' => ''])['errors']);
        $this->assertDirectoryDoesNotExist($this->journal->directory());
    }

    public function test_journal_cannot_be_reused_with_a_different_plan_hash(): void
    {
        $this->passingRunner();
        $this->assertSame('pass', $this->invoke('apply')['status']);
        $this->writePlan(static function (array &$plan): void {
            $plan['steps'][1]['timeout_seconds']++;
        });
        $this->assertContains('upgrade_journal_identity_mismatch', $this->invoke('apply')['errors']);
    }

    public function test_maintenance_can_apply_an_online_plan_and_verify_completed_operation_read_only(): void
    {
        $this->writePlan($this->onlinePlan(...));
        $this->passingRunner();
        $this->assertSame('pass', $this->invoke('apply')['status']);
        $path = $this->journal->directory().'/operation-1.json';
        $bytes = file_get_contents($path);
        $this->assertSame('pass', $this->invoke('verify')['status']);
        $this->assertSame($bytes, file_get_contents($path));
    }

    public function test_apply_rejects_missing_hash_and_invalid_operation_or_source(): void
    {
        $this->assertSame('fail', $this->invoke('apply', ['--plan-sha256' => ''])['status']);
        $this->assertSame('fail', $this->invoke('apply', ['--operation' => '../unsafe'])['status']);
        $this->assertSame('fail', $this->invoke('apply', ['--source-sequence' => '1.5'])['status']);
        $this->assertDirectoryDoesNotExist($this->journal->directory());
    }

    #[DataProvider('validSourceSequences')]
    public function test_source_sequence_accepts_the_full_integer_contract_without_losing_precision(string $source, int $expected): void
    {
        $this->writePlan(static function (array &$plan) use ($expected): void {
            $plan['allowed_sources'] = $expected === 0 ? [] : [$expected];
        });
        $this->assertSame('pass', $this->invoke('inspect', ['--source-sequence' => $source])['status']);
        if ($expected === 0) {
            $this->assertContains('upgrade_apply_requires_plan_hash_and_source', $this->invoke('apply', ['--source-sequence' => $source])['errors']);
            $this->assertDirectoryDoesNotExist($this->journal->directory());

            return;
        }
        $this->passingRunner();
        $this->assertSame('pass', $this->invoke('apply', ['--source-sequence' => $source])['status']);
        $journal = json_decode(file_get_contents($this->journal->directory().'/operation-1.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($expected, $journal['source_sequence']);
    }

    public static function validSourceSequences(): array
    {
        return [
            'zero for inspection' => ['0', 0],
            'minimum upgrade source' => ['1', 1],
            'below maximum' => [(string) (PHP_INT_MAX - 1), PHP_INT_MAX - 1],
            'maximum' => [(string) PHP_INT_MAX, PHP_INT_MAX],
            'leading zero maximum' => ['000'.PHP_INT_MAX, PHP_INT_MAX],
        ];
    }

    #[DataProvider('invalidSourceSequences')]
    public function test_source_sequence_rejects_overflow_and_non_decimal_inputs_before_mutation(string $source): void
    {
        $this->assertContains('upgrade_options_invalid', $this->invoke('apply', ['--source-sequence' => $source])['errors']);
        $this->assertDirectoryDoesNotExist($this->journal->directory());
    }

    public static function invalidSourceSequences(): array
    {
        $overflow = substr((string) PHP_INT_MAX, 0, -1).'8';

        return array_map(static fn (string $source): array => [$source], [
            'overflow' => $overflow, 'leading zero overflow' => '000'.$overflow,
            'unbounded integer' => str_repeat('9', 64), 'negative' => '-1', 'negative zero' => '-0',
            'explicit positive' => '+1', 'fractional' => '1.5', 'decimal float' => '1.0',
            'exponent' => '1e3', 'hexadecimal' => '0x10', 'whitespace' => ' 1', 'empty' => '',
        ]);
    }

    public function test_real_migration_subprocess_applies_only_verified_paths_and_retries_cleanly(): void
    {
        $databaseRoot = $this->temporary.'/database';
        mkdir($databaseRoot.'/migrations', 0700, true);
        $name = '2026_09_08_999999_create_upgrade_fixture_table';
        $migrationPath = $databaseRoot.'/migrations/'.$name.'.php';
        file_put_contents($migrationPath, <<<'MIGRATION'
<?php
return new class extends \Illuminate\Database\Migrations\Migration {
    public function up(): void {
        \Illuminate\Support\Facades\Schema::create('upgrade_fixture', function ($table): void { $table->id(); });
    }
};
MIGRATION);
        $databaseFile = $databaseRoot.'/fixture.sqlite';
        touch($databaseFile);
        $originalDatabasePath = $this->app->databasePath();
        $originalDatabase = config('database.connections.sqlite.database');
        $originalEnvironment = getenv('DB_DATABASE');
        $this->app->useDatabasePath($databaseRoot);
        config()->set('database.connections.sqlite.database', $databaseFile);
        DB::purge('sqlite');
        $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $databaseFile;
        putenv('DB_DATABASE='.$databaseFile);
        try {
            Schema::create('migrations', function (Blueprint $table): void {
                $table->id();
                $table->string('migration');
                $table->integer('batch');
            });
            $this->writePlan(static function (array &$plan) use ($name, $migrationPath): void {
                $plan['migrations'] = [['name' => $name, 'sha256' => hash_file('sha256', $migrationPath), 'online' => false]];
                $plan['steps'] = [$plan['steps'][0]];
            });
            $this->app->instance(UpgradeStepRunner::class, new class extends UpgradeStepRunner
            {
                public function verifyData(): array
                {
                    return ['fixture_schema' => ['status' => Schema::hasTable('upgrade_fixture') ? 'pass' : 'fail']];
                }
            });
            $this->assertSame('pass', $this->invoke('apply')['status']);
            $this->assertTrue(Schema::hasTable('upgrade_fixture'));
            $this->assertSame([$name], DB::table('migrations')->pluck('migration')->all());
            $this->assertSame('pass', $this->invoke('apply')['status']);
            file_put_contents($migrationPath, "\n// Changed after signature verification\n", FILE_APPEND);
            $this->assertContains('upgrade_migration_hash_mismatch:'.$name, $this->invoke('inspect')['errors']);
        } finally {
            $this->app->useDatabasePath($originalDatabasePath);
            config()->set('database.connections.sqlite.database', $originalDatabase);
            DB::purge('sqlite');
            $_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = (string) $originalEnvironment;
            putenv('DB_DATABASE='.(string) $originalEnvironment);
        }
    }

    public function test_standalone_verification_checks_readiness_without_claiming_an_upgrade_source(): void
    {
        $this->writePlan(static function (array &$plan): void {
            $plan['allowed_sources'] = [7];
        });
        $this->passingRunner();
        $options = ['--operation' => '', '--source-sequence' => 0];
        $this->assertSame('pass', $this->invoke('verify', $options)['status']);
        $this->assertContains('upgrade_source_not_allowed', $this->invoke('inspect', $options)['errors']);
        $this->assertContains('upgrade_source_not_allowed', $this->invoke('verify', ['--source-sequence' => 0])['errors']);
        $this->assertContains('upgrade_apply_requires_plan_hash_and_source', $this->invoke('apply', $options)['errors']);
        $this->assertDirectoryDoesNotExist($this->journal->directory());
    }

    private function passingRunner(): void
    {
        $runner = Mockery::mock(UpgradeStepRunner::class);
        $runner->shouldReceive('run')->andReturnNull();
        $runner->shouldReceive('verifyData')->andReturn(['security_audit' => ['status' => 'pass']]);
        $this->app->instance(UpgradeStepRunner::class, $runner);
    }

    private function setDrainConfirmation(?string $value): void
    {
        $key = 'GEOFLOW_SECURITY_UPGRADE_DRAIN_CONFIRMED';
        if ($value === null) {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);

            return;
        }
        $_SERVER[$key] = $_ENV[$key] = $value;
        putenv($key.'='.$value);
    }

    /** @param array<string,mixed> $plan */
    private function onlinePlan(array &$plan): void
    {
        $plan['strategy'] = 'online';
        $plan['allowed_sources'] = [7];
        $plan['compatibility'] = array_fill_keys(['schema', 'queue', 'cache', 'storage'], true);
        foreach ($plan['migrations'] as &$migration) {
            $migration['online'] = true;
        }
        foreach ($plan['steps'] as &$step) {
            $step['online'] = true;
        }
    }

    private function writePlan(?callable $change = null): void
    {
        $plan = json_decode(file_get_contents(base_path('deployment/upgrade-plan.json')), true);
        $plan['steps'] = array_values(array_filter($plan['steps'], fn (array $step): bool => in_array($step['kind'], ['migrate', 'security_audit'], true)));
        if ($change !== null) {
            $change($plan);
        }
        file_put_contents($this->planPath, json_encode($plan, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function invoke(string $phase, array $options = []): array
    {
        $exit = Artisan::call('geoflow:upgrade', array_replace([
            '--phase' => $phase, '--strategy' => 'maintenance', '--operation' => 'operation-1',
            '--source-sequence' => 7, '--plan' => $this->planPath,
            '--plan-sha256' => hash_file('sha256', $this->planPath), '--json' => true,
        ], $options));
        $report = json_decode(Artisan::output(), true, 64, JSON_THROW_ON_ERROR);
        $this->assertSame($report['status'] === 'pass' ? 0 : 1, $exit);

        return $report;
    }
}
