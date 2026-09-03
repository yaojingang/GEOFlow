<?php

namespace App\Services\GeoFlow;

use App\Models\EnterpriseKnowledgeProject;

final readonly class EnterpriseKnowledgeExecutionFence
{
    public function __construct(
        public string $leaseToken,
        public int $executionAttempt,
    ) {}

    public static function fromProject(EnterpriseKnowledgeProject $project): self
    {
        return new self(
            leaseToken: trim((string) ($project->execution_lease_token ?? '')),
            executionAttempt: (int) ($project->execution_attempt ?? 0),
        );
    }
}
