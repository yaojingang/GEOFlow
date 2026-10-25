<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Services\SystemUpdater\RecoveryEvidence;
use App\Services\SystemUpdater\RecoveryPreparation;
use App\Services\SystemUpdater\RecoveryReconciliation;
use App\Services\SystemUpdater\RecoveryState;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RecoveryReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/geoflow-reconcile-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        config(['geoflow.recovery_contract_required' => true, 'geoflow.recovery_control_directory' => $this->directory]);
        $this->state('validating');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    private function state(string $phase): void
    {
        file_put_contents($this->directory.'/state.json', json_encode([
            'schema_version' => 1, 'instance_id' => 'primary', 'host_id' => str_repeat('b', 32), 'epoch' => str_repeat('a', 32),
            'transaction_id' => 'recovery-test-01', 'phase' => $phase, 'minimum_updater_protocol' => 5,
        ]));
    }

    private function prepare(): void
    {
        Admin::query()->create(['username' => 'restore-admin', 'password' => 'fixture-password', 'role' => 'super_admin', 'status' => 'active']);
        $preparation = app(RecoveryPreparation::class);
        $preparation->prepare('recovery-test-01', $preparation->inspect()['admin_digest']);
        $this->state('http_ready');
    }

    public function test_inspection_preserves_unknown_work_and_returns_only_redacted_identity(): void
    {
        DB::table('jobs')->insert(['queue' => 'unknown-custom-queue', 'payload' => '{"secret":"never-print-me"}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $this->prepare();

        $report = app(RecoveryReconciliation::class)->inspect('recovery-test-01');

        $this->assertSame('held', $report['background_status']);
        $this->assertSame('core_only', $report['proof_scope']);
        $this->assertSame(1, $report['quarantine_count']);
        $this->assertSame('jobs', $report['entries'][0]['source_table']);
        $this->assertSame('replay_adapter_required', $report['entries'][0]['hold_reason']);
        $this->assertStringNotContainsString('never-print-me', json_encode($report));
        $this->assertSame('{"secret":"never-print-me"}', DB::table('jobs')->value('payload'));
    }

    public function test_fixed_inspect_command_is_available_while_business_commands_remain_held(): void
    {
        $this->prepare();
        $exit = Artisan::call('geoflow:recovery', ['--phase' => 'reconcile-inspect', '--transaction' => 'recovery-test-01', '--json' => true]);

        $this->assertSame(0, $exit);
        $report = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame('held', $report['background_status']);
        $this->assertSame(0, $report['quarantine_count']);
        $this->assertSame([], $report['entries']);
    }

    public function test_inspect_rejects_new_source_identity_even_when_existing_rows_are_unchanged(): void
    {
        $this->prepare();
        DB::table('jobs')->insert(['queue' => 'unexpected', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $this->expectExceptionMessage('recovery_quarantine_changed');
        app(RecoveryReconciliation::class)->inspect('recovery-test-01');
    }

    public function test_inspect_rejects_changed_source_bytes_even_when_source_identity_is_the_same(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $this->prepare();
        DB::table('jobs')->update(['payload' => '{"changed":true}']);
        $this->expectExceptionMessage('recovery_quarantine_changed');
        app(RecoveryReconciliation::class)->inspect('recovery-test-01');
    }

    public function test_inspect_does_not_accept_another_transaction_or_unprepared_epoch(): void
    {
        $this->prepare();
        $exit = Artisan::call('geoflow:recovery', ['--phase' => 'reconcile-inspect', '--transaction' => 'different-transaction', '--json' => true]);
        $this->assertSame(1, $exit);
        $this->assertSame('recovery_reconciliation_boundary_invalid', json_decode(Artisan::output(), true)['error']);
    }

    public function test_ready_phase_alone_cannot_resume_a_prepared_restore(): void
    {
        $this->prepare();
        $this->state('ready');

        $this->expectException(ApiException::class);
        app(RecoveryState::class)->assertBackgroundReady();
    }

    public function test_empty_core_proof_requires_host_transition_before_allowing_new_work(): void
    {
        $this->prepare();
        $recovery = app(RecoveryReconciliation::class);
        $proof = $recovery->proveEmpty('recovery-test-01', '20260916T120000Z-1234abcd', str_repeat('c', 64));
        $this->assertSame('core_only', $proof['proof_scope']);
        $this->assertSame('held', $proof['background_status']);
        $this->assertSame($proof, $recovery->proveEmpty('recovery-test-01', '20260916T120000Z-1234abcd', str_repeat('c', 64)));
        try {
            app(RecoveryState::class)->assertBackgroundReady();
            $this->fail('Core evidence must not open workers.');
        } catch (ApiException $exception) {
            $this->assertSame('recovery_background_held', $exception->getErrorCode());
        }
        $this->state('ready');
        app(RecoveryState::class)->assertBackgroundReady();
        DB::table('jobs')->insert(['queue' => 'new-work', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        app(RecoveryState::class)->assertBackgroundReady();
        $this->assertSame(1, DB::table('jobs')->count());
    }

    public function test_manually_ready_blocks_http_even_for_login(): void
    {
        $this->prepare();
        $this->state('ready');

        $this->postJson('/api/v1/auth/login', ['username' => 'restore-admin', 'password' => 'fixture-password'])
            ->assertStatus(503)->assertJsonPath('error.code', 'recovery_background_held');
    }

    public function test_fresh_ready_without_restore_remains_compatible(): void
    {
        $this->state('ready');
        $this->changeState(['transaction_id' => null]);

        app(RecoveryState::class)->assertBackgroundReady();
        $this->assertSame('ready', app(RecoveryState::class)->assertHttpReady()['phase']);
    }

    public function test_deleted_preparation_cannot_bypass_recovered_ready_gate(): void
    {
        $this->prepare();
        DB::table('recovery_preparations')->delete();
        $this->state('ready');

        $this->assertHeld();
    }

    public function test_prepared_epoch_cannot_use_a_null_transaction_to_bypass_gate(): void
    {
        $this->prepare();
        $this->state('ready');
        $this->changeState(['transaction_id' => null]);

        $this->assertHeld();
    }

    public function test_source_added_after_proof_prevents_first_activation(): void
    {
        $this->prepare();
        $this->prove();
        $this->newJob();
        $this->state('ready');

        $this->assertHeld();
        $this->assertNull(DB::table('recovery_reconciliations')->value('activated_at'));
    }

    public function test_completed_intents_cannot_receive_an_empty_proof(): void
    {
        DB::table('job_batches')->insert(['id' => 'finished-batch', 'name' => 'complete', 'total_jobs' => 1,
            'pending_jobs' => 0, 'failed_jobs' => 0, 'failed_job_ids' => '[]', 'options' => null,
            'created_at' => time(), 'finished_at' => time(), 'cancelled_at' => null]);
        $this->prepare();

        $this->expectExceptionMessage('recovery_reconciliation_nonempty');
        try {
            $this->prove();
        } finally {
            $this->assertDatabaseCount('recovery_reconciliations', 0);
        }
    }

    #[DataProvider('proofChanges')]
    public function test_changed_proof_is_held_even_when_its_hash_is_recomputed(string $field, mixed $value): void
    {
        $this->prepare();
        $this->prove();
        $report = json_decode(DB::table('recovery_reconciliations')->value('report'), true);
        $report[$field] = $value;
        $bytes = RecoveryEvidence::json($report);
        DB::table('recovery_reconciliations')->update(['report' => $bytes, 'proof_sha256' => hash('sha256', $bytes)]);
        $this->state('ready');

        $this->assertHeld();
    }

    public static function proofChanges(): array
    {
        return [
            'catalog' => ['catalog_sha256', str_repeat('f', 64)], 'scope' => ['proof_scope', 'host'],
            'schema' => ['schema_version', 2], 'extra field' => ['execute', true],
            'old epoch' => ['epoch', str_repeat('d', 32)], 'other host' => ['host_id', str_repeat('d', 32)],
            'other instance' => ['instance_id', 'foreign'], 'other transaction' => ['transaction_id', 'other-transaction'],
            'quarantine' => ['quarantine_sha256', str_repeat('f', 64)], 'point shape' => ['source_recovery_point_id', '../secret'],
        ];
    }

    public function test_valid_proof_with_changed_host_identity_is_held(): void
    {
        $this->prepare();
        $this->prove();
        $this->state('ready');
        $this->changeState(['host_id' => str_repeat('f', 32)]);

        $this->assertHeld();
    }

    public function test_activated_proof_is_rechecked_on_every_boundary(): void
    {
        $this->prepare();
        $this->prove();
        $this->state('ready');
        app(RecoveryState::class)->assertBackgroundReady();
        DB::table('recovery_reconciliations')->update(['proof_sha256' => str_repeat('f', 64)]);

        $this->assertHeld();
    }

    public function test_missing_schema_fails_closed_even_for_initial_ready(): void
    {
        $this->state('ready');
        $this->changeState(['transaction_id' => null]);
        Schema::drop('recovery_reconciliations');

        $this->assertHeld();
    }

    #[DataProvider('readySchemaStates')]
    public function test_missing_business_table_keeps_ready_held_after_activation_or_on_initial_install(bool $activated): void
    {
        if ($activated) {
            $this->prepare();
            $this->prove();
            $this->state('ready');
            app(RecoveryState::class)->assertBackgroundReady();
            $this->assertNotNull(DB::table('recovery_reconciliations')->value('activated_at'));
        } else {
            $this->state('ready');
            $this->changeState(['transaction_id' => null]);
        }
        Schema::drop('jobs');

        $this->assertHeld();
    }

    public static function readySchemaStates(): array
    {
        return ['activated recovery' => [true], 'initial ready' => [false]];
    }

    public function test_database_failure_is_held_without_exposing_connection_details(): void
    {
        $this->state('ready');
        $this->changeState(['transaction_id' => null]);
        config(['database.connections.reconciliation_unreadable' => ['driver' => 'sqlite', 'database' => '/never-print-password-private/missing.sqlite', 'prefix' => '']]);
        $default = DB::getDefaultConnection();
        DB::setDefaultConnection('reconciliation_unreadable');
        try {
            $this->assertHeld();
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('reconciliation_unreadable');
        }
    }

    #[DataProvider('dispositions')]
    public function test_all_decisions_are_idempotent_audits_and_preserve_original_work(string $disposition): void
    {
        $this->newJob();
        $this->prepare();
        $decision = $this->decision($disposition);
        $source = (array) DB::table('jobs')->first();
        Queue::fake();

        $first = app(RecoveryReconciliation::class)->record('recovery-test-01', $decision);
        $second = app(RecoveryReconciliation::class)->record('recovery-test-01', array_reverse($decision, true));

        $this->assertSame($first, $second);
        $this->assertSame('held', $first['background_status']);
        $this->assertFalse($first['execution_created']);
        $this->assertSame($source, (array) DB::table('jobs')->first());
        $this->assertDatabaseCount('recovery_reconciliation_decisions', 1);
        $this->assertDatabaseCount('recovery_reconciliations', 0);
        $this->assertStringNotContainsString('never-print-password-private', json_encode($first));
        Queue::assertNothingPushed();
    }

    public static function dispositions(): array
    {
        return [['hold'], ['verified_no_replay'], ['reexecute_requested']];
    }

    public function test_decision_uuid_cannot_be_reused_with_changed_evidence(): void
    {
        $this->newJob();
        $this->prepare();
        $decision = $this->decision();
        app(RecoveryReconciliation::class)->record('recovery-test-01', $decision);
        $decision['evidence_sha256'] = str_repeat('f', 64);

        $this->expectExceptionMessage('recovery_reconciliation_decision_conflict');
        try {
            app(RecoveryReconciliation::class)->record('recovery-test-01', $decision);
        } finally {
            $this->assertDatabaseCount('recovery_reconciliation_decisions', 1);
        }
    }

    public function test_later_decision_retains_history_and_must_use_the_same_recovery_point(): void
    {
        $this->newJob();
        $this->prepare();
        $decision = $this->decision();
        app(RecoveryReconciliation::class)->record('recovery-test-01', $decision);
        $decision['decision_id'] = 'c959b1d0-17ac-49d0-b7f6-087d48868655';
        $decision['disposition'] = 'verified_no_replay';
        app(RecoveryReconciliation::class)->record('recovery-test-01', $decision);
        $decision['decision_id'] = 'c959b1d0-17ac-49d0-b7f6-087d48868656';
        $decision['source_recovery_point_sha256'] = str_repeat('f', 64);

        $this->expectExceptionMessage('recovery_reconciliation_point_conflict');
        try {
            app(RecoveryReconciliation::class)->record('recovery-test-01', $decision);
        } finally {
            $this->assertDatabaseCount('recovery_reconciliation_decisions', 2);
        }
    }

    public function test_decision_rechecks_source_bytes_before_any_audit_write(): void
    {
        $this->newJob();
        $this->prepare();
        $decision = $this->decision();
        DB::table('jobs')->update(['payload' => '{"changed":true}']);

        $this->expectExceptionMessage('recovery_quarantine_changed');
        try {
            app(RecoveryReconciliation::class)->record('recovery-test-01', $decision);
        } finally {
            $this->assertDatabaseCount('recovery_reconciliation_decisions', 0);
        }
    }

    #[DataProvider('invalidDecisionChanges')]
    public function test_decision_rejects_unrecognized_fields_and_incorrect_types(array $changes, ?string $remove): void
    {
        $this->newJob();
        $this->prepare();
        $decision = array_replace($this->decision(), $changes);
        if ($remove !== null) {
            unset($decision[$remove]);
        }

        $this->expectExceptionMessage('recovery_reconciliation_decision_invalid');
        try {
            app(RecoveryReconciliation::class)->record('recovery-test-01', $decision);
        } finally {
            $this->assertDatabaseCount('recovery_reconciliation_decisions', 0);
        }
    }

    public static function invalidDecisionChanges(): array
    {
        return [
            'raw evidence' => [['evidence' => 'never-print-password-private'], null],
            'schema type' => [['schema_version' => '1'], null], 'source type' => [['source_id' => 1], null],
            'unknown disposition' => [['disposition' => 'execute'], null], 'unsafe table' => [['source_table' => 'admins'], null],
            'raw hash' => [['reviewer_sha256' => 'never-print-password-private'], null],
            'missing hash' => [[], 'source_sha256'], 'missing confirmation' => [['disposition' => 'reexecute_requested'], null],
            'irrelevant confirmation' => [['user_confirmation_sha256' => str_repeat('f', 64)], null],
            'invalid uuid' => [['decision_id' => 'not-a-uuid'], null],
        ];
    }

    public function test_empty_proof_is_immutable_for_a_recovery_point(): void
    {
        $this->prepare();
        $first = $this->prove();

        $this->expectExceptionMessage('recovery_reconciliation_point_conflict');
        try {
            app(RecoveryReconciliation::class)->proveEmpty('recovery-test-01', 'another-point-id', str_repeat('d', 64));
        } finally {
            $this->assertSame($first['proof_sha256'], DB::table('recovery_reconciliations')->value('proof_sha256'));
        }
    }

    public function test_fixed_command_accepts_protected_hash_only_decisions_and_empty_proof(): void
    {
        $this->prepare();
        $this->assertSame(0, Artisan::call('geoflow:recovery-reconcile', ['--transaction' => 'recovery-test-01', '--json' => true]));
        $this->assertSame(0, Artisan::call('geoflow:recovery-reconcile', ['--phase' => 'prove-empty', '--transaction' => 'recovery-test-01',
            '--recovery-point' => '20260916T120000Z-1234abcd', '--recovery-point-sha256' => str_repeat('c', 64), '--json' => true]));
        $this->assertSame('held', json_decode(Artisan::output(), true)['background_status']);
        $this->assertSame('http_ready', app(RecoveryState::class)->snapshot()['phase']);
    }

    public function test_record_command_reads_a_protected_regular_file(): void
    {
        $this->newJob();
        $this->prepare();
        $path = realpath($this->directory).'/decision.json';
        file_put_contents($path, json_encode($this->decision()));
        chmod($path, 0600);

        $this->assertSame(0, Artisan::call('geoflow:recovery-reconcile', ['--phase' => 'record', '--transaction' => 'recovery-test-01', '--decision-file' => $path, '--json' => true]));
        $report = json_decode(Artisan::output(), true);
        $this->assertFalse($report['execution_created']);
        $this->assertDatabaseCount('recovery_reconciliation_decisions', 1);
    }

    #[DataProvider('unsafeFiles')]
    public function test_command_rejects_unsafe_decision_files_without_echoing_contents(string $kind): void
    {
        $this->newJob();
        $this->prepare();
        $path = realpath($this->directory).'/decision.json';
        file_put_contents($path, json_encode($this->decision()));
        chmod($path, 0600);
        if ($kind === 'permissions') {
            chmod($path, 0644);
        } elseif ($kind === 'symlink') {
            symlink($path, realpath($this->directory).'/linked.json');
            $path = realpath($this->directory).'/linked.json';
        } elseif ($kind === 'oversized') {
            file_put_contents($path, str_repeat('never-print-password-private', 1024));
        } elseif ($kind === 'json') {
            file_put_contents($path, '{never-print-password-private');
        } elseif ($kind === 'directory') {
            $path = realpath($this->directory);
        }

        $this->assertSame(1, Artisan::call('geoflow:recovery-reconcile', ['--phase' => 'record', '--transaction' => 'recovery-test-01', '--decision-file' => $path, '--json' => true]));
        $this->assertStringNotContainsString('never-print-password-private', Artisan::output());
        $this->assertDatabaseCount('recovery_reconciliation_decisions', 0);
    }

    public static function unsafeFiles(): array
    {
        return [['permissions'], ['symlink'], ['oversized'], ['json'], ['directory']];
    }

    #[DataProvider('invalidCommandOptions')]
    public function test_fixed_command_rejects_irrelevant_or_malformed_options(array $options): void
    {
        $this->prepare();

        $this->assertSame(1, Artisan::call('geoflow:recovery-reconcile', $options + ['--transaction' => 'recovery-test-01', '--json' => true]));
        $this->assertSame('fail', json_decode(Artisan::output(), true)['status']);
        $this->assertDatabaseCount('recovery_reconciliations', 0);
        $this->assertDatabaseCount('recovery_reconciliation_decisions', 0);
    }

    public static function invalidCommandOptions(): array
    {
        return [
            [['--phase' => 'execute']], [['--after' => '-1']], [['--limit' => '201']], [['--limit' => '1.5']],
            [['--phase' => 'record', '--after' => '0']], [['--decision-file' => '/secret']],
            [['--phase' => 'prove-empty', '--limit' => '100']], [['--phase' => 'prove-empty']],
        ];
    }

    private function newJob(): void
    {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{"secret":"never-print-password-private"}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
    }

    private function decision(string $disposition = 'hold'): array
    {
        $entry = DB::table('recovery_quarantines')->first();
        $decision = ['schema_version' => 1, 'decision_id' => 'c959b1d0-17ac-49d0-b7f6-087d48868654',
            'source_table' => $entry->source_table, 'source_id' => $entry->source_id, 'source_sha256' => $entry->source_sha256,
            'disposition' => $disposition, 'evidence_sha256' => str_repeat('d', 64), 'reviewer_sha256' => str_repeat('e', 64),
            'source_recovery_point_id' => '20260916T120000Z-1234abcd', 'source_recovery_point_sha256' => str_repeat('c', 64)];
        if ($disposition === 'reexecute_requested') {
            $decision['user_confirmation_sha256'] = str_repeat('f', 64);
        }

        return $decision;
    }

    private function prove(): array
    {
        return app(RecoveryReconciliation::class)->proveEmpty('recovery-test-01', '20260916T120000Z-1234abcd', str_repeat('c', 64));
    }

    private function changeState(array $changes): void
    {
        $path = $this->directory.'/state.json';
        file_put_contents($path, json_encode(array_replace(json_decode(file_get_contents($path), true), $changes)));
    }

    private function assertHeld(): void
    {
        foreach (['assertHttpReady', 'assertBackgroundReady'] as $method) {
            try {
                app(RecoveryState::class)->{$method}();
                $this->fail('A recovered epoch requires valid Core evidence.');
            } catch (ApiException $exception) {
                $this->assertSame('recovery_background_held', $exception->getErrorCode());
                $this->assertSame(503, $exception->getHttpStatus());
                $this->assertNull($exception->getPrevious());
                $this->assertStringNotContainsString('never-print-password-private', $exception->getMessage());
            }
        }
    }

    public function test_record_rejects_wrong_source_hash_without_creating_an_audit(): void
    {
        $this->newJob();
        $this->prepare();
        $decision = array_replace($this->decision(), ['source_sha256' => str_repeat('f', 64)]);

        $this->expectExceptionMessage('recovery_reconciliation_source_conflict');
        try {
            app(RecoveryReconciliation::class)->record('recovery-test-01', $decision);
        } finally {
            $this->assertDatabaseCount('recovery_reconciliation_decisions', 0);
        }
    }

    public function test_decision_uuid_cannot_be_reused_in_a_later_epoch(): void
    {
        $this->newJob();
        $this->prepare();
        $decision = $this->decision();
        app(RecoveryReconciliation::class)->record('recovery-test-01', $decision);
        $this->state('validating');
        $this->changeState(['epoch' => str_repeat('c', 32), 'transaction_id' => 'recovery-test-02']);
        $preparation = app(RecoveryPreparation::class);
        $preparation->prepare('recovery-test-02', $preparation->inspect()['admin_digest']);
        $this->changeState(['phase' => 'http_ready']);

        $this->expectExceptionMessage('recovery_reconciliation_decision_conflict');
        try {
            app(RecoveryReconciliation::class)->record('recovery-test-02', $decision);
        } finally {
            $this->assertDatabaseCount('recovery_reconciliation_decisions', 1);
        }
    }

    #[DataProvider('evidenceWrites')]
    public function test_host_change_during_evidence_write_rolls_back(string $operation, string $table): void
    {
        if ($operation === 'record') {
            $this->newJob();
        }
        $this->prepare();
        DB::listen(function (QueryExecuted $event) use ($table): void {
            if (str_starts_with($event->sql, 'insert into "'.$table.'"')) {
                $this->changeState(['host_id' => str_repeat('f', 32)]);
            }
        });

        $this->expectExceptionMessage('recovery_reconciliation_boundary_changed');
        try {
            if ($operation === 'record') {
                app(RecoveryReconciliation::class)->record('recovery-test-01', $this->decision());
            } else {
                $this->prove();
            }
        } finally {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public static function evidenceWrites(): array
    {
        return [['record', 'recovery_reconciliation_decisions'], ['prove', 'recovery_reconciliations']];
    }

    public function test_host_change_during_first_activation_rolls_back_activation(): void
    {
        $this->prepare();
        $this->prove();
        $this->state('ready');
        DB::listen(function (QueryExecuted $event): void {
            if (str_starts_with($event->sql, 'update "recovery_reconciliations"')) {
                $this->changeState(['host_id' => str_repeat('f', 32)]);
            }
        });

        $this->assertHeld();
        $this->assertNull(DB::table('recovery_reconciliations')->value('activated_at'));
    }

    public function test_reordered_proof_json_keys_preserve_verification(): void
    {
        $this->prepare();
        $this->prove();
        $report = json_decode(DB::table('recovery_reconciliations')->value('report'), true);
        DB::table('recovery_reconciliations')->update(['report' => json_encode(array_reverse($report, true))]);
        $this->state('ready');

        app(RecoveryState::class)->assertBackgroundReady();
        $this->assertNotNull(DB::table('recovery_reconciliations')->value('activated_at'));
    }
}
