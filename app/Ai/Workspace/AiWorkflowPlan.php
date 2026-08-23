<?php

namespace App\Ai\Workspace;

final readonly class AiWorkflowPlan
{
    /**
     * @param  list<array<string,mixed>>  $steps
     * @param  array<string,string>  $capabilityVersions
     */
    public function __construct(
        public int $version,
        public string $intent,
        public array $steps,
        public array $capabilityVersions,
        public string $riskLevel,
        public string $parameterDigest,
        public string $targetDigest,
        public string $digest,
    ) {}

    public function requiresApproval(): bool
    {
        return collect($this->steps)->contains(static fn (array $step): bool => (bool) ($step['requires_approval'] ?? false));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'intent' => $this->intent,
            'steps' => $this->steps,
            'capability_versions' => $this->capabilityVersions,
            'risk_level' => $this->riskLevel,
            'parameter_digest' => $this->parameterDigest,
            'target_digest' => $this->targetDigest,
            'digest' => $this->digest,
        ];
    }
}
