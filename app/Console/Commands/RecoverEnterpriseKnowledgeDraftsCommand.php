<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\EnterpriseKnowledgeDraftRecoveryService;
use Illuminate\Console\Command;

final class RecoverEnterpriseKnowledgeDraftsCommand extends Command
{
    protected $signature = 'geoflow:recover-enterprise-knowledge-drafts {--limit=50}';

    protected $description = 'Requeue stale enterprise knowledge draft generations';

    public function handle(EnterpriseKnowledgeDraftRecoveryService $recovery): int
    {
        $result = $recovery->reconcile(max(1, min(500, (int) $this->option('limit'))));
        $this->info(sprintf(
            'Recovered enterprise knowledge drafts: %d; dispatch failures: %d',
            $result['recovered'],
            $result['dispatch_failed'],
        ));

        return $result['dispatch_failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
