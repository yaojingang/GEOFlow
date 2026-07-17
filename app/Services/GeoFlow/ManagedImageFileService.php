<?php

namespace App\Services\GeoFlow;

use App\Models\Image;
use App\Models\ManagedImagePath;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

class ManagedImageFileService extends ManagedImagePathHasherV1
{
    private const PATH_LOCK_SECONDS = 300;

    /** @var array<string,int> */
    private array $heldPathLocks = [];

    /** @var array<string,int> */
    private array $heldFenceTokens = [];

    public function canonicalizeExistingPath(string $path): string
    {
        return $this->resolve($path, true)['db_path'];
    }

    /**
     * @return array{filename:string,file_name:string,original_name:string,file_path:string,managed_path_hash:string,file_size:int,mime_type:string,width:int,height:int}
     */
    public function storeUploadedImage(UploadedFile $file): array
    {
        $upload = $this->describeUpload($file);

        return $this->withPathLock($upload['db_path'], function () use ($file, $upload): array {
            $temporaryDiskPath = null;
            $managed = $this->resolve($upload['db_path'], false);
            try {
                if (! $managed['exists']) {
                    if (! Storage::disk('public')->exists($upload['directory'])
                        && ! Storage::disk('public')->makeDirectory($upload['directory'])) {
                        throw new RuntimeException('managed_image_directory_failed');
                    }
                    $this->resolve($upload['db_path'], false);

                    $temporaryFilename = '.'.$upload['filename'].'.'.bin2hex(random_bytes(8)).'.tmp';
                    $temporaryDiskPath = Storage::disk('public')->putFileAs($upload['directory'], $file, $temporaryFilename);
                    if (! is_string($temporaryDiskPath)
                        || $temporaryDiskPath !== $upload['directory'].'/'.$temporaryFilename) {
                        throw new RuntimeException('managed_image_store_failed');
                    }
                    $temporary = $this->resolve('storage/'.$temporaryDiskPath, true);
                    $temporaryHash = hash_file('sha256', $temporary['absolute_path']);
                    if (! is_string($temporaryHash)
                        || ! hash_equals($upload['content_sha256'], $temporaryHash)
                        || @getimagesize($temporary['absolute_path']) === false) {
                        throw new RuntimeException('managed_image_store_verification_failed');
                    }

                    $managed = $this->resolve($upload['db_path'], false);
                    if (! $managed['exists']) {
                        if (! @rename($temporary['absolute_path'], $managed['absolute_path'])) {
                            throw new RuntimeException('managed_image_store_publish_failed');
                        }
                        $temporaryDiskPath = null;
                    }
                }

                $managed = $this->resolve($upload['db_path'], true);
                $fileSize = filesize($managed['absolute_path']);
                $imageInfo = @getimagesize($managed['absolute_path']);
                $storedHash = hash_file('sha256', $managed['absolute_path']);
                if ($fileSize === false || $imageInfo === false || ! is_string($storedHash)
                    || ! hash_equals($upload['content_sha256'], $storedHash)) {
                    throw new RuntimeException('managed_image_metadata_failed');
                }
                if ($temporaryDiskPath !== null) {
                    Storage::disk('public')->delete($temporaryDiskPath);
                    $temporaryDiskPath = null;
                }
                $this->markRegistry($upload['db_path'], 'present', $upload['content_sha256']);

                return [
                    'filename' => $upload['filename'],
                    'file_name' => $upload['filename'],
                    'original_name' => (string) $file->getClientOriginalName(),
                    'file_path' => $managed['db_path'],
                    'managed_path_hash' => $managed['path_hash'],
                    'file_size' => (int) $fileSize,
                    'mime_type' => (string) ($imageInfo['mime'] ?? $upload['mime_type']),
                    'width' => (int) ($imageInfo[0] ?? 0),
                    'height' => (int) ($imageInfo[1] ?? 0),
                ];
            } catch (\Throwable $exception) {
                if ($temporaryDiskPath !== null) {
                    Storage::disk('public')->delete($temporaryDiskPath);
                }

                throw $exception;
            }
        }, $upload['content_sha256']);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withUploadedImagePathLock(UploadedFile $file, callable $callback): mixed
    {
        $upload = $this->describeUpload($file);

        return $this->withPathLock($upload['db_path'], $callback, $upload['content_sha256']);
    }

    public function pathHash(string $path): string
    {
        return $this->resolve($path, false)['path_hash'];
    }

    /**
     * @return array{processed:int,resolved:int,terminal:int,remaining:int,registry_reconciled:int,registry_failed:int,deletion_enabled:bool,ready:bool}
     */
    public function managedPathHashReadiness(bool $reconcileRegistry = true): array
    {
        $processed = 0;
        $resolved = 0;
        $terminal = 0;
        $registryReconciled = 0;
        $registryFailed = 0;

        Image::query()
            ->whereNull('managed_path_hash')
            ->select(['id', 'file_path'])
            ->chunkById(200, function ($images) use (&$processed, &$resolved, &$terminal): void {
                foreach ($images as $image) {
                    $filePath = (string) $image->file_path;
                    $isTerminal = false;
                    try {
                        $pathHash = $this->hashManagedPathV1($filePath);
                    } catch (InvalidArgumentException) {
                        $pathHash = $this->terminalHashV1($filePath);
                        $isTerminal = true;
                    }

                    $updated = Image::query()
                        ->whereKey($image->getKey())
                        ->whereNull('managed_path_hash')
                        ->update(['managed_path_hash' => $pathHash]);
                    if ($updated === 1) {
                        $processed++;
                        if ($isTerminal) {
                            $terminal++;
                        } else {
                            $resolved++;
                        }
                    }
                }
            });

        if ($reconcileRegistry) {
            $reconciledPaths = [];

            Image::query()
                ->select(['id', 'file_path', 'managed_path_hash'])
                ->chunkById(200, function ($images) use (&$terminal, &$registryReconciled, &$registryFailed, &$reconciledPaths): void {
                    foreach ($images as $image) {
                        $filePath = (string) $image->file_path;

                        try {
                            $managed = $this->resolve($filePath, false);
                        } catch (InvalidArgumentException) {
                            $terminal++;

                            continue;
                        }

                        $storedPathHash = (string) $image->managed_path_hash;
                        if ($storedPathHash === '' || ! hash_equals($managed['path_hash'], $storedPathHash)) {
                            $registryFailed++;

                            continue;
                        }
                        if (isset($reconciledPaths[$managed['path_hash']])) {
                            continue;
                        }
                        $reconciledPaths[$managed['path_hash']] = true;

                        if (! $managed['exists']) {
                            try {
                                $this->withPathLock($managed['db_path'], function () use ($managed): void {
                                    $this->markRegistry($managed['db_path'], 'missing');
                                });
                            } catch (\Throwable) {
                                // The failure count below keeps deletion disabled until an operator resolves the path.
                            }
                            $registryFailed++;

                            continue;
                        }

                        try {
                            $this->withPathLock($managed['db_path'], function () use ($managed): void {
                                $current = $this->resolve($managed['db_path'], true);
                                $contentSha256 = hash_file('sha256', $current['absolute_path']);
                                if (! is_string($contentSha256)) {
                                    throw new RuntimeException('managed_image_content_hash_failed');
                                }

                                $this->markRegistry($current['db_path'], 'present', $contentSha256);
                            });
                            $registryReconciled++;
                        } catch (\Throwable) {
                            $registryFailed++;
                        }
                    }
                });
        }

        $remaining = Image::query()->whereNull('managed_path_hash')->count();
        $deletionEnabled = (bool) config('geoflow.managed_image_deletion_enabled', false);

        return [
            'processed' => $processed,
            'resolved' => $resolved,
            'terminal' => $terminal,
            'remaining' => $remaining,
            'registry_reconciled' => $registryReconciled,
            'registry_failed' => $registryFailed,
            'deletion_enabled' => $deletionEnabled,
            'ready' => $deletionEnabled
                && $remaining === 0
                && $terminal === 0
                && $registryFailed === 0,
        ];
    }

    /**
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public function withExistingPathLock(string $path, callable $callback): mixed
    {
        $canonicalPath = $this->canonicalizeExistingPath($path);

        return $this->withPathLock($canonicalPath, function () use ($canonicalPath, $callback): mixed {
            $canonicalPath = $this->canonicalizeExistingPath($canonicalPath);
            $this->markRegistry($canonicalPath, 'present');

            return $callback($canonicalPath);
        });
    }

    /**
     * @param  list<string>  $paths
     */
    public function cleanupUnreferenced(array $paths): int
    {
        $failed = 0;

        foreach (array_values(array_unique($paths)) as $path) {
            try {
                $cleaned = $this->deleteUnreferencedWithFence($path);

                if (! $cleaned) {
                    $failed++;
                    Log::warning('geoflow.managed_image_cleanup_failed', $this->redactedContext($path, 'delete_failed'));
                }
            } catch (InvalidArgumentException $exception) {
                $failed++;
                Log::warning('geoflow.managed_image_cleanup_skipped', $this->redactedContext($path, $exception->getMessage()));
            } catch (LockTimeoutException) {
                $failed++;
                Log::warning('geoflow.managed_image_cleanup_failed', $this->redactedContext($path, 'lock_timeout'));
            } catch (\Throwable) {
                $failed++;
                Log::warning('geoflow.managed_image_cleanup_failed', $this->redactedContext($path, 'operation_failed'));
            }
        }

        return $failed;
    }

    public function discardStoredUpload(string $path): bool
    {
        try {
            $deleted = $this->deleteUnreferencedWithFence($path);
            if (! $deleted) {
                Log::warning('geoflow.managed_image_compensation_failed', $this->redactedContext($path, 'delete_failed'));
            }

            return $deleted;
        } catch (InvalidArgumentException $exception) {
            Log::warning('geoflow.managed_image_compensation_failed', $this->redactedContext($path, $exception->getMessage()));

            return false;
        } catch (LockTimeoutException) {
            Log::warning('geoflow.managed_image_compensation_failed', $this->redactedContext($path, 'lock_timeout'));

            return false;
        } catch (\Throwable) {
            Log::warning('geoflow.managed_image_compensation_failed', $this->redactedContext($path, 'operation_failed'));

            return false;
        }
    }

    private function deleteUnreferencedWithFence(string $path): bool
    {
        $readiness = $this->managedPathHashReadiness(false);
        if (! $readiness['deletion_enabled']) {
            throw new RuntimeException('managed_image_deletion_rollout_gate_closed');
        }
        if ($readiness['remaining'] !== 0) {
            throw new RuntimeException('managed_image_identity_backfill_required');
        }

        /** @var array{path:string,path_hash:string,fence_token:int}|null $intent */
        $intent = $this->withPathLock($path, function () use ($path): ?array {
            $managed = $this->resolve($path, false);
            if ($this->hasPhysicalReference($managed['path_hash'])) {
                $this->markRegistry($managed['db_path'], 'present');

                return null;
            }

            if (! $managed['exists']) {
                $this->markRegistry($managed['db_path'], 'missing');

                return null;
            }

            $fenceToken = $this->currentFenceToken($managed['db_path']);
            $this->markRegistry($managed['db_path'], 'deleting');

            return [
                'path' => $managed['db_path'],
                'path_hash' => $managed['path_hash'],
                'fence_token' => $fenceToken,
            ];
        });

        if ($intent === null) {
            return true;
        }

        $this->afterDeletionPrepared($intent['path'], $intent['fence_token']);
        $current = ManagedImagePath::query()
            ->where('path_hash', $intent['path_hash'])
            ->where('state', 'deleting')
            ->where('lock_version', $intent['fence_token'])
            ->exists();
        if (! $current) {
            return false;
        }

        $managed = $this->resolve($intent['path'], false);
        if ($managed['exists']) {
            $deleted = $managed['disk_path'] !== null
                ? Storage::disk('public')->delete($managed['disk_path'])
                : @unlink($managed['absolute_path']);
            if (! $deleted && is_file($managed['absolute_path'])) {
                return false;
            }
        }

        $finalized = ManagedImagePath::query()
            ->where('path_hash', $intent['path_hash'])
            ->where('state', 'deleting')
            ->where('lock_version', $intent['fence_token'])
            ->update([
                'state' => 'missing',
                'lock_version' => $intent['fence_token'] + 1,
                'updated_at' => now(),
            ]);

        return $finalized === 1;
    }

    protected function afterDeletionPrepared(string $path, int $fenceToken): void
    {
        // Test seam for crash and stale-worker sequencing at the committed deletion boundary.
    }

    private function withPathLock(string $path, callable $callback, ?string $contentSha256 = null): mixed
    {
        $managed = $this->resolve($path, false);
        $path = $managed['db_path'];
        $pathHash = $managed['path_hash'];
        $lockName = 'geoflow:managed-image-path:'.$pathHash;
        if (isset($this->heldPathLocks[$lockName])) {
            $this->heldPathLocks[$lockName]++;

            try {
                if (DB::connection()->transactionLevel() > 0 && isset($this->heldFenceTokens[$lockName])) {
                    return $callback();
                }

                return $this->withRegistryTransaction($path, $pathHash, $contentSha256, $lockName, $callback);
            } finally {
                $this->heldPathLocks[$lockName]--;
            }
        }

        return Cache::lock($lockName, self::PATH_LOCK_SECONDS)->block(1, function () use ($path, $pathHash, $contentSha256, $lockName, $callback, $managed): mixed {
            $this->ensureRegistry($path, $contentSha256, $managed['exists'] ? 'present' : 'missing');
            $this->heldPathLocks[$lockName] = 1;

            try {
                return $this->withRegistryTransaction($path, $pathHash, $contentSha256, $lockName, $callback);
            } finally {
                unset($this->heldPathLocks[$lockName]);
            }
        });
    }

    private function withRegistryTransaction(string $path, string $pathHash, ?string $contentSha256, string $lockName, callable $callback): mixed
    {
        return DB::transaction(function () use ($path, $pathHash, $contentSha256, $lockName, $callback): mixed {
            $locked = ManagedImagePath::query()
                ->where('path_hash', $pathHash)
                ->where('state', '!=', 'deleting')
                ->increment('lock_version');

            $registry = ManagedImagePath::query()
                ->where('path_hash', $pathHash)
                ->lockForUpdate()
                ->firstOrFail();
            $registryIdentity = $this->resolve((string) $registry->file_path, false)['physical_identity'];
            $requestedIdentity = $this->resolve($path, false)['physical_identity'];
            if (! hash_equals($requestedIdentity, $registryIdentity)) {
                throw new RuntimeException('managed_image_registry_mismatch');
            }
            if ($registry->state === 'deleting') {
                throw new RuntimeException('managed_image_deletion_in_progress');
            }
            if ($locked !== 1) {
                throw new RuntimeException('managed_image_registry_lock_failed');
            }
            if ($contentSha256 !== null && $registry->content_sha256 !== null
                && ! hash_equals($contentSha256, (string) $registry->content_sha256)) {
                throw new RuntimeException('managed_image_registry_mismatch');
            }
            if ($contentSha256 !== null && $registry->content_sha256 === null) {
                $registry->update(['content_sha256' => $contentSha256]);
            }

            $previousFenceToken = $this->heldFenceTokens[$lockName] ?? null;
            $this->heldFenceTokens[$lockName] = (int) $registry->lock_version;

            try {
                return $callback();
            } finally {
                if ($previousFenceToken === null) {
                    unset($this->heldFenceTokens[$lockName]);
                } else {
                    $this->heldFenceTokens[$lockName] = $previousFenceToken;
                }
            }
        });
    }

    private function currentFenceToken(string $path): int
    {
        $lockName = 'geoflow:managed-image-path:'.$this->resolve($path, false)['path_hash'];
        $fenceToken = $this->heldFenceTokens[$lockName] ?? null;
        if (! is_int($fenceToken) || DB::connection()->transactionLevel() <= 0) {
            throw new RuntimeException('managed_image_fence_unavailable');
        }

        return $fenceToken;
    }

    private function ensureRegistry(string $path, ?string $contentSha256, string $state): void
    {
        $managed = $this->resolve($path, false);
        $now = now();
        ManagedImagePath::query()->insertOrIgnore([
            'path_hash' => $managed['path_hash'],
            'file_path' => $managed['db_path'],
            'content_sha256' => $contentSha256,
            'state' => $state,
            'lock_version' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function markRegistry(string $path, string $state, ?string $contentSha256 = null): void
    {
        $managed = $this->resolve($path, false);
        $updates = ['state' => $state, 'updated_at' => now()];
        if ($contentSha256 !== null) {
            $updates['content_sha256'] = $contentSha256;
        }

        $registry = ManagedImagePath::query()->where('path_hash', $managed['path_hash'])->first();
        if (! $registry) {
            throw new RuntimeException('managed_image_registry_update_failed');
        }
        $registryIdentity = $this->resolve((string) $registry->file_path, false)['physical_identity'];
        if (! hash_equals($managed['physical_identity'], $registryIdentity)) {
            throw new RuntimeException('managed_image_registry_mismatch');
        }

        $updated = ManagedImagePath::query()->whereKey($registry->getKey())->update($updates);
        if ($updated !== 1) {
            throw new RuntimeException('managed_image_registry_update_failed');
        }
    }

    /**
     * @return array{content_sha256:string,mime_type:string,filename:string,directory:string,disk_path:string,db_path:string}
     */
    private function describeUpload(UploadedFile $file): array
    {
        $mimeType = (string) $file->getMimeType();
        $extension = match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => throw new InvalidArgumentException('unsupported_image_type'),
        };
        $realPath = $file->getRealPath();
        $contentSha256 = is_string($realPath) && is_file($realPath) && is_readable($realPath)
            ? hash_file('sha256', $realPath)
            : false;
        if (! is_string($contentSha256)) {
            throw new InvalidArgumentException('unreadable_upload');
        }

        $filename = $contentSha256.'.'.$extension;
        $directory = 'uploads/images/sha256/'.substr($contentSha256, 0, 2).'/'.substr($contentSha256, 2, 2);
        $diskPath = $directory.'/'.$filename;

        return [
            'content_sha256' => $contentSha256,
            'mime_type' => $mimeType,
            'filename' => $filename,
            'directory' => $directory,
            'disk_path' => $diskPath,
            'db_path' => 'storage/'.$diskPath,
        ];
    }

    /**
     * @return array{path_fingerprint:string,path_length:int,reason:string}
     */
    private function redactedContext(string $path, string $reason): array
    {
        return [
            'path_fingerprint' => substr(hash('sha256', $path), 0, 16),
            'path_length' => strlen($path),
            'reason' => $reason,
        ];
    }

    /**
     * @return array{absolute_path:string,db_path:string,disk_path:?string,exists:bool,physical_identity:string,path_hash:string}
     */
    private function resolve(string $path, bool $mustExist): array
    {
        if ($path === '' || trim($path) !== $path) {
            throw new InvalidArgumentException('invalid_path');
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $path) === 1) {
            throw new InvalidArgumentException('control_character');
        }
        if (str_contains($path, '\\')) {
            throw new InvalidArgumentException('backslash');
        }
        if (str_contains($path, '%')) {
            throw new InvalidArgumentException('percent_encoding');
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:/', $path) === 1) {
            throw new InvalidArgumentException('uri_or_drive_path');
        }
        if (str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new InvalidArgumentException('absolute_path');
        }
        if (str_contains($path, '//')) {
            throw new InvalidArgumentException('duplicate_separator');
        }

        if (str_starts_with($path, 'storage/uploads/images/')) {
            $remainder = substr($path, strlen('storage/uploads/images/'));
            $diskRoot = Storage::disk('public')->path('');
            $root = Storage::disk('public')->path('uploads/images');
            $rootSegments = [$diskRoot, Storage::disk('public')->path('uploads'), $root];
            $diskPath = 'uploads/images/'.$remainder;
            $dbPath = 'storage/uploads/images/'.$remainder;
        } elseif (str_starts_with($path, 'uploads/images/')) {
            $remainder = substr($path, strlen('uploads/images/'));
            $root = public_path('uploads/images');
            $rootSegments = [public_path(), public_path('uploads'), $root];
            $diskPath = null;
            $dbPath = 'uploads/images/'.$remainder;
        } else {
            throw new InvalidArgumentException('unmanaged_prefix');
        }

        $segments = explode('/', $remainder);
        if ($remainder === '' || in_array('', $segments, true) || in_array('.', $segments, true) || in_array('..', $segments, true)) {
            throw new InvalidArgumentException('invalid_segment');
        }

        $absolutePath = $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
        $exists = $this->validateFilesystemBoundary($root, $rootSegments, $segments);
        if ($mustExist && ! $exists) {
            throw new InvalidArgumentException('missing_file');
        }

        $identityPath = $this->physicalIdentityForResolvedPathV1($dbPath, $root);

        return [
            'absolute_path' => $absolutePath,
            'db_path' => $dbPath,
            'disk_path' => $diskPath,
            'exists' => $exists,
            'physical_identity' => $identityPath,
            'path_hash' => hash('sha256', $identityPath),
        ];
    }

    private function hasPhysicalReference(string $pathHash): bool
    {
        return Image::query()->where('managed_path_hash', $pathHash)->exists();
    }

    /**
     * @param  list<string>  $rootSegments
     * @param  list<string>  $segments
     */
    private function validateFilesystemBoundary(string $root, array $rootSegments, array $segments): bool
    {
        foreach ($rootSegments as $rootSegment) {
            if (is_link($rootSegment)) {
                throw new InvalidArgumentException('symlink_segment');
            }
        }
        if (! file_exists($root)) {
            return false;
        }
        if (! is_dir($root)) {
            throw new InvalidArgumentException('invalid_root');
        }

        $realRoot = realpath($root);
        if ($realRoot === false) {
            throw new InvalidArgumentException('invalid_root');
        }

        $current = $root;
        $lastIndex = count($segments) - 1;
        foreach ($segments as $index => $segment) {
            $current .= DIRECTORY_SEPARATOR.$segment;
            if (is_link($current)) {
                throw new InvalidArgumentException('symlink_segment');
            }
            if (! file_exists($current)) {
                return false;
            }
            if ($index < $lastIndex && ! is_dir($current)) {
                throw new InvalidArgumentException('non_directory_segment');
            }
        }

        if (! is_file($current)) {
            throw new InvalidArgumentException('non_regular_file');
        }

        $realPath = realpath($current);
        if ($realPath === false || ! str_starts_with($realPath, $realRoot.DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('outside_managed_root');
        }

        return true;
    }
}
