<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\UrlImportRecoveryService;
use Illuminate\Console\Command;

final class RecoverUrlImportsCommand extends Command
{
    protected $signature = 'geoflow:recover-url-imports {--limit=50}';

    protected $description = 'Requeue stale URL imports whose execution lease is missing or expired';

    public function handle(UrlImportRecoveryService $recovery): int
    {
        $result = $recovery->reconcile(max(1, min(500, (int) $this->option('limit'))));
        $this->info(sprintf(
            'Recovered stale URL imports: %d; dispatch failures: %d',
            $result['recovered'],
            $result['dispatch_failed'],
        ));

        return $result['dispatch_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
