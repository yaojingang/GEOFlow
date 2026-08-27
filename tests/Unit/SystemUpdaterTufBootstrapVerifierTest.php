<?php

namespace Tests\Unit;

use App\Services\SystemUpdater\TufBootstrapVerifier;
use PHPUnit\Framework\TestCase;

class SystemUpdaterTufBootstrapVerifierTest extends TestCase
{
    public function test_it_verifies_root_threshold_and_targets_signature(): void
    {
        [$root, $envelope] = $this->signedFixture();

        $manifest = (new TufBootstrapVerifier)->verify(
            $this->encode($envelope),
            $this->encode($root),
        );

        $this->assertSame('0.1.0', $manifest['updater_version']);
        $this->assertSame(17, $manifest['release_sequence']);
        $this->assertArrayHasKey('linux-amd64', $manifest['assets']);
    }

    public function test_it_rejects_a_tampered_bootstrap_asset(): void
    {
        [$root, $envelope] = $this->signedFixture();
        $envelope['signed']['assets']['linux-amd64']['sha256'] = str_repeat('b', 64);

        $this->expectExceptionMessage('signature threshold');

        (new TufBootstrapVerifier)->verify($this->encode($envelope), $this->encode($root));
    }

    public function test_it_rejects_a_root_below_two_of_three_threshold(): void
    {
        [$root, $envelope] = $this->signedFixture();
        $root['signatures'] = array_slice($root['signatures'], 0, 1);

        $this->expectExceptionMessage('root signature threshold');

        (new TufBootstrapVerifier)->verify($this->encode($envelope), $this->encode($root));
    }

    public function test_the_embedded_production_root_meets_its_offline_signature_threshold(): void
    {
        $root = (string) file_get_contents(dirname(__DIR__, 2).'/resources/update-trust/root.json');
        $envelope = [
            'signed' => [
                'assets' => [
                    'linux-amd64' => [
                        'sha256' => str_repeat('a', 64),
                        'size' => 1,
                        'url' => 'https://github.com/yaojingang/geoflow-updater/releases/download/v0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz',
                    ],
                ],
                'expires' => '2028-08-25T00:00:00Z',
                'release_sequence' => 17,
                'schema_version' => 1,
                'updater_version' => '0.1.0',
            ],
            'signatures' => [],
        ];

        $this->expectExceptionMessage('bootstrap signature threshold');

        (new TufBootstrapVerifier)->verify($this->encode($envelope), $root);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function signedFixture(): array
    {
        $rootKeys = [$this->keyPair(), $this->keyPair(), $this->keyPair()];
        $targetsKey = $this->keyPair();
        $keys = [];
        $rootKeyIds = [];
        foreach ($rootKeys as $keyPair) {
            $key = $this->tufKey($keyPair['public']);
            $keyId = hash('sha256', $this->canonicalize($key));
            $keys[$keyId] = $key;
            $rootKeyIds[] = $keyId;
        }
        $targets = $this->tufKey($targetsKey['public']);
        $targetsKeyId = hash('sha256', $this->canonicalize($targets));
        $keys[$targetsKeyId] = $targets;

        $rootSigned = [
            '_type' => 'root',
            'consistent_snapshot' => true,
            'expires' => '2099-01-01T00:00:00Z',
            'keys' => $keys,
            'roles' => [
                'root' => ['keyids' => $rootKeyIds, 'threshold' => 2],
                'snapshot' => ['keyids' => [], 'threshold' => 1],
                'targets' => ['keyids' => [$targetsKeyId], 'threshold' => 1],
                'timestamp' => ['keyids' => [], 'threshold' => 1],
            ],
            'spec_version' => '1.0.31',
            'version' => 1,
        ];
        $rootCanonical = $this->canonicalize($rootSigned);
        $rootSignatures = [];
        foreach ($rootKeys as $index => $keyPair) {
            $rootSignatures[] = [
                'keyid' => $rootKeyIds[$index],
                'sig' => bin2hex(sodium_crypto_sign_detached($rootCanonical, $keyPair['secret'])),
            ];
        }

        $manifest = [
            'assets' => [
                'linux-amd64' => [
                    'sha256' => str_repeat('a', 64),
                    'size' => 12345,
                    'url' => 'https://github.com/yaojingang/geoflow-updater/releases/download/v0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz',
                ],
            ],
            'expires' => '2099-01-01T00:00:00Z',
            'release_sequence' => 17,
            'schema_version' => 1,
            'updater_version' => '0.1.0',
        ];
        $envelope = [
            'signed' => $manifest,
            'signatures' => [[
                'keyid' => $targetsKeyId,
                'sig' => bin2hex(sodium_crypto_sign_detached($this->canonicalize($manifest), $targetsKey['secret'])),
            ]],
        ];

        return [
            ['signed' => $rootSigned, 'signatures' => $rootSignatures],
            $envelope,
        ];
    }

    /**
     * @return array{public: string, secret: string}
     */
    private function keyPair(): array
    {
        $pair = sodium_crypto_sign_keypair();

        return [
            'public' => sodium_crypto_sign_publickey($pair),
            'secret' => sodium_crypto_sign_secretkey($pair),
        ];
    }

    /**
     * @return array{keytype: string, keyval: array{public: string}, scheme: string}
     */
    private function tufKey(string $publicKey): array
    {
        return [
            'keytype' => 'ed25519',
            'keyval' => ['public' => bin2hex($publicKey)],
            'scheme' => 'ed25519',
        ];
    }

    private function canonicalize(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '['.implode(',', array_map($this->canonicalize(...), $value)).']';
            }

            ksort($value, SORT_STRING);
            $pairs = [];
            foreach ($value as $key => $item) {
                $pairs[] = $this->encode((string) $key).':'.$this->canonicalize($item);
            }

            return '{'.implode(',', $pairs).'}';
        }

        return $this->encode($value);
    }

    private function encode(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
