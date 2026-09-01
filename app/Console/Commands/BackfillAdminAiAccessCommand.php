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
        {--apply : Apply the audited backfill in one transaction}
        {--dry-run : Explicitly request preflight-only mode}
        {--batch=200 : Administrators processed per batch}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Preflight or apply administrator AI model ownership and sharing backfill';

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
            [$ownerId, $cutoff, $adminMaxId, $modelMaxId, $apply, $batchSize] = $this->argumentsForRun();
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
                    $batchSize,
                )
                : $this->backfillService->preview($ownerId, $cutoff, $adminMaxId, $modelMaxId);
        } catch (AdminAiAccessBackfillException $exception) {
            $this->error('Preflight failed: '.$exception->getErrorCode());

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
        $this->line('Unowned models: '.$result['unowned_models']);
        $this->line('Historical administrators to share: '.$result['historical_administrators']);
        $this->line('Super administrator bindings to clear: '.$result['super_admin_bindings_to_clear']);
        $this->line('Access versions to normalize: '.$result['invalid_access_versions']);
        $this->line('System-only models to mark: '.$result['system_models_to_mark']);
        $this->line('System/user-content conflicts: '.count($result['conflict_model_ids']));
        $this->line('Invalid system bindings: '.count($result['invalid_bindings']));

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

        if ($apply) {
            $this->line('Models assigned: '.$result['models_assigned']);
            $this->line('Administrators shared: '.$result['administrators_shared']);
            $this->line('Super administrator bindings cleared: '.$result['super_admin_bindings_cleared']);
            $this->line('Access versions normalized: '.$result['access_versions_normalized']);
            $this->line('System-only models marked: '.$result['system_models_marked']);
        }

        return self::SUCCESS;
    }

    /** @return array{?int, CarbonImmutable, ?int, ?int, bool, int} */
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

        $batchValue = trim((string) $this->option('batch'));
        if (! ctype_digit($batchValue) || (int) $batchValue < 1 || (int) $batchValue > 1000) {
            throw new AdminAiAccessBackfillException('batch_invalid');
        }

        return [
            $ownerValue === '' ? null : (int) $ownerValue,
            $cutoff,
            $adminMaxId,
            $modelMaxId,
            $apply,
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
