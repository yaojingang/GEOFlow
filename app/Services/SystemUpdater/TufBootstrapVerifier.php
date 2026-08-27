<?php

namespace App\Services\SystemUpdater;

use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

class TufBootstrapVerifier
{
    private const MAX_MANIFEST_BYTES = 1024 * 1024;

    private const MAX_ASSET_BYTES = 100 * 1024 * 1024;

    /**
     * @return array<string, mixed>
     */
    public function verify(string $envelopeJson, string $trustedRootJson): array
    {
        if (mb_strlen($envelopeJson, '8bit') > self::MAX_MANIFEST_BYTES
            || mb_strlen($trustedRootJson, '8bit') > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('Updater trust metadata exceeded the size limit.');
        }

        $envelope = $this->decodeObject($envelopeJson, 'bootstrap manifest');
        $root = $this->decodeObject($trustedRootJson, 'trusted root');
        $this->assertExactKeys($envelope, ['signed', 'signatures'], 'bootstrap envelope');
        $this->assertExactKeys($root, ['signed', 'signatures'], 'trusted root envelope');

        $rootSigned = $this->object($root['signed'] ?? null, 'trusted root signed payload');
        $rootSignatures = $this->list($root['signatures'] ?? null, 'trusted root signatures');
        $this->validateRoot($rootSigned);
        $this->verifyRoleThreshold($rootSigned, $rootSignatures, $rootSigned, 'root', 'root signature threshold');

        $manifest = $this->object($envelope['signed'] ?? null, 'bootstrap signed payload');
        $signatures = $this->list($envelope['signatures'] ?? null, 'bootstrap signatures');
        $this->validateManifest($manifest);
        $this->verifyRoleThreshold($rootSigned, $signatures, $manifest, 'targets', 'bootstrap signature threshold');

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $root
     */
    private function validateRoot(array $root): void
    {
        $this->assertExactKeys($root, [
            '_type',
            'consistent_snapshot',
            'expires',
            'keys',
            'roles',
            'spec_version',
            'version',
        ], 'trusted root');
        if (($root['_type'] ?? null) !== 'root' || ! is_int($root['version'] ?? null) || $root['version'] < 1) {
            throw new RuntimeException('Trusted root metadata is invalid.');
        }
        $this->assertFutureTimestamp($root['expires'] ?? null, 'Trusted root metadata has expired.');
        $keys = $this->object($root['keys'] ?? null, 'trusted root keys');
        $roles = $this->object($root['roles'] ?? null, 'trusted root roles');
        foreach (['root', 'targets'] as $roleName) {
            $role = $this->object($roles[$roleName] ?? null, $roleName.' role');
            $this->assertExactKeys($role, ['keyids', 'threshold'], $roleName.' role');
            $keyIds = $this->list($role['keyids'] ?? null, $roleName.' key ids');
            $threshold = $role['threshold'] ?? null;
            if (! is_int($threshold) || $threshold < 1 || count($keyIds) < $threshold) {
                throw new RuntimeException(ucfirst($roleName).' role threshold is invalid.');
            }
            foreach ($keyIds as $keyId) {
                if (! is_string($keyId) || ! isset($keys[$keyId])) {
                    throw new RuntimeException(ucfirst($roleName).' role references an unknown key.');
                }
            }
        }
        foreach ($keys as $keyId => $key) {
            $keyData = $this->object($key, 'trusted root key');
            $this->assertExactKeys($keyData, ['keytype', 'keyval', 'scheme'], 'trusted root key');
            if (! hash_equals((string) $keyId, hash('sha256', $this->canonicalize($keyData)))) {
                throw new RuntimeException('Trusted root key identifier is invalid.');
            }
            $this->publicKey($keyData);
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function validateManifest(array $manifest): void
    {
        $this->assertExactKeys($manifest, ['assets', 'expires', 'release_sequence', 'schema_version', 'updater_version'], 'bootstrap manifest');
        if (($manifest['schema_version'] ?? null) !== 1) {
            throw new RuntimeException('Bootstrap manifest schema is unsupported.');
        }
        if (! is_int($manifest['release_sequence'] ?? null) || $manifest['release_sequence'] < 1) {
            throw new RuntimeException('Bootstrap release sequence is invalid.');
        }
        $version = $manifest['updater_version'] ?? null;
        if (! is_string($version) || ! preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?\z/', $version)) {
            throw new RuntimeException('Bootstrap updater version is invalid.');
        }
        $this->assertFutureTimestamp($manifest['expires'] ?? null, 'Bootstrap manifest has expired.');
        $assets = $this->object($manifest['assets'] ?? null, 'bootstrap assets');
        if ($assets === []) {
            throw new RuntimeException('Bootstrap manifest has no assets.');
        }
        foreach ($assets as $platform => $asset) {
            if (! in_array($platform, ['linux-amd64', 'linux-arm64'], true)) {
                throw new RuntimeException('Bootstrap manifest contains an unsupported platform.');
            }
            $assetData = $this->object($asset, 'bootstrap asset');
            $this->assertExactKeys($assetData, ['sha256', 'size', 'url'], 'bootstrap asset');
            $expectedPrefix = 'https://github.com/yaojingang/geoflow-updater/releases/download/v'.$version.'/';
            $url = $assetData['url'] ?? null;
            if (! is_string($url)
                || ! str_starts_with($url, $expectedPrefix)
                || str_contains(mb_substr($url, mb_strlen($expectedPrefix)), '/')) {
                throw new RuntimeException('Bootstrap asset URL is outside the official release.');
            }
            if (! is_string($assetData['sha256'] ?? null)
                || ! preg_match('/\A[a-f0-9]{64}\z/', $assetData['sha256'])) {
                throw new RuntimeException('Bootstrap asset digest is invalid.');
            }
            if (! is_int($assetData['size'] ?? null)
                || $assetData['size'] < 1
                || $assetData['size'] > self::MAX_ASSET_BYTES) {
                throw new RuntimeException('Bootstrap asset size is invalid.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $root
     * @param  list<mixed>  $signatures
     * @param  array<string, mixed>  $signedPayload
     */
    private function verifyRoleThreshold(
        array $root,
        array $signatures,
        array $signedPayload,
        string $roleName,
        string $errorPrefix,
    ): void {
        $roles = $this->object($root['roles'] ?? null, 'trusted root roles');
        $role = $this->object($roles[$roleName] ?? null, $roleName.' role');
        $authorizedKeyIds = $this->list($role['keyids'] ?? null, $roleName.' key ids');
        $threshold = (int) ($role['threshold'] ?? 0);
        $keys = $this->object($root['keys'] ?? null, 'trusted root keys');
        $canonical = $this->canonicalize($signedPayload);
        $verified = [];

        foreach ($signatures as $signature) {
            $signatureData = $this->object($signature, $roleName.' signature');
            $this->assertExactKeys($signatureData, ['keyid', 'sig'], $roleName.' signature');
            $keyId = $signatureData['keyid'] ?? null;
            $signatureHex = $signatureData['sig'] ?? null;
            if (! is_string($keyId)
                || ! is_string($signatureHex)
                || isset($verified[$keyId])
                || ! in_array($keyId, $authorizedKeyIds, true)
                || ! isset($keys[$keyId])
                || ! preg_match('/\A[a-f0-9]{128}\z/', $signatureHex)) {
                continue;
            }
            $publicKey = $this->publicKey($this->object($keys[$keyId], 'trusted root key'));
            $binarySignature = hex2bin($signatureHex);
            if (is_string($binarySignature) && sodium_crypto_sign_verify_detached($binarySignature, $canonical, $publicKey)) {
                $verified[$keyId] = true;
            }
        }
        if (count($verified) < $threshold) {
            throw new RuntimeException($errorPrefix.' was not met.');
        }
    }

    /**
     * @param  array<string, mixed>  $key
     */
    private function publicKey(array $key): string
    {
        if (($key['keytype'] ?? null) !== 'ed25519' || ($key['scheme'] ?? null) !== 'ed25519') {
            throw new RuntimeException('Trusted root contains an unsupported key type.');
        }
        $keyValue = $this->object($key['keyval'] ?? null, 'trusted root key value');
        $this->assertExactKeys($keyValue, ['public'], 'trusted root key value');
        $publicHex = $keyValue['public'] ?? null;
        if (! is_string($publicHex) || ! preg_match('/\A[a-f0-9]{64}\z/', $publicHex)) {
            throw new RuntimeException('Trusted root public key is invalid.');
        }
        $publicKey = hex2bin($publicHex);
        if (! is_string($publicKey)) {
            throw new RuntimeException('Trusted root public key is invalid.');
        }

        return $publicKey;
    }

    private function canonicalize(mixed $value): string
    {
        if (is_float($value)) {
            throw new RuntimeException('Floating point values are not allowed in signed metadata.');
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map($this->canonicalize(...), $value)).']';
            }

            ksort($value, SORT_STRING);
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = $this->encodeJson((string) $key).':'.$this->canonicalize($item);
            }

            return '{'.implode(',', $pairs).'}';
        }

        return $this->encodeJson($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeObject(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException(ucfirst($label).' JSON is invalid.', 0, $exception);
        }

        return $this->object($decoded, $label);
    }

    /**
     * @return array<string, mixed>
     */
    private function object(mixed $value, string $label): array
    {
        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException(ucfirst($label).' must be an object.');
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private function list(mixed $value, string $label): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException(ucfirst($label).' must be a list.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $value
     * @param  list<string>  $expected
     */
    private function assertExactKeys(array $value, array $expected, string $label): void
    {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new RuntimeException(ucfirst($label).' contains unsupported fields.');
        }
    }

    private function assertFutureTimestamp(mixed $value, string $expiredMessage): void
    {
        if (! is_string($value)) {
            throw new RuntimeException('Signed metadata expiry is invalid.');
        }
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $value, new DateTimeZone('UTC'));
        if (! $expiry) {
            $expiry = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new DateTimeZone('UTC'));
        }
        $errors = DateTimeImmutable::getLastErrors();
        if (! $expiry || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new RuntimeException('Signed metadata expiry is invalid.');
        }
        if ($expiry <= new DateTimeImmutable('now', new DateTimeZone('UTC'))) {
            throw new RuntimeException($expiredMessage);
        }
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
