<?php

namespace App\Console\Commands;

use App\Services\Admin\Analytics\AiVisibilityCompetitorDetectionService;
use Illuminate\Console\Command;

class GeoFlowDetectCompetitorsCommand extends Command
{
    protected $signature = 'geoflow:ai-visibility:detect-competitors {--limit=8}';

    protected $description = 'Use AI to detect competitor brands mentioned in recent AI visibility samples';

    public function handle(AiVisibilityCompetitorDetectionService $detection): int
    {
        $result = $detection->detect((int) $this->option('limit'));

        $this->info(sprintf(
            'AI competitor detection processed: %d runs, discovered: %s',
            $result['processed'],
            $result['discovered'] === [] ? '(none)' : implode(', ', $result['discovered']),
        ));

        return self::SUCCESS;
    }
}
