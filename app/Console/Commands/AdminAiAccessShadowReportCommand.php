<?php

namespace App\Console\Commands;

use App\Services\Admin\AdminAiAccessShadowReportService;
use Illuminate\Console\Command;

final class AdminAiAccessShadowReportCommand extends Command
{
    protected $signature = 'geoflow:admin-ai-shadow-report
        {--hours=24 : Observation window in hours, from 1 to 2160}
        {--json : Print the complete report as JSON}';

    protected $description = 'Report administrator AI access shadow, identity, and shared-usage metrics';

    public function __construct(private readonly AdminAiAccessShadowReportService $reportService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $hoursValue = trim((string) $this->option('hours'));
        if (! ctype_digit($hoursValue) || (int) $hoursValue < 1 || (int) $hoursValue > 2160) {
            $this->components->error('The hours option must be an integer from 1 to 2160.');

            return self::INVALID;
        }

        $report = $this->reportService->report((int) $hoursValue);
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->components->info(sprintf('Administrator AI access report (%d hours)', $report['window_hours']));
        $this->table(['Metric', 'Value'], [
            ['Shadow enabled', $report['shadow']['enabled'] ? 'yes' : 'no'],
            ['Resolution count', $report['shadow']['resolution_count']],
            ['Preferred-model mismatches', $report['shadow']['preferred_model_mismatch_count']],
            ['Preferred-model mismatch rate', $report['shadow']['preferred_model_mismatch_rate']],
            ['Safe model missing', $report['shadow']['safe_model_missing_count']],
            ['Models without owner', $report['identity']['models_without_owner']],
            ['Execution identity gaps', $report['identity']['gap_count']],
            ['Shared successful calls', $report['shared_usage']['shared_success_count']],
            ['Shared total tokens', $report['shared_usage']['shared_total_tokens']],
        ]);

        return self::SUCCESS;
    }
}
