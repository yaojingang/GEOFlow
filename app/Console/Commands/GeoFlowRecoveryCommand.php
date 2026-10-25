<?php

namespace App\Console\Commands;

use App\Services\SystemUpdater\RecoveryPreparation;
use App\Services\SystemUpdater\RecoveryReconciliation;
use Illuminate\Console\Command;
use Throwable;

class GeoFlowRecoveryCommand extends Command
{
    protected $signature = 'geoflow:recovery {--phase=inspect} {--transaction=} {--expected-admin-digest=} {--after=0} {--limit=100} {--json}';

    protected $description = 'Run fixed isolated host recovery validation and credential invalidation';

    public function handle(RecoveryPreparation $recovery, RecoveryReconciliation $reconciliation): int
    {
        try {
            $report = match ($this->option('phase')) {
                'inspect' => $recovery->inspect(),
                'prepare' => $recovery->prepare((string) $this->option('transaction'), (string) $this->option('expected-admin-digest')),
                'verify' => $recovery->verify((string) $this->option('transaction')),
                'reconcile-inspect' => $reconciliation->inspect((string) $this->option('transaction'), (int) $this->option('after'), (int) $this->option('limit')),
                default => throw new \RuntimeException('recovery_phase_invalid'),
            };
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
            $report = ['schema_version' => 1, 'status' => 'fail', 'error' => preg_match('/^recovery_[a-z_]+$/D', $message) === 1 ? $message : 'recovery_validation_failed'];
        }
        // Security digests are available only to the structured host protocol.
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('Recovery validation: '.$report['status']);
            if (isset($report['error'])) {
                $this->error($report['error']);
            }
        }

        return $report['status'] === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
