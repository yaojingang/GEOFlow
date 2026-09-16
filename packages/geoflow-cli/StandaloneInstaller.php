<?php

declare(strict_types=1);

namespace GeoFlow\Distribution;

use RuntimeException;

final class StandaloneInstaller
{
    private string $directory;

    public function __construct(string $directory, private readonly array $trustedKeys)
    {
        $this->directory = StandaloneFiles::directory($directory);
    }

    /** The observer reports durable phases; interrupted installations resume from the journal. */
    public function run(?string $bundle, bool $update = false, ?callable $observer = null, bool $rollback = false): array
    {
        $lock = StandaloneFiles::lock($this->directory.'/.geoflow-install.lock');
        try {
            $this->recordTrustedRoot();
            $recovered = false;
            if (file_exists($this->journal()) || is_link($this->journal())) {
                $this->activate($observer);
                $recovered = true;
            }
            $this->cleanAbandoned();
            if ($bundle === null) {
                return ['recovered' => $recovered, 'installed' => $this->directory.'/geoflow'];
            }
            $candidate = StandaloneBundle::verify(StandaloneBundle::resolve($bundle), $this->trustedKeys);
            $previous = $this->installed();
            $state = $this->nextState($candidate, $rollback, $previous);
            if ($previous !== null && $previous['manifest'] === $candidate['manifest']) {
                $stateBytes = json_encode($state, JSON_THROW_ON_ERROR);
                if (! is_file($this->statePath()) || StandaloneFiles::read($this->statePath(), 1024 * 1024) !== $stateBytes) {
                    StandaloneFiles::replace($this->statePath(), $stateBytes);
                }

                return $this->result($candidate, $recovered);
            }
            if ($previous !== null) {
                if (! $update && ! $rollback) {
                    throw new RuntimeException('An installation exists. Use --update for an explicit replacement.');
                }
                if (! $rollback && StandaloneBundle::compareVersions($candidate['metadata']['version'], $previous['metadata']['version']) < 0) {
                    throw new RuntimeException('Automatic downgrade is not allowed.');
                }
            }
            $transaction = '.geoflow-transaction-'.bin2hex(random_bytes(12));
            $stage = $this->directory.'/'.$transaction;
            if (! mkdir($stage, 0700)) {
                throw new RuntimeException('Cannot prepare installation transaction.');
            }
            try {
                $this->storeBundle($stage.'/candidate', $candidate);
                $stateBytes = json_encode($state, JSON_THROW_ON_ERROR);
                StandaloneFiles::writeNew($stage.'/state.json', $stateBytes);
                $previousState = is_file($this->statePath()) ? StandaloneFiles::read($this->statePath(), 1024 * 1024) : null;
                if ($previous !== null) {
                    $this->storeBundle($stage.'/previous', $previous);
                }
                StandaloneFiles::syncDirectory($stage);
                StandaloneFiles::replace($this->journal(), json_encode([
                    'schema_version' => 1, 'transaction' => $transaction,
                    'state_sha256' => hash('sha256', $stateBytes),
                    'previous_state_sha256' => $previousState === null ? null : hash('sha256', $previousState),
                    'candidate_manifest_sha256' => hash('sha256', $candidate['manifest']),
                    'previous_manifest_sha256' => $previous === null ? null : hash('sha256', $previous['manifest']),
                ], JSON_THROW_ON_ERROR));
                $this->observe($observer, 'prepared');
                $this->activate($observer);
            } finally {
                if (! file_exists($this->journal()) && is_dir($stage)) {
                    StandaloneFiles::removeTree($stage);
                }
            }

            return $this->result($candidate, $recovered);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function activate(?callable $observer): void
    {
        $journal = json_decode(StandaloneFiles::read($this->journal(), 4096), true, flags: JSON_THROW_ON_ERROR);
        $name = $journal['transaction'] ?? null;
        if (($journal['schema_version'] ?? null) !== 1 || ! is_string($name)
            || preg_match('/^\.geoflow-transaction-[a-f0-9]{24}$/D', $name) !== 1) {
            throw new RuntimeException('Invalid installation transaction; no files were replaced.');
        }
        $stage = $this->directory.'/'.$name;
        if (realpath($stage) !== $stage || ! is_dir($stage)) {
            throw new RuntimeException('Installation transaction directory is unavailable.');
        }
        $candidate = StandaloneBundle::verify($stage.'/candidate', $this->trustedKeys);
        if (! hash_equals((string) ($journal['candidate_manifest_sha256'] ?? ''), hash('sha256', $candidate['manifest']))) {
            throw new RuntimeException('Installation candidate changed after preparation.');
        }
        $previous = null;
        if (($journal['previous_manifest_sha256'] ?? null) !== null) {
            $previous = $this->readPair($stage.'/previous/geoflow.phar', $stage.'/previous/manifest.json', $stage.'/previous/manifest.sig');
            if (! hash_equals((string) $journal['previous_manifest_sha256'], hash('sha256', $previous['manifest']))) {
                throw new RuntimeException('Previous installation snapshot changed after preparation.');
            }
        }
        $stateBytes = null;
        if (isset($journal['state_sha256'])) {
            $stateBytes = StandaloneFiles::read($stage.'/state.json', 1024 * 1024);
            if (! hash_equals($journal['state_sha256'], hash('sha256', $stateBytes))) {
                throw new RuntimeException('Installation high-water state changed after preparation.');
            }
            $preparedState = $this->validateState(json_decode($stateBytes, true, flags: JSON_THROW_ON_ERROR));
            $trustVersion = $this->trustedKeys['schema_version'] ?? null ? $this->trustedKeys['version'] : 0;
            $trustHash = $trustVersion > 0 ? hash('sha256', json_encode($this->trustedKeys, JSON_THROW_ON_ERROR)) : '';
            if ($trustVersion < $preparedState['trust_version'] || ($trustVersion === $preparedState['trust_version'] && $trustHash !== $preparedState['trust_sha256'])) {
                throw new RuntimeException('Recovery rejects a downgraded or replaced trust bundle.');
            }
            StandaloneFiles::regularOrMissing($this->statePath());
            $current = is_file($this->statePath()) ? hash('sha256', StandaloneFiles::read($this->statePath(), 1024 * 1024)) : null;
            if ($current !== ($journal['previous_state_sha256'] ?? null) && $current !== $journal['state_sha256']) {
                throw new RuntimeException('Installation high-water state changed outside the transaction.');
            }
        }
        foreach (['geoflow' => 'archive', 'geoflow.manifest.json' => 'manifest', 'geoflow.manifest.sig' => 'signature'] as $file => $key) {
            $path = $this->directory.'/'.$file;
            StandaloneFiles::regularOrMissing($path);
            $actual = is_file($path) ? StandaloneFiles::read($path, StandaloneBundle::MAX_ARCHIVE_BYTES) : null;
            if ($actual !== ($previous[$key] ?? null) && $actual !== $candidate[$key]) {
                throw new RuntimeException('Installed files changed outside the pending transaction; refusing to overwrite them.');
            }
            StandaloneFiles::regularOrMissing($path.'.previous');
        }
        if ($previous !== null) {
            $this->activateFiles($previous, '.previous');
        }
        $this->observe($observer, 'previous_preserved');
        StandaloneFiles::replace($this->directory.'/geoflow', $candidate['archive'], 0755);
        $this->observe($observer, 'executable_activated');
        StandaloneFiles::replace($this->directory.'/geoflow.manifest.json', $candidate['manifest']);
        $this->observe($observer, 'receipt_activated');
        StandaloneFiles::replace($this->directory.'/geoflow.manifest.sig', $candidate['signature']);
        $this->observe($observer, 'signature_activated');
        if ($stateBytes !== null) {
            StandaloneFiles::replace($this->statePath(), $stateBytes);
        }
        $this->observe($observer, 'state_activated');
        if (! unlink($this->journal())) {
            throw new RuntimeException('Installation completed but its recovery journal could not be cleared.');
        }
        StandaloneFiles::syncDirectory($this->directory);
        StandaloneFiles::removeTree($stage);
    }

    private function activateFiles(array $bundle, string $suffix): void
    {
        StandaloneFiles::replace($this->directory.'/geoflow'.$suffix, $bundle['archive'], 0755);
        StandaloneFiles::replace($this->directory.'/geoflow.manifest.json'.$suffix, $bundle['manifest']);
        $signature = $this->directory.'/geoflow.manifest.sig'.$suffix;
        if ($bundle['signature'] !== null) {
            StandaloneFiles::replace($signature, $bundle['signature']);
        } elseif (is_file($signature)) {
            if (! unlink($signature)) {
                throw new RuntimeException('Cannot clear obsolete previous signature.');
            }
            StandaloneFiles::syncDirectory($this->directory);
        }
    }

    private function installed(): ?array
    {
        foreach (['geoflow', 'geoflow.manifest.json', 'geoflow.manifest.sig'] as $name) {
            StandaloneFiles::regularOrMissing($this->directory.'/'.$name);
        }
        if (! is_file($this->directory.'/geoflow')) {
            if (is_file($this->directory.'/geoflow.manifest.json') || is_file($this->directory.'/geoflow.manifest.sig')) {
                throw new RuntimeException('Installation metadata exists without an executable.');
            }

            return null;
        }

        return $this->readPair($this->directory.'/geoflow', $this->directory.'/geoflow.manifest.json', $this->directory.'/geoflow.manifest.sig');
    }

    private function readPair(string $archivePath, string $manifestPath, string $signaturePath): array
    {
        $manifest = StandaloneFiles::read($manifestPath, 65536);
        $metadata = StandaloneBundle::manifest($manifest);
        $archive = StandaloneFiles::read($archivePath, StandaloneBundle::MAX_ARCHIVE_BYTES);
        if (strlen($archive) !== $metadata['size'] || ! hash_equals($metadata['sha256'], hash('sha256', $archive))) {
            throw new RuntimeException('Executable and receipt disagree; refusing replacement.');
        }

        return [
            'manifest' => $manifest, 'metadata' => $metadata, 'archive' => $archive,
            'signature' => is_file($signaturePath) ? StandaloneFiles::read($signaturePath, 4096) : null,
        ];
    }

    private function storeBundle(string $path, array $bundle): void
    {
        if (! mkdir($path, 0700)) {
            throw new RuntimeException('Cannot prepare installation snapshot.');
        }
        StandaloneFiles::writeNew($path.'/geoflow.phar', $bundle['archive']);
        StandaloneFiles::writeNew($path.'/manifest.json', $bundle['manifest']);
        if ($bundle['signature'] !== null) {
            StandaloneFiles::writeNew($path.'/manifest.sig', $bundle['signature']);
        }
        StandaloneFiles::syncDirectory($path);
    }

    private function cleanAbandoned(): void
    {
        foreach (new \FilesystemIterator($this->directory) as $entry) {
            if (preg_match('/^\.geoflow-transaction-[a-f0-9]{24}$/D', $entry->getFilename()) === 1) {
                StandaloneFiles::removeTree($entry->getPathname());
            } elseif (preg_match('/^\.geoflow-write-[a-f0-9]{24}$/D', $entry->getFilename()) === 1) {
                StandaloneFiles::regularOrMissing($entry->getPathname());
                if (! unlink($entry->getPathname())) {
                    throw new RuntimeException('Cannot clean abandoned distribution write.');
                }
            }
        }
    }

    /** Root acceptance has its own durable high-water, including interrupted activation. */
    private function recordTrustedRoot(): void
    {
        StandaloneBundle::activeKeys($this->trustedKeys);
        $version = isset($this->trustedKeys['schema_version']) ? $this->trustedKeys['version'] : 0;
        $identity = $version > 0 ? hash('sha256', json_encode($this->trustedKeys, JSON_THROW_ON_ERROR)) : '';
        $path = $this->directory.'/.geoflow-trust-state.json';
        StandaloneFiles::regularOrMissing($path);
        if (is_file($path)) {
            $previous = json_decode(StandaloneFiles::read($path, 4096), true, flags: JSON_THROW_ON_ERROR);
            if (($previous['schema_version'] ?? null) !== 1 || ! is_int($previous['version'] ?? null) || ! is_string($previous['sha256'] ?? null)
                || $version < $previous['version'] || ($version === $previous['version'] && $identity !== $previous['sha256'])) {
                throw new RuntimeException('Trust rollback or same-version root replacement is forbidden.');
            }
            if ($version === $previous['version']) {
                return;
            }
        }
        StandaloneFiles::replace($path, json_encode(['schema_version' => 1, 'version' => $version, 'sha256' => $identity], JSON_THROW_ON_ERROR));
    }

    private function statePath(): string
    {
        return $this->directory.'/.geoflow-install-state.json';
    }

    private function validateState(mixed $state): array
    {
        if (! is_array($state) || ($state['schema_version'] ?? null) !== 1 || ! is_int($state['highest_sequence'] ?? null)
            || $state['highest_sequence'] < 0 || ! is_string($state['highest_version'] ?? null) || preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $state['highest_version']) !== 1 || ! is_array($state['releases'] ?? null)
            || count($state['releases']) > 4096 || ! is_int($state['trust_version'] ?? null) || ! is_string($state['trust_sha256'] ?? null)) {
            throw new RuntimeException('Invalid installed high-water state.');
        }
        foreach ($state['releases'] as $version => $identity) {
            if (! is_string($version) || preg_match('/^\d+\.\d+\.\d+(?:-[A-Za-z0-9.-]+)?$/D', $version) !== 1 || ! is_string($identity) || preg_match('/^[a-f0-9]{64}$/D', $identity) !== 1) {
                throw new RuntimeException('Invalid installed release identity.');
            }
        }

        return $state;
    }

    private function nextState(array $candidate, bool $rollback, ?array $previous): array
    {
        StandaloneFiles::regularOrMissing($this->statePath());
        if ($previous !== null && $previous['metadata']['schema_version'] === 2 && ! is_file($this->statePath())) {
            throw new RuntimeException('Official installation high-water state is missing; restore its verified local state before proceeding.');
        }
        $state = is_file($this->statePath()) ? $this->validateState(json_decode(StandaloneFiles::read($this->statePath(), 1024 * 1024), true, flags: JSON_THROW_ON_ERROR))
            : ['schema_version' => 1, 'highest_sequence' => 0, 'highest_version' => '0.0.0', 'releases' => [], 'trust_version' => 0, 'trust_sha256' => ''];
        if ($previous !== null) {
            $version = $previous['metadata']['version'];
            $identity = hash('sha256', $previous['manifest']);
            if (isset($state['releases'][$version]) && $state['releases'][$version] !== $identity) {
                throw new RuntimeException('Installed release differs from its recorded identity.');
            }
            $state['releases'][$version] = $identity;
            $state['highest_sequence'] = max($state['highest_sequence'], $previous['metadata']['release_sequence'] ?? 0);
            if (StandaloneBundle::compareVersions($version, $state['highest_version']) > 0) {
                $state['highest_version'] = $version;
            }
        }
        $trustVersion = $this->trustedKeys['schema_version'] ?? null ? $this->trustedKeys['version'] : 0;
        $trustHash = $trustVersion > 0 ? hash('sha256', json_encode($this->trustedKeys, JSON_THROW_ON_ERROR)) : '';
        if ($trustVersion < $state['trust_version'] || ($trustVersion > 0 && $trustVersion === $state['trust_version'] && $state['trust_sha256'] !== $trustHash)) {
            throw new RuntimeException('Trust bundle rollback or same-version replacement is forbidden.');
        }
        $version = $candidate['metadata']['version'];
        $sequence = $candidate['metadata']['release_sequence'] ?? 0;
        $identity = hash('sha256', $candidate['manifest']);
        if (isset($state['releases'][$version]) && $state['releases'][$version] !== $identity) {
            throw new RuntimeException('A release version cannot identify different bytes or metadata.');
        }
        if ($rollback) {
            if (! isset($state['releases'][$version]) || $previous === null || StandaloneBundle::compareVersions($version, $previous['metadata']['version']) >= 0) {
                throw new RuntimeException('Rollback requires a previously installed, currently trusted older local release.');
            }
        } elseif ($sequence < $state['highest_sequence'] || StandaloneBundle::compareVersions($version, $state['highest_version']) < 0
            || ($sequence > 0 && $sequence === $state['highest_sequence'] && ! isset($state['releases'][$version]))) {
            throw new RuntimeException('Automatic release downgrade or sequence reuse is forbidden.');
        }
        $state['releases'][$version] = $identity;
        $state['highest_sequence'] = max($state['highest_sequence'], $sequence);
        if (StandaloneBundle::compareVersions($version, $state['highest_version']) > 0) {
            $state['highest_version'] = $version;
        }
        $state['trust_version'] = $trustVersion;
        $state['trust_sha256'] = $trustHash;

        return $this->validateState($state);
    }

    private function observe(?callable $observer, string $phase): void
    {
        if ($observer !== null) {
            $observer($phase);
        }
    }

    private function journal(): string
    {
        return $this->directory.'/.geoflow-install.json';
    }

    private function result(array $candidate, bool $recovered): array
    {
        return ['installed' => $this->directory.'/geoflow', 'version' => $candidate['metadata']['version'], 'signature_verified' => true, 'recovered' => $recovered];
    }
}
