<?php

namespace App\Contracts\SystemUpdater;

interface PlannedAgentClient extends AgentClient
{
    /** @return array<string, mixed> */
    public function preview(): array;

    /** @return array<string, mixed> */
    public function startPlannedUpdate(string $authorizationCode, bool $allowMaintenance, string $expectedPlanSha256): array;

    /** @return array<string, mixed> */
    public function startSwitchBack(string $authorizationCode): array;
}
