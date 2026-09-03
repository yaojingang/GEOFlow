<?php

namespace App\Services\GeoFlow;

use App\Jobs\GenerateEnterpriseKnowledgeDraftJob;

class EnterpriseKnowledgeDraftRecoveryDispatcher
{
    public function dispatch(int $projectId): void
    {
        GenerateEnterpriseKnowledgeDraftJob::dispatch($projectId)->onQueue('geoflow');
    }
}
