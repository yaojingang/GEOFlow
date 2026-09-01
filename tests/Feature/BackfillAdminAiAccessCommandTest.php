<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\SiteSetting;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BackfillAdminAiAccessCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_reports_changes_and_conflicts_without_writing(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $legacyAdmin = $this->admin('legacy-admin', 'admin', '2026-08-15 00:00:00');
        $newAdmin = $this->admin('new-admin', 'admin', '2026-09-02 00:00:00');
        $systemModel = $this->model(null, 'system-model', 'embedding');
        $conflictModel = $this->model(null, 'conflict-model', 'chat');
        SiteSetting::query()->create(['setting_key' => 'default_embedding_model_id', 'setting_value' => (string) $systemModel->id]);
        SiteSetting::query()->create(['setting_key' => 'knowledge_chunking_model_id', 'setting_value' => (string) $conflictModel->id]);
        Task::query()->create(['name' => 'Conflict task', 'status' => 'paused', 'ai_model_id' => $conflictModel->id]);

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])
            ->expectsOutput('Mode: dry-run')
            ->expectsOutput('Legacy owner: '.$legacyOwner->id)
            ->expectsOutput('Unowned models: 2')
            ->expectsOutput('Historical administrators to share: 1')
            ->expectsOutput('System-only models to mark: 1')
            ->expectsOutput('System/user-content conflicts: 1')
            ->assertSuccessful();

        $this->assertNull($legacyAdmin->fresh()->shared_ai_config_owner_id);
        $this->assertNull($newAdmin->fresh()->shared_ai_config_owner_id);
        $this->assertNull($systemModel->fresh()->owner_admin_id);
        $this->assertSame(AiModel::ACCESS_SCOPE_USER_CONTENT, $systemModel->fresh()->access_scope);
        $this->assertSame(AiModel::ACCESS_SCOPE_USER_CONTENT, $conflictModel->fresh()->access_scope);
    }

    public function test_apply_is_idempotent_preserves_explicit_bindings_and_keeps_new_admin_independent(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $otherOwner = $this->admin('other-owner', 'super_admin', '2026-08-01 00:00:00');
        $legacyAdmin = $this->admin('legacy-admin', 'admin', '2026-08-15 00:00:00');
        $explicitAdmin = $this->admin('explicit-admin', 'admin', '2026-08-16 00:00:00', [
            'shared_ai_config_owner_id' => $otherOwner->id,
        ]);
        $newAdmin = $this->admin('new-admin', 'admin', '2026-09-02 00:00:00');
        $unowned = $this->model(null, 'legacy-model', 'chat');
        $newModel = $this->model(null, 'new-model', 'chat', '2026-09-02 00:00:00');
        $alreadyOwned = $this->model($otherOwner, 'explicit-model', 'chat');
        $systemModel = $this->model(null, 'system-model', 'embedding');
        SiteSetting::query()->create(['setting_key' => 'default_embedding_model_id', 'setting_value' => (string) $systemModel->id]);
        DB::table('admins')->where('id', $legacyAdmin->id)->update(['ai_config_access_version' => 0]);

        $arguments = [
            '--apply' => true,
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ];
        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutput('Mode: apply')
            ->expectsOutput('Models assigned: 2')
            ->expectsOutput('Administrators shared: 1')
            ->expectsOutput('Access versions normalized: 1')
            ->expectsOutput('System-only models marked: 1')
            ->assertSuccessful();

        $this->assertSame($legacyOwner->id, $legacyAdmin->fresh()->shared_ai_config_owner_id);
        $this->assertSame(1, $legacyAdmin->fresh()->ai_config_access_version);
        $this->assertSame($otherOwner->id, $explicitAdmin->fresh()->shared_ai_config_owner_id);
        $this->assertNull($newAdmin->fresh()->shared_ai_config_owner_id);
        $this->assertNull($legacyOwner->fresh()->shared_ai_config_owner_id);
        $this->assertSame($legacyOwner->id, $unowned->fresh()->owner_admin_id);
        $this->assertNull($newModel->fresh()->owner_admin_id);
        $this->assertSame($otherOwner->id, $alreadyOwned->fresh()->owner_admin_id);
        $this->assertSame(AiModel::ACCESS_SCOPE_SYSTEM_ONLY, $systemModel->fresh()->access_scope);

        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutput('Models assigned: 0')
            ->expectsOutput('Administrators shared: 0')
            ->expectsOutput('Access versions normalized: 0')
            ->expectsOutput('System-only models marked: 0')
            ->assertSuccessful();
    }

    public function test_owner_selection_fails_safely_for_multiple_missing_inactive_and_non_super_admins(): void
    {
        $superA = $this->admin('super-a', 'super_admin', '2026-08-01 00:00:00');
        $superB = $this->admin('super-b', 'superadmin', '2026-08-01 00:00:00');
        $ordinary = $this->admin('ordinary', 'admin', '2026-08-01 00:00:00');
        $inactive = $this->admin('inactive', 'super_admin', '2026-08-01 00:00:00', ['status' => 'disabled']);
        $model = $this->model(null, 'legacy-model', 'chat');
        $base = ['--apply' => true, '--created-before' => '2026-09-01T00:00:00+08:00'];

        $this->artisan('geoflow:backfill-admin-ai-access', $base)
            ->expectsOutput('Preflight failed: multiple_active_super_admins')
            ->assertFailed();
        $this->artisan('geoflow:backfill-admin-ai-access', [...$base, '--legacy-owner' => $ordinary->id])
            ->expectsOutput('Preflight failed: legacy_owner_not_active_super_admin')
            ->assertFailed();
        $this->artisan('geoflow:backfill-admin-ai-access', [...$base, '--legacy-owner' => $inactive->id])
            ->expectsOutput('Preflight failed: legacy_owner_not_active_super_admin')
            ->assertFailed();
        $this->assertNull($model->fresh()->owner_admin_id);

        $superA->forceFill(['status' => 'disabled'])->save();
        $superB->forceFill(['status' => 'disabled'])->save();
        $this->artisan('geoflow:backfill-admin-ai-access', $base)
            ->expectsOutput('Preflight failed: no_active_super_admin')
            ->assertFailed();
        $this->assertNull($model->fresh()->owner_admin_id);
    }

    public function test_cutoff_is_required_and_must_include_an_explicit_timezone(): void
    {
        $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');

        $this->artisan('geoflow:backfill-admin-ai-access')
            ->expectsOutput('Invalid arguments: created_before_required')
            ->assertExitCode(2);
        $this->artisan('geoflow:backfill-admin-ai-access', ['--created-before' => '2026-09-01 00:00:00'])
            ->expectsOutput('Invalid arguments: created_before_timezone_required')
            ->assertExitCode(2);
    }

    public function test_null_admin_timestamps_require_an_explicit_snapshot_and_only_include_ids_at_or_below_it(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $legacyAdmin = $this->admin('legacy-null-time', 'admin', '2026-08-15 00:00:00');
        $outsideSnapshot = $this->admin('outside-snapshot', 'admin', '2026-08-16 00:00:00');
        DB::table('admins')->whereIn('id', [$legacyAdmin->id, $outsideSnapshot->id])->update([
            'created_at' => null,
        ]);

        $base = [
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ];
        $this->artisan('geoflow:backfill-admin-ai-access', $base)
            ->expectsOutput('Preflight failed: admin_max_id_required_for_null_created_at')
            ->assertFailed();

        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$base,
            '--admin-max-id' => $legacyAdmin->id,
        ])
            ->expectsOutput('Historical administrators to share: 1')
            ->assertSuccessful();

        $arguments = [...$base, '--apply' => true, '--admin-max-id' => $legacyAdmin->id];
        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutput('Admin max ID: '.$legacyAdmin->id)
            ->expectsOutput('Administrators shared: 1')
            ->assertSuccessful();

        $this->assertSame($legacyOwner->id, $legacyAdmin->fresh()->shared_ai_config_owner_id);
        $this->assertNull($outsideSnapshot->fresh()->shared_ai_config_owner_id);

        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutput('Administrators shared: 0')
            ->assertSuccessful();
    }

    public function test_null_model_timestamps_require_an_explicit_snapshot_and_only_include_ids_at_or_below_it(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $legacyModel = $this->model(null, 'legacy-null-time', 'chat');
        $outsideSnapshot = $this->model(null, 'outside-snapshot', 'chat');
        DB::table('ai_models')->whereIn('id', [$legacyModel->id, $outsideSnapshot->id])->update([
            'created_at' => null,
        ]);

        $base = [
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ];
        $this->artisan('geoflow:backfill-admin-ai-access', $base)
            ->expectsOutput('Preflight failed: model_max_id_required_for_null_created_at')
            ->assertFailed();

        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$base,
            '--model-max-id' => $legacyModel->id,
        ])
            ->expectsOutput('Unowned models: 1')
            ->assertSuccessful();

        $arguments = [...$base, '--apply' => true, '--model-max-id' => $legacyModel->id];
        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutput('Model max ID: '.$legacyModel->id)
            ->expectsOutput('Models assigned: 1')
            ->assertSuccessful();

        $this->assertSame($legacyOwner->id, $legacyModel->fresh()->owner_admin_id);
        $this->assertNull($outsideSnapshot->fresh()->owner_admin_id);

        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutput('Models assigned: 0')
            ->assertSuccessful();
    }

    public function test_snapshot_excludes_newer_ids_even_when_their_timestamp_is_before_the_cutoff(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $legacyAdmin = $this->admin('legacy-admin', 'admin', '2026-08-15 00:00:00');
        $outsideSnapshot = $this->admin('outside-snapshot', 'admin', '2026-08-16 00:00:00');
        $legacyModel = $this->model(null, 'legacy-model', 'chat');
        $outsideModelSnapshot = $this->model(null, 'outside-model-snapshot', 'chat');

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--apply' => true,
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
            '--admin-max-id' => $legacyAdmin->id,
            '--model-max-id' => $legacyModel->id,
        ])->assertSuccessful();

        $this->assertSame($legacyOwner->id, $legacyAdmin->fresh()->shared_ai_config_owner_id);
        $this->assertNull($outsideSnapshot->fresh()->shared_ai_config_owner_id);
        $this->assertSame($legacyOwner->id, $legacyModel->fresh()->owner_admin_id);
        $this->assertNull($outsideModelSnapshot->fresh()->owner_admin_id);
    }

    public function test_equivalent_cutoff_timezones_use_the_same_storage_boundary_and_report(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $included = $this->admin('included', 'admin', '2026-09-01 00:00:00');
        $excluded = $this->admin('excluded', 'admin', '2026-09-01 00:00:01');

        foreach (['2026-09-01T00:00:00+08:00', '2026-08-31T16:00:00Z'] as $cutoff) {
            $this->artisan('geoflow:backfill-admin-ai-access', [
                '--legacy-owner' => $legacyOwner->id,
                '--created-before' => $cutoff,
            ])
                ->expectsOutput('Created before: 2026-09-01T00:00:00+08:00')
                ->expectsOutput('Historical administrators to share: 1')
                ->assertSuccessful();
        }

        $this->assertNull($included->fresh()->shared_ai_config_owner_id);
        $this->assertNull($excluded->fresh()->shared_ai_config_owner_id);
    }

    public function test_apply_clears_historical_super_admin_bindings_and_advances_revocation_version(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $boundSuper = $this->admin('bound-super', 'super_admin', '2026-08-02 00:00:00', [
            'shared_ai_config_owner_id' => $legacyOwner->id,
            'ai_config_access_version' => 3,
        ]);
        $boundOrdinary = $this->admin('bound-ordinary', 'admin', '2026-08-03 00:00:00', [
            'shared_ai_config_owner_id' => $legacyOwner->id,
            'ai_config_access_version' => 4,
        ]);
        $base = [
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ];

        $this->artisan('geoflow:backfill-admin-ai-access', $base)
            ->expectsOutput('Super administrator bindings to clear: 1')
            ->assertSuccessful();
        $this->assertSame($legacyOwner->id, $boundSuper->fresh()->shared_ai_config_owner_id);
        $this->assertSame(3, $boundSuper->fresh()->ai_config_access_version);

        $this->artisan('geoflow:backfill-admin-ai-access', [...$base, '--apply' => true])
            ->expectsOutput('Super administrator bindings cleared: 1')
            ->assertSuccessful();

        $this->assertNull($boundSuper->fresh()->shared_ai_config_owner_id);
        $this->assertSame(4, $boundSuper->fresh()->ai_config_access_version);
        $this->assertSame($legacyOwner->id, $boundOrdinary->fresh()->shared_ai_config_owner_id);
        $this->assertSame(4, $boundOrdinary->fresh()->ai_config_access_version);

        $this->artisan('geoflow:backfill-admin-ai-access', [...$base, '--apply' => true])
            ->expectsOutput('Super administrator bindings cleared: 0')
            ->assertSuccessful();
    }

    public function test_rerun_does_not_restore_sharing_after_a_versioned_revocation(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $legacyAdmin = $this->admin('legacy-admin', 'admin', '2026-08-15 00:00:00');
        $arguments = [
            '--apply' => true,
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ];
        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)->assertSuccessful();
        $legacyAdmin->refresh()->forceFill([
            'shared_ai_config_owner_id' => null,
            'ai_config_access_version' => 2,
        ])->save();

        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutput('Administrators shared: 0')
            ->assertSuccessful();

        $this->assertNull($legacyAdmin->fresh()->shared_ai_config_owner_id);
        $this->assertSame(2, $legacyAdmin->fresh()->ai_config_access_version);
    }

    public function test_apply_rolls_back_every_change_when_any_backfill_write_fails(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The deterministic failure trigger is SQLite-specific.');
        }

        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $legacyAdmin = $this->admin('legacy-admin', 'admin', '2026-08-15 00:00:00');
        $model = $this->model(null, 'legacy-model', 'chat');
        DB::unprepared(sprintf(
            "CREATE TRIGGER fail_admin_ai_backfill BEFORE UPDATE OF shared_ai_config_owner_id ON admins WHEN NEW.id = %d BEGIN SELECT RAISE(ABORT, 'forced rollback'); END",
            $legacyAdmin->id,
        ));

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--apply' => true,
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])->assertFailed();

        $this->assertNull($model->fresh()->owner_admin_id);
        $this->assertNull($legacyAdmin->fresh()->shared_ai_config_owner_id);
    }

    public function test_report_exposes_stable_findings_without_credentials_or_raw_setting_values(): void
    {
        $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $model = $this->model(null, 'credential-model', 'chat');
        SiteSetting::query()->create([
            'setting_key' => 'ai_visibility_ark_model_id',
            'setting_value' => 'https://secret.example.test/?key=raw-secret',
        ]);

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])
            ->expectsOutput('Invalid system binding: ai_visibility_ark_model_id (invalid_model_id)')
            ->doesntExpectOutputToContain('raw-secret')
            ->doesntExpectOutputToContain((string) $model->api_key)
            ->doesntExpectOutputToContain((string) $model->api_url)
            ->assertSuccessful();
    }

    public function test_each_dangling_system_binding_is_reported_and_overflow_ids_are_rejected(): void
    {
        $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        SiteSetting::query()->create([
            'setting_key' => 'default_embedding_model_id',
            'setting_value' => '999999',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunking_model_id',
            'setting_value' => '999999',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'ai_visibility_ark_model_id',
            'setting_value' => '9999999999999999999999999999999999999999',
        ]);

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])
            ->expectsOutput('Invalid system bindings: 3')
            ->expectsOutput('Invalid system binding: ai_visibility_ark_model_id (invalid_model_id)')
            ->expectsOutput('Invalid system binding: default_embedding_model_id (model_not_found)')
            ->expectsOutput('Invalid system binding: knowledge_chunking_model_id (model_not_found)')
            ->assertSuccessful();
    }

    /** @param array<string, mixed> $overrides */
    private function admin(
        string $username,
        string $role,
        string $createdAt,
        array $overrides = [],
    ): Admin {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => ucfirst($username),
            'role' => $role,
            'status' => 'active',
        ]);
        $admin->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
            ...$overrides,
        ])->save();

        return $admin->refresh();
    }

    private function model(
        ?Admin $owner,
        string $modelId,
        string $type,
        string $createdAt = '2026-08-10 00:00:00',
    ): AiModel {
        $model = new AiModel([
            'name' => $modelId,
            'version' => 'test',
            'api_key' => 'secret-'.$modelId,
            'model_id' => $modelId,
            'model_type' => $type,
            'api_url' => 'https://sensitive.example.test/'.$modelId,
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner?->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $model;
    }
}
