<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\ManagedImageFileService;
use Illuminate\Console\Command;

class ManagedImageHashReadinessCommand extends Command
{
    protected $signature = 'geoflow:managed-images:readiness';

    protected $description = 'Backfill managed image identities, reconcile their registry, and report physical deletion readiness';

    public function handle(ManagedImageFileService $managedImages): int
    {
        $status = $managedImages->managedPathHashReadiness();

        $this->table(['processed', 'resolved', 'terminal', 'remaining', 'registry_reconciled', 'registry_failed', 'deletion_enabled', 'ready'], [[
            $status['processed'],
            $status['resolved'],
            $status['terminal'],
            $status['remaining'],
            $status['registry_reconciled'],
            $status['registry_failed'],
            $status['deletion_enabled'] ? 'yes' : 'no',
            $status['ready'] ? 'yes' : 'no',
        ]]);

        if ($status['remaining'] !== 0 || $status['terminal'] !== 0 || $status['registry_failed'] !== 0) {
            $this->components->error('Managed image readiness is incomplete. Keep physical deletion disabled and resolve every terminal or failed registry entry.');

            return self::FAILURE;
        }

        if (! $status['deletion_enabled']) {
            $this->components->warn('Backfill and registry reconciliation are complete. Drain and restart every old app/queue process before enabling GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED.');

            return self::SUCCESS;
        }

        $this->components->info('Managed image deletion is enabled and managed image readiness is complete.');

        return self::SUCCESS;
    }
}
