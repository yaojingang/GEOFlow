<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class WorkArticleAiQualityQueueCommand extends Command
{
    protected $signature = 'geoflow:work-ai-quality
        {lane=front : front, backfill or deadlines}
        {--validate : Validate the normalized timeout chain without starting a worker}';

    protected $description = 'Run an AI quality queue worker with the same normalized timeout configuration as the application';

    public function handle(): int
    {
        $lane = strtolower(trim((string) $this->argument('lane')));
        if (! in_array($lane, ['front', 'backfill', 'deadlines'], true)) {
            $this->components->error('Lane must be front, backfill or deadlines.');

            return self::INVALID;
        }

        $deadlines = $lane === 'deadlines';
        $business = (int) config('geoflow.ai_quality_deadline_seconds', 180)
            + (int) config('geoflow.ai_quality_sampled_fallback_seconds', 45)
            + (int) config('geoflow.ai_quality_persistence_reserve_seconds', 10);
        $job = $deadlines ? 30 : (int) config('geoflow.ai_quality_job_timeout_seconds', 245);
        $worker = $deadlines ? 35 : (int) config('geoflow.ai_quality_worker_timeout_seconds', 250);
        $retryAfter = (int) config('queue.connections.redis.retry_after', 960);
        if ((! $deadlines && $business >= $job) || $job >= $worker || $worker >= $retryAfter) {
            $this->components->error('AI quality timeout chain must satisfy business < job < worker < retry_after.');

            return self::FAILURE;
        }
        if ((bool) $this->option('validate')) {
            $this->components->info("AI quality {$lane} worker configuration is valid.");

            return self::SUCCESS;
        }

        $front = $lane === 'front';
        $queue = match ($lane) {
            'deadlines' => 'ai-quality-deadlines',
            'front' => (string) config('geoflow.ai_quality_queue', 'ai-quality'),
            'backfill' => (string) config('geoflow.ai_quality_backfill_queue', 'ai-quality-backfill'),
        };

        return $this->call('queue:work', [
            'connection' => 'redis',
            '--queue' => $queue,
            '--sleep' => $front || $deadlines ? 1 : 2,
            '--tries' => 1,
            '--timeout' => $worker,
            '--memory' => $front ? 192 : 128,
            '--max-jobs' => $deadlines ? 1000 : ($front ? 100 : 25),
            '--max-time' => $front ? 3600 : 1800,
            '--force' => true,
        ]);
    }
}
