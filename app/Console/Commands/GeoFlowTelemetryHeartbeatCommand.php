<?php

namespace App\Console\Commands;

use App\Services\GeoFlow\AnonymousUsageTelemetry;
use Illuminate\Console\Command;

class GeoFlowTelemetryHeartbeatCommand extends Command
{
    protected $signature = 'geoflow:telemetry:heartbeat';

    protected $description = 'Send one anonymous GEOFlow deployment activity event per day';

    public function handle(AnonymousUsageTelemetry $telemetry): int
    {
        $event = $telemetry->reportDailyActivity();

        $this->components->info($event === null
            ? 'Anonymous deployment activity is already current or telemetry is unavailable.'
            : "Anonymous {$event} event was accepted.");

        return self::SUCCESS;
    }
}
