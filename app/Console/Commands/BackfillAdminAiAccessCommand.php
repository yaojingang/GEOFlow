<?php

namespace App\Console\Commands;

use App\Exceptions\AdminAiAccessBackfillException;
use App\Services\Admin\AdminAiAccessBackfillService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

final class BackfillAdminAiAccessCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geoflow:backfill-admin-ai-access
        {--legacy-owner= : Explicit legacy super administrator ID}
        {--created-before= : Historical administrator cutoff with an explicit timezone}
        {--admin-max-id= : Stable administrator ID snapshot captured before deployment}
        {--model-max-id= : Stable AI model ID snapshot captured before deployment}
        {--task-max-id= : Stable task ID snapshot captured before deployment}
        {--task-run-max-id= : Stable task run ID snapshot captured before deployment}
        {--apply : Apply the audited backfill in one transaction}
        {--maintenance-confirmed : Confirm Web and AI workers are stopped and maintenance mode is active}
        {--dry-run : Explicitly request preflight-only mode}
        {--batch=200 : Administrators processed per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preflight or apply administrator AI ownership, sharing, and task execution identity backfill';

    public function __construct(private readonly AdminAiAccessBackfillService $backfillService)
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        try {
            [
                $ownerId,
                $cutoff,
                $adminMaxId,
                $modelMaxId,
                $taskMaxId,
                $taskRunMaxId,
                $apply,
                $maintenanceConfirmed,
                $batchSize,
            ] = $this->argumentsForRun();
        } catch (AdminAiAccessBackfillException $exception) {
            $this->error('Invalid arguments: '.$exception->getErrorCode());

            return self::INVALID;
        }

        try {
            $result = $apply
                ? $this->backfillService->apply(
                    $ownerId,
                    $cutoff,
                    $adminMaxId,
                    $modelMaxId,
                    $maintenanceConfirmed,
                    $batchSize,
                    $taskMaxId,
                    $taskRunMaxId,
                )
                : $this->backfillService->preview(
                    $ownerId,
                    $cutoff,
                    $adminMaxId,
                    $modelMaxId,
                    $taskMaxId,
                    $taskRunMaxId,
                );
        } catch (AdminAiAccessBackfillException $exception) {
            $this->error('Preflight failed: '.$exception->getErrorCode());
            if (in_array($exception->getErrorCode(), [
                'maintenance_confirmation_required',
                'application_maintenance_mode_required',
            ], true)) {
                $this->line('Required: stop Web and AI workers, run php artisan down, then pass --maintenance-confirmed.');
            }

            return self::FAILURE;
        } catch (Throwable) {
            $this->error('Backfill failed: transaction_failed');

            return self::FAILURE;
        }

        $this->line('Mode: '.($apply ? 'apply' : 'dry-run'));
        $this->line('Legacy owner: '.$result['legacy_owner_id']);
        $this->line('Created before: '.$result['created_before']);
        $this->line('Admin max ID: '.($result['admin_max_id'] ?? 'not set'));
        $this->line('Model max ID: '.($result['model_max_id'] ?? 'not set'));
        $this->line('Task max ID: '.($result['task_max_id'] ?? 'not set'));
        $this->line('Task run max ID: '.($result['task_run_max_id'] ?? 'not set'));
        $this->line('Unowned models: '.$result['unowned_models']);
        $this->line('Historical administrators to share: '.$result['historical_administrators']);
        $this->line('Super administrator bindings to clear: '.$result['super_admin_bindings_to_clear']);
        $this->line('Access versions to normalize: '.$result['invalid_access_versions']);
        $this->line('System-only models to mark: '.$result['system_models_to_mark']);
        $this->line('System/user-content conflicts: '.count($result['conflict_model_ids']));
        $this->line('Invalid system bindings: '.count($result['invalid_bindings']));
        $this->line('Historical structured model references: '.$result['historical_structured_reference_count']);
        $this->line('Structured model reference findings: '.$result['structured_reference_finding_count']);
        $this->line('Tasks recovered from historical runs: '.$result['tasks_recovered_from_historical_runs']);
        $this->line('Tasks recovered from creation audit: '.$result['tasks_recovered_from_creation_audit']);
        $this->line('Tasks mapped to legacy owner: '.$result['tasks_mapped_to_legacy_owner']);
        $this->line('Run identities to inherit: '.$result['run_identities_to_inherit']);
        $this->line('Requested models to backfill: '.$result['requested_models_to_backfill']);
        $this->line('Legacy-inferred tasks paused: '.$result['legacy_inferred_tasks_to_pause']);
        $this->line('Legacy-inferred active runs frozen: '.$result['legacy_inferred_active_runs_to_freeze']);
        $this->line('Manual execution identity findings: '.$result['manual_execution_identity_finding_count']);
        $this->line('Execution identity blocking conflicts: '.$result['execution_identity_blocking_conflict_count']);
        $this->line('Lifecycle identities recovered from creators: '.$result['lifecycle_identities_recovered_from_creators']);
        $this->line('Lifecycle identities mapped to legacy owner: '.$result['lifecycle_identities_mapped_to_legacy_owner']);
        $this->line('Unattributed active lifecycle records frozen: '.$result['unattributed_active_lifecycle_records_to_freeze']);
        if ($apply) {
            $this->line('Lifecycle identity records updated: '.$result['lifecycle_identity_records_updated']);
        }

        foreach ($result['conflict_model_ids'] as $modelId) {
            $this->line('Conflict model ID: '.$modelId);
        }
        foreach ($result['invalid_bindings'] as $invalidBinding) {
            $this->line(sprintf(
                'Invalid system binding: %s (%s)',
                $invalidBinding['setting_key'],
                $invalidBinding['reason'],
            ));
        }
        foreach ($result['structured_reference_findings'] as $finding) {
            $this->line(sprintf(
                'Structured model reference finding: %s#%d %s (%s:%s)',
                $finding['reference'],
                $finding['row_id'],
                $finding['path'],
                $finding['state'],
                $finding['reason'],
            ));
        }
        foreach ($result['task_execution_identity_findings'] as $finding) {
            $this->line(sprintf(
                'Task execution identity finding: %s#%d %s (%s)',
                $finding['subject_type'],
                $finding['subject_id'],
                $finding['severity'],
                $finding['reason'],
            ));
        }

        if ($apply) {
            $this->line('Models assigned: '.$result['models_assigned']);
            $this->line('Administrators shared: '.$result['administrators_shared']);
            $this->line('Super administrator bindings cleared: '.$result['super_admin_bindings_cleared']);
            $this->line('Access versions normalized: '.$result['access_versions_normalized']);
            $this->line('System-only models marked: '.$result['system_models_marked']);
            $this->line('Tasks recovered: '.$result['tasks_recovered']);
            $this->line('Task run identities inherited: '.$result['task_run_identities_inherited']);
            $this->line('Requested models backfilled: '.$result['requested_models_backfilled']);
            $this->line('Tasks paused: '.$result['legacy_inferred_tasks_paused']);
            $this->line('Active runs frozen: '.$result['legacy_inferred_active_runs_frozen']);
            $this->line('Remaining tasks with empty identity: '.$result['remaining_tasks_with_empty_identity']);
            $this->line('Remaining tasks with partial identity: '.$result['remaining_tasks_with_partial_identity']);
            $this->line('Remaining task runs with empty identity: '.$result['remaining_task_runs_with_empty_identity']);
            $this->line('Remaining task runs with partial identity: '.$result['remaining_task_runs_with_partial_identity']);
            $this->line('Remaining active task runs without identity: '.$result['remaining_active_task_runs_without_identity']);
        }

        return self::SUCCESS;
    }

    /** @return array{?int, CarbonImmutable, ?int, ?int, ?int, ?int, bool, bool, int} */
    private function argumentsForRun(): array
    {
        $apply = (bool) $this->option('apply');
        if ($apply && (bool) $this->option('dry-run')) {
            throw new AdminAiAccessBackfillException('apply_and_dry_run_are_mutually_exclusive');
        }

        $cutoffValue = trim((string) $this->option('created-before'));
        if ($cutoffValue === '') {
            throw new AdminAiAccessBackfillException('created_before_required');
        }
        if (preg_match('/(?:Z|[+-]\d{2}:\d{2})\z/', $cutoffValue) !== 1) {
            throw new AdminAiAccessBackfillException('created_before_timezone_required');
        }

        try {
            $cutoff = CarbonImmutable::parse($cutoffValue)
                ->setTimezone((string) config('app.timezone', 'UTC'));
        } catch (Throwable) {
            throw new AdminAiAccessBackfillException('created_before_invalid');
        }

        $ownerValue = trim((string) $this->option('legacy-owner'));
        if ($ownerValue !== '' && (! ctype_digit($ownerValue) || (int) $ownerValue < 1)) {
            throw new AdminAiAccessBackfillException('legacy_owner_invalid');
        }

        $adminMaxId = $this->optionalSnapshotId('admin-max-id');
        $modelMaxId = $this->optionalSnapshotId('model-max-id');
        $taskMaxId = $this->optionalSnapshotId('task-max-id');
        $taskRunMaxId = $this->optionalSnapshotId('task-run-max-id');

        $batchValue = trim((string) $this->option('batch'));
        if (! ctype_digit($batchValue) || (int) $batchValue < 1 || (int) $batchValue > 1000) {
            throw new AdminAiAccessBackfillException('batch_invalid');
        }

        return [
            $ownerValue === '' ? null : (int) $ownerValue,
            $cutoff,
            $adminMaxId,
            $modelMaxId,
            $taskMaxId,
            $taskRunMaxId,
            $apply,
            (bool) $this->option('maintenance-confirmed'),
            (int) $batchValue,
        ];
    }

    private function optionalSnapshotId(string $option): ?int
    {
        $value = trim((string) $this->option($option));
        if ($value === '') {
            return null;
        }
        if (! ctype_digit($value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new AdminAiAccessBackfillException(str_replace('-', '_', $option).'_invalid');
        }

        return (int) $value;
    }
}
