<?php

namespace App\Data\Ai;

use App\Models\AiModel;
use App\Services\GeoFlow\DirectAdminAiModelInvocation;

final class DirectAdminAiExecutionState
{
    public function __construct(
        public readonly DirectAdminAiExecutionContext $context,
        public AiModel $model,
        public string $source,
    ) {}

    public function adopt(DirectAdminAiModelInvocation $invocation): void
    {
        $this->model = $invocation->model;
        $this->source = $invocation->source;
    }
}
