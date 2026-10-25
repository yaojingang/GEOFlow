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
        $metadata = self::manifest($manifest);
        if ($metadata['schema_version'] === 2 && ! isset($trustedKeys['schema_version'])) {
            throw new RuntimeException('Official releases require a versioned trust bundle.');
        }
        $trustedKeys = self::activeKeys($trustedKeys);
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
        $archive = StandaloneFiles::read($directory.'/geoflow.phar', self::MAX_ARCHIVE_BYTES);
        if (strlen($archive) !== $metadata['size'] || ! hash_equals($metadata['sha256'], hash('sha256', $archive))) {
            throw new RuntimeException('Archive integrity verification failed.');
        }

        return ['manifest' => $manifest, 'signature' => $signature, 'archive' => $archive, 'metadata' => $metadata];
    }

    /** Legacy key maps are accepted for preview schema 1 only. */
    public static function activeKeys(array $trust): array
    {
        if (! isset($trust['schema_version'])) {
            return $trust;
        }
        $expiry = is_string($trust['expires_at'] ?? null) ? \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $trust['expires_at'], new \DateTimeZone('UTC')) : false;
        if (array_diff(array_keys($trust), ['schema_version', 'version', 'expires_at', 'keys']) !== [] || $trust['schema_version'] !== 1 || ! is_int($trust['version'] ?? null) || $trust['version'] < 1
            || $expiry === false || $expiry->format('Y-m-d\\TH:i:s\\Z') !== $trust['expires_at'] || $expiry->getTimestamp() <= time()
            || ! is_array($trust['keys'] ?? null) || count($trust['keys']) > 32) {
            throw new RuntimeException('Trust bundle is invalid or expired. Refresh it through the verified official trust workflow.');
        }
        $keys = [];
        foreach ($trust['keys'] as $id => $key) {
            if (! is_string($id) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $id) !== 1 || ! is_array($key)
                || ! in_array($key['status'] ?? null, ['active', 'revoked'], true)
                || array_diff(array_keys($key), ['public_key', 'status']) !== []
                || ! is_string($key['public_key'] ?? null) || strlen(base64_decode($key['public_key'], true) ?: '') !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                throw new RuntimeException('Trust bundle contains an invalid key.');
            }
            if ($key['status'] === 'active') {
                $keys[$id] = $key['public_key'];
            }
        }

        return $keys;
    }

    /** SemVer ordering keeps preview identifiers below the corresponding stable release. */
    public static function compareVersions(string $left, string $right): int
    {
        $a = explode('-', $left, 2);
        $b = explode('-', $right, 2);
        $number = static function (string $x, string $y): int {
            $x = ltrim($x, '0') ?: '0';
            $y = ltrim($y, '0') ?: '0';

            return (strlen($x) <=> strlen($y)) ?: (strcmp($x, $y) <=> 0);
        };
        $aBase = explode('.', $a[0]);
        $bBase = explode('.', $b[0]);
        foreach ($aBase as $index => $part) {
            if (($order = $number($part, $bBase[$index])) !== 0) {
                return $order;
            }
        }
        if (! isset($a[1]) || ! isset($b[1])) {
            return isset($b[1]) <=> isset($a[1]);
        }
        $aPre = explode('.', $a[1]);
        $bPre = explode('.', $b[1]);
        foreach ($aPre as $index => $part) {
            if (! isset($bPre[$index])) {
                return 1;
            }
            $other = $bPre[$index];
            $numeric = preg_match('/^[0-9]+$/D', $part) === 1;
            $otherNumeric = preg_match('/^[0-9]+$/D', $other) === 1;
            $order = $numeric && $otherNumeric ? $number($part, $other)
                : ($numeric !== $otherNumeric ? ($otherNumeric <=> $numeric) : (strcmp($part, $other) <=> 0));
            if ($order !== 0) {
                return $order;
            }
        }

        return count($aPre) <=> count($bPre);
    }

    public static function manifest(string $bytes): array
    {
        $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (! in_array($manifest['schema_version'] ?? null, [1, 2], true) || ($manifest['file'] ?? null) !== 'geoflow.phar'
            || ! is_int($manifest['size'] ?? null) || $manifest['size'] < 1 || $manifest['size'] > self::MAX_ARCHIVE_BYTES
            || ($manifest['protocol_version'] ?? null) !== '1.0'
            || ! is_string($manifest['version'] ?? null) || preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $manifest['version']) !== 1
            || ! is_string($manifest['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/D', $manifest['sha256']) !== 1) {
            throw new RuntimeException('Invalid release manifest.');
        }

        if ($manifest['schema_version'] === 2 && (! is_int($manifest['release_sequence'] ?? null) || $manifest['release_sequence'] < 1
            || ! is_string($manifest['source_commit'] ?? null) || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $manifest['source_commit']) !== 1)) {
            throw new RuntimeException('Release sequence and immutable source commit are required.');
        }

        return $manifest;
    }
}
