<?php

namespace App\Contracts\SystemUpdater;

interface AgentClient
{
    /**
     * @return array<string, mixed>
     */
    public function status(): array;

    /** @return array<string, mixed> */
    public function startUpdate(): array;

    /** @return array<string, mixed> */
    public function startBackup(): array;

    /** @return array<string, mixed> */
    public function startRollback(string $recoveryPointId): array;

    /** @return array<string, mixed> */
    public function startVerify(): array;

    /** @return array<string, mixed>|null */
    public function currentOperation(): ?array;

    /** @return list<array<string, mixed>> */
    public function recoveryPoints(): array;
}
