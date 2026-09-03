<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiModelTestSnapshot;
use App\Services\AiWorkspace\AiModelInvocationLock;

final readonly class GovernanceAiModelUsageSessionFactory
{
    public function __construct(
        private AiModelUsageAttemptFactory $attempts,
        private AiModelInvocationLock $invocationLocks,
        private AdminAiModelTestPreparationService $testPreparation,
    ) {}

    public function create(AdminAiModelTestSnapshot $snapshot): GovernanceAiModelUsageSession
    {
        return new GovernanceAiModelUsageSession(
            attempts: $this->attempts,
            invocationLocks: $this->invocationLocks,
            testPreparation: $this->testPreparation,
            snapshot: $snapshot,
            requestId: $this->attempts->requestId(),
        );
    }
}
