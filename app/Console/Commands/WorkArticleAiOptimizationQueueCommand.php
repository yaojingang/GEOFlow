<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class WorkArticleAiOptimizationQueueCommand extends Command
{
    protected $signature = 'geoflow:work-ai-optimization {--validate : Validate queue and timeout configuration without starting a worker}';

    protected $description = 'Run the prioritized AI content optimization queues';

    public function handle(): int
    {
        $frontQueue = (string) config('geoflow.ai_quality_optimization_queue', 'ai-content-optimization');
        $bulkQueue = (string) config('geoflow.ai_quality_optimization_bulk_queue', 'ai-content-optimization-bulk');
        $jobTimeout = (int) config('geoflow.ai_quality_optimization_job_timeout_seconds', 850);
        $workerTimeout = max($jobTimeout + 10, min(940, (int) config('geoflow.ai_quality_optimization_worker_timeout_seconds', 900)));
        $retryAfter = (int) config('queue.connections.redis.retry_after', 960);
        if ($frontQueue === '' || $bulkQueue === '' || $frontQueue === $bulkQueue) {
            $this->components->error('AI optimization queues must be non-empty and distinct.');

            return self::FAILURE;
        }
        if (! ($jobTimeout < $workerTimeout && $workerTimeout < $retryAfter)) {
            $this->components->error('AI optimization timeout chain must satisfy job < worker < retry_after.');

            return self::FAILURE;
        }
        if ((bool) $this->option('validate')) {
            $this->components->info('AI optimization worker configuration is valid.');

            return self::SUCCESS;
        }

        return $this->call('queue:work', [
            'connection' => 'redis',
            '--queue' => $frontQueue.','.$bulkQueue,
            '--sleep' => 1,
            '--tries' => 1,
            '--timeout' => $workerTimeout,
            '--memory' => 192,
            '--max-jobs' => 25,
            '--max-time' => 3600,
            '--force' => true,
        ]);
    }
}
