<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiQualityCheck;
use App\Models\Author;
use App\Models\Category;
use App\Models\EnterpriseKnowledgeProject;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\UrlImportJob;
use App\Services\Admin\AdminAiAccessBackfillService;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class BackfillAdminAiAccessCommandTest extends TestCase
{
    use RefreshDatabase;

    private MaintenanceModeContract $maintenanceMode;

    protected function setUp(): void
    {
        parent::setUp();

        $this->maintenanceMode = new InMemoryMaintenanceMode(true);
        $this->app->instance(MaintenanceModeContract::class, $this->maintenanceMode);
    }

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
            '--maintenance-confirmed' => true,
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

    public function test_apply_backfills_non_task_execution_identities_and_freezes_unattributed_active_work(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $creator = $this->admin('known-creator', 'admin', '2026-08-01 00:00:00', [
            'ai_config_access_version' => 7,
        ]);
        $model = $this->model($creator, 'known-creator-model', 'chat');
        $project = EnterpriseKnowledgeProject::query()->create([
            'name' => 'Historical enterprise draft',
            'status' => 'queued',
            'ai_model_id' => $model->id,
            'created_by_admin_id' => $creator->id,
            'created_at' => '2026-08-15 00:00:00',
            'updated_at' => '2026-08-15 00:00:00',
        ]);
        $project->forceFill([
            'created_at' => '2026-08-15 00:00:00',
            'updated_at' => '2026-08-15 00:00:00',
        ])->saveQuietly();
        $job = UrlImportJob::query()->create([
            'url' => 'https://historical.example.test',
            'normalized_url' => 'https://historical.example.test/',
            'status' => 'queued',
            'created_by' => 'missing-creator',
            'created_at' => '2026-08-15 00:00:00',
            'updated_at' => '2026-08-15 00:00:00',
        ]);
        $job->forceFill([
            'created_at' => '2026-08-15 00:00:00',
            'updated_at' => '2026-08-15 00:00:00',
        ])->saveQuietly();

        $preview = app(AdminAiAccessBackfillService::class)->preview(
            $legacyOwner->id,
            CarbonImmutable::parse('2026-09-01T00:00:00+08:00'),
            null,
            null,
        );
        $this->assertSame(1, $preview['lifecycle_identities_recovered_from_creators']);
        $this->assertSame(1, $preview['lifecycle_identities_mapped_to_legacy_owner']);

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--apply' => true,
            '--maintenance-confirmed' => true,
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])
            ->expectsOutput('Lifecycle identities recovered from creators: 1')
            ->expectsOutput('Lifecycle identities mapped to legacy owner: 1')
            ->expectsOutput('Unattributed active lifecycle records frozen: 1')
            ->assertSuccessful();

        $project->refresh();
        $this->assertSame($creator->id, $project->model_access_admin_id);
        $this->assertSame('admin', $project->model_access_admin_role);
        $this->assertSame(7, $project->ai_config_access_version);
        $this->assertSame($model->id, $project->requested_ai_model_id);

        $job->refresh();
        $this->assertSame($legacyOwner->id, $job->model_access_admin_id);
        $this->assertSame('failed', $job->status);
        $this->assertFalse((bool) $job->retryable_failure);
        $this->assertSame('ai_historical_identity_unresolved', $job->error_code);
    }

    public function test_owner_selection_fails_safely_for_multiple_missing_inactive_and_non_super_admins(): void
    {
        $superA = $this->admin('super-a', 'super_admin', '2026-08-01 00:00:00');
        $superB = $this->admin('super-b', 'superadmin', '2026-08-01 00:00:00');
        $ordinary = $this->admin('ordinary', 'admin', '2026-08-01 00:00:00');
        $inactive = $this->admin('inactive', 'super_admin', '2026-08-01 00:00:00', ['status' => 'disabled']);
        $model = $this->model(null, 'legacy-model', 'chat');
        $base = [
            '--apply' => true,
            '--maintenance-confirmed' => true,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ];

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

        $arguments = [
            ...$base,
            '--apply' => true,
            '--maintenance-confirmed' => true,
            '--admin-max-id' => $legacyAdmin->id,
        ];
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

        $arguments = [
            ...$base,
            '--apply' => true,
            '--maintenance-confirmed' => true,
            '--model-max-id' => $legacyModel->id,
        ];
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
            '--maintenance-confirmed' => true,
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

        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$base,
            '--apply' => true,
            '--maintenance-confirmed' => true,
        ])
            ->expectsOutput('Super administrator bindings cleared: 1')
            ->assertSuccessful();

        $this->assertNull($boundSuper->fresh()->shared_ai_config_owner_id);
        $this->assertSame(4, $boundSuper->fresh()->ai_config_access_version);
        $this->assertSame($legacyOwner->id, $boundOrdinary->fresh()->shared_ai_config_owner_id);
        $this->assertSame(4, $boundOrdinary->fresh()->ai_config_access_version);

        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$base,
            '--apply' => true,
            '--maintenance-confirmed' => true,
        ])
            ->expectsOutput('Super administrator bindings cleared: 0')
            ->assertSuccessful();
    }

    public function test_rerun_does_not_restore_sharing_after_a_versioned_revocation(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $legacyAdmin = $this->admin('legacy-admin', 'admin', '2026-08-15 00:00:00');
        $arguments = [
            '--apply' => true,
            '--maintenance-confirmed' => true,
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
            '--maintenance-confirmed' => true,
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

    public function test_apply_requires_maintenance_mode_and_explicit_operator_confirmation_without_writes(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $model = $this->model(null, 'legacy-model', 'chat');
        $base = [
            '--apply' => true,
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ];

        $this->artisan('geoflow:backfill-admin-ai-access', $base)
            ->expectsOutput('Preflight failed: maintenance_confirmation_required')
            ->expectsOutput('Required: stop Web and AI workers, run php artisan down, then pass --maintenance-confirmed.')
            ->assertFailed();
        $this->assertNull($model->fresh()->owner_admin_id);

        $this->maintenanceMode->deactivate();
        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$base,
            '--maintenance-confirmed' => true,
        ])
            ->expectsOutput('Preflight failed: application_maintenance_mode_required')
            ->expectsOutput('Required: stop Web and AI workers, run php artisan down, then pass --maintenance-confirmed.')
            ->assertFailed();
        $this->assertNull($model->fresh()->owner_admin_id);
    }

    public function test_admin_ai_defaults_make_a_system_binding_a_stable_user_content_conflict(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $consumer = $this->admin('consumer', 'admin', '2026-08-02 00:00:00');
        $mixedModel = $this->model(null, 'mixed-model', 'chat');
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunking_model_id',
            'setting_value' => (string) $mixedModel->id,
        ]);
        $setting = new AdminAiSetting;
        $setting->forceFill([
            'admin_id' => $consumer->id,
            'default_chat_model_id' => $mixedModel->id,
            'updated_by_admin_id' => $legacyOwner->id,
        ])->save();
        $arguments = [
            '--apply' => true,
            '--maintenance-confirmed' => true,
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ];

        foreach ([1, 2] as $run) {
            $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
                ->expectsOutput('System/user-content conflicts: 1')
                ->expectsOutput('Conflict model ID: '.$mixedModel->id)
                ->assertSuccessful();
            $this->assertSame(
                AiModel::ACCESS_SCOPE_USER_CONTENT,
                $mixedModel->fresh()->access_scope,
                'Run '.$run.' must preserve the mixed model scope.',
            );
        }
    }

    public function test_active_optimization_json_references_block_system_scope_while_terminal_history_does_not(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $queuedModel = $this->model(null, 'queued-model', 'chat');
        $awaitingModel = $this->model(null, 'awaiting-model', 'chat');
        $terminalModel = $this->model(null, 'terminal-model', 'chat');
        $embeddingModel = $this->model(null, 'embedding-model', 'embedding');
        foreach ([
            'default_embedding_model_id' => $embeddingModel,
            'knowledge_chunking_model_id' => $queuedModel,
            'ai_visibility_ark_model_id' => $awaitingModel,
            'ai_visibility_deepseek_analysis_model_id' => $terminalModel,
        ] as $settingKey => $model) {
            SiteSetting::query()->create([
                'setting_key' => $settingKey,
                'setting_value' => (string) $model->id,
            ]);
        }

        $article = $this->article();
        $this->optimizationRun($article, ArticleAiOptimizationRun::STATUS_QUEUED, [
            'optimization_model_ids' => [$queuedModel->id],
        ]);
        $this->optimizationRun($article, ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY, [
            'optimization_model_id' => $awaitingModel->id,
        ]);
        $this->optimizationRun($article, ArticleAiOptimizationRun::STATUS_COMPLETED, [
            'optimization_model_ids' => [$terminalModel->id],
        ]);
        $this->optimizationRun($article, ArticleAiOptimizationRun::STATUS_FAILED, [
            'optimization_model_id' => $terminalModel->id,
        ]);

        $arguments = [
            '--apply' => true,
            '--maintenance-confirmed' => true,
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ];

        foreach ([1, 2] as $run) {
            $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
                ->expectsOutput('System/user-content conflicts: 2')
                ->expectsOutput('Historical structured model references: 2')
                ->assertSuccessful();

            $this->assertSame(
                AiModel::ACCESS_SCOPE_USER_CONTENT,
                $queuedModel->fresh()->access_scope,
                'Queued run '.$run.' must preserve the model scope.',
            );
            $this->assertSame(
                AiModel::ACCESS_SCOPE_USER_CONTENT,
                $awaitingModel->fresh()->access_scope,
                'Awaiting run '.$run.' must preserve the model scope.',
            );
            $this->assertSame(AiModel::ACCESS_SCOPE_SYSTEM_ONLY, $terminalModel->fresh()->access_scope);
            $this->assertSame(AiModel::ACCESS_SCOPE_SYSTEM_ONLY, $embeddingModel->fresh()->access_scope);
        }
    }

    public function test_active_optimization_quality_candidate_ids_remain_user_content(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $qualityCandidate = $this->model(null, 'active-quality-candidate', 'chat');
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunking_model_id',
            'setting_value' => (string) $qualityCandidate->id,
        ]);
        $this->optimizationRun(
            $this->article(),
            ArticleAiOptimizationRun::STATUS_QUEUED,
            ['quality_model_candidate_ids' => [$qualityCandidate->id]],
        );

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])
            ->expectsOutput('System-only models to mark: 0')
            ->expectsOutput('System/user-content conflicts: 1')
            ->assertSuccessful();
    }

    public function test_structured_json_findings_are_stable_and_never_echo_raw_values(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $mixedModel = $this->model(null, 'mixed-model', 'chat');
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunking_model_id',
            'setting_value' => (string) $mixedModel->id,
        ]);
        $article = $this->article();
        $this->optimizationRun($article, ArticleAiOptimizationRun::STATUS_QUEUED, [
            'optimization_model_id' => (string) $mixedModel->id,
            'optimization_model_ids' => [$mixedModel->id, 'raw-secret-model', 999999],
            'provider_model_id' => 'provider-secret-model',
        ]);

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])
            ->expectsOutput('System/user-content conflicts: 1')
            ->expectsOutput('Structured model reference findings: 2')
            ->expectsOutputToContain('execution_meta.optimization_model_ids (active:invalid_model_id)')
            ->expectsOutputToContain('execution_meta.optimization_model_ids (active:model_not_found)')
            ->doesntExpectOutputToContain('raw-secret-model')
            ->doesntExpectOutputToContain('provider-secret-model')
            ->assertSuccessful();
    }

    public function test_active_invalid_structured_references_abort_apply_without_writes(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $legacyAdmin = $this->admin('legacy-admin', 'admin', '2026-08-02 00:00:00');
        $systemModel = $this->model(null, 'system-model', 'chat');
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunking_model_id',
            'setting_value' => (string) $systemModel->id,
        ]);
        $article = $this->article();
        $qualityPrimary = $this->model($legacyOwner, 'quality-primary', 'chat');
        $malformed = $this->optimizationRun($article, ArticleAiOptimizationRun::STATUS_QUEUED, []);
        $nonArray = $this->optimizationRun($article, ArticleAiOptimizationRun::STATUS_AWAITING_QUALITY, []);
        $mixed = $this->optimizationRun($article, ArticleAiOptimizationRun::STATUS_QUEUED, []);
        $historical = $this->optimizationRun($article, ArticleAiOptimizationRun::STATUS_COMPLETED, []);
        $qualityMalformed = $this->qualityCheck($article, $qualityPrimary, 'queued', $qualityPrimary);
        DB::table('article_ai_optimization_runs')->where('id', $malformed->id)->update([
            'execution_meta' => '{"optimization_model_id":'.$systemModel->id,
        ]);
        DB::table('article_ai_optimization_runs')->where('id', $nonArray->id)->update([
            'execution_meta' => '42',
        ]);
        DB::table('article_ai_optimization_runs')->where('id', $mixed->id)->update([
            'execution_meta' => json_encode([
                'optimization_model_ids' => [$systemModel->id, -1, 'sensitive-invalid-id', 999999],
            ], JSON_THROW_ON_ERROR),
        ]);
        DB::table('article_ai_optimization_runs')->where('id', $historical->id)->update([
            'execution_meta' => '{"optimization_model_id":"historical-sensitive-value"',
        ]);
        DB::table('article_ai_quality_checks')->where('id', $qualityMalformed->id)->update([
            'execution_meta' => null,
            'model_snapshot' => '{"candidate_ids":["quality-sensitive-value"',
        ]);

        $base = [
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ];
        $this->artisan('geoflow:backfill-admin-ai-access', $base)
            ->expectsOutput('System/user-content conflicts: 1')
            ->expectsOutput('Historical structured model references: 0')
            ->expectsOutput('Structured model reference findings: 6')
            ->expectsOutputToContain('article_ai_optimization_runs#'.$malformed->id.' execution_meta (active:invalid_json)')
            ->expectsOutputToContain('article_ai_optimization_runs#'.$nonArray->id.' execution_meta (active:invalid_json)')
            ->expectsOutputToContain('article_ai_optimization_runs#'.$mixed->id.' execution_meta.optimization_model_ids (active:invalid_model_id)')
            ->expectsOutputToContain('article_ai_optimization_runs#'.$mixed->id.' execution_meta.optimization_model_ids (active:model_not_found)')
            ->expectsOutputToContain('article_ai_optimization_runs#'.$historical->id.' execution_meta (historical:invalid_json)')
            ->expectsOutputToContain('article_ai_quality_checks#'.$qualityMalformed->id.' model_snapshot (active:invalid_json)')
            ->doesntExpectOutputToContain('sensitive-invalid-id')
            ->doesntExpectOutputToContain('historical-sensitive-value')
            ->doesntExpectOutputToContain('quality-sensitive-value')
            ->assertSuccessful();

        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$base,
            '--apply' => true,
            '--maintenance-confirmed' => true,
        ])
            ->expectsOutput('Preflight failed: active_structured_model_reference_invalid')
            ->doesntExpectOutputToContain('sensitive-invalid-id')
            ->doesntExpectOutputToContain('historical-sensitive-value')
            ->doesntExpectOutputToContain('quality-sensitive-value')
            ->assertFailed();

        $this->assertNull($systemModel->fresh()->owner_admin_id);
        $this->assertSame(AiModel::ACCESS_SCOPE_USER_CONTENT, $systemModel->fresh()->access_scope);
        $this->assertNull($legacyAdmin->fresh()->shared_ai_config_owner_id);
    }

    public function test_terminal_invalid_structured_references_are_reported_without_blocking_apply(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $systemModel = $this->model(null, 'system-model', 'chat');
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunking_model_id',
            'setting_value' => (string) $systemModel->id,
        ]);
        $article = $this->article();
        $malformed = $this->optimizationRun($article, ArticleAiOptimizationRun::STATUS_COMPLETED, []);
        $invalidIds = $this->optimizationRun($article, ArticleAiOptimizationRun::STATUS_FAILED, []);
        DB::table('article_ai_optimization_runs')->where('id', $malformed->id)->update([
            'execution_meta' => '{"optimization_model_id":"historical-sensitive-value"',
        ]);
        DB::table('article_ai_optimization_runs')->where('id', $invalidIds->id)->update([
            'execution_meta' => json_encode([
                'optimization_model_ids' => [-1, 999999],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--apply' => true,
            '--maintenance-confirmed' => true,
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])
            ->expectsOutput('Mode: apply')
            ->expectsOutput('System-only models to mark: 1')
            ->expectsOutput('Historical structured model references: 1')
            ->expectsOutput('Structured model reference findings: 3')
            ->expectsOutputToContain('article_ai_optimization_runs#'.$malformed->id.' execution_meta (historical:invalid_json)')
            ->expectsOutputToContain('article_ai_optimization_runs#'.$invalidIds->id.' execution_meta.optimization_model_ids (historical:invalid_model_id)')
            ->expectsOutputToContain('article_ai_optimization_runs#'.$invalidIds->id.' execution_meta.optimization_model_ids (historical:model_not_found)')
            ->doesntExpectOutputToContain('historical-sensitive-value')
            ->expectsOutput('System-only models marked: 1')
            ->assertSuccessful();

        $this->assertSame($legacyOwner->id, $systemModel->fresh()->owner_admin_id);
        $this->assertSame(AiModel::ACCESS_SCOPE_SYSTEM_ONLY, $systemModel->fresh()->access_scope);
    }

    public function test_active_quality_fallback_and_article_policy_snapshots_remain_user_content(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $policyModel = $this->model(null, 'policy-model', 'chat');
        $fallbackModel = $this->model(null, 'fallback-model', 'chat');
        $historicalModel = $this->model(null, 'historical-model', 'chat');
        $primaryModel = $this->model($legacyOwner, 'primary-model', 'chat');
        foreach ([
            'knowledge_chunking_model_id' => $policyModel,
            'ai_visibility_ark_model_id' => $fallbackModel,
            'ai_visibility_deepseek_analysis_model_id' => $historicalModel,
        ] as $settingKey => $model) {
            SiteSetting::query()->create([
                'setting_key' => $settingKey,
                'setting_value' => (string) $model->id,
            ]);
        }

        $article = $this->article();
        $article->forceFill([
            'ai_quality_required_at_creation' => true,
            'ai_quality_policy_snapshot' => [
                'required' => true,
                'model_id' => $policyModel->id,
            ],
        ])->save();
        $this->qualityCheck($article, $primaryModel, 'queued', $fallbackModel);
        $this->qualityCheck($article, $primaryModel, 'completed', $historicalModel);

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])
            ->expectsOutput('System-only models to mark: 1')
            ->expectsOutput('System/user-content conflicts: 2')
            ->expectsOutput('Structured model reference findings: 0')
            ->doesntExpectOutputToContain('provider-sensitive-name')
            ->assertSuccessful();
    }

    public function test_article_snapshot_does_not_block_a_model_replaced_by_an_enabled_task_policy(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $staleSnapshotModel = $this->model(null, 'stale-snapshot-model', 'chat');
        $currentTaskModel = $this->model($legacyOwner, 'current-task-model', 'chat');
        SiteSetting::query()->create([
            'setting_key' => 'knowledge_chunking_model_id',
            'setting_value' => (string) $staleSnapshotModel->id,
        ]);
        $task = Task::query()->create([
            'name' => 'Current quality task',
            'status' => 'paused',
            'ai_model_id' => $currentTaskModel->id,
            'ai_quality_model_id' => $currentTaskModel->id,
            'ai_quality_enabled' => true,
        ]);
        $article = $this->article();
        $article->forceFill([
            'task_id' => $task->id,
            'ai_quality_required_at_creation' => true,
            'ai_quality_policy_snapshot' => [
                'required' => true,
                'model_id' => $staleSnapshotModel->id,
            ],
        ])->save();

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])
            ->expectsOutput('System-only models to mark: 1')
            ->expectsOutput('System/user-content conflicts: 0')
            ->assertSuccessful();

        $task->forceFill(['ai_quality_enabled' => false])->save();
        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])
            ->expectsOutput('System-only models to mark: 0')
            ->expectsOutput('System/user-content conflicts: 1')
            ->assertSuccessful();
    }

    public function test_apply_rebuilds_the_reference_plan_after_preview_and_uses_the_current_binding(): void
    {
        $legacyOwner = $this->admin('legacy-owner', 'super_admin', '2026-08-01 00:00:00');
        $previewModel = $this->model(null, 'preview-model', 'embedding');
        $applyModel = $this->model(null, 'apply-model', 'embedding');
        $setting = SiteSetting::query()->create([
            'setting_key' => 'default_embedding_model_id',
            'setting_value' => (string) $previewModel->id,
        ]);
        $base = [
            '--legacy-owner' => $legacyOwner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ];

        $this->artisan('geoflow:backfill-admin-ai-access', $base)
            ->expectsOutput('System-only models to mark: 1')
            ->assertSuccessful();
        $setting->forceFill(['setting_value' => (string) $applyModel->id])->save();

        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$base,
            '--apply' => true,
            '--maintenance-confirmed' => true,
        ])
            ->expectsOutput('System-only models marked: 1')
            ->assertSuccessful();

        $this->assertSame(AiModel::ACCESS_SCOPE_USER_CONTENT, $previewModel->fresh()->access_scope);
        $this->assertSame(AiModel::ACCESS_SCOPE_SYSTEM_ONLY, $applyModel->fresh()->access_scope);
    }

    public function test_automatic_owner_query_handles_historical_role_format_and_limits_candidates(): void
    {
        $owner = $this->admin('legacy-owner', ' Super_Admin ', '2026-08-01 00:00:00');
        foreach (range(1, 5) as $index) {
            $this->admin('ordinary-'.$index, 'admin', '2026-08-02 00:00:00');
        }
        DB::enableQueryLog();

        $this->artisan('geoflow:backfill-admin-ai-access', [
            '--created-before' => '2026-09-01T00:00:00+08:00',
        ])
            ->expectsOutput('Legacy owner: '.$owner->id)
            ->expectsOutput('Historical administrators to share: 5')
            ->assertSuccessful();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertTrue(collect($queries)->contains(static function (array $query): bool {
            $sql = strtolower((string) $query['query']);

            return str_contains($sql, 'from "admins"')
                && str_contains($sql, 'lower(trim(role)) in')
                && str_contains($sql, 'limit 2');
        }));
        $this->assertTrue(collect($queries)->contains(static function (array $query): bool {
            $sql = strtolower((string) $query['query']);

            return str_contains($sql, 'select count(*) as aggregate from "admins"')
                && str_contains($sql, 'lower(trim(role)) not in');
        }));
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

    private function article(): Article
    {
        $suffix = Str::lower(Str::random(10));
        $author = Author::query()->create([
            'name' => 'Author '.$suffix,
            'email' => $suffix.'@example.test',
        ]);
        $category = Category::query()->create([
            'name' => 'Category '.$suffix,
            'slug' => 'category-'.$suffix,
        ]);

        return Article::query()->create([
            'title' => 'Article '.$suffix,
            'slug' => 'article-'.$suffix,
            'excerpt' => 'Excerpt',
            'content' => 'Content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'keywords' => 'keyword',
            'meta_description' => 'Description',
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
    }

    /** @param array<string, mixed> $executionMeta */
    private function optimizationRun(
        Article $article,
        string $status,
        array $executionMeta,
    ): ArticleAiOptimizationRun {
        return ArticleAiOptimizationRun::query()->create([
            'article_id' => $article->id,
            'request_key' => (string) Str::uuid(),
            'trigger' => ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
            'status' => $status,
            'base_article_hash' => hash('sha256', (string) Str::uuid()),
            'policy_hash' => hash('sha256', (string) Str::uuid()),
            'execution_meta' => $executionMeta,
        ]);
    }

    private function qualityCheck(
        Article $article,
        AiModel $primaryModel,
        string $status,
        AiModel $candidateModel,
    ): ArticleAiQualityCheck {
        return ArticleAiQualityCheck::query()->create([
            'article_id' => $article->id,
            'ai_model_id' => $primaryModel->id,
            'request_key' => (string) Str::uuid(),
            'status' => $status,
            'input_fingerprint' => hash('sha256', (string) Str::uuid()),
            'algorithm_version' => 'test-v1',
            'execution_meta' => [
                'model_candidate_ids' => [$candidateModel->id],
            ],
            'model_snapshot' => [
                'id' => $primaryModel->id,
                'model_id' => 'provider-sensitive-name',
                'candidate_ids' => [$candidateModel->id],
            ],
        ]);
    }
}

final class InMemoryMaintenanceMode implements MaintenanceModeContract
{
    /** @param array<string, mixed> $data */
    public function __construct(private bool $active, private array $data = []) {}

    public function activate(array $payload): void
    {
        $this->active = true;
        $this->data = $payload;
    }

    public function deactivate(): void
    {
        $this->active = false;
        $this->data = [];
    }

    public function active(): bool
    {
        return $this->active;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return $this->data;
    }
}
