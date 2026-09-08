<?php

namespace App\Console\Commands;

use App\Services\Deployment\GracefulScheduleWorker;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;

class GeoFlowScheduleWorkCommand extends Command
{
    protected $signature = 'geoflow:schedule-work
        {--drain-timeout=900 : Mark drain failed after this many seconds while preserving active tasks}';

    protected $description = 'Run scheduled tasks and drain every active scheduler child on TERM or INT';

    public function handle(GracefulScheduleWorker $worker, Schedule $schedule): int
    {
        if (! extension_loaded('pcntl') || ! ctype_digit((string) $this->option('drain-timeout'))
            || (int) $this->option('drain-timeout') < 1 || (int) $this->option('drain-timeout') > 86400) {
            $this->error('The scheduler requires pcntl and a drain timeout between 1 and 86400 seconds.');

            return self::FAILURE;
        }
        foreach ($schedule->events() as $event) {
            if ($event->runInBackground) {
                $this->error('Drainable schedules require foreground scheduled events.');

                return self::FAILURE;
            }
        }
        $this->trap([SIGTERM, SIGINT], static fn () => $worker->requestStop());
        try {
            return $worker->run((int) $this->option('drain-timeout'), fn (string $message) => $this->output->write($message));
        } finally {
            $this->untrap();
        }
    }
}
