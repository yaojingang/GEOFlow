<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiQualityAuditEvent;
use App\Models\SystemState;
use App\Models\Task;
use App\Models\TaskRun;
use Illuminate\Contracts\Foundation\MaintenanceMode as MaintenanceModeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('historical-task-identity-backfill')]
class HistoricalTaskExecutionIdentityBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(MaintenanceModeContract::class, new TaskBackfillMaintenanceMode);
    }

    public function test_apply_recovers_task_from_unique_creation_audit_and_copies_the_snapshot_to_runs(): void
    {
        $owner = $this->admin('owner', 'super_admin', 7);
        $creator = $this->admin('creator', 'admin', 12);
        $model = $this->model($creator);
        $task = $this->task(['ai_model_id' => $model->id]);
        $run = $this->taskRun($task, ['status' => 'completed']);
        $this->creationAudit($task, $creator);

        $arguments = $this->applyArguments($owner, $task, $run);
        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutput('Tasks recovered from creation audit: 1')
            ->expectsOutput('Run identities to inherit: 1')
            ->expectsOutput('Execution identity blocking conflicts: 0')
            ->assertSuccessful();

        $task->refresh();
        $run->refresh();
        $this->assertSame($creator->id, $task->model_access_admin_id);
        $this->assertSame('admin', $task->model_access_admin_role);
        $this->assertSame(1, $task->model_access_policy_version);
        $this->assertSame($creator->id, $run->model_access_admin_id);
        $this->assertSame('admin', $run->model_access_admin_role);
        $this->assertSame(12, $run->ai_config_access_version);
        $this->assertSame($model->id, $run->requested_ai_model_id);
        $this->assertSame(1, $run->resolver_policy_version);
    }

    public function test_consistent_historical_run_identity_recovers_task_and_existing_snapshots_are_preserved(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $executionAdmin = $this->admin('execution-admin', 'admin', 9);
        $oldModel = $this->model($executionAdmin, 'old-model');
        $newModel = $this->model($executionAdmin, 'new-model');
        $task = $this->task(['ai_model_id' => $newModel->id]);
        $run = $this->taskRun($task, [
            'status' => 'completed',
            'model_access_admin_id' => $executionAdmin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => 4,
            'requested_ai_model_id' => $oldModel->id,
            'resolver_policy_version' => 1,
        ]);

        $this->artisan('geoflow:backfill-admin-ai-access', $this->applyArguments($owner, $task, $run))
            ->expectsOutput('Tasks recovered from historical runs: 1')
            ->assertSuccessful();

        $this->assertSame($executionAdmin->id, $task->fresh()->model_access_admin_id);
        $this->assertSame(4, $run->fresh()->ai_config_access_version);
        $this->assertSame($oldModel->id, $run->fresh()->requested_ai_model_id);
    }

    public function test_existing_task_identity_is_authoritative_for_an_empty_historical_run(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $executionAdmin = $this->admin('execution-admin', 'admin', 8);
        $oldModel = $this->model($executionAdmin, 'existing-old-model');
        $model = $this->model($executionAdmin, 'existing-current-model');
        $task = $this->task([
            'ai_model_id' => $model->id,
            'model_access_admin_id' => $executionAdmin->id,
            'model_access_admin_role' => 'admin',
            'model_access_policy_version' => 1,
        ]);
        $run = $this->taskRun($task, [
            'status' => 'completed',
            'requested_ai_model_id' => $oldModel->id,
        ]);

        $this->artisan('geoflow:backfill-admin-ai-access', $this->applyArguments($owner, $task, $run))
            ->expectsOutput('Tasks recovered: 0')
            ->expectsOutput('Task run identities inherited: 1')
            ->assertSuccessful();

        $this->assertSame($executionAdmin->id, $task->fresh()->model_access_admin_id);
        $this->assertSame($executionAdmin->id, $run->fresh()->model_access_admin_id);
        $this->assertSame(8, $run->fresh()->ai_config_access_version);
        $this->assertSame($oldModel->id, $run->fresh()->requested_ai_model_id);
    }

    public function test_conflicting_or_partial_authoritative_identity_is_reported_and_blocks_apply_without_overwriting(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $adminA = $this->admin('admin-a', 'admin', 2);
        $adminB = $this->admin('admin-b', 'admin', 3);
        $task = $this->task([
            'model_access_admin_id' => $adminA->id,
            'model_access_admin_role' => null,
            'model_access_policy_version' => 1,
        ]);
        $run = $this->taskRun($task, [
            'model_access_admin_id' => $adminB->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => 3,
            'resolver_policy_version' => 1,
        ]);
        $arguments = $this->baseArguments($owner, $task, $run);

        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutput('Execution identity blocking conflicts: 2')
            ->expectsOutputToContain('Task execution identity finding: task#'.$task->id.' blocking (partial_task_identity)')
            ->expectsOutputToContain('Task execution identity finding: task_run#'.$run->id.' blocking (run_task_identity_conflict)')
            ->assertSuccessful();

        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$arguments,
            '--apply' => true,
            '--maintenance-confirmed' => true,
        ])
            ->expectsOutput('Preflight failed: historical_task_execution_identity_conflict')
            ->assertFailed();

        $this->assertSame($adminA->id, $task->fresh()->model_access_admin_id);
        $this->assertNull($task->fresh()->model_access_admin_role);
        $this->assertSame($adminB->id, $run->fresh()->model_access_admin_id);
    }

    public function test_unresolved_terminal_history_uses_legacy_owner_and_is_reported_for_manual_review(): void
    {
        $owner = $this->admin('owner', 'super_admin', 5);
        $task = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $run = $this->taskRun($task, ['status' => 'completed']);

        $this->artisan('geoflow:backfill-admin-ai-access', $this->applyArguments($owner, $task, $run))
            ->expectsOutput('Tasks mapped to legacy owner: 1')
            ->expectsOutput('Manual execution identity findings: 1')
            ->expectsOutputToContain('Task execution identity finding: task#'.$task->id.' manual_review (legacy_owner_inferred_terminal_history)')
            ->assertSuccessful();

        $this->assertSame($owner->id, $task->fresh()->model_access_admin_id);
        $this->assertSame('super_admin', $task->fresh()->model_access_admin_role);
        $this->assertSame($owner->id, $run->fresh()->model_access_admin_id);
        $this->assertSame(5, $run->fresh()->ai_config_access_version);
    }

    public function test_run_inherits_the_access_version_after_legacy_owner_binding_cleanup(): void
    {
        $otherSuper = $this->admin('other-super', 'super_admin', 1);
        $owner = $this->admin('owner', 'super_admin', 5, [
            'shared_ai_config_owner_id' => $otherSuper->id,
        ]);
        $task = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $run = $this->taskRun($task, ['status' => 'completed']);

        $this->artisan('geoflow:backfill-admin-ai-access', $this->applyArguments($owner, $task, $run))
            ->expectsOutput('Super administrator bindings cleared: 1')
            ->assertSuccessful();

        $this->assertNull($owner->fresh()->shared_ai_config_owner_id);
        $this->assertSame(6, $owner->fresh()->ai_config_access_version);
        $this->assertSame(6, $run->fresh()->ai_config_access_version);
    }

    public function test_unresolved_active_history_is_paused_and_pending_or_running_runs_are_frozen(): void
    {
        $owner = $this->admin('owner', 'super_admin', 2);
        $task = $this->task([
            'status' => 'active',
            'schedule_enabled' => 1,
            'next_run_at' => '2026-08-25 00:00:00',
        ]);
        $pending = $this->taskRun($task, ['status' => 'pending']);
        $running = $this->taskRun($task, [
            'status' => 'running',
            'execution_lease_token' => (string) Str::uuid(),
            'started_at' => '2026-08-20 00:00:00',
        ]);

        $this->artisan('geoflow:backfill-admin-ai-access', $this->applyArguments($owner, $task, $running))
            ->expectsOutput('Legacy-inferred tasks paused: 1')
            ->expectsOutput('Legacy-inferred active runs frozen: 2')
            ->assertSuccessful();

        $task->refresh();
        $this->assertSame('paused', $task->status);
        $this->assertSame(0, $task->schedule_enabled);
        $this->assertNull($task->next_run_at);
        foreach ([$pending, $running] as $run) {
            $run->refresh();
            $this->assertSame('cancelled', $run->status);
            $this->assertSame('ai_historical_identity_unresolved', $run->error_code);
            $this->assertNull($run->execution_lease_token);
            $this->assertNotNull($run->finished_at);
        }
    }

    public function test_task_and_run_snapshots_handle_null_timestamps_soft_deletes_and_exclude_later_ids(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $included = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $includedRun = $this->taskRun($included, ['status' => 'completed']);
        $afterCutoff = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $afterCutoffRun = $this->taskRun($afterCutoff, ['status' => 'completed']);
        $afterCutoff->forceFill([
            'created_at' => '2026-09-02 00:00:00',
            'updated_at' => '2026-09-02 00:00:00',
        ])->save();
        $afterCutoffRun->forceFill(['created_at' => '2026-09-02 00:00:00'])->save();
        $excluded = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $excludedRun = $this->taskRun($excluded, ['status' => 'completed']);
        DB::table('tasks')->whereIn('id', [$included->id, $excluded->id])->update(['created_at' => null]);
        DB::table('task_runs')->whereIn('id', [$includedRun->id, $excludedRun->id])->update(['created_at' => null]);
        DB::table('tasks')->where('id', $included->id)->update(['deleted_at' => '2026-08-22 00:00:00']);

        $base = [
            '--legacy-owner' => $owner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
            '--admin-max-id' => Admin::query()->max('id'),
            '--model-max-id' => AiModel::query()->max('id'),
        ];
        $this->artisan('geoflow:backfill-admin-ai-access', $base)
            ->expectsOutput('Preflight failed: task_max_id_required_for_null_created_at')
            ->assertFailed();
        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$base,
            '--task-max-id' => $afterCutoff->id,
        ])
            ->expectsOutput('Preflight failed: task_run_max_id_required_for_null_created_at')
            ->assertFailed();

        $arguments = [
            ...$base,
            '--task-max-id' => $afterCutoff->id,
            '--task-run-max-id' => $afterCutoffRun->id,
            '--apply' => true,
            '--maintenance-confirmed' => true,
        ];
        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutput('Task max ID: '.$afterCutoff->id)
            ->expectsOutput('Task run max ID: '.$afterCutoffRun->id)
            ->expectsOutput('Tasks mapped to legacy owner: 1')
            ->assertSuccessful();

        $this->assertSame($owner->id, Task::withTrashed()->findOrFail($included->id)->model_access_admin_id);
        $this->assertNull($afterCutoff->fresh()->model_access_admin_id);
        $this->assertNull($afterCutoffRun->fresh()->model_access_admin_id);
        $this->assertNull($excluded->fresh()->model_access_admin_id);
        $this->assertNull($excludedRun->fresh()->model_access_admin_id);
    }

    public function test_missing_explicit_creation_audit_never_guesses_an_ordinary_administrator(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $this->admin('ordinary', 'admin', 4);
        $task = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $run = $this->taskRun($task, ['status' => 'completed']);

        $this->artisan('geoflow:backfill-admin-ai-access', $this->applyArguments($owner, $task, $run))
            ->expectsOutput('Tasks recovered from creation audit: 0')
            ->expectsOutput('Tasks mapped to legacy owner: 1')
            ->assertSuccessful();

        $this->assertSame($owner->id, $task->fresh()->model_access_admin_id);
    }

    public function test_conflicting_creation_audits_block_apply_without_guessing_an_owner(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $creatorA = $this->admin('creator-a', 'admin', 2);
        $creatorB = $this->admin('creator-b', 'admin', 3);
        $task = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $run = $this->taskRun($task, ['status' => 'completed']);
        $this->creationAudit($task, $creatorA);
        $this->creationAudit($task, $creatorB);

        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$this->applyArguments($owner, $task, $run),
        ])
            ->expectsOutput('Preflight failed: historical_task_execution_identity_conflict')
            ->assertFailed();

        $this->assertNull($task->fresh()->model_access_admin_id);
        $this->assertNull($run->fresh()->model_access_admin_id);
    }

    public function test_active_run_created_after_the_snapshot_blocks_legacy_inference(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $task = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $historicalRun = $this->taskRun($task, ['status' => 'completed']);
        $concurrentRun = $this->taskRun($task, ['status' => 'pending']);
        $arguments = $this->applyArguments($owner, $task, $historicalRun);

        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutput('Preflight failed: historical_task_execution_identity_conflict')
            ->assertFailed();

        $this->assertNull($task->fresh()->model_access_admin_id);
        $this->assertSame('pending', $concurrentRun->fresh()->status);
        $this->assertNull($concurrentRun->fresh()->model_access_admin_id);
    }

    public function test_backfill_is_idempotent_and_dry_run_does_not_write(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $creator = $this->admin('creator', 'admin', 6);
        $task = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $run = $this->taskRun($task, ['status' => 'completed']);
        $this->creationAudit($task, $creator);
        $base = $this->baseArguments($owner, $task, $run);

        $this->artisan('geoflow:backfill-admin-ai-access', $base)
            ->expectsOutput('Tasks recovered from creation audit: 1')
            ->assertSuccessful();
        $this->assertNull($task->fresh()->model_access_admin_id);

        $apply = [...$base, '--apply' => true, '--maintenance-confirmed' => true];
        $this->artisan('geoflow:backfill-admin-ai-access', $apply)
            ->expectsOutput('Tasks recovered: 1')
            ->expectsOutput('Task run identities inherited: 1')
            ->assertSuccessful();
        $this->artisan('geoflow:backfill-admin-ai-access', $apply)
            ->expectsOutput('Tasks recovered: 0')
            ->expectsOutput('Task run identities inherited: 0')
            ->assertSuccessful();
    }

    public function test_active_run_outside_the_run_snapshot_blocks_every_recovery_source(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $creator = $this->admin('creator', 'admin', 3);
        $task = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $historicalRun = $this->taskRun($task, ['status' => 'completed']);
        $outsideSnapshot = $this->taskRun($task, ['status' => 'pending']);
        $this->creationAudit($task, $creator);

        $arguments = $this->baseArguments($owner, $task, $historicalRun);
        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutputToContain('Task execution identity finding: task_run#'.$outsideSnapshot->id.' blocking (active_run_outside_snapshot)')
            ->assertSuccessful();
        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$arguments,
            '--apply' => true,
            '--maintenance-confirmed' => true,
        ])
            ->expectsOutput('Preflight failed: historical_task_execution_identity_conflict')
            ->assertFailed();

        $this->assertNull($task->fresh()->model_access_admin_id);
        $this->assertSame('pending', $outsideSnapshot->fresh()->status);
    }

    public function test_active_run_and_task_both_outside_the_snapshots_block_apply(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $historicalTask = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $historicalRun = $this->taskRun($historicalTask, ['status' => 'completed']);
        $arguments = $this->baseArguments($owner, $historicalTask, $historicalRun);
        $outsideTask = $this->task(['status' => 'active', 'schedule_enabled' => 1]);
        $outsideRun = $this->taskRun($outsideTask, ['status' => 'running']);

        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutputToContain('Task execution identity finding: task_run#'.$outsideRun->id.' blocking (active_run_outside_snapshot)')
            ->assertSuccessful();
        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$arguments,
            '--apply' => true,
            '--maintenance-confirmed' => true,
        ])
            ->expectsOutput('Preflight failed: historical_task_execution_identity_conflict')
            ->assertFailed();

        $this->assertNull($outsideTask->fresh()->model_access_admin_id);
        $this->assertSame('running', $outsideRun->fresh()->status);
    }

    public function test_creation_audit_is_authoritative_and_conflicting_run_identity_blocks_apply(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $creator = $this->admin('creator', 'admin', 3);
        $runAdmin = $this->admin('run-admin', 'admin', 4);
        $task = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $run = $this->taskRun($task, [
            'status' => 'completed',
            'model_access_admin_id' => $runAdmin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => 4,
            'resolver_policy_version' => 1,
        ]);
        $this->creationAudit($task, $creator);

        $arguments = $this->baseArguments($owner, $task, $run);
        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutputToContain('Task execution identity finding: task#'.$task->id.' blocking (creation_audit_run_identity_conflict)')
            ->assertSuccessful();
        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$arguments,
            '--apply' => true,
            '--maintenance-confirmed' => true,
        ])
            ->expectsOutput('Preflight failed: historical_task_execution_identity_conflict')
            ->assertFailed();
    }

    public function test_creation_audit_quality_policy_version_never_becomes_resolver_policy(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $creator = $this->admin('creator', 'admin', 3);
        $task = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $run = $this->taskRun($task, ['status' => 'completed']);
        $this->creationAudit($task, $creator, 1);
        $this->creationAudit($task, $creator, 2);

        $this->artisan('geoflow:backfill-admin-ai-access', $this->applyArguments($owner, $task, $run))
            ->expectsOutput('Tasks recovered from creation audit: 1')
            ->assertSuccessful();

        $this->assertSame(1, $task->fresh()->model_access_policy_version);
        $this->assertSame(1, $run->fresh()->resolver_policy_version);
    }

    public function test_historical_run_with_a_non_current_resolver_policy_cannot_restore_a_task(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $runAdmin = $this->admin('run-admin', 'admin', 4);
        $task = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $run = $this->taskRun($task, [
            'status' => 'completed',
            'model_access_admin_id' => $runAdmin->id,
            'model_access_admin_role' => 'admin',
            'ai_config_access_version' => 4,
            'resolver_policy_version' => 2,
        ]);

        $arguments = $this->baseArguments($owner, $task, $run);
        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)
            ->expectsOutputToContain('Task execution identity finding: task_run#'.$run->id.' blocking (unsupported_resolver_policy_version)')
            ->assertSuccessful();
        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$arguments,
            '--apply' => true,
            '--maintenance-confirmed' => true,
        ])
            ->expectsOutput('Preflight failed: historical_task_execution_identity_conflict')
            ->assertFailed();

        $this->assertNull($task->fresh()->model_access_admin_id);
    }

    public function test_completed_migration_state_rejects_a_different_parameter_set(): void
    {
        $ownerA = $this->admin('owner-a', 'super_admin', 1);
        $ownerB = $this->admin('owner-b', 'super_admin', 1);
        $task = $this->task(['status' => 'paused', 'schedule_enabled' => 0]);
        $run = $this->taskRun($task, ['status' => 'completed']);
        $arguments = $this->applyArguments($ownerA, $task, $run);

        $this->artisan('geoflow:backfill-admin-ai-access', $arguments)->assertSuccessful();
        $state = SystemState::query()->where('key', 'admin_ai_access_backfill_v1')->firstOrFail();
        $this->assertSame('completed', $state->value['status']);
        $this->assertSame($ownerA->id, $state->value['legacy_owner_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $state->value['parameter_hash']);
        $this->artisan('geoflow:backfill-admin-ai-access', [
            ...$arguments,
            '--legacy-owner' => $ownerB->id,
        ])
            ->expectsOutput('Preflight failed: admin_ai_access_backfill_parameters_conflict')
            ->assertFailed();

        $this->assertSame($ownerA->id, $task->fresh()->model_access_admin_id);
    }

    public function test_apply_reports_identity_and_requested_model_backfill_separately_with_remaining_gaps(): void
    {
        $owner = $this->admin('owner', 'super_admin', 1);
        $creator = $this->admin('creator', 'admin', 6);
        $model = $this->model($creator);
        $task = $this->task(['ai_model_id' => $model->id]);
        $run = $this->taskRun($task, ['status' => 'completed']);
        $this->creationAudit($task, $creator);

        $this->artisan('geoflow:backfill-admin-ai-access', $this->applyArguments($owner, $task, $run))
            ->expectsOutput('Run identities to inherit: 1')
            ->expectsOutput('Requested models to backfill: 1')
            ->expectsOutput('Task run identities inherited: 1')
            ->expectsOutput('Requested models backfilled: 1')
            ->expectsOutput('Remaining tasks with empty identity: 0')
            ->expectsOutput('Remaining tasks with partial identity: 0')
            ->expectsOutput('Remaining task runs with empty identity: 0')
            ->expectsOutput('Remaining task runs with partial identity: 0')
            ->expectsOutput('Remaining active task runs without identity: 0')
            ->assertSuccessful();
    }

    /** @param array<string, mixed> $attributes */
    private function admin(string $username, string $role, int $accessVersion, array $attributes = []): Admin
    {
        $admin = Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.test',
            'display_name' => ucfirst($username),
            'role' => $role,
            'status' => 'active',
        ]);
        $admin->forceFill([
            'ai_config_access_version' => $accessVersion,
            'created_at' => '2026-08-01 00:00:00',
            'updated_at' => '2026-08-01 00:00:00',
            ...$attributes,
        ])->save();

        return $admin->refresh();
    }

    private function model(Admin $owner, string $modelId = 'chat-model'): AiModel
    {
        $model = new AiModel([
            'name' => $modelId,
            'version' => 'test',
            'api_key' => 'secret-'.$modelId,
            'model_id' => $modelId,
            'model_type' => 'chat',
            'api_url' => 'https://example.test',
            'status' => 'active',
        ]);
        $model->forceFill([
            'owner_admin_id' => $owner->id,
            'access_scope' => AiModel::ACCESS_SCOPE_USER_CONTENT,
            'created_at' => '2026-08-01 00:00:00',
            'updated_at' => '2026-08-01 00:00:00',
        ])->save();

        return $model;
    }

    /** @param array<string, mixed> $attributes */
    private function task(array $attributes = []): Task
    {
        $task = Task::query()->create([
            'name' => 'Historical task '.Str::lower(Str::random(8)),
            'status' => $attributes['status'] ?? 'paused',
            'schedule_enabled' => $attributes['schedule_enabled'] ?? 0,
            'ai_model_id' => $attributes['ai_model_id'] ?? null,
        ]);
        $task->forceFill([
            'created_at' => '2026-08-20 00:00:00',
            'updated_at' => '2026-08-20 00:00:00',
            ...$attributes,
        ])->save();

        return $task->refresh();
    }

    /** @param array<string, mixed> $attributes */
    private function taskRun(Task $task, array $attributes = []): TaskRun
    {
        $run = TaskRun::query()->create([
            'task_id' => $task->id,
            'status' => $attributes['status'] ?? 'completed',
            'meta' => [],
        ]);
        $run->forceFill([
            'created_at' => '2026-08-20 01:00:00',
            ...$attributes,
        ])->save();

        return $run->refresh();
    }

    private function creationAudit(Task $task, Admin $admin, int $policyVersion = 1): void
    {
        AiQualityAuditEvent::query()->create([
            'event_uuid' => (string) Str::uuid(),
            'event_type' => 'task_quality_configuration_created',
            'occurred_at' => '2026-08-20 00:00:01',
            'task_id' => $task->id,
            'admin_id' => $admin->id,
            'authorization_result' => 'allowed',
            'policy_version' => $policyVersion,
            'metadata' => [],
        ]);
    }

    /** @return array<string, int|string> */
    private function baseArguments(Admin $owner, Task $task, TaskRun $run): array
    {
        return [
            '--legacy-owner' => $owner->id,
            '--created-before' => '2026-09-01T00:00:00+08:00',
            '--admin-max-id' => (int) Admin::query()->max('id'),
            '--model-max-id' => (int) AiModel::query()->max('id'),
            '--task-max-id' => $task->id,
            '--task-run-max-id' => $run->id,
        ];
    }

    /** @return array<string, bool|int|string> */
    private function applyArguments(Admin $owner, Task $task, TaskRun $run): array
    {
        return [
            ...$this->baseArguments($owner, $task, $run),
            '--apply' => true,
            '--maintenance-confirmed' => true,
        ];
    }
}

final class TaskBackfillMaintenanceMode implements MaintenanceModeContract
{
    public function activate(array $payload): void {}

    public function deactivate(): void {}

    public function active(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function data(): array
    {
        return [];
    }
}
