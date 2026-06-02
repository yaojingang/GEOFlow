<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\SystemUpdateBackup;
use App\Models\SystemUpdateRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class SystemUpdateApplyService
{
    public function __construct(
        private readonly SystemUpdatePathGuard $pathGuard,
        private readonly SystemUpdateRunProgressService $progressService,
        private readonly SystemUpdateVerificationService $verificationService,
    ) {}

    public function apply(SystemUpdateRun $planRun, Admin $admin): SystemUpdateRun
    {
        $run = $this->queueApply($planRun, $admin);

        return $this->executeQueued($run);
    }

    public function queueApply(SystemUpdateRun $planRun, Admin $admin): SystemUpdateRun
    {
        if (! (bool) config('geoflow.update_execution_enabled', false)
            || ! (bool) config('geoflow.update_archive_apply_enabled', false)) {
            throw new RuntimeException(__('admin.system_updates.error.execution_disabled'));
        }

        $backup = SystemUpdateBackup::query()
            ->where('run_id', $planRun->id)
            ->whereIn('status', ['available', 'not_required'])
            ->latest('id')
            ->first();
        if (! $backup) {
            throw new RuntimeException(__('admin.system_updates.error.backup_required'));
        }

        $run = $this->createRun($planRun, $admin, $backup);
        $this->progressService->record($run, 'queued', 5, 'queued');

        return $run;
    }

    public function executeQueued(SystemUpdateRun $run): SystemUpdateRun
    {
        $run->refresh();
        if (in_array((string) $run->status, ['succeeded', 'failed'], true)) {
            return $run;
        }

        if (! in_array((string) $run->status, ['queued', 'running'], true)) {
            throw new RuntimeException(__('admin.system_updates.error.run_not_executable'));
        }

        $run->forceFill([
            'status' => 'running',
            'error_message' => null,
            'started_at' => $run->started_at ?: now(),
        ])->save();

        try {
            $this->progressService->record($run, 'apply_preflight', 20);
            $report = $this->applyPlan($run);
            $this->progressService->record($run, 'apply_files', 72);
            $verification = $this->verificationService->verify($run);
            $this->progressService->record($run, 'verify', 92, $verification['status'] === 'fail' ? 'warning' : 'running');
            $logPath = $this->writeLog($run->run_uuid, $report);
            $this->progressService->record($run, 'complete', 100, 'succeeded');

            $payload = is_array($run->plan_json) ? $run->plan_json : [];
            $payload['apply_report'] = $report;
            $payload['verification'] = $verification;

            $run->forceFill([
                'status' => 'succeeded',
                'plan_json' => $payload,
                'log_path' => $logPath,
                'finished_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $this->progressService->record($run, 'failed', 100, 'failed');

            $run->forceFill([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }

        return $run;
    }

    private function createRun(SystemUpdateRun $planRun, Admin $admin, SystemUpdateBackup $backup): SystemUpdateRun
    {
        $payload = is_array($planRun->plan_json) ? $planRun->plan_json : [];
        $payload['source_plan_run_id'] = (int) $planRun->id;
        $payload['source_plan_run_uuid'] = (string) $planRun->run_uuid;
        $payload['backup_uuid'] = (string) $backup->backup_uuid;

        return SystemUpdateRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'action' => 'apply',
            'status' => 'queued',
            'current_version' => $planRun->current_version,
            'target_version' => $planRun->target_version,
            'current_commit' => $planRun->current_commit,
            'target_commit' => $planRun->target_commit,
            'deployment_mode' => $planRun->deployment_mode,
            'risk_level' => $planRun->risk_level,
            'plan_json' => $payload,
            'plan_path' => $planRun->plan_path,
            'backup_path' => $backup->backup_path,
            'started_by_admin_id' => $admin->id,
        ]);
    }

    /**
     * @return array{added: int, modified: int, deleted: int, skipped: int, files: array<int, array<string, string>>}
     */
    private function applyPlan(SystemUpdateRun $planRun): array
    {
        $plan = is_array($planRun->plan_json) ? $planRun->plan_json : [];
        $changes = is_array($plan['changes'] ?? null) ? $plan['changes'] : [];
        if ($changes === []) {
            throw new RuntimeException(__('admin.system_updates.error.plan_empty'));
        }

        $sourceRoot = $this->resolveSourceRoot($plan);
        $preflight = $this->preflightChanges($changes, $sourceRoot);
        $report = [
            'added' => 0,
            'modified' => 0,
            'deleted' => 0,
            'skipped' => $preflight['skipped'],
            'files' => [],
        ];

        foreach ($preflight['changes'] as $change) {
            $action = $change['action'];
            $relativePath = $change['relative_path'];
            $targetPath = $change['target_path'];

            if ($action === 'deleted') {
                if (is_file($targetPath)) {
                    File::delete($targetPath);
                }
                $report['deleted']++;
                $report['files'][] = ['path' => $relativePath, 'action' => 'deleted'];
                continue;
            }

            File::ensureDirectoryExists(dirname($targetPath));
            if (! File::copy($change['source_path'], $targetPath)) {
                throw new RuntimeException(__('admin.system_updates.error.file_apply_failed', ['path' => $relativePath]));
            }

            $report[$action]++;
            $report['files'][] = ['path' => $relativePath, 'action' => $action];
        }

        return $report;
    }

    /**
     * Validate the full plan before changing any local file. This keeps a bad
     * source archive or stale local hash from producing a partially applied update.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array{changes: array<int, array{action: string, relative_path: string, target_path: string, source_path?: string}>, skipped: int}
     */
    private function preflightChanges(array $changes, string $sourceRoot): array
    {
        $validated = [];
        $skipped = 0;

        foreach ($changes as $change) {
            $action = (string) ($change['action'] ?? '');
            $relativePath = $this->pathGuard->assertAllowedPath((string) ($change['path'] ?? ''));
            $targetPath = base_path($relativePath);

            if ($action === 'deleted') {
                $this->assertCurrentHash($targetPath, (string) ($change['old_sha256'] ?? ''), true);
                $validated[] = [
                    'action' => 'deleted',
                    'relative_path' => $relativePath,
                    'target_path' => $targetPath,
                ];

                continue;
            }

            if (! in_array($action, ['added', 'modified'], true)) {
                $skipped++;

                continue;
            }

            $sourcePath = $sourceRoot.DIRECTORY_SEPARATOR.$relativePath;
            if (! is_file($sourcePath)) {
                throw new RuntimeException(__('admin.system_updates.error.source_file_missing', ['path' => $relativePath]));
            }

            if ($action === 'added' && is_file($targetPath)) {
                throw new RuntimeException(__('admin.system_updates.error.local_file_changed', ['path' => $relativePath]));
            }

            $this->assertCurrentHash($targetPath, (string) ($change['old_sha256'] ?? ''), $action === 'modified');
            $validated[] = [
                'action' => $action,
                'relative_path' => $relativePath,
                'target_path' => $targetPath,
                'source_path' => $sourcePath,
            ];
        }

        return [
            'changes' => $validated,
            'skipped' => $skipped,
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function resolveSourceRoot(array $plan): string
    {
        $sourceRootPath = trim((string) ($plan['source_root_path'] ?? ''));
        if ($sourceRootPath !== '') {
            $candidate = Storage::disk('local')->path($sourceRootPath);
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        $extractPath = trim((string) ($plan['extracted_path'] ?? ''));
        if ($extractPath === '') {
            throw new RuntimeException(__('admin.system_updates.error.extracted_archive_missing'));
        }

        $candidate = Storage::disk('local')->path($extractPath);
        if (! is_dir($candidate)) {
            throw new RuntimeException(__('admin.system_updates.error.extracted_archive_missing'));
        }

        $directories = File::directories($candidate);
        $files = File::files($candidate);
        if (count($directories) === 1 && count($files) === 0) {
            return $directories[0];
        }

        return $candidate;
    }

    private function assertCurrentHash(string $targetPath, string $expectedSha256, bool $mustExist): void
    {
        if (! is_file($targetPath)) {
            if ($mustExist) {
                throw new RuntimeException(__('admin.system_updates.error.local_file_missing', ['path' => $this->relativePath($targetPath)]));
            }

            return;
        }

        if ($expectedSha256 !== '' && ! hash_equals($expectedSha256, hash_file('sha256', $targetPath))) {
            throw new RuntimeException(__('admin.system_updates.error.local_file_changed', ['path' => $this->relativePath($targetPath)]));
        }
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function writeLog(string $runUuid, array $report): string
    {
        $baseDir = trim((string) config('geoflow.update_backup_path', 'geoflow-updates'), '/');
        $logPath = "{$baseDir}/logs/{$runUuid}.json";
        Storage::disk('local')->put($logPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $logPath;
    }

    private function relativePath(string $targetPath): string
    {
        return ltrim(str_replace('\\', '/', str_replace(base_path(), '', $targetPath)), '/');
    }
}
