<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\AiVisibility\AiVisibilityCollectionService;
use Illuminate\Console\Command;
use Throwable;

class GeoFlowCollectAiVisibilityCommand extends Command
{
    protected $signature = 'geoflow:ai-visibility:collect
                            {keywords* : One or more keywords to collect}';

    protected $description = 'Collect AI visibility search and analysis runs with saved provider bindings';

    public function handle(AiVisibilityCollectionService $collection): int
    {
        $keywords = array_values(array_unique(array_filter(
            array_map(static fn (mixed $keyword): string => trim((string) $keyword), (array) $this->argument('keywords')),
        )));
        if ($keywords === []) {
            $this->error('At least one keyword is required.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($keywords as $keyword) {
            try {
                $runs = $collection->collect($keyword);
                $this->info(sprintf('AI visibility collected: keyword=%s, runs=%d', $keyword, count($runs)));
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
                $this->error(sprintf('AI visibility collection failed: keyword=%s', $keyword));
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
