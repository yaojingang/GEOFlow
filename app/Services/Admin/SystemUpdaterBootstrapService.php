<?php

namespace App\Services\Admin;

use App\Exceptions\SystemUpdaterPreparationException;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
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

    private const SEQUENCE_PATH = 'system-updater/bootstrap/highest-sequence.json';

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
        if ($manifestUrl !== 'https://github.com/yaojingang/geoflow-updater/releases/latest/download/bootstrap-manifest.json') {
            throw new RuntimeException('Updater bootstrap source is invalid.');
        }

        $trustedRoot = $this->trustedRoot();

        try {
            $manifestResponse = $this->safeHttp->get(
                $this->http->acceptJson()->connectTimeout(5)->timeout(15),
                $manifestUrl,
                self::MANIFEST_MAX_BYTES,
                2,
                [],
                $this->validateRedirect(...),
            );
        } catch (OutboundRequestBlockedException|OutboundRequestFailedException $exception) {
            $this->throwOutboundFailure($exception);
        }
        $manifestResponse->throw();
        $envelope = $manifestResponse->body();
        if (mb_strlen($envelope, '8bit') > self::MANIFEST_MAX_BYTES) {
            throw SystemUpdaterPreparationException::verificationFailed(
                new RuntimeException('Updater bootstrap manifest exceeded the size limit.'),
            );
        }

        $manifest = $this->verifier->verify($envelope, $trustedRoot);
        $releaseSequence = (int) ($manifest['release_sequence'] ?? 0);
        $disk = Storage::disk('local');
        if ($releaseSequence < $this->highestAcceptedSequence($disk)) {
            throw SystemUpdaterPreparationException::verificationFailed(
                new RuntimeException('Updater bootstrap release rollback was rejected.'),
            );
        }
        try {
            $platform = $this->platform->current();
        } catch (Throwable $exception) {
            throw SystemUpdaterPreparationException::platformUnsupported($exception);
        }
        $asset = is_array($manifest['assets'][$platform] ?? null) ? $manifest['assets'][$platform] : null;
        if ($asset === null) {
            throw SystemUpdaterPreparationException::platformUnsupported(
                new RuntimeException('The signed updater release has no package for this host.'),
            );
        }

        $version = (string) ($manifest['updater_version'] ?? '');
        $url = (string) ($asset['url'] ?? '');
        $digest = (string) ($asset['sha256'] ?? '');
        $size = (int) ($asset['size'] ?? 0);
        $filename = basename((string) parse_url($url, PHP_URL_PATH));
        $expectedFilename = 'geoflow-updater_'.$version.'_'.str_replace('-', '_', $platform).'.tar.gz';
        $maxBytes = (int) config('geoflow.updater_bootstrap_max_bytes', 100 * 1024 * 1024);
        if ($filename !== $expectedFilename || $size < 1 || $size > $maxBytes) {
            throw SystemUpdaterPreparationException::verificationFailed(
                new RuntimeException('The signed updater package description is invalid.'),
            );
        }

        $relativePath = 'system-updater/bootstrap/'.$version.'/'.$filename;
        $envelopePath = 'system-updater/bootstrap/'.$version.'/bootstrap-manifest.json';
        $destination = $disk->path($relativePath);
        $this->ensureSafeDirectory($disk->path('system-updater/bootstrap'));
        $this->ensureSafeDirectory(dirname($destination));
        $temporary = $destination.'.part.'.bin2hex(random_bytes(8));

        try {
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
                );
            } catch (OutboundRequestBlockedException|OutboundRequestFailedException $exception) {
                $this->throwOutboundFailure($exception);
            }
            $archiveResponse->throw();
            $contentLength = $archiveResponse->header('Content-Length');
            if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength !== $size) {
                throw SystemUpdaterPreparationException::verificationFailed(
                    new RuntimeException('Updater package length does not match the signed manifest.'),
                );
            }
            if (! is_file($temporary)
                || filesize($temporary) !== $size
                || ! hash_equals($digest, (string) hash_file('sha256', $temporary))) {
                throw SystemUpdaterPreparationException::verificationFailed(
                    new RuntimeException('Updater package integrity validation failed.'),
                );
            }

            chmod($temporary, 0640);
            if (! rename($temporary, $destination)) {
                throw SystemUpdaterPreparationException::storageFailed(
                    new RuntimeException('Updater package could not be staged.'),
                );
            }

            $this->writePrivateFile($disk, $envelopePath, $envelope);
            $this->writeHighestSequence($disk, $releaseSequence);

            $state = [
                'release_sequence' => $releaseSequence,
                'version' => $version,
                'filename' => $filename,
                'path' => $relativePath,
                'envelope_path' => $envelopePath,
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
        $state = $this->readState();
        if ($state === null) {
            return null;
        }

        try {
            return $this->verifyPreparedState(Storage::disk('local'), $state);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readState(): ?array
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
        $state = $this->readState();
        $disk = Storage::disk('local');
        if ($state === null) {
            throw new RuntimeException('The prepared updater package is unavailable.');
        }

        try {
            $state = $this->verifyPreparedState($disk, $state);
        } catch (Throwable $exception) {
            throw new RuntimeException('The prepared updater package is no longer valid.', 0, $exception);
        }

        $path = $disk->path((string) $state['path']);
        if (! $disk->exists((string) $state['path'])
            || ! $this->isSafePreparedFile($disk, $path)
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
        $encoded = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";
        $this->writePrivateFile($disk, self::STATE_PATH, $encoded);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function validStateShape(array $state): bool
    {
        $expectedKeys = ['envelope_path', 'expires', 'filename', 'path', 'platform', 'prepared_at', 'release_sequence', 'sha256', 'size', 'version'];
        $actualKeys = array_keys($state);
        sort($actualKeys, SORT_STRING);
        if ($actualKeys !== $expectedKeys) {
            return false;
        }

        $path = $state['path'] ?? null;
        $envelopePath = $state['envelope_path'] ?? null;
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
            && is_string($envelopePath)
            && $envelopePath === 'system-updater/bootstrap/'.$version.'/bootstrap-manifest.json'
            && is_int($state['release_sequence'] ?? null)
            && $state['release_sequence'] > 0
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

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private function verifyPreparedState(FilesystemAdapter $disk, array $state): array
    {
        $envelopePath = (string) $state['envelope_path'];
        if (! $disk->exists($envelopePath)) {
            throw new RuntimeException('Signed updater bootstrap manifest is unavailable.');
        }
        $absoluteEnvelopePath = $disk->path($envelopePath);
        $envelopeInfo = @lstat($absoluteEnvelopePath);
        if (! $this->isSafePreparedFile($disk, $absoluteEnvelopePath)
            || ! is_array($envelopeInfo)
            || ($envelopeInfo['size'] ?? 0) < 1
            || ($envelopeInfo['size'] ?? 0) > self::MANIFEST_MAX_BYTES) {
            throw new RuntimeException('Signed updater bootstrap manifest is unsafe.');
        }
        $envelope = $disk->get($envelopePath);
        $manifest = $this->verifier->verify($envelope, $this->trustedRoot());
        $sequence = (int) ($manifest['release_sequence'] ?? 0);
        if ($sequence < $this->highestAcceptedSequence($disk)) {
            throw new RuntimeException('Updater bootstrap release rollback was rejected.');
        }

        $platform = $this->platform->current();
        $asset = is_array($manifest['assets'][$platform] ?? null) ? $manifest['assets'][$platform] : null;
        if ($asset === null) {
            throw new RuntimeException('The signed updater release has no package for this host.');
        }
        $version = (string) ($manifest['updater_version'] ?? '');
        $url = (string) ($asset['url'] ?? '');
        $filename = basename((string) parse_url($url, PHP_URL_PATH));
        $expected = [
            'release_sequence' => $sequence,
            'version' => $version,
            'filename' => $filename,
            'path' => 'system-updater/bootstrap/'.$version.'/'.$filename,
            'envelope_path' => 'system-updater/bootstrap/'.$version.'/bootstrap-manifest.json',
            'sha256' => (string) ($asset['sha256'] ?? ''),
            'size' => (int) ($asset['size'] ?? 0),
            'platform' => $platform,
            'expires' => (string) ($manifest['expires'] ?? ''),
            'prepared_at' => (string) ($state['prepared_at'] ?? ''),
        ];
        if ($state !== $expected || ! $this->validStateShape($expected)) {
            throw new RuntimeException('Prepared updater state does not match its signed manifest.');
        }

        return $expected;
    }

    private function trustedRoot(): string
    {
        $trustedRoot = @file_get_contents((string) config('geoflow.updater_trusted_root_path'));
        if (! is_string($trustedRoot) || $trustedRoot === '') {
            throw SystemUpdaterPreparationException::verificationFailed(
                new RuntimeException('Updater trusted root is unavailable.'),
            );
        }

        return $trustedRoot;
    }

    private function highestAcceptedSequence(FilesystemAdapter $disk): int
    {
        if (! $disk->exists(self::SEQUENCE_PATH)) {
            return 0;
        }
        $info = @lstat($disk->path(self::SEQUENCE_PATH));
        if (! is_array($info)
            || (($info['mode'] ?? 0) & 0170000) !== 0100000
            || ($info['size'] ?? 0) < 1
            || ($info['size'] ?? 0) > 1024) {
            throw SystemUpdaterPreparationException::verificationFailed(
                new RuntimeException('Updater bootstrap sequence state is invalid.'),
            );
        }
        try {
            $decoded = json_decode($disk->get(self::SEQUENCE_PATH), true, 4, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            throw SystemUpdaterPreparationException::verificationFailed(
                new RuntimeException('Updater bootstrap sequence state is invalid.', 0, $exception),
            );
        }
        if (! is_array($decoded)
            || array_keys($decoded) !== ['release_sequence']
            || ! is_int($decoded['release_sequence'])
            || $decoded['release_sequence'] < 1) {
            throw SystemUpdaterPreparationException::verificationFailed(
                new RuntimeException('Updater bootstrap sequence state is invalid.'),
            );
        }

        return $decoded['release_sequence'];
    }

    private function writeHighestSequence(FilesystemAdapter $disk, int $sequence): void
    {
        if ($sequence <= $this->highestAcceptedSequence($disk)) {
            return;
        }
        $encoded = json_encode(['release_sequence' => $sequence], JSON_THROW_ON_ERROR)."\n";
        $this->writePrivateFile($disk, self::SEQUENCE_PATH, $encoded);
    }

    private function writePrivateFile(FilesystemAdapter $disk, string $path, string $contents): void
    {
        try {
            $this->ensureSafeDirectory(dirname($disk->path($path)));
            $temporaryPath = $path.'.tmp.'.bin2hex(random_bytes(8));
            if (! $disk->put($temporaryPath, $contents)) {
                throw new RuntimeException('Updater package state could not be stored.');
            }
            @chmod($disk->path($temporaryPath), 0640);
            if (! $disk->move($temporaryPath, $path)) {
                $disk->delete($temporaryPath);
                throw new RuntimeException('Updater package state could not be stored.');
            }
        } catch (SystemUpdaterPreparationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw SystemUpdaterPreparationException::storageFailed($exception);
        }
    }

    private function ensureSafeDirectory(string $path): void
    {
        try {
            File::ensureDirectoryExists($path, 0750, true);
        } catch (Throwable $exception) {
            throw SystemUpdaterPreparationException::storageFailed($exception);
        }
        $info = @lstat($path);
        if (! is_array($info) || (($info['mode'] ?? 0) & 0170000) !== 0040000) {
            throw SystemUpdaterPreparationException::storageFailed(
                new RuntimeException('Updater bootstrap storage path is unsafe.'),
            );
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
            throw SystemUpdaterPreparationException::verificationFailed(
                new RuntimeException('Updater download redirect left the official GitHub release service.'),
            );
        }
    }

    private function throwOutboundFailure(OutboundRequestBlockedException|OutboundRequestFailedException $exception): never
    {
        if ($exception instanceof OutboundRequestFailedException
            || $exception->reasonCode === 'dns_resolution_failed') {
            throw SystemUpdaterPreparationException::connectionFailed($exception);
        }

        throw SystemUpdaterPreparationException::verificationFailed($exception);
    }
}
