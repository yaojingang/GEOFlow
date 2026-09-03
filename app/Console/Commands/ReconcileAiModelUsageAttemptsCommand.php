<?php

namespace App\Console\Commands;

use App\Services\Admin\AiModelUsageAttemptReconciler;
use Illuminate\Console\Command;

final class ReconcileAiModelUsageAttemptsCommand extends Command
{
    protected $signature = 'geoflow:reconcile-ai-usage-attempts
        {--older-than=900 : Minimum attempt age in seconds}
        {--batch=200 : Rows processed per batch}';

    protected $description = 'Append terminal failures for stale AI provider attempts without outcomes';

    public function handle(AiModelUsageAttemptReconciler $reconciler): int
    {
        $olderThan = filter_var($this->option('older-than'), FILTER_VALIDATE_INT);
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        if ($olderThan === false || $olderThan < 1 || $batch === false || $batch < 1 || $batch > 1000) {
            $this->error('Invalid reconciliation bounds.');

            return self::INVALID;
        }

        $count = $reconciler->reconcile($olderThan, $batch);
        $this->line('Stale AI usage attempts reconciled: '.$count);

        return self::SUCCESS;
    }
}
