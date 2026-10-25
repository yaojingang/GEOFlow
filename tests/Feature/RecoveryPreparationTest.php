<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\SystemUpdater\RecoveryPreparation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecoveryPreparationTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/geoflow-recovery-prepare-'.bin2hex(random_bytes(6));
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

    private function admin(): Admin
    {
        return Admin::query()->create(['username' => 'restore-admin', 'password' => 'private-test-password', 'role' => 'super_admin', 'status' => 'active']);
    }

    public function test_preparation_invalidates_restored_credentials_and_is_idempotent(): void
    {
        $admin = $this->admin();
        $admin->createToken('restored-token');
        $service = app(RecoveryPreparation::class);
        $before = $service->inspect();
        $first = $service->prepare('recovery-test-01', $before['admin_digest']);
        $second = $service->prepare('recovery-test-01', $before['admin_digest']);
        $this->assertSame($first, $second);
        $this->assertSame(0, $admin->tokens()->count());
        $this->assertSame(2, $admin->fresh()->auth_version);
        $this->assertNull($admin->fresh()->remember_token);
        $this->assertSame('held', $first['background_status']);
        $this->assertSame('pass', $service->verify('recovery-test-01')['status']);
        $this->assertStringNotContainsString($admin->password, json_encode($first));
        $this->assertStringNotContainsString('private-test-password', json_encode($first));
    }

    public function test_changed_administrator_security_state_keeps_recovery_blocked(): void
    {
        $admin = $this->admin();
        $admin->createToken('restored-token');
        $service = app(RecoveryPreparation::class);
        $before = $service->inspect()['admin_digest'];
        $admin->update(['role' => 'admin']);
        $this->expectExceptionMessage('recovery_admin_review_required');
        try {
            $service->prepare('recovery-test-01', $before);
        } finally {
            $this->assertSame(1, $admin->tokens()->count());
            $this->assertSame(0, DB::table('recovery_preparations')->count());
        }
    }

    public function test_changed_source_with_same_quarantine_count_fails_verification(): void
    {
        $this->admin();
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $service = app(RecoveryPreparation::class);
        $service->prepare('recovery-test-01', $service->inspect()['admin_digest']);
        DB::table('jobs')->update(['payload' => '{"modified":true}']);
        $this->expectExceptionMessage('recovery_quarantine_changed');
        $service->verify('recovery-test-01');
    }

    public function test_new_source_identity_cannot_bypass_an_existing_empty_manifest(): void
    {
        $this->admin();
        $service = app(RecoveryPreparation::class);
        $service->prepare('recovery-test-01', $service->inspect()['admin_digest']);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $this->expectExceptionMessage('recovery_quarantine_changed');
        $service->verify('recovery-test-01');
    }

    public function test_wrong_transaction_or_open_http_state_never_invalidates_new_credentials(): void
    {
        $admin = $this->admin();
        $admin->createToken('new-token');
        $service = app(RecoveryPreparation::class);
        $before = $service->inspect()['admin_digest'];
        $this->state('http_ready');
        $this->expectExceptionMessage('recovery_prepare_boundary_invalid');
        try {
            $service->prepare('recovery-test-01', $before);
        } finally {
            $this->assertSame(1, $admin->tokens()->count());
        }
    }

    public function test_database_queue_payloads_are_preserved_and_quarantine_manifest_is_redacted(): void
    {
        $this->admin();
        DB::table('jobs')->insert(['queue' => 'unknown-custom-queue', 'payload' => '{"secret":"never-print-me"}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        $service = app(RecoveryPreparation::class);
        $report = $service->prepare('recovery-test-01', $service->inspect()['admin_digest']);
        $this->assertSame(1, $report['quarantine_count']);
        $this->assertSame(1, DB::table('jobs')->count());
        $entry = DB::table('recovery_quarantines')->first();
        $this->assertSame('jobs', $entry->source_table);
        $this->assertStringNotContainsString('never-print-me', $entry->summary);
        $this->assertStringNotContainsString('never-print-me', json_encode($report));
    }
}
