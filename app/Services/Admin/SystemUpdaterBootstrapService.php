<?php

namespace App\Services\Admin;

use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\SystemUpdater\SystemUpdaterPlatform;
use App\Services\SystemUpdater\TufBootstrapVerifier;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class SystemUpdaterBootstrapService
{
    private const MANIFEST_MAX_BYTES = 1024 * 1024;

    private const STATE_PATH = 'system-updater/bootstrap/current.json';

    public function __construct(
        private readonly TufBootstrapVerifier $verifier,
        private readonly SystemUpdaterPlatform $platform,
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly Factory $http,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function prepare(): array
    {
        $manifestUrl = (string) config('geoflow.updater_bootstrap_manifest_url');
        $trustedRootPath = (string) config('geoflow.updater_trusted_root_path');
        if ($manifestUrl !== 'https://github.com/yaojingang/geoflow-updater/releases/latest/download/bootstrap-manifest.json') {
            throw new RuntimeException('Updater bootstrap source is invalid.');
        }

        $trustedRoot = @file_get_contents($trustedRootPath);
        if (! is_string($trustedRoot) || $trustedRoot === '') {
            throw new RuntimeException('Updater trusted root is unavailable.');
        }

        $manifestResponse = $this->safeHttp->get(
            $this->http->acceptJson()->connectTimeout(5)->timeout(15),
            $manifestUrl,
            self::MANIFEST_MAX_BYTES,
            2,
            [],
            $this->validateRedirect(...),
        )->throw();
        $envelope = $manifestResponse->body();
        if (mb_strlen($envelope, '8bit') > self::MANIFEST_MAX_BYTES) {
            throw new RuntimeException('Updater bootstrap manifest exceeded the size limit.');
        }

        $manifest = $this->verifier->verify($envelope, $trustedRoot);
        $platform = $this->platform->current();
        $asset = is_array($manifest['assets'][$platform] ?? null) ? $manifest['assets'][$platform] : null;
        if ($asset === null) {
            throw new RuntimeException('The signed updater release has no package for this host.');
        }

        $version = (string) ($manifest['updater_version'] ?? '');
        $url = (string) ($asset['url'] ?? '');
        $digest = (string) ($asset['sha256'] ?? '');
        $size = (int) ($asset['size'] ?? 0);
        $filename = basename((string) parse_url($url, PHP_URL_PATH));
        $expectedFilename = 'geoflow-updater_'.$version.'_'.str_replace('-', '_', $platform).'.tar.gz';
        $maxBytes = (int) config('geoflow.updater_bootstrap_max_bytes', 100 * 1024 * 1024);
        if ($filename !== $expectedFilename || $size < 1 || $size > $maxBytes) {
            throw new RuntimeException('The signed updater package description is invalid.');
        }

        $disk = Storage::disk('local');
        $relativePath = 'system-updater/bootstrap/'.$version.'/'.$filename;
        $destination = $disk->path($relativePath);
        $this->ensureSafeDirectory($disk->path('system-updater/bootstrap'));
        $this->ensureSafeDirectory(dirname($destination));
        $temporary = $destination.'.part.'.bin2hex(random_bytes(8));

        try {
            $archiveResponse = $this->safeHttp->get(
                $this->http->accept('application/gzip')
                    ->connectTimeout(5)
                    ->timeout(120)
                    ->sink($temporary),
                $url,
                $maxBytes,
                1,
                [],
                $this->validateRedirect(...),
            )->throw();
            $contentLength = $archiveResponse->header('Content-Length');
            if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength !== $size) {
                throw new RuntimeException('Updater package length does not match the signed manifest.');
            }
            if (! is_file($temporary)
                || filesize($temporary) !== $size
                || ! hash_equals($digest, (string) hash_file('sha256', $temporary))) {
                throw new RuntimeException('Updater package integrity validation failed.');
            }

            chmod($temporary, 0640);
            if (! rename($temporary, $destination)) {
                throw new RuntimeException('Updater package could not be staged.');
            }

            $state = [
                'version' => $version,
                'filename' => $filename,
                'path' => $relativePath,
                'sha256' => $digest,
                'size' => $size,
                'platform' => $platform,
                'expires' => (string) ($manifest['expires'] ?? ''),
                'prepared_at' => now()->utc()->toIso8601String(),
            ];
            $this->writeState($disk, $state);

            return $state;
        } catch (Throwable $exception) {
            @unlink($temporary);
            if (is_file($destination)
                && (filesize($destination) !== $size
                    || ! hash_equals($digest, (string) hash_file('sha256', $destination)))) {
                @unlink($destination);
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function state(): ?array
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::STATE_PATH)) {
            return null;
        }
        $stateInfo = @lstat($disk->path(self::STATE_PATH));
        if (! is_array($stateInfo) || (($stateInfo['mode'] ?? 0) & 0170000) !== 0100000) {
            return null;
        }

        try {
            $decoded = json_decode((string) $disk->get(self::STATE_PATH), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) && $this->validStateShape($decoded) ? $decoded : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function download(): array
    {
        $state = $this->state();
        $disk = Storage::disk('local');
        if ($state === null || ! $disk->exists((string) $state['path'])) {
            throw new RuntimeException('The prepared updater package is unavailable.');
        }

        $path = $disk->path((string) $state['path']);
        if (! $this->isSafePreparedFile($disk, $path)
            || filesize($path) !== (int) $state['size']
            || ! hash_equals((string) $state['sha256'], (string) hash_file('sha256', $path))) {
            throw new RuntimeException('The prepared updater package is no longer valid.');
        }

        return $state;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function writeState(FilesystemAdapter $disk, array $state): void
    {
        $temporaryPath = self::STATE_PATH.'.tmp.'.bin2hex(random_bytes(8));
        $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        if (! $disk->put($temporaryPath, $encoded) || ! $disk->move($temporaryPath, self::STATE_PATH)) {
            $disk->delete($temporaryPath);
            throw new RuntimeException('Updater package state could not be stored.');
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validStateShape(array $state): bool
    {
        $expectedKeys = ['expires', 'filename', 'path', 'platform', 'prepared_at', 'sha256', 'size', 'version'];
        $actualKeys = array_keys($state);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            return false;
        }

        $path = $state['path'] ?? null;
        $filename = $state['filename'] ?? null;
        $version = $state['version'] ?? null;
        $platform = $state['platform'] ?? null;
        if (! is_string($version)
            || preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?\z/', $version) !== 1
            || ! is_string($platform)
            || ! in_array($platform, ['linux-amd64', 'linux-arm64'], true)) {
            return false;
        }
        $expectedFilename = 'geoflow-updater_'.$version.'_'.str_replace('-', '_', $platform).'.tar.gz';
        $expectedPath = 'system-updater/bootstrap/'.$version.'/'.$expectedFilename;
        $expires = $state['expires'] ?? null;
        $preparedAt = $state['prepared_at'] ?? null;
        $expiryTimestamp = null;
        $preparedTimestamp = null;
        try {
            $expiryTimestamp = is_string($expires)
                && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?Z\z/', $expires) === 1
                    ? new \DateTimeImmutable($expires)
                    : null;
            $preparedTimestamp = is_string($preparedAt)
                && preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|\+00:00)\z/', $preparedAt) === 1
                    ? new \DateTimeImmutable($preparedAt)
                    : null;
        } catch (Throwable) {
        }

        return $filename === $expectedFilename
            && is_string($path)
            && $path === $expectedPath
            && is_string($state['sha256'] ?? null)
            && preg_match('/\A[a-f0-9]{64}\z/', $state['sha256']) === 1
            && is_int($state['size'] ?? null)
            && $state['size'] > 0
            && $state['size'] <= (int) config('geoflow.updater_bootstrap_max_bytes', 100 * 1024 * 1024)
            && $expiryTimestamp instanceof \DateTimeImmutable
            && $expiryTimestamp > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            && $preparedTimestamp instanceof \DateTimeImmutable
            && $preparedTimestamp->getOffset() === 0;
    }

    private function ensureSafeDirectory(string $path): void
    {
        File::ensureDirectoryExists($path, 0750, true);
        $info = @lstat($path);
        if (! is_array($info) || (($info['mode'] ?? 0) & 0170000) !== 0040000) {
            throw new RuntimeException('Updater bootstrap storage path is unsafe.');
        }
    }

    private function isSafePreparedFile(FilesystemAdapter $disk, string $path): bool
    {
        $info = @lstat($path);
        if (! is_array($info) || (($info['mode'] ?? 0) & 0170000) !== 0100000) {
            return false;
        }
        $bootstrapPath = $disk->path('system-updater/bootstrap');
        $versionPath = dirname($path);
        foreach ([$bootstrapPath, $versionPath] as $directory) {
            $directoryInfo = @lstat($directory);
            if (! is_array($directoryInfo) || (($directoryInfo['mode'] ?? 0) & 0170000) !== 0040000) {
                return false;
            }
        }
        $realBootstrap = realpath($bootstrapPath);
        $realFile = realpath($path);

        return is_string($realBootstrap)
            && is_string($realFile)
            && str_starts_with($realFile, $realBootstrap.DIRECTORY_SEPARATOR);
    }

    private function validateRedirect(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = (int) ($parts['port'] ?? 443);
        if (($parts['scheme'] ?? null) !== 'https'
            || $port !== 443
            || isset($parts['user'])
            || isset($parts['pass'])
            || ! in_array($host, [
                'github.com',
                'objects.githubusercontent.com',
                'release-assets.githubusercontent.com',
            ], true)) {
            throw new RuntimeException('Updater download redirect left the official GitHub release service.');
        }
    }
}
