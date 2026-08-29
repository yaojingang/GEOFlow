<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use Illuminate\Console\Command;

class ConvergeArticleAiQualityCommand extends Command
{
    protected $signature = 'geoflow:converge-ai-quality {--limit=100} {--json}';

    protected $description = 'Synchronously close expired AI quality checks and resume completed workflows';

    public function handle(ArticleAiQualityReconciliationService $reconciliation): int
    {
        $result = $reconciliation->convergeExpired((int) $this->option('limit'));

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
