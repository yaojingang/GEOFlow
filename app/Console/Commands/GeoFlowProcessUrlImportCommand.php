<?php

namespace App\Console\Commands;

use App\Models\UrlImportJob;
use App\Services\GeoFlow\UrlImportProcessingService;
use Illuminate\Console\Command;

class GeoFlowProcessUrlImportCommand extends Command
{
    protected $signature = 'geoflow:process-url-import {jobId : URL import job ID}';

    protected $description = 'Process a GEOFlow URL smart import job in the background';

    public function handle(UrlImportProcessingService $service): int
    {
        $job = UrlImportJob::query()->whereKey((int) $this->argument('jobId'))->first();
        if (! $job) {
            $this->error('URL import job not found.');

            return self::FAILURE;
        }

        if (in_array($job->status, ['completed', 'imported'], true)) {
            $this->info('URL import job already completed.');

            return self::SUCCESS;
        }

        if ((string) $job->status === 'failed' && ! (bool) $job->retryable_failure) {
            $this->error('URL import job cannot be retried: '.((string) $job->error_code ?: 'url_import_failed'));

            return self::FAILURE;
        }

        $job = $service->process($job);
        if ((string) $job->status === 'failed') {
            $this->error('URL import job failed: '.((string) $job->error_code ?: 'url_import_failed'));

            return self::FAILURE;
        }

        $this->info('URL import job processed.');

        return self::SUCCESS;
    }
}
