<?php

namespace App\Jobs;

use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

final class ProcessUrlImportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 660;

    public function __construct(public readonly int $urlImportJobId) {}

    /** @return list<string> */
    public function tags(): array
    {
        return ['url-import', 'url-import:'.$this->urlImportJobId];
    }

    public function handle(UrlImportProcessingService $processing): void
    {
        $job = UrlImportJob::query()->whereKey($this->urlImportJobId)->first();
        if ($job instanceof UrlImportJob) {
            $processing->process($job);
        }
    }
}
