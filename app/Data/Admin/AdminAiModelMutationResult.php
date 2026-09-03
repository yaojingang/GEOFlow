<?php

namespace App\Data\Admin;

use App\Models\AiModel;

final readonly class AdminAiModelMutationResult
{
    public function __construct(
        public AiModel $model,
        public ?string $error = null,
        public int $dependencyCount = 0,
    ) {}

    public function succeeded(): bool
    {
        return $this->error === null;
    }
}
