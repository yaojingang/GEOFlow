<?php

namespace App\Console\Commands;

use App\Services\Security\SecurityAuditService;
use Illuminate\Console\Command;
use JsonException;

class GeoFlowSecurityAuditCommand extends Command
{
    protected $signature = 'geoflow:security-audit {--json : Emit a deterministic machine-readable report}';

    protected $description = 'Run read-only security readiness and integrity checks';

    /**
     * @throws JsonException
     */
    public function handle(SecurityAuditService $audit): int
    {
        $report = $audit->audit();

        if ((bool) $this->option('json')) {
            $this->line(json_encode(
                $report,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));
        } else {
            $summary = $report['summary'];
            $this->table(['Critical', 'High', 'Medium', 'Low', 'Total'], [[
                $summary['critical'],
                $summary['high'],
                $summary['medium'],
                $summary['low'],
                $summary['total'],
            ]]);

            if ($report['findings'] === []) {
                $this->components->info('Security audit: OK');
            } else {
                $this->table(
                    ['Code', 'Severity', 'Count', 'Message', 'Remediation'],
                    array_map(static fn (array $finding): array => [
                        $finding['code'],
                        $finding['severity'],
                        $finding['count'],
                        $finding['message'],
                        $finding['remediation'],
                    ], $report['findings']),
                );
                $this->components->error('Security audit: findings require review.');
            }
        }

        return $report['findings'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
