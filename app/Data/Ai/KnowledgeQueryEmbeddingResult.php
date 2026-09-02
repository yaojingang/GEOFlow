<?php

namespace App\Data\Ai;

final readonly class KnowledgeQueryEmbeddingResult
{
    /**
     * @param  list<float>  $vector
     */
    private function __construct(
        public array $vector,
        public ?int $modelId,
        public ?string $modelSource,
        public ?string $errorCode,
        public ?string $reason,
    ) {}

    /** @param list<float> $vector */
    public static function success(array $vector, int $modelId, string $modelSource): self
    {
        return new self($vector, $modelId, $modelSource, null, null);
    }

    public static function incompatible(string $reason): self
    {
        return new self([], null, null, 'ai_embedding_incompatible', $reason);
    }

    public function successful(): bool
    {
        return $this->vector !== [] && $this->modelId !== null;
    }

    /** @return array{embedding_mode:string,embedding_model_id:?int,embedding_model_source:?string,error_code:?string,reason:?string} */
    public function safeMetadata(): array
    {
        return [
            'embedding_mode' => $this->successful() ? 'compatible_vector' : 'keyword_fallback',
            'embedding_model_id' => $this->modelId,
            'embedding_model_source' => $this->modelSource,
            'error_code' => $this->errorCode,
            'reason' => $this->reason,
        ];
    }
}
