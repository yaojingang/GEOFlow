<?php

namespace App\Console\Commands;

use App\Models\DistributionChannel;
use App\Services\HostedSites\HostedSiteQualityService;
use Illuminate\Console\Command;

final class HostedSitesPreflightCommand extends Command
{
    protected $signature = 'hosted-sites:preflight {hostname?} {--all} {--dry-run}';

    protected $description = 'Run hosted site configuration quality checks';

    public function handle(HostedSiteQualityService $quality): int
    {
        $query = DistributionChannel::query()->where('channel_type', DistributionChannel::TYPE_HOSTED_SITE);
        if (! $this->option('all')) {
            $hostname = strtolower(trim((string) $this->argument('hostname')));
            if ($hostname === '') {
                $this->error('Provide a hostname or use --all.');

                return self::INVALID;
            }
            $query->where('domain', $hostname);
        }

        $channels = $query->orderBy('id')->get();
        if ($channels->isEmpty()) {
            $this->error('No hosted sites matched.');

            return self::FAILURE;
        }

        $failed = 0;
        foreach ($channels as $channel) {
            $result = $quality->preflight($channel, ! $this->option('dry-run'));
            $this->line($channel->domain.': '.($result['passed'] ? 'passed' : 'failed'));
            if (! $result['passed']) {
                $failedChecks = array_keys(array_filter(
                    $result['checks'],
                    static fn (bool $passed): bool => ! $passed
                ));
                $this->warn('  failed checks: '.implode(', ', $failedChecks));
            }
            $failed += $result['passed'] ? 0 : 1;
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
