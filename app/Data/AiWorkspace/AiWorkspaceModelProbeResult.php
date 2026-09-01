<?php

namespace App\Data\AiWorkspace;

use Carbon\CarbonImmutable;

final readonly class AiWorkspaceModelProbeResult
{
    /**
     * @param  array<string, mixed>  $providerResult
     * @param  array<string, mixed>  $profile
     */
    public function __construct(
        public array $providerResult,
        public array $profile,
        public CarbonImmutable $checkedAt,
        public CarbonImmutable $expiresAt,
    ) {}

    /** @return array<string, mixed> */
    public function responseData(): array
    {
        return $this->providerResult + [
            'readiness_status' => 'ready',
            'profile' => $this->profile,
            'expires_at' => $this->expiresAt->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    public function persistenceAttributes(): array
    {
        return [
            'ai_workspace_structured_output_status' => null,
            'ai_workspace_structured_output_verified_at' => null,
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => $this->profile,
            'ai_workspace_readiness_checked_at' => $this->checkedAt,
            'ai_workspace_readiness_expires_at' => $this->expiresAt,
            'ai_workspace_readiness_failure_code' => null,
        ];
    }
}
