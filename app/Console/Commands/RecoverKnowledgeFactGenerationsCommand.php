<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\KnowledgeFacts\KnowledgeFactGenerationRecoveryService;
use Illuminate\Console\Command;

class RecoverKnowledgeFactGenerationsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'geoflow:recover-knowledge-fact-generations {--limit=50}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recover stalled knowledge fact AI generation runs';

    /**
     * Execute the console command.
     */
    public function handle(KnowledgeFactGenerationRecoveryService $recovery): int
    {
        $result = $recovery->reconcile(max(1, min(500, (int) $this->option('limit'))));
        $this->info(sprintf(
            'Recovered knowledge fact generation runs: %d; dispatch failures: %d',
            $result['recovered'],
            $result['dispatch_failed'],
        ));

        return $result['dispatch_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
