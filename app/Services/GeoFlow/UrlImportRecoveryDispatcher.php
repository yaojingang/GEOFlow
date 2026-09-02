<?php

namespace App\Services\GeoFlow;

use App\Jobs\ProcessUrlImportJob;

class UrlImportRecoveryDispatcher
{
    public function dispatch(int $jobId): void
    {
        ProcessUrlImportJob::dispatch($jobId);
    }
}
