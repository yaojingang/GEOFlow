<?php

namespace App\Ai\Workspace;

final readonly class AiIntentResolution
{
    /**
     * @param  list<array{key:string,confidence:float,reason:string}>  $candidates
     * @param  array<string,mixed>  $knownParameters
     * @param  list<string>  $missingParameters
     * @param  list<string>  $ambiguities
     */
    public function __construct(
        public string $mode,
        public string $intent,
        public array $candidates,
        public array $knownParameters,
        public array $missingParameters,
        public array $ambiguities,
        public float $semanticConfidence,
        public float $objectConfidence,
        public float $completenessConfidence,
        public string $source = 'rules',
        public array $workflowSteps = [],
    ) {}

    public function score(): float
    {
        return round(
            ($this->semanticConfidence * 0.55)
            + ($this->objectConfidence * 0.25)
            + ($this->completenessConfidence * 0.20),
            4,
        );
    }

    public function requiresClarification(): bool
    {
        return $this->mode === 'workflow'
            && ($this->score() < (float) config('ai-workspace.resolution_threshold', 0.85)
                || $this->missingParameters !== []
                || $this->ambiguities !== []);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'intent' => $this->intent,
            'candidate_capabilities' => $this->candidates,
            'known_parameters' => $this->knownParameters,
            'missing_parameters' => $this->missingParameters,
            'ambiguities' => $this->ambiguities,
            'semantic_confidence' => $this->semanticConfidence,
            'object_confidence' => $this->objectConfidence,
            'completeness_confidence' => $this->completenessConfidence,
            'resolution_score' => $this->score(),
            'source' => $this->source,
            'workflow_steps' => $this->workflowSteps,
        ];
    }
}
