<?php

namespace App\Console\Commands;

use App\Models\GeoAiSearchRun;
use App\Services\Geo\GeoSearchBatchRunner;
use Illuminate\Console\Command;
use Throwable;

class GeoSearchRunCommand extends Command
{
    protected $signature = 'geo:search-run {runId : GEO AI search run id}';

    protected $description = 'Run a GEO AI search batch from the CLI.';

    public function handle(GeoSearchBatchRunner $runner): int
    {
        $run = GeoAiSearchRun::query()->find((int) $this->argument('runId'));
        if (! $run instanceof GeoAiSearchRun) {
            $this->error('Search run not found.');

            return self::FAILURE;
        }

        if ($run->status === 'completed') {
            $this->info('Search run already completed.');

            return self::SUCCESS;
        }

        try {
            $runner->run($run);
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => mb_substr($exception->getMessage(), 0, 1000),
            ])->save();

            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Search run completed.');

        return self::SUCCESS;
    }
}
