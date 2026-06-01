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

class SystemUpdateBackupService
{
    public function __construct(
        private readonly SystemUpdatePathGuard $pathGuard,
    ) {}

    public function createFromPlan(SystemUpdateRun $run, Admin $admin): SystemUpdateBackup
    {
        $plan = is_array($run->plan_json) ? $run->plan_json : [];
        $changes = is_array($plan['changes'] ?? null) ? $plan['changes'] : [];
        if ($changes === []) {
            throw new RuntimeException(__('admin.system_updates.error.plan_empty'));
        }

        $backupUuid = (string) Str::uuid();
        $baseDir = trim((string) config('geoflow.update_backup_path', 'geoflow-updates'), '/');
        $backupPath = "{$baseDir}/backups/{$backupUuid}";
        $filesArchivePath = "{$backupPath}/files.zip";
        $manifestPath = "{$backupPath}/manifest.json";

        $manifestFiles = [];
        $fileCount = 0;
        $totalBytes = 0;
        $candidates = [];

        foreach ($changes as $change) {
            $action = (string) ($change['action'] ?? '');
            if (! in_array($action, ['added', 'modified', 'deleted'], true)) {
                continue;
            }

            try {
                $relativePath = $this->pathGuard->assertAllowedPath((string) ($change['path'] ?? ''));
            } catch (RuntimeException) {
                continue;
            }

            if ($action === 'added') {
                $manifestFiles[] = [
                    'path' => $relativePath,
                    'action' => $action,
                    'old_sha256' => '',
                    'new_sha256' => (string) ($change['new_sha256'] ?? ''),
                    'sha256' => '',
                    'bytes' => 0,
                    'backed_up' => false,
                ];
                continue;
            }

            $localPath = base_path($relativePath);
            if (! is_file($localPath)) {
                continue;
            }

            $bytes = (int) filesize($localPath);
            $currentSha256 = hash_file('sha256', $localPath);
            $candidates[] = [
                'local_path' => $localPath,
                'path' => $relativePath,
                'action' => $action,
                'old_sha256' => (string) ($change['old_sha256'] ?? $currentSha256),
                'new_sha256' => (string) ($change['new_sha256'] ?? ''),
                'sha256' => $currentSha256,
                'bytes' => $bytes,
                'backed_up' => true,
            ];
        }

        $status = 'not_required';
        $storedFilesArchivePath = null;

        if ($candidates !== []) {
            $archiveDiskPath = Storage::disk('local')->path($filesArchivePath);
            File::ensureDirectoryExists(dirname($archiveDiskPath));

            $zip = new ZipArchive();
            if ($zip->open($archiveDiskPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException(__('admin.system_updates.error.backup_archive_failed'));
            }

            foreach ($candidates as $candidate) {
                if (! $zip->addFile((string) $candidate['local_path'], (string) $candidate['path'])) {
                    $zip->close();
                    throw new RuntimeException(__('admin.system_updates.error.backup_archive_failed'));
                }

                $manifestFiles[] = [
                    'path' => $candidate['path'],
                    'action' => $candidate['action'],
                    'old_sha256' => $candidate['old_sha256'],
                    'new_sha256' => $candidate['new_sha256'],
                    'sha256' => $candidate['sha256'],
                    'bytes' => $candidate['bytes'],
                    'backed_up' => $candidate['backed_up'],
                ];

                $bytes = (int) $candidate['bytes'];
                $fileCount++;
                $totalBytes += $bytes;
            }

            if (! $zip->close()) {
                throw new RuntimeException(__('admin.system_updates.error.backup_archive_failed'));
            }

            $status = 'available';
            $storedFilesArchivePath = $filesArchivePath;
        }

        $manifest = [
            'backup_uuid' => $backupUuid,
            'run_uuid' => $run->run_uuid,
            'created_at' => now()->toDateTimeString(),
            'from_version' => $run->current_version,
            'to_version' => $run->target_version,
            'from_commit' => $run->current_commit,
            'to_commit' => $run->target_commit,
            'file_count' => $fileCount,
            'total_bytes' => $totalBytes,
            'files_archive_path' => $storedFilesArchivePath,
            'files' => $manifestFiles,
        ];

        Storage::disk('local')->put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $backup = SystemUpdateBackup::query()->create([
            'backup_uuid' => $backupUuid,
            'run_id' => $run->id,
            'from_version' => $run->current_version,
            'to_version' => $run->target_version,
            'from_commit' => $run->current_commit,
            'to_commit' => $run->target_commit,
            'backup_path' => $backupPath,
            'manifest_path' => $manifestPath,
            'files_archive_path' => $storedFilesArchivePath,
            'file_count' => $fileCount,
            'total_bytes' => $totalBytes,
            'status' => $status,
            'created_by_admin_id' => $admin->id,
        ]);

        $run->forceFill(['backup_path' => $backupPath])->save();
        $this->pruneOldBackups();

        return $backup;
    }

    private function pruneOldBackups(): void
    {
        $keep = max(1, (int) config('geoflow.update_backup_keep', 10));
        $oldBackups = SystemUpdateBackup::query()
            ->latest('id')
            ->skip($keep)
            ->take(100)
            ->get();

        foreach ($oldBackups as $backup) {
            $path = trim((string) $backup->backup_path, '/');
            if ($path !== '') {
                Storage::disk('local')->deleteDirectory($path);
            }
            $backup->delete();
        }
    }
}
