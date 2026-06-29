<?php

namespace App\Console\Commands;

use App\Models\DistributionChannel;
use App\Services\GeoFlow\FrontendExperienceInspector;
use Illuminate\Console\Command;

class FrontendExperienceInspectCommand extends Command
{
    protected $signature = 'geoflow:frontend-experience
        {channel? : Distribution channel id}
        {--json : Output JSON}
        {--live-remote : Read remote frontend capabilities without updating the channel cache}';

    protected $description = 'Inspect default and channel frontend experience capabilities.';

    public function handle(FrontendExperienceInspector $inspector): int
    {
        $channelId = $this->argument('channel');
        $channel = null;
        if ($channelId !== null) {
            $channel = DistributionChannel::query()->find((int) $channelId);
            if (! $channel) {
                $this->error('Distribution channel not found.');

                return self::FAILURE;
            }
        }

        $report = $inspector->inspect($channel, $channel !== null, (bool) $this->option('live-remote'));
        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('GEOFlow frontend experience capability report');
        $this->line('Default site modules: '.$report['default_site']['homepage_modules_count']);
        $this->line('Target package capability version: '.$report['target_package']['capability_version']);
        if ($channel) {
            $this->line('Channel: '.$channel->name.' (#'.$channel->id.')');
            $this->line('Channel type: '.$report['channel']['type']);
            $this->line('Frontend mode: '.$report['channel']['frontend_experience_mode']);
            $summary = is_array($report['channel']['sync_summary'] ?? null) ? $report['channel']['sync_summary'] : [];
            $this->line('Sync summary: modules='.(int) ($summary['homepage_modules_count'] ?? 0).', slides='.(int) ($summary['home_carousel_slides_count'] ?? 0).', text_ads='.(int) ($summary['article_text_ads_count'] ?? 0));
            if (is_array($report['remote_target'] ?? null)) {
                $remote = $report['remote_target'];
                $this->line('Remote capabilities: '.$remote['status'].(($remote['capability_version'] ?? '') !== '' ? ' ('.$remote['capability_version'].')' : ''));
            }
            foreach ($report['differences'] as $difference) {
                $this->line('['.$difference['severity'].'] '.$difference['area'].': '.$difference['message']);
            }
        }

        return self::SUCCESS;
    }
}
