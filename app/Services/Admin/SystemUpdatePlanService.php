<?php

namespace App\Services\Admin;

use App\Models\Admin;
use App\Models\SystemUpdateRun;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

class SystemUpdatePlanService
{
    public function __construct(
        private readonly AdminUpdateMetadataService $metadataService,
        private readonly SystemUpdateDeploymentDetector $deploymentDetector,
        private readonly SystemUpdateArchiveValidator $archiveValidator,
        private readonly SystemUpdatePathGuard $pathGuard,
    ) {}

    public function createPlan(Admin $admin): SystemUpdateRun
    {
        $state = $this->metadataService->fetchState();
        $archiveUrl = trim((string) ($state['archive_url'] ?? ''));

        if ($archiveUrl === '') {
            throw new RuntimeException(__('admin.system_updates.error.archive_missing'));
        }
        $this->archiveValidator->assertAllowedArchiveUrl($archiveUrl);

        $runUuid = (string) Str::uuid();
        $deployment = $this->deploymentDetector->detect();
        $startedAt = now();
        $baseDir = trim((string) config('geoflow.update_backup_path', 'geoflow-updates'), '/');
        $downloadPath = "{$baseDir}/downloads/{$runUuid}.zip";
        $extractPath = "{$baseDir}/extracted/{$runUuid}";

        $this->downloadArchive($archiveUrl, $downloadPath, trim((string) ($state['archive_sha256'] ?? '')));

        $sourceRoot = $this->extractArchive($downloadPath, $extractPath);
        $plan = $this->buildPlan($sourceRoot, $state, $deployment, $downloadPath, $extractPath);
        $planPath = "{$baseDir}/plans/{$runUuid}.json";
        Storage::disk('local')->put($planPath, json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return SystemUpdateRun::query()->create([
            'run_uuid' => $runUuid,
            'action' => 'plan',
            'status' => 'succeeded',
            'current_version' => (string) ($state['current_version'] ?? ''),
            'target_version' => (string) ($state['latest_version'] ?? ''),
            'current_commit' => (string) ($deployment['current_commit'] ?? ''),
            'target_commit' => (string) ($state['latest_commit'] ?? ''),
            'deployment_mode' => (string) ($deployment['mode'] ?? ''),
            'risk_level' => (string) ($plan['risk_level'] ?? 'low'),
            'plan_json' => $plan,
            'plan_path' => $planPath,
            'started_by_admin_id' => $admin->id,
            'started_at' => $startedAt,
            'finished_at' => now(),
        ]);
    }

    private function downloadArchive(string $archiveUrl, string $downloadPath, string $expectedSha256): void
    {
        $response = Http::timeout(45)->get($archiveUrl);
        if (! $response->successful()) {
            throw new RuntimeException(__('admin.system_updates.error.archive_download_failed', ['status' => $response->status()]));
        }

        $maxBytes = $this->archiveMaxBytes();
        $contentLength = (int) ($response->header('Content-Length') ?? 0);
        if ($contentLength > $maxBytes) {
            throw new RuntimeException(__('admin.system_updates.error.archive_too_large', [
                'max' => $this->formatBytes($maxBytes),
            ]));
        }

        $body = $response->body();
        if ($body === '') {
            throw new RuntimeException(__('admin.system_updates.error.archive_empty'));
        }
        if (strlen($body) > $maxBytes) {
            throw new RuntimeException(__('admin.system_updates.error.archive_too_large', [
                'max' => $this->formatBytes($maxBytes),
            ]));
        }

        if ($expectedSha256 !== '' && ! hash_equals(strtolower($expectedSha256), hash('sha256', $body))) {
            throw new RuntimeException(__('admin.system_updates.error.archive_checksum_failed'));
        }

        Storage::disk('local')->put($downloadPath, $body);
    }

    private function extractArchive(string $downloadPath, string $extractPath): string
    {
        $zipPath = Storage::disk('local')->path($downloadPath);
        $targetPath = Storage::disk('local')->path($extractPath);
        File::ensureDirectoryExists($targetPath);

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException(__('admin.system_updates.error.archive_open_failed'));
        }

        $extracted = false;
        try {
            $this->validateArchiveEntries($zip);
            $extracted = $zip->extractTo($targetPath);
        } finally {
            $closed = $zip->close();
        }

        if (! $extracted || ! $closed) {
            throw new RuntimeException(__('admin.system_updates.error.archive_extract_failed'));
        }

        $directories = File::directories($targetPath);
        $files = File::files($targetPath);

        if (count($directories) === 1 && count($files) === 0) {
            return $directories[0];
        }

        return $targetPath;
    }

    private function validateArchiveEntries(ZipArchive $zip): void
    {
        $fileCount = 0;
        $totalUncompressedBytes = 0;
        $maxFiles = max(1, (int) config('geoflow.update_archive_max_files', 2000));
        $maxFileBytes = max(1, (int) config('geoflow.update_archive_max_file_bytes', 50 * 1024 * 1024));
        $maxUncompressedBytes = max(1, (int) config('geoflow.update_archive_max_uncompressed_bytes', 150 * 1024 * 1024));

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);
            if (! is_string($entryName) || $entryName === '') {
                throw new RuntimeException(__('admin.system_updates.error.archive_unsafe'));
            }

            $normalized = str_replace('\\', '/', $entryName);
            $segments = array_filter(explode('/', $normalized), fn (string $segment): bool => $segment !== '');

            if (
                str_starts_with($normalized, '/')
                || str_contains($normalized, '//')
                || str_contains($normalized, "\0")
                || preg_match('/^[A-Za-z]:\//', $normalized) === 1
                || in_array('..', $segments, true)
                || in_array('.', $segments, true)
            ) {
                throw new RuntimeException(__('admin.system_updates.error.archive_unsafe'));
            }

            if (str_ends_with($normalized, '/')) {
                continue;
            }

            $stat = $zip->statIndex($index);
            if (! is_array($stat)) {
                throw new RuntimeException(__('admin.system_updates.error.archive_unsafe'));
            }

            $fileCount++;
            if ($fileCount > $maxFiles) {
                throw new RuntimeException(__('admin.system_updates.error.archive_too_many_files', ['max' => $maxFiles]));
            }

            $size = max(0, (int) ($stat['size'] ?? 0));
            if ($size > $maxFileBytes) {
                throw new RuntimeException(__('admin.system_updates.error.archive_file_too_large', [
                    'max' => $this->formatBytes($maxFileBytes),
                ]));
            }

            $totalUncompressedBytes += $size;
            if ($totalUncompressedBytes > $maxUncompressedBytes) {
                throw new RuntimeException(__('admin.system_updates.error.archive_uncompressed_too_large', [
                    'max' => $this->formatBytes($maxUncompressedBytes),
                ]));
            }
        }
    }

    private function archiveMaxBytes(): int
    {
        return max(1, (int) config('geoflow.update_archive_max_bytes', 50 * 1024 * 1024));
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 1).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $deployment
     * @return array<string, mixed>
     */
    private function buildPlan(string $sourceRoot, array $state, array $deployment, string $downloadPath, string $extractPath): array
    {
        $changes = [];
        $releasePaths = [];

        foreach ($this->releaseFiles($sourceRoot) as $item) {
            $relativePath = $item['relative_path'];
            if (! $this->pathGuard->isAllowedPath($relativePath)) {
                continue;
            }
            $relativePath = $this->pathGuard->normalize($relativePath);
            $releasePaths[$relativePath] = true;

            $localPath = base_path($relativePath);
            $newHash = hash_file('sha256', $item['path']);
            $oldHash = is_file($localPath) ? hash_file('sha256', $localPath) : '';

            if ($oldHash !== '' && hash_equals($oldHash, $newHash)) {
                continue;
            }

            $changes[] = [
                'path' => $relativePath,
                'action' => $oldHash === '' ? 'added' : 'modified',
                'old_sha256' => $oldHash,
                'new_sha256' => $newHash,
                'bytes' => (int) filesize($item['path']),
            ];
        }

        if ($this->shouldDetectDeletedFiles($releasePaths)) {
            foreach ($this->localComparableFiles() as $relativePath => $localPath) {
                if (isset($releasePaths[$relativePath])) {
                    continue;
                }

                $changes[] = [
                    'path' => $relativePath,
                    'action' => 'deleted',
                    'old_sha256' => hash_file('sha256', $localPath),
                    'new_sha256' => '',
                    'bytes' => (int) filesize($localPath),
                ];
            }
        }

        usort($changes, fn (array $a, array $b): int => strcmp((string) ($a['path'] ?? ''), (string) ($b['path'] ?? '')));

        $flags = $this->planFlags($changes);

        return [
            'generated_at' => now()->toDateTimeString(),
            'current_version' => (string) ($state['current_version'] ?? ''),
            'target_version' => (string) ($state['latest_version'] ?? ''),
            'current_commit' => (string) ($deployment['current_commit'] ?? ''),
            'target_commit' => (string) ($state['latest_commit'] ?? ''),
            'deployment_mode' => (string) ($deployment['mode'] ?? ''),
            'release_archive_path' => $downloadPath,
            'extracted_path' => $extractPath,
            'source_root_path' => $this->sourceRootStoragePath($sourceRoot),
            'summary' => [
                'added' => $this->countAction($changes, 'added'),
                'modified' => $this->countAction($changes, 'modified'),
                'deleted' => $this->countAction($changes, 'deleted'),
                'total' => count($changes),
            ],
            'flags' => $flags,
            'manual_commands' => $this->manualCommands($flags, $deployment),
            'update_script' => $this->updateScript($flags, $deployment),
            'risk_level' => $this->riskLevel($flags, $changes),
            'changes' => $changes,
        ];
    }

    /**
     * @return array<int, array{path: string, relative_path: string}>
     */
    private function releaseFiles(string $sourceRoot): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = str_replace('\\', '/', ltrim(substr($path, strlen($sourceRoot)), DIRECTORY_SEPARATOR));
            $files[] = [
                'path' => $path,
                'relative_path' => $relativePath,
            ];
        }

        usort($files, fn (array $a, array $b): int => strcmp($a['relative_path'], $b['relative_path']));

        return $files;
    }

    /**
     * Only a complete release archive can safely imply local deletions. Partial
     * fixtures or future patch archives should not turn every absent file into
     * a deleted-file operation.
     *
     * @param  array<string, bool>  $releasePaths
     */
    private function shouldDetectDeletedFiles(array $releasePaths): bool
    {
        return isset($releasePaths['artisan'], $releasePaths['composer.json']);
    }

    /**
     * @return array<string, string>
     */
    private function localComparableFiles(): array
    {
        return $this->gitTrackedFiles();
    }

    /**
     * @return array<string, string>
     */
    private function gitTrackedFiles(): array
    {
        if (! file_exists(base_path('.git'))) {
            return [];
        }

        $command = 'git -C '.escapeshellarg(base_path()).' ls-files 2>/dev/null';
        $output = [];
        $exitCode = 1;
        exec($command, $output, $exitCode);
        if ($exitCode !== 0 || $output === []) {
            return [];
        }

        $files = [];
        foreach ($output as $relativePath) {
            $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
            if ($relativePath === '' || ! $this->pathGuard->isAllowedPath($relativePath)) {
                continue;
            }

            $relativePath = $this->pathGuard->normalize($relativePath);

            $path = base_path($relativePath);
            if (is_file($path)) {
                $files[$relativePath] = $path;
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<string, bool>
     */
    private function planFlags(array $changes): array
    {
        return [
            'requires_composer' => $this->touchesAny($changes, ['composer.json', 'composer.lock']),
            'requires_npm_build' => $this->touchesAny($changes, ['package.json', 'package-lock.json', 'vite.config.js', 'resources/', 'public/js/', 'public/css/']),
            'requires_migration' => $this->touchesAny($changes, ['database/migrations/']),
            'touches_docker' => $this->touchesAny($changes, ['docker/', 'docker-compose.yml', 'docker-compose.prod.yml']),
            'touches_config' => $this->touchesAny($changes, ['config/']),
            'touches_routes' => $this->touchesAny($changes, ['routes/']),
        ];
    }

    /**
     * @param  array<string, bool>  $flags
     * @param  array<string, mixed>  $deployment
     * @return array<int, array{key: string, command: string, level: string}>
     */
    private function manualCommands(array $flags, array $deployment): array
    {
        $commands = [
            ['key' => 'maintenance_down', 'command' => 'php artisan down || true', 'level' => 'recommended'],
        ];

        if (! empty($flags['requires_composer'])) {
            $commands[] = ['key' => 'composer_install', 'command' => 'composer install --no-dev --optimize-autoloader', 'level' => 'required'];
        }

        if (! empty($flags['requires_npm_build'])) {
            $commands[] = ['key' => 'frontend_build', 'command' => 'npm ci && npm run build', 'level' => 'required'];
        }

        if (! empty($flags['requires_migration'])) {
            $commands[] = ['key' => 'migrate', 'command' => 'php artisan migrate --force', 'level' => 'required'];
        }

        if (! empty($flags['touches_docker'])) {
            $commands[] = ['key' => 'docker_rebuild', 'command' => $this->dockerRebuildCommand($deployment), 'level' => 'deployment'];
        }

        $commands[] = ['key' => 'clear_cache', 'command' => 'php artisan optimize:clear', 'level' => 'recommended'];
        $commands[] = ['key' => 'restart_queue', 'command' => 'php artisan queue:restart', 'level' => 'recommended'];
        $commands[] = ['key' => 'maintenance_up', 'command' => 'php artisan up', 'level' => 'recommended'];

        return $commands;
    }

    /**
     * @param  array<string, bool>  $flags
     * @param  array<string, mixed>  $deployment
     */
    private function updateScript(array $flags, array $deployment): string
    {
        return implode("\n", array_map(
            static fn (array $item): string => (string) $item['command'],
            $this->manualCommands($flags, $deployment)
        ));
    }

    /**
     * @param  array<string, mixed>  $deployment
     */
    private function dockerRebuildCommand(array $deployment): string
    {
        $mode = (string) ($deployment['mode'] ?? '');
        if (($mode === 'docker_image' || app()->environment('production')) && is_file(base_path('docker-compose.prod.yml'))) {
            return 'docker compose --env-file .env.prod -f docker-compose.prod.yml up -d --build';
        }

        return 'docker compose up -d --build';
    }

    private function sourceRootStoragePath(string $sourceRoot): string
    {
        $storageRoot = rtrim(str_replace('\\', '/', Storage::disk('local')->path('')), '/').'/';
        $sourceRoot = str_replace('\\', '/', $sourceRoot);

        if (str_starts_with($sourceRoot, $storageRoot)) {
            return ltrim(substr($sourceRoot, strlen($storageRoot)), '/');
        }

        return '';
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @param  array<int, string>  $needles
     */
    private function touchesAny(array $changes, array $needles): bool
    {
        foreach ($changes as $change) {
            $path = (string) ($change['path'] ?? '');
            foreach ($needles as $needle) {
                if ($path === $needle || str_starts_with($path, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string, bool>  $flags
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function riskLevel(array $flags, array $changes): string
    {
        if (($flags['requires_composer'] ?? false) || ($flags['requires_migration'] ?? false) || ($flags['touches_docker'] ?? false)) {
            return 'high';
        }

        if (($flags['touches_config'] ?? false) || ($flags['touches_routes'] ?? false) || count($changes) > 25) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     */
    private function countAction(array $changes, string $action): int
    {
        return count(array_filter($changes, fn (array $change): bool => ($change['action'] ?? '') === $action));
    }
}
