<?php

namespace App\Data\Ai;

final readonly class KnowledgeEmbeddingProviderResult
{
    /**
     * @param  array<int,mixed>  $embeddings
     */
    public function __construct(
        public array $embeddings,
        public mixed $usage = null,
    ) {}
}
