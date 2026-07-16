<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\ManagedImageFileService;
use Illuminate\Console\Command;

class ManagedImageHashReadinessCommand extends Command
{
    protected $signature = 'geoflow:managed-images:readiness';

    protected $description = 'Backfill managed image path hashes and report whether physical deletion is rollout-ready';

    public function handle(ManagedImageFileService $managedImages): int
    {
        $status = $managedImages->managedPathHashReadiness();

        $this->table(['processed', 'resolved', 'terminal', 'remaining', 'deletion_enabled', 'ready'], [[
            $status['processed'],
            $status['resolved'],
            $status['terminal'],
            $status['remaining'],
            $status['deletion_enabled'] ? 'yes' : 'no',
            $status['ready'] ? 'yes' : 'no',
        ]]);

        if ($status['remaining'] !== 0) {
            $this->components->error('Managed image hashes remain incomplete. Keep physical deletion disabled.');

            return self::FAILURE;
        }

        if (! $status['deletion_enabled']) {
            $this->components->warn('Backfill is complete. Drain and restart every old app/queue process before enabling GEOFLOW_MANAGED_IMAGE_DELETION_ENABLED.');

            return self::SUCCESS;
        }

        $this->components->info('Managed image deletion is enabled and the hash backfill is complete.');

        return self::SUCCESS;
    }
}
