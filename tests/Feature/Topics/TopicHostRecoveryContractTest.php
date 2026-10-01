<?php

namespace Tests\Feature\Topics;

use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\TopicBuildRun;
use App\Models\TopicImportBatch;
use App\Services\SystemUpdater\RecoveryPreparation;
use App\Services\SystemUpdater\RecoveryReconciliation;
use App\Services\SystemUpdater\RecoveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TopicHostRecoveryContractTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/geoflow-topic-host-recovery-'.bin2hex(random_bytes(6));
        mkdir($this->directory, 0700);
        config(['geoflow.recovery_contract_required' => true, 'geoflow.recovery_control_directory' => $this->directory]);
        $this->state('validating');
        Admin::query()->create(['username' => 'restored-topic-owner', 'password' => 'fixture-password', 'role' => 'super_admin', 'status' => 'active']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
        parent::tearDown();
    }

    public static function topicIntents(): array
    {
        return ['build' => ['topic_build_runs'], 'batch' => ['topic_import_batches']];
    }

    #[DataProvider('topicIntents')]
    public function test_restored_topic_work_is_quarantined_with_redacted_identity_and_cannot_receive_an_empty_proof(string $table): void
    {
        $source = $this->intent($table);
        Queue::fake();
        $report = $this->prepare();
        $this->assertSame(1, $report['quarantine_count']);
        $entry = DB::table('recovery_quarantines')->sole();
        $this->assertSame($table, $entry->source_table);
        $this->assertSame((string) $source['id'], $entry->source_id);
        $this->assertSame('held', $entry->status);
        $inspection = app(RecoveryReconciliation::class)->inspect('topic-host-restore-01');
        $this->assertSame('pending', $inspection['entries'][0]['summary']['status']);
        $this->assertStringContainsString($table, json_encode($inspection));
        $this->assertStringNotContainsString('private-topic-source', json_encode($inspection));
        $this->assertSame($source, (array) DB::table($table)->sole());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('tasks', 0);
        Queue::assertNothingPushed();
        try {
            $this->prove();
            $this->fail('Restored topic work must retain the recovery hold.');
        } catch (RuntimeException $failure) {
            $this->assertSame('recovery_reconciliation_nonempty', $failure->getMessage());
        }
        $this->assertDatabaseCount('recovery_reconciliations', 0);
    }

    #[DataProvider('topicIntents')]
    public function test_completed_topic_records_remain_held_for_possible_follow_up_intents(string $table): void
    {
        $this->intent($table, 'completed');
        $this->prepare();
        $this->expectExceptionMessage('recovery_reconciliation_nonempty');
        $this->prove();
    }

    #[DataProvider('topicIntents')]
    public function test_changed_topic_source_bytes_fail_both_preparation_and_reconciliation_verification(string $table): void
    {
        $this->intent($table);
        $this->prepare();
        DB::table($table)->update(['status' => 'running']);
        $this->state('validating');
        try {
            app(RecoveryPreparation::class)->verify('topic-host-restore-01');
            $this->fail('Changed source bytes must invalidate the preparation.');
        } catch (RuntimeException $failure) {
            $this->assertSame('recovery_quarantine_changed', $failure->getMessage());
        }
        $this->state('http_ready');
        $this->expectExceptionMessage('recovery_quarantine_changed');
        app(RecoveryReconciliation::class)->inspect('topic-host-restore-01');
    }

    #[DataProvider('topicIntents')]
    public function test_topic_source_added_after_preparation_invalidates_an_empty_snapshot(string $table): void
    {
        $this->prepare();
        $this->intent($table);
        $this->expectExceptionMessage('recovery_quarantine_changed');
        app(RecoveryReconciliation::class)->inspect('topic-host-restore-01');
    }

    #[DataProvider('topicIntents')]
    public function test_topic_source_added_after_empty_proof_blocks_first_background_activation(string $table): void
    {
        $this->prepare();
        $this->prove();
        $this->intent($table);
        $this->state('ready');
        try {
            app(RecoveryState::class)->assertBackgroundReady();
            $this->fail('Work added after proof must prevent first activation.');
        } catch (ApiException $failure) {
            $this->assertSame('recovery_background_held', $failure->getErrorCode());
        }
        $this->assertNull(DB::table('recovery_reconciliations')->value('activated_at'));
    }

    #[DataProvider('topicIntents')]
    public function test_hash_only_topic_reexecution_review_preserves_work_and_never_dispatches(string $table): void
    {
        $source = $this->intent($table);
        $this->prepare();
        $entry = DB::table('recovery_quarantines')->sole();
        $decision = [
            'schema_version' => 1, 'decision_id' => (string) Str::uuid(),
            'source_table' => $table, 'source_id' => $entry->source_id, 'source_sha256' => $entry->source_sha256,
            'disposition' => 'reexecute_requested', 'evidence_sha256' => str_repeat('d', 64), 'reviewer_sha256' => str_repeat('e', 64),
            'source_recovery_point_id' => '20260916T120000Z-1234abcd', 'source_recovery_point_sha256' => str_repeat('c', 64),
            'user_confirmation_sha256' => str_repeat('f', 64),
        ];
        Queue::fake();
        $first = app(RecoveryReconciliation::class)->record('topic-host-restore-01', $decision);
        $this->assertSame($first, app(RecoveryReconciliation::class)->record('topic-host-restore-01', $decision));
        $this->assertSame('held', $first['background_status']);
        $this->assertFalse($first['execution_created']);
        $this->assertSame($source, (array) DB::table($table)->sole());
        $this->assertDatabaseCount('recovery_reconciliation_decisions', 1);
        $this->assertDatabaseCount('recovery_reconciliations', 0);
        $this->assertSame('http_ready', json_decode(file_get_contents($this->directory.'/state.json'), true)['phase']);
        Queue::assertNothingPushed();
    }

    public function test_recovery_of_a_schema_before_topic_execution_tables_preserves_the_core_contract(): void
    {
        Schema::drop('topic_build_runs');
        Schema::drop('topic_import_batches');
        $report = $this->prepare();
        $this->assertSame(0, $report['quarantine_count']);
        $proof = $this->prove();
        $this->assertSame('held', $proof['background_status']);
        $this->state('ready');
        app(RecoveryState::class)->assertBackgroundReady();
        $this->assertNotNull(DB::table('recovery_reconciliations')->value('activated_at'));
    }

    public function test_optional_topic_schema_does_not_weaken_required_core_table_checks(): void
    {
        Schema::drop('topic_build_runs');
        Schema::drop('topic_import_batches');
        Schema::drop('jobs');
        $this->state('ready', null);
        $this->expectException(ApiException::class);
        app(RecoveryState::class)->assertBackgroundReady();
    }

    private function state(string $phase, ?string $transaction = 'topic-host-restore-01'): void
    {
        file_put_contents($this->directory.'/state.json', json_encode([
            'schema_version' => 1, 'instance_id' => 'primary', 'host_id' => str_repeat('b', 32), 'epoch' => str_repeat('a', 32),
            'transaction_id' => $transaction, 'phase' => $phase, 'minimum_updater_protocol' => 5,
        ]));
    }

    private function intent(string $table, string $status = 'pending'): array
    {
        $actorId = Admin::query()->sole()->id;
        if ($table === 'topic_build_runs') {
            TopicBuildRun::query()->create([
                'request_key' => (string) Str::uuid(), 'dispatch_key' => (string) Str::uuid(), 'site_key' => 'primary', 'owner_admin_id' => $actorId,
                'identity' => [], 'input' => ['rules' => 'private-topic-source'], 'result' => ['intro' => 'private-topic-source'], 'status' => $status,
            ]);
        } else {
            TopicImportBatch::query()->create([
                'request_key' => (string) Str::uuid(), 'site_key' => 'primary', 'owner_admin_id' => $actorId,
                'settings' => ['rules' => 'private-topic-source'], 'rows' => [['title' => 'private-topic-source', 'status' => 'pending']], 'status' => $status,
            ]);
        }

        return (array) DB::table($table)->orderByDesc('id')->first();
    }

    private function prepare(): array
    {
        $service = app(RecoveryPreparation::class);
        $report = $service->prepare('topic-host-restore-01', $service->inspect()['admin_digest']);
        $this->assertSame('pass', $service->verify('topic-host-restore-01')['status']);
        $this->state('http_ready');

        return $report;
    }

    private function prove(): array
    {
        return app(RecoveryReconciliation::class)->proveEmpty('topic-host-restore-01', '20260916T120000Z-1234abcd', str_repeat('c', 64));
    }
}
