<?php

namespace App\Console\Commands;

use App\Jobs\ReconcileArticleAiQualityJob;
use App\Services\GeoFlow\ArticleAiQualityBackfillGuard;
use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use Illuminate\Console\Command;

class ReconcileArticleAiQualityCommand extends Command
{
    protected $signature = 'geoflow:reconcile-ai-quality {--limit=100}';

    protected $description = 'Queue missing, stale and version-changed article AI quality checks';

    public function handle(
        ArticleAiQualityBackfillGuard $guard,
        ArticleAiQualityReconciliationService $reconciliation,
    ): int {
        $reconciliation->convergeExpired(max(1, min(500, (int) $this->option('limit'))));
        ReconcileArticleAiQualityJob::dispatch($guard->resumeCursor(), 0, max(1, min(500, (int) $this->option('limit'))))
            ->onConnection('redis')
            ->onQueue((string) config('geoflow.ai_quality_backfill_queue', 'ai-quality-backfill'));
        $this->info('AI quality reconciliation queued.');

        return self::SUCCESS;
    }
}
