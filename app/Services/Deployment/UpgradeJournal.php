<?php

namespace App\Services\Deployment;

use RuntimeException;

class UpgradeJournal
{
    public function directory(): string
    {
        return storage_path('framework/geoflow-upgrades');
    }

    public function assertOperation(string $operation): void
    {
        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/', $operation)) {
            throw new RuntimeException('upgrade_operation_invalid');
        }
    }

    /** @return resource */
    public function acquire(): mixed
    {
        $directory = $this->directory();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('upgrade_journal_directory_failed');
        }
        $lock = fopen($directory.'/apply.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('upgrade_lock_unavailable');
        }
        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            throw new RuntimeException('upgrade_lock_held');
        }

        return $lock;
    }

    /** @param resource $lock */
    public function release(mixed $lock): void
    {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    /** @return array<string,mixed>|null */
    public function read(string $operation, string $hash, string $version, string $strategy, int $source): ?array
    {
        $this->assertOperation($operation);
        $path = $this->directory().'/'.$operation.'.json';
        if (! is_file($path)) {
            return null;
        }
        $journal = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
        if (! is_array($journal) || ($journal['schema_version'] ?? null) !== 1
            || ($journal['operation'] ?? null) !== $operation || ($journal['plan_sha256'] ?? null) !== $hash
            || ($journal['version'] ?? null) !== $version || ($journal['strategy'] ?? null) !== $strategy
            || ($journal['source_sequence'] ?? null) !== $source || ! is_array($journal['steps'] ?? null)
            || ! in_array($journal['status'] ?? null, ['running', 'failed', 'completed'], true)) {
            throw new RuntimeException('upgrade_journal_identity_mismatch');
        }

        return $journal;
    }

    /** @param array<string,mixed> $journal */
    public function write(array $journal): void
    {
        $this->assertOperation($journal['operation']);
        $bytes = json_encode($journal, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        $path = $this->directory().'/'.$journal['operation'].'.json';
        $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
        $handle = fopen($temporary, 'x');
        if ($handle === false) {
            throw new RuntimeException('upgrade_journal_write_failed');
        }
        try {
            chmod($temporary, 0600);
            if (fwrite($handle, $bytes) !== strlen($bytes) || ! fflush($handle) || ! fsync($handle)) {
                throw new RuntimeException('upgrade_journal_sync_failed');
            }
            fclose($handle);
            $handle = null;
            if (! rename($temporary, $path)) {
                throw new RuntimeException('upgrade_journal_publish_failed');
            }
            $directory = fopen($this->directory(), 'r');
            if ($directory === false) {
                throw new RuntimeException('upgrade_journal_directory_sync_failed');
            }
            try {
                if (! fsync($directory)) {
                    throw new RuntimeException('upgrade_journal_directory_sync_failed');
                }
            } finally {
                fclose($directory);
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
