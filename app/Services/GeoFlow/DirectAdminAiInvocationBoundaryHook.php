<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\DirectAdminAiExecutionContext;
use App\Models\AiModel;

class DirectAdminAiInvocationBoundaryHook
{
    public function beforeCandidateLock(DirectAdminAiExecutionContext $context, AiModel $candidate): void {}
}
