<?php

namespace App\Services\GeoFlow;

final class AiQualityComparisonCheckpointClaim
{
    private bool $released = false;

    /**
     * @param  array<string,mixed>  $request
     * @param  list<array<string,mixed>>  $calls
     */
    public function __construct(
        public readonly string $path,
        public readonly string $runId,
        public readonly string $fingerprint,
        public readonly array $request,
        public readonly array $calls,
        private mixed $lockHandle,
    ) {}

    public function release(): void
    {
        if ($this->released) {
            return;
        }
        $this->released = true;

        if (is_resource($this->lockHandle)) {
            flock($this->lockHandle, LOCK_UN);
            fclose($this->lockHandle);
        }
        $this->lockHandle = null;
    }
}
