<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\TitleGenerationCoordinator;
use Illuminate\Console\Command;

class RecoverTitleGenerationRunsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geoflow:recover-title-generations {--limit=100}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Requeue stalled AI title generation batches';

    /**
     * Execute the console command.
     */
    public function handle(TitleGenerationCoordinator $coordinator): int
    {
        $recovered = $coordinator->recoverStalled(
            max(1, min(500, (int) $this->option('limit'))),
        );
        $this->info(sprintf('Recovered title generation runs: %d', $recovered));

        return self::SUCCESS;
    }
}
