<?php

namespace App\Console\Commands;

use App\Jobs\DetectAiVisibilityCompetitorsJob;
use App\Services\Admin\Analytics\AiVisibilityCompetitorDetectionService;
use Illuminate\Console\Command;

class GeoFlowDetectCompetitorsCommand extends Command
{
    protected $signature = 'geoflow:ai-visibility:detect-competitors {--limit=8}';

    protected $description = 'Use AI to detect competitor brands mentioned in recent AI visibility samples';

    public function handle(AiVisibilityCompetitorDetectionService $detection): int
    {
        $runIds = $detection->pendingRunIds((int) $this->option('limit'));
        foreach ($runIds as $runId) {
            DetectAiVisibilityCompetitorsJob::dispatch($runId);
        }
        $this->info(sprintf('AI competitor detection queued: %d runs', count($runIds)));

        return self::SUCCESS;
    }
}
