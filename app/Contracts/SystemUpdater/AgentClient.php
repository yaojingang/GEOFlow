<?php

namespace App\Contracts\SystemUpdater;

interface AgentClient
{
    /**
     * @return array<string, mixed>
     */
    public function status(): array;

    /** @return array<string, mixed> */
    public function startUpdate(string $authorizationCode): array;

    /** @return array<string, mixed> */
    public function startBackup(string $authorizationCode): array;

    /** @return array<string, mixed> */
    public function startRollback(string $recoveryPointId, string $authorizationCode): array;

    /** @return array<string, mixed> */
    public function startVerify(): array;

    /** @return array<string, mixed>|null */
    public function currentOperation(): ?array;

    /** @return list<array<string, mixed>> */
    public function recoveryPoints(): array;
}
