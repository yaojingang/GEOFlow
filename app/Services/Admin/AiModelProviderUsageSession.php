<?php

namespace App\Services\Admin;

use Closure;

class AiModelProviderUsageSession
{
    /** @var array<int,array{attempt:AiModelUsageAttempt,usage:mixed}> */
    private array $pending = [];

    /** @param Closure(string, ?string=): AiModelUsageAttempt $attemptFactory */
    public function __construct(private readonly Closure $attemptFactory) {}

    public function begin(string $mode, ?string $requestPayload = null): AiModelUsageAttempt
    {
        return $requestPayload === null
            ? ($this->attemptFactory)($mode)
            : ($this->attemptFactory)($mode, $requestPayload);
    }

    public function providerFailed(AiModelUsageAttempt $attempt, string $errorCode = 'ai_provider_request_failed'): void
    {
        $attempt->failed($errorCode);
    }

    public function providerReturned(AiModelUsageAttempt $attempt, mixed $usage = null): void
    {
        $this->pending[spl_object_id($attempt)] = compact('attempt', 'usage');
    }

    public function providerResultDiscarded(
        AiModelUsageAttempt $attempt,
        mixed $usage = null,
        string $errorCode = 'ai_result_not_committed',
    ): void {
        unset($this->pending[spl_object_id($attempt)]);
        $attempt->discarded($errorCode, $usage);
    }

    public function succeeded(): void
    {
        foreach ($this->drain() as $pending) {
            $pending['attempt']->succeeded($pending['usage']);
        }
    }

    public function revoked(string $errorCode = 'ai_config_access_revoked'): void
    {
        foreach ($this->drain() as $pending) {
            $pending['attempt']->revoked($errorCode, $pending['usage']);
        }
    }

    public function discarded(string $errorCode = 'ai_result_not_committed'): void
    {
        foreach ($this->drain() as $pending) {
            $pending['attempt']->discarded($errorCode, $pending['usage']);
        }
    }

    /** @return array<int,array{attempt:AiModelUsageAttempt,usage:mixed}> */
    private function drain(): array
    {
        $pending = $this->pending;
        $this->pending = [];

        return $pending;
    }

    public function __destruct()
    {
        $this->discarded('ai_result_not_delivered');
    }
}
