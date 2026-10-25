<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\Distribution\PlatformWeb\PlatformWebDistributionBridge;
use App\Services\SystemUpdater\RecoveryState;
use Illuminate\Console\Command;

class ReconcilePlatformWebPublications extends Command
{
    protected $signature = 'geoflow:reconcile-platform-web {--limit=100 : Maximum work orders per run (1-500)}';

    protected $description = 'Reconcile persisted terminal manual publication receipts with platform distributions';

    public function handle(PlatformWebDistributionBridge $bridge, RecoveryState $recovery): int
    {
        $recovery->assertBackgroundReady();
        $count = $bridge->reconcile((int) $this->option('limit'));
        $this->info('Reconciled '.$count.' manual publication receipts.');

        return self::SUCCESS;
    }
}
