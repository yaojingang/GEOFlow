<?php

namespace App\Services\GeoFlow;

use App\Exceptions\AiQualityComparisonCheckpointException;
use Illuminate\Support\Facades\File;

final class AiQualityComparisonCheckpointStore
{
    /** @param array<string,mixed> $request */
    public function claim(string $outputBase, string $runId, array $request): AiQualityComparisonCheckpointClaim
    {
        File::ensureDirectoryExists(dirname($outputBase));
        $path = $outputBase.'.partial.json';
        $lockHandle = fopen($outputBase.'.partial.lock', 'c+');
        if (! is_resource($lockHandle)) {
            throw AiQualityComparisonCheckpointException::busy();
        }
        if (! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            fclose($lockHandle);

            throw AiQualityComparisonCheckpointException::busy();
        }

        try {
            $fingerprint = $this->fingerprint($request);
            $checkpoint = File::isFile($path)
                ? json_decode((string) File::get($path), true)
                : [];
            if (! is_array($checkpoint)) {
                throw AiQualityComparisonCheckpointException::mismatch();
            }
            $calls = [];
            if ($checkpoint !== []) {
                $storedRequest = is_array($checkpoint['request'] ?? null) ? $checkpoint['request'] : [];
                $storedFingerprint = trim((string) ($checkpoint['fingerprint'] ?? ''));
                $computedStoredFingerprint = $this->fingerprint($storedRequest);
                if ($storedFingerprint === '') {
                    $storedFingerprint = $computedStoredFingerprint;
                }
                if (! hash_equals($computedStoredFingerprint, $storedFingerprint)
                    || ! hash_equals($fingerprint, $storedFingerprint)
                    || ! is_array($checkpoint['calls'] ?? null)) {
                    throw AiQualityComparisonCheckpointException::mismatch();
                }
                $calls = array_values(array_filter($checkpoint['calls'], 'is_array'));
            }

            $claim = new AiQualityComparisonCheckpointClaim(
                $path,
                $runId,
                $fingerprint,
                $request,
                $calls,
                $lockHandle,
            );
            $this->persist($claim, $calls);

            return $claim;
        } catch (\Throwable $exception) {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);

            throw $exception;
        }
    }

    /** @param list<array<string,mixed>> $calls */
    public function persist(AiQualityComparisonCheckpointClaim $claim, array $calls): void
    {
        $temporaryPath = $claim->path.'.'.$claim->runId.'.tmp';

        try {
            File::put($temporaryPath, json_encode([
                'schema_version' => 2,
                'run_id' => $claim->runId,
                'fingerprint' => $claim->fingerprint,
                'request' => $claim->request,
                'calls' => array_values($calls),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
            File::replace($claim->path, (string) File::get($temporaryPath));
        } finally {
            File::delete($temporaryPath);
        }
    }

    public function complete(AiQualityComparisonCheckpointClaim $claim): void
    {
        $checkpoint = File::isFile($claim->path)
            ? json_decode((string) File::get($claim->path), true)
            : null;
        if (! is_array($checkpoint)
            || (string) ($checkpoint['run_id'] ?? '') !== $claim->runId
            || (string) ($checkpoint['fingerprint'] ?? '') !== $claim->fingerprint) {
            throw AiQualityComparisonCheckpointException::mismatch();
        }

        File::delete($claim->path);
    }

    /** @param array<string,mixed> $request */
    private function fingerprint(array $request): string
    {
        unset($request['request_id'], $request['run_id']);

        return hash('sha256', json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
