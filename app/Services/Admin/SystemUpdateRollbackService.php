<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\SystemUpdateBackup;
use App\Models\SystemUpdateRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class SystemUpdateRollbackService
{
    public function __construct(
        private readonly SystemUpdatePathGuard $pathGuard,
    ) {}

    public function rollback(SystemUpdateBackup $backup, Admin $admin): SystemUpdateRun
    {
        if (! (bool) config('geoflow.update_execution_enabled', false)
            || ! (bool) config('geoflow.update_rollback_enabled', false)) {
            throw new RuntimeException(__('admin.system_updates.error.rollback_disabled'));
        }

        $run = SystemUpdateRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'action' => 'rollback',
            'status' => 'running',
            'current_version' => $backup->to_version,
            'target_version' => $backup->from_version,
            'current_commit' => $backup->to_commit,
            'target_commit' => $backup->from_commit,
            'deployment_mode' => optional($backup->run)->deployment_mode,
            'risk_level' => optional($backup->run)->risk_level,
            'backup_path' => $backup->backup_path,
            'started_by_admin_id' => $admin->id,
            'started_at' => now(),
        ]);

        try {
            $manifest = $this->readManifest($backup);
            $restoreRoot = $this->extractBackupArchive($backup, $run->run_uuid);
            $report = $this->restoreFiles($manifest, $restoreRoot);
            $logPath = $this->writeLog($run->run_uuid, $report);

            $run->forceFill([
                'status' => 'succeeded',
                'plan_json' => ['rollback_report' => $report],
                'log_path' => $logPath,
                'finished_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }

        return $run;
    }

    public function rollbackFile(SystemUpdateBackup $backup, string $relativePath, Admin $admin): SystemUpdateRun
    {
        if (! (bool) config('geoflow.update_execution_enabled', false)
            || ! (bool) config('geoflow.update_rollback_enabled', false)) {
            throw new RuntimeException(__('admin.system_updates.error.rollback_disabled'));
        }

        $relativePath = $this->pathGuard->assertAllowedPath($relativePath);
        $run = SystemUpdateRun::query()->create([
            'run_uuid' => (string) Str::uuid(),
            'action' => 'rollback_file',
            'status' => 'running',
            'current_version' => $backup->to_version,
            'target_version' => $backup->from_version,
            'current_commit' => $backup->to_commit,
            'target_commit' => $backup->from_commit,
            'deployment_mode' => optional($backup->run)->deployment_mode,
            'risk_level' => optional($backup->run)->risk_level,
            'backup_path' => $backup->backup_path,
            'started_by_admin_id' => $admin->id,
            'started_at' => now(),
        ]);

        try {
            $manifest = $this->readManifest($backup);
            $restoreRoot = $this->extractBackupArchive($backup, $run->run_uuid);
            $report = $this->restoreFiles($manifest, $restoreRoot, [$relativePath]);
            $logPath = $this->writeLog($run->run_uuid, $report);

            $run->forceFill([
                'status' => 'succeeded',
                'plan_json' => [
                    'file_path' => $relativePath,
                    'rollback_report' => $report,
                ],
                'log_path' => $logPath,
                'finished_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'finished_at' => now(),
            ])->save();

            throw $e;
        }

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(SystemUpdateBackup $backup): array
    {
        if (! Storage::disk('local')->exists($backup->manifest_path)) {
            throw new RuntimeException(__('admin.system_updates.error.backup_manifest_missing'));
        }

        $decoded = json_decode((string) Storage::disk('local')->get($backup->manifest_path), true);
        if (! is_array($decoded)) {
            throw new RuntimeException(__('admin.system_updates.error.backup_manifest_invalid'));
        }

        return $decoded;
    }

    private function extractBackupArchive(SystemUpdateBackup $backup, string $runUuid): ?string
    {
        $archivePath = trim((string) $backup->files_archive_path);
        if ($archivePath === '') {
            return null;
        }

        if (! Storage::disk('local')->exists($archivePath)) {
            throw new RuntimeException(__('admin.system_updates.error.backup_archive_missing'));
        }

        $baseDir = trim((string) config('geoflow.update_backup_path', 'geoflow-updates'), '/');
        $restorePath = "{$baseDir}/rollbacks/{$runUuid}";
        $restoreDiskPath = Storage::disk('local')->path($restorePath);
        File::ensureDirectoryExists($restoreDiskPath);

        $zip = new ZipArchive();
        if ($zip->open(Storage::disk('local')->path($archivePath)) !== true) {
            throw new RuntimeException(__('admin.system_updates.error.backup_archive_open_failed'));
        }

        $extracted = false;
        try {
            $this->validateBackupArchive($zip);
            $extracted = $zip->extractTo($restoreDiskPath);
        } finally {
            $closed = $zip->close();
        }

        if (! $extracted || ! $closed) {
            throw new RuntimeException(__('admin.system_updates.error.backup_archive_extract_failed'));
        }

        return $restoreDiskPath;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{restored: int, removed: int, skipped: int, files: array<int, array<string, string>>}
     */
    private function restoreFiles(array $manifest, ?string $restoreRoot, ?array $onlyPaths = null): array
    {
        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        if ($files === []) {
            throw new RuntimeException(__('admin.system_updates.error.backup_manifest_empty'));
        }

        $report = [
            'restored' => 0,
            'removed' => 0,
            'skipped' => 0,
            'files' => [],
        ];
        $matched = 0;

        foreach ($files as $file) {
            $action = (string) ($file['action'] ?? '');
            $relativePath = $this->pathGuard->assertAllowedPath((string) ($file['path'] ?? ''));
            if ($onlyPaths !== null && ! in_array($relativePath, $onlyPaths, true)) {
                continue;
            }

            $matched++;
            $targetPath = base_path($relativePath);
            $newSha256 = (string) ($file['new_sha256'] ?? '');
            $oldSha256 = (string) ($file['old_sha256'] ?? $file['sha256'] ?? '');

            if ($action === 'added') {
                $this->assertRollbackTargetMatchesBackupState($targetPath, $action, $oldSha256, $newSha256);
                if (is_file($targetPath)) {
                    File::delete($targetPath);
                    $report['removed']++;
                    $report['files'][] = ['path' => $relativePath, 'action' => 'removed_added_file'];
                } else {
                    $report['skipped']++;
                    $report['files'][] = ['path' => $relativePath, 'action' => 'added_file_already_missing'];
                }

                continue;
            }

            if (! in_array($action, ['modified', 'deleted'], true)) {
                if ($onlyPaths !== null) {
                    throw new RuntimeException(__('admin.system_updates.error.backup_file_not_restorable'));
                }

                $report['skipped']++;
                $report['files'][] = ['path' => $relativePath, 'action' => 'unsupported_action'];
                continue;
            }

            if ($restoreRoot === null) {
                throw new RuntimeException(__('admin.system_updates.error.backup_archive_missing'));
            }

            $sourcePath = $restoreRoot.DIRECTORY_SEPARATOR.$relativePath;
            if (! is_file($sourcePath)) {
                throw new RuntimeException(__('admin.system_updates.error.backup_file_missing', ['path' => $relativePath]));
            }

            $this->assertRollbackTargetMatchesBackupState($targetPath, $action, $oldSha256, $newSha256);
            File::ensureDirectoryExists(dirname($targetPath));
            if (! File::copy($sourcePath, $targetPath)) {
                throw new RuntimeException(__('admin.system_updates.error.rollback_file_failed', ['path' => $relativePath]));
            }

            $report['restored']++;
            $report['files'][] = ['path' => $relativePath, 'action' => 'restored'];
        }

        if ($onlyPaths !== null && $matched === 0) {
            throw new RuntimeException(__('admin.system_updates.error.backup_file_not_in_manifest'));
        }

        return $report;
    }

    private function validateBackupArchive(ZipArchive $zip): void
    {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);
            if (! is_string($entryName) || $entryName === '') {
                throw new RuntimeException(__('admin.system_updates.error.backup_archive_unsafe'));
            }

            $this->pathGuard->assertAllowedPath($entryName);
        }
    }

    private function assertRollbackTargetMatchesBackupState(string $targetPath, string $action, string $oldSha256, string $newSha256): void
    {
        if (! is_file($targetPath)) {
            return;
        }

        $currentSha256 = hash_file('sha256', $targetPath);
        $expectedSha256 = $action === 'deleted' ? $oldSha256 : $newSha256;

        if ($expectedSha256 === '' || ! hash_equals($expectedSha256, $currentSha256)) {
            throw new RuntimeException(__('admin.system_updates.error.rollback_target_changed', [
                'path' => ltrim(str_replace('\\', '/', str_replace(base_path(), '', $targetPath)), '/'),
            ]));
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
}
