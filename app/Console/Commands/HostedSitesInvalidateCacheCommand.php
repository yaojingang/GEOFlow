<?php

namespace App\Console\Commands;

use App\Models\HostedSiteProfile;
use App\Services\Site\HostedSiteResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

final class HostedSitesInvalidateCacheCommand extends Command
{
    protected $signature = 'hosted-sites:invalidate-cache {hostname?} {--all} {--dry-run}';

    protected $description = 'Invalidate hosted site resolver and versioned settings caches';

    public function handle(HostedSiteResolver $resolver): int
    {
        $query = HostedSiteProfile::query();
        if (! $this->option('all')) {
            $hostname = strtolower(trim((string) $this->argument('hostname')));
            if ($hostname === '') {
                $this->error('Provide a hostname or use --all.');

                return self::INVALID;
            }
            $query->where('hostname', $hostname);
        }

        $profiles = $query->orderBy('id')->get();
        if ($profiles->isEmpty()) {
            $this->error('No hosted sites matched.');

            return self::FAILURE;
        }
        if (! $this->option('dry-run')) {
            foreach ($profiles as $profile) {
                Cache::forget(sprintf(
                    'geoflow.hosted_sites.settings.%d.v%d',
                    (int) $profile->id,
                    (int) $profile->settings_version,
                ));
                $resolver->invalidate((string) $profile->hostname);
            }
        }
        $this->info(sprintf('Matched hosted site caches: %d%s', $profiles->count(), $this->option('dry-run') ? ' (dry run)' : ''));

        return self::SUCCESS;
    }
}
