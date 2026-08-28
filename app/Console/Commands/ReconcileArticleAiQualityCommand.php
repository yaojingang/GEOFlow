<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileArticleAiQualityJob;
use Illuminate\Console\Command;

class ReconcileArticleAiQualityCommand extends Command
{
    protected $signature = 'geoflow:reconcile-ai-quality {--limit=100}';

    protected $description = 'Queue missing, stale, and retryable article AI quality checks';

    public function handle(): int
    {
        ReconcileArticleAiQualityJob::dispatch(0, 0, max(1, min(500, (int) $this->option('limit'))))
            ->onQueue('geoflow');
        $this->info('AI quality reconciliation queued.');

        return self::SUCCESS;
    }
}
