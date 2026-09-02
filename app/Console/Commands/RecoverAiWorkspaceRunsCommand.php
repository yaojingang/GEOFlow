<?php

namespace App\Console\Commands;

use App\Services\AiWorkspace\AiWorkflowEngine;
use App\Services\AiWorkspace\AiWorkspaceCoordinator;
use Illuminate\Console\Command;

final class RecoverAiWorkspaceRunsCommand extends Command
{
    protected $signature = 'geoflow:recover-ai-workspace {--limit=50}';

    protected $description = 'Recover AI workspace runs whose execution leases expired';

    public function handle(AiWorkspaceCoordinator $coordinator, AiWorkflowEngine $engine): int
    {
        $limit = max(1, min(200, (int) $this->option('limit')));
        $resolutionRuns = $coordinator->recoverExpiredResolutions($limit);
        $executionRuns = $resolutionRuns < $limit
            ? $engine->recoverExpiredExecutions($limit - $resolutionRuns)
            : 0;

        $this->info(sprintf(
            'Recovered AI workspace runs: %d',
            $resolutionRuns + $executionRuns,
        ));

        return self::SUCCESS;
    }
}
