<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\ArticleAiQualityHealthService;
use Illuminate\Console\Command;

class ArticleAiQualityHealthCommand extends Command
{
    protected $signature = 'geoflow:ai-quality-health {--json} {--probe} {--wait=10}';

    protected $description = 'Check article AI quality queue readiness and optionally run a front-queue probe';

    public function handle(ArticleAiQualityHealthService $health): int
    {
        $probe = $this->option('probe')
            ? $health->probe((int) $this->option('wait'))
            : null;
        $snapshot = $health->snapshot(recordTransition: true);
        if ($probe !== null) {
            $snapshot['probe'] = $probe;
            if (! $snapshot['probe']['passed']) {
                $snapshot['status'] = 'unavailable';
                $snapshot['issues'][] = (string) $snapshot['probe']['error'];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf('AI quality service: %s', $snapshot['status']));
            foreach ($snapshot['issues'] as $issue) {
                $this->warn((string) $issue);
            }
        }

        return $snapshot['status'] === 'healthy' ? self::SUCCESS : self::FAILURE;
    }
}
