<?php

namespace App\Support\GeoFlow;

final class AiQualityRetrievalBasis
{
    private function __construct(private readonly array $payload) {}

    /**
     * @param  list<array<string,mixed>>  $knowledgeSources
     * @param  array<string,mixed>  $rollout
     */
    public static function make(
        string $retrievalMode,
        int $policyVersion,
        array $knowledgeSources,
        array $rollout,
        string $strategyVersion = 'ai-quality-retrieval-1.0.0',
        array $executionOptions = [],
    ): self {
        $usesAtomicFacts = $retrievalMode === AiQualityRetrievalMode::ATOMIC_FIRST;
        $payload = self::canonicalize([
            'schema_version' => 1,
            'retrieval_mode' => $retrievalMode,
            'strategy_version' => $strategyVersion,
            'execution_options' => $executionOptions,
            'policy_version' => max(1, $policyVersion),
            'knowledge_sources' => $knowledgeSources,
            'rollout' => [
                'epoch' => max(1, (int) ($rollout['epoch'] ?? 1)),
                'atomic_fact_percent' => $usesAtomicFacts ? (int) ($rollout['atomic_fact_percent'] ?? 0) : 0,
                'atomic_fact_frozen' => $usesAtomicFacts && (bool) ($rollout['atomic_fact_frozen'] ?? false),
                'frozen' => (bool) ($rollout['frozen'] ?? false),
            ],
        ]);

        return new self($payload);
    }

    public function hash(): string
    {
        return hash('sha256', json_encode(
            $this->payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [...$this->payload, 'hash' => $this->hash()];
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value);

        return array_map(self::canonicalize(...), $value);
    }
}
