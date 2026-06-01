<?php

namespace App\Services\Admin;

use App\Models\SystemUpdateBackup;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SystemUpdateBackupInspectionService
{
    public function __construct(
        private readonly SystemUpdatePathGuard $pathGuard,
    ) {}

    /**
     * @return array{manifest: array<string, mixed>, files: array<int, array<string, mixed>>, preflight: array{pass: int, warn: int, fail: int, total: int, status: string}}
     */
    public function inspect(SystemUpdateBackup $backup): array
    {
        $manifest = $this->readManifest($backup);
        $files = [];
        $preflight = [
            'pass' => 0,
            'warn' => 0,
            'fail' => 0,
            'total' => 0,
            'status' => 'pass',
        ];

        foreach ($this->manifestFiles($manifest) as $file) {
            $inspected = $this->inspectFile($file);
            $files[] = $inspected;

            $status = (string) $inspected['preflight_status'];
            if (isset($preflight[$status])) {
                $preflight[$status]++;
            }
            $preflight['total']++;
        }

        $preflight['status'] = $preflight['fail'] > 0 ? 'fail' : ($preflight['warn'] > 0 ? 'warn' : 'pass');

        return [
            'manifest' => $manifest,
            'files' => $files,
            'preflight' => $preflight,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function readManifest(SystemUpdateBackup $backup): array
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

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<int, array<string, mixed>>
     */
    private function manifestFiles(array $manifest): array
    {
        return is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    private function inspectFile(array $file): array
    {
        $action = (string) ($file['action'] ?? '');
        $relativePath = $this->pathGuard->assertAllowedPath((string) ($file['path'] ?? ''));
        $targetPath = base_path($relativePath);
        $exists = is_file($targetPath);
        $currentSha256 = $exists ? hash_file('sha256', $targetPath) : '';
        $oldSha256 = (string) ($file['old_sha256'] ?? $file['sha256'] ?? '');
        $newSha256 = (string) ($file['new_sha256'] ?? '');
        $status = 'pass';
        $messageKey = 'ready';
        $canRestore = true;

        if ($action === 'added') {
            if (! $exists) {
                $messageKey = 'added_already_missing';
            } elseif ($newSha256 !== '' && hash_equals($newSha256, $currentSha256)) {
                $messageKey = 'added_ready_remove';
            } else {
                $status = 'fail';
                $messageKey = 'target_changed';
                $canRestore = false;
            }
        } elseif ($action === 'modified') {
            if (! $exists) {
                $status = 'warn';
                $messageKey = 'target_missing_restore';
            } elseif ($newSha256 !== '' && ! hash_equals($newSha256, $currentSha256)) {
                $status = 'fail';
                $messageKey = 'target_changed';
                $canRestore = false;
            } else {
                $messageKey = 'ready_restore';
            }
        } elseif ($action === 'deleted') {
            if (! $exists) {
                $messageKey = 'deleted_ready_restore';
            } elseif ($oldSha256 !== '' && hash_equals($oldSha256, $currentSha256)) {
                $messageKey = 'deleted_already_restored';
            } else {
                $status = 'fail';
                $messageKey = 'target_changed';
                $canRestore = false;
            }
        } else {
            $status = 'warn';
            $messageKey = 'unknown_action';
            $canRestore = false;
        }

        return array_merge($file, [
            'path' => $relativePath,
            'action' => $action,
            'current_sha256' => $currentSha256,
            'target_exists' => $exists,
            'preflight_status' => $status,
            'preflight_message' => __('admin.system_updates.rollback_preflight.'.$messageKey),
            'can_restore' => $canRestore,
        ]);
    }
}
