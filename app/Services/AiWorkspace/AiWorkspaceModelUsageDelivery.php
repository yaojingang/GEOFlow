<?php

namespace App\Services\AiWorkspace;

use App\Services\Admin\AiModelUsageAttempt;

final class AiWorkspaceModelUsageDelivery
{
    /** @param array<string,mixed> $usage */
    public function __construct(
        private readonly AiModelUsageAttempt $attempt,
        private readonly array $usage,
    ) {}

    public function succeeded(): void
    {
        $this->attempt->succeeded($this->usage);
    }

    public function revoked(string $errorCode = 'ai_config_access_revoked'): void
    {
        $this->attempt->revoked($errorCode, $this->usage);
    }

    public function discarded(string $errorCode = 'ai_result_not_committed'): void
    {
        $this->attempt->discarded($errorCode, $this->usage);
    }

    public function isFinalized(): bool
    {
        return $this->attempt->isFinalized();
    }

    public function __destruct()
    {
        $this->discarded('ai_result_not_delivered');
    }
}
