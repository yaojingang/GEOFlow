<?php

namespace App\Console\Commands;

use App\Services\HostedSites\HostedSiteReconciler;
use Illuminate\Console\Command;

final class HostedSitesReconcileCommand extends Command
{
    protected $signature = 'hosted-sites:reconcile {--limit=500} {--dry-run}';

    protected $description = 'Repair due hosted allocation requests and expired reservations';

    public function handle(HostedSiteReconciler $reconciler): int
    {
        $result = $reconciler->reconcile(
            (int) $this->option('limit'),
            (bool) $this->option('dry-run'),
        );
        $this->table(array_keys($result), [array_values($result)]);

        return self::SUCCESS;
    }
}
