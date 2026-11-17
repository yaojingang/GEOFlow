<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use App\Services\GeoFlow\ArticlePublicationDeliveryService;
use App\Services\GeoFlow\DistributionOrchestrator;
use Illuminate\Console\Command;

class ConvergeArticleAiQualityCommand extends Command
{
    protected $signature = 'geoflow:converge-ai-quality {--limit=100} {--json} {--deadlines-only : Only close stuck or expired checks and due publication handoffs}';

    protected $description = 'Close expired AI quality checks. The scheduled run only watches deadlines; a full run also resumes workflows and due retries';

    public function handle(ArticleAiQualityReconciliationService $reconciliation): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $result = $this->option('deadlines-only')
            ? [
                ...$reconciliation->convergeDeadlines($limit),
                'workflows' => 0,
                'technical_retries' => 0,
                'delivery_handoffs' => app(ArticlePublicationDeliveryService::class)->recoverPending($limit),
                'delivery_queue_submissions' => app(DistributionOrchestrator::class)->recoverUndispatched($limit),
            ]
            : $reconciliation->convergeExpired($limit);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info(sprintf(
                'AI quality convergence finished: %d expired, %d workflows resumed.',
                $result['expired'],
                $result['workflows'],
            ));
        }

        return self::SUCCESS;
    }
}
