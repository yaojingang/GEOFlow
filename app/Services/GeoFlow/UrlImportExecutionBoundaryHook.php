<?php

namespace App\Services\GeoFlow;

use App\Models\UrlImportJob;

class UrlImportExecutionBoundaryHook
{
    public function beforePreviewCommit(UrlImportJob $job): void {}
}
