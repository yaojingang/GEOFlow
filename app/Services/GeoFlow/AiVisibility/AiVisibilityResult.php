<?php

namespace App\Services\GeoFlow\AiVisibility;

final class AiVisibilityResult
{
    /**
     * @param  list<AiVisibilitySourceData>  $sources
     * @param  array<string,mixed>  $usage
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $rawRequest
     * @param  array<string,mixed>  $rawResponse
     */
    public function __construct(
        public readonly string $providerType,
        public readonly ?string $providerKey,
        public readonly ?string $modelId,
        public readonly string $answerText,
        public readonly array $sources,
        public readonly array $usage,
        public readonly array $metadata,
        public readonly array $rawRequest,
        public readonly array $rawResponse,
        public readonly int $latencyMs,
    ) {}
}
