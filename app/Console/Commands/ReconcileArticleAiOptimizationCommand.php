<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileArticleAiOptimizationJob;
use Illuminate\Console\Command;

final class ReconcileArticleAiOptimizationCommand extends Command
{
    protected $signature = 'geoflow:reconcile-ai-optimization {--limit=500}';

    protected $description = 'Recover stale AI quality optimization runs and pending workflows';

    public function handle(): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        ReconcileArticleAiOptimizationJob::dispatch($limit)
            ->onConnection('redis')
            ->onQueue((string) config('geoflow.ai_quality_optimization_bulk_queue', 'ai-content-optimization-bulk'));
        $this->info('AI quality optimization reconciliation queued.');

        return self::SUCCESS;
    }
}
