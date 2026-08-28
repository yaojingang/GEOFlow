<?php

namespace App\Ai\Workspace;

final readonly class AiCapabilityResult
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  array<string,mixed>  $provenance
     * @param  array<string,int|float>  $usage
     * @param  array<string,mixed>  $presentation
     */
    public function __construct(
        public string $summary,
        public array $payload,
        public string $artifactType,
        public string $artifactName,
        public ?string $sourceRoute = null,
        public ?string $sourceUrl = null,
        public bool $externalOutcomeKnown = true,
        public string $outcome = 'completed',
        public array $provenance = [],
        public array $usage = [],
        public array $presentation = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'outcome' => $this->outcome,
            'summary' => $this->summary,
            'payload' => $this->payload,
            'artifact_type' => $this->artifactType,
            'artifact_name' => $this->artifactName,
            'source_route' => $this->sourceRoute,
            'source_url' => $this->sourceUrl,
            'source' => array_filter([
                'route' => $this->sourceRoute,
                'url' => $this->sourceUrl,
            ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            'provenance' => $this->provenance,
            'usage' => $this->usage,
            'presentation' => $this->presentation,
            'external_outcome_known' => $this->externalOutcomeKnown,
        ];
    }
}
