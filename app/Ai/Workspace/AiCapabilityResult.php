<?php

namespace App\Ai\Workspace;

final readonly class AiCapabilityResult
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public string $summary,
        public array $payload,
        public string $artifactType,
        public string $artifactName,
        public ?string $sourceRoute = null,
        public ?string $sourceUrl = null,
        public bool $externalOutcomeKnown = true,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'payload' => $this->payload,
            'artifact_type' => $this->artifactType,
            'artifact_name' => $this->artifactName,
            'source_route' => $this->sourceRoute,
            'source_url' => $this->sourceUrl,
            'external_outcome_known' => $this->externalOutcomeKnown,
        ];
    }
}
