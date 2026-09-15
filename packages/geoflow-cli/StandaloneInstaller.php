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
    public function run(?string $bundle, bool $update = false, ?callable $observer = null): array
    {
        $lock = StandaloneFiles::lock($this->directory.'/.geoflow-install.lock');
        try {
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
            if ($recovered && $previous !== null && $previous['manifest'] === $candidate['manifest']) {
                return $this->result($candidate, true);
            }
            if ($previous !== null) {
                if (! $update) {
                    throw new RuntimeException('An installation exists. Use --update for an explicit replacement.');
                }
                if (version_compare($candidate['metadata']['version'], $previous['metadata']['version'], '<')) {
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
                if ($previous !== null) {
                    $this->storeBundle($stage.'/previous', $previous);
                }
                StandaloneFiles::syncDirectory($stage);
                StandaloneFiles::replace($this->journal(), json_encode([
                    'schema_version' => 1, 'transaction' => $transaction,
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
