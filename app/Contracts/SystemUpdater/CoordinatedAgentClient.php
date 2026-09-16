<?php

namespace App\Contracts\SystemUpdater;

interface CoordinatedAgentClient extends PlannedAgentClient
{
    public function capabilities(): array;

    public function createActionPlan(array $request): array;

    public function actionPlan(string $planId): array;

    public function submitAction(array $request, #[\SensitiveParameter] ?string $authorizationCode): array;

    public function requestReceipt(string $requestId): ?array;

    public function operationReceipt(string $operationId): array;
}
