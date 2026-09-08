<?php

namespace App\Console\Commands;

use App\Services\Deployment\UpgradeOrchestrator;
use Illuminate\Console\Command;

class GeoFlowUpgradeCommand extends Command
{
    protected $signature = 'geoflow:upgrade
        {--phase=inspect : inspect, apply, or verify}
        {--strategy=maintenance : online or maintenance}
        {--operation= : Host operation identifier, required for apply}
        {--source-sequence=0 : Installed numeric release sequence}
        {--plan= : Versioned plan path; defaults to deployment/upgrade-plan.json}
        {--plan-sha256= : SHA256 of the exact signed plan bytes; required for apply}
        {--json : Emit the machine-readable upgrade report}';

    protected $description = 'Inspect, apply, or verify the signed GEOFlow deployment upgrade plan';

    public function handle(UpgradeOrchestrator $upgrades): int
    {
        $report = $upgrades->execute(
            (string) $this->option('phase'), (string) $this->option('strategy'),
            (string) $this->option('operation'),
            $this->sourceSequence(),
            (string) ($this->option('plan') ?: base_path('deployment/upgrade-plan.json')),
            (string) $this->option('plan-sha256'),
        );
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line('Upgrade '.$report['phase'].': '.$report['status']);
            foreach ($report['errors'] as $error) {
                $this->error($error);
            }
        }

        return $report['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
    }

    private function sourceSequence(): int
    {
        $source = (string) $this->option('source-sequence');
        if (! preg_match('/\A[0-9]+\z/', $source)) {
            return -1;
        }
        $source = ltrim($source, '0');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($source) > strlen($maximum)
            || (strlen($source) === strlen($maximum) && strcmp($source, $maximum) > 0)) {
            return -1;
        }

        return $source === '' ? 0 : (int) $source;
    }
}
