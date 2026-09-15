<?php

declare(strict_types=1);

namespace GeoFlow\Distribution;

use RuntimeException;

final class StandaloneBundle
{
    public const MAX_ARCHIVE_BYTES = 100 * 1024 * 1024;

    public static function resolve(string $directory): string
    {
        $directory = realpath($directory);
        if ($directory === false || ! is_dir($directory)) {
            throw new RuntimeException('Bundle directory does not exist.');
        }
        if (file_exists($directory.'/current.json') || is_link($directory.'/current.json')) {
            $pointer = json_decode(StandaloneFiles::read($directory.'/current.json', 4096), true, flags: JSON_THROW_ON_ERROR);
            $relative = $pointer['bundle'] ?? null;
            if (! is_string($relative) || preg_match('~^bundles/[a-f0-9]{64}$~D', $relative) !== 1) {
                throw new RuntimeException('Invalid build bundle pointer.');
            }
            $resolved = realpath($directory.'/'.$relative);
            if ($resolved === false || $resolved !== $directory.'/'.$relative) {
                throw new RuntimeException('Build bundle pointer escapes its directory.');
            }

            return $resolved;
        }

        return $directory;
    }

    public static function verify(string $directory, array $trustedKeys): array
    {
        $manifest = StandaloneFiles::read($directory.'/manifest.json', 65536);
        $signature = StandaloneFiles::read($directory.'/manifest.sig', 4096);
        $signed = json_decode($signature, true, flags: JSON_THROW_ON_ERROR);
        $keyId = $signed['key_id'] ?? null;
        $encodedKey = is_string($keyId) ? ($trustedKeys[$keyId] ?? null) : null;
        $encodedSignature = $signed['signature'] ?? null;
        $key = is_string($encodedKey) ? base64_decode($encodedKey, true) : false;
        $signatureBytes = is_string($encodedSignature) ? base64_decode($encodedSignature, true) : false;
        if (! is_string($key) || strlen($key) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || ! is_string($signatureBytes) || strlen($signatureBytes) !== SODIUM_CRYPTO_SIGN_BYTES
            || ! sodium_crypto_sign_verify_detached($signatureBytes, $manifest, $key)) {
            throw new RuntimeException('Release signature is not trusted. No executable was installed.');
        }
        $metadata = self::manifest($manifest);
        $archive = StandaloneFiles::read($directory.'/geoflow.phar', self::MAX_ARCHIVE_BYTES);
        if (strlen($archive) !== $metadata['size'] || ! hash_equals($metadata['sha256'], hash('sha256', $archive))) {
            throw new RuntimeException('Archive integrity verification failed.');
        }

        return ['manifest' => $manifest, 'signature' => $signature, 'archive' => $archive, 'metadata' => $metadata];
    }

    public static function manifest(string $bytes): array
    {
        $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (($manifest['schema_version'] ?? null) !== 1 || ($manifest['file'] ?? null) !== 'geoflow.phar'
            || ! is_int($manifest['size'] ?? null) || $manifest['size'] < 1 || $manifest['size'] > self::MAX_ARCHIVE_BYTES
            || ($manifest['protocol_version'] ?? null) !== '1.0'
            || ! is_string($manifest['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $manifest['version']) !== 1
            || ! is_string($manifest['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256']) !== 1) {
            throw new RuntimeException('Invalid release manifest.');
        }

        return $manifest;
    }
}
