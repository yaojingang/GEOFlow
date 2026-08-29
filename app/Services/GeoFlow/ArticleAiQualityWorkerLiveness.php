<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleAiQualityCheck;
use App\Models\WorkerHeartbeat;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ArticleAiQualityWorkerLiveness
{
    public const PROBE_STATE_CACHE_KEY = 'geoflow:ai-quality:health:last-probe';

    /** @var array<string, float> */
    private static array $lastWrites = [];

    /** @var array<string, true> */
    private static array $recordedWorkerIds = [];

    public function record(string $connection, string $queues): void
    {
        if ($connection !== 'redis') {
            return;
        }

        foreach ($this->qualityQueues($queues) as $queue) {
            $key = $connection.':'.$queue;
            $now = microtime(true);
            try {
                if (! Schema::hasTable('worker_heartbeats')) {
                    continue;
                }

                $workerId = $this->workerId($connection, $queue);
                if (($now - (self::$lastWrites[$key] ?? 0.0)) < $this->heartbeatInterval()
                    && WorkerHeartbeat::query()->whereKey($workerId)->exists()) {
                    continue;
                }

                self::$lastWrites[$key] = $now;
                self::$recordedWorkerIds[$workerId] = true;

                WorkerHeartbeat::query()->updateOrCreate(
                    ['worker_id' => $workerId],
                    [
                        'status' => 'idle',
                        'last_seen_at' => now(),
                        'meta' => [
                            'kind' => 'article_ai_quality_queue',
                            'connection' => $connection,
                            'queue' => $queue,
                            'role' => $queue === $this->frontQueue() ? 'front' : 'backfill',
                            'pid' => getmypid(),
                        ],
                    ],
                );
            } catch (Throwable $exception) {
                Log::warning('ai_quality_worker_heartbeat_write_failed', [
                    'connection' => $connection,
                    'queue' => $queue,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    public function removeCurrentProcess(): int
    {
        $workerIds = array_keys(self::$recordedWorkerIds);
        if ($workerIds === []) {
            return 0;
        }

        try {
            $deleted = WorkerHeartbeat::query()->whereKey($workerIds)->delete();
            self::$recordedWorkerIds = [];

            return $deleted;
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return array{front: int, backfill: int} */
    public function freshCounts(): array
    {
        $counts = ['front' => 0, 'backfill' => 0];
        foreach ($this->freshInstances() as $heartbeat) {
            $queue = (string) data_get($heartbeat->meta, 'queue');
            if ($queue === $this->frontQueue()) {
                $counts['front']++;
            } elseif ($queue === $this->backfillQueue()) {
                $counts['backfill']++;
            }
        }

        return $counts;
    }

    public function hasFreshConsumer(string $queue): bool
    {
        return $this->freshInstances()->contains(
            fn (WorkerHeartbeat $heartbeat): bool => (string) data_get($heartbeat->meta, 'queue') === $queue,
        );
    }

    public function expirationCode(ArticleAiQualityCheck $check): string
    {
        $consumerAvailable = $this->hasFreshConsumer($this->queueForCheck($check));
        if ((string) $check->status === 'queued') {
            return $consumerAvailable ? 'queue_wait_timeout' : 'queue_worker_unavailable';
        }

        return $consumerAvailable ? 'inspection_deadline_exceeded' : 'worker_interrupted';
    }

    public function queueForCheck(ArticleAiQualityCheck $check): string
    {
        $trigger = (string) data_get($check->execution_meta, 'trigger');

        return in_array($trigger, ['reconcile', 'backfill'], true)
            ? $this->backfillQueue()
            : $this->frontQueue();
    }

    public function serviceStatus(): string
    {
        $counts = $this->freshCounts();
        if ($counts['front'] === 0) {
            return 'unavailable';
        }
        try {
            if ($this->lastProbePassed() === false) {
                return 'unavailable';
            }
        } catch (Throwable) {
            // Heartbeat counts still provide a safe degraded view when cache is unavailable.
        }
        if ($counts['front'] < $this->expectedFrontWorkers()
            || $counts['backfill'] < $this->expectedBackfillWorkers()) {
            return 'degraded';
        }

        return 'healthy';
    }

    public function lastProbePassed(): ?bool
    {
        try {
            $probe = Cache::get(self::PROBE_STATE_CACHE_KEY);

            return is_array($probe) && is_bool($probe['passed'] ?? null)
                ? $probe['passed']
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function pruneStale(): int
    {
        try {
            if (! Schema::hasTable('worker_heartbeats')) {
                return 0;
            }

            $cutoff = now()->subSeconds(max(3600, $this->staleSeconds() * 10));

            return WorkerHeartbeat::query()
                ->where('worker_id', 'like', 'aiq:%')
                ->where('last_seen_at', '<', $cutoff)
                ->delete();
        } catch (Throwable) {
            return 0;
        }
    }

    /** @return Collection<int, WorkerHeartbeat> */
    private function freshInstances(): Collection
    {
        try {
            if (! Schema::hasTable('worker_heartbeats')) {
                return collect();
            }

            return WorkerHeartbeat::query()
                ->where('worker_id', 'like', 'aiq:%')
                ->where('last_seen_at', '>=', now()->subSeconds($this->staleSeconds()))
                ->get()
                ->filter(fn (WorkerHeartbeat $heartbeat): bool => data_get($heartbeat->meta, 'kind') === 'article_ai_quality_queue')
                ->values();
        } catch (Throwable) {
            return collect();
        }
    }

    /** @return list<string> */
    private function qualityQueues(string $queues): array
    {
        $configured = [$this->frontQueue(), $this->backfillQueue()];

        return array_values(array_unique(array_filter(
            array_map('trim', explode(',', $queues)),
            static fn (string $queue): bool => in_array($queue, $configured, true),
        )));
    }

    private function workerId(string $connection, string $queue): string
    {
        $host = gethostname() ?: 'unknown';

        return sprintf(
            'aiq:%s:%s:%d',
            substr(hash('sha256', $connection.':'.$queue), 0, 12),
            substr(hash('sha256', $host), 0, 16),
            getmypid(),
        );
    }

    private function frontQueue(): string
    {
        return (string) config('geoflow.ai_quality_queue', 'ai-quality');
    }

    private function backfillQueue(): string
    {
        return (string) config('geoflow.ai_quality_backfill_queue', 'ai-quality-backfill');
    }

    private function expectedFrontWorkers(): int
    {
        return max(1, (int) config('geoflow.ai_quality_front_workers', 2));
    }

    private function expectedBackfillWorkers(): int
    {
        return max(1, (int) config('geoflow.ai_quality_backfill_workers', 1));
    }

    private function heartbeatInterval(): int
    {
        return max(1, (int) config('geoflow.ai_quality_worker_heartbeat_seconds', 10));
    }

    private function staleSeconds(): int
    {
        return max(30, (int) config('geoflow.ai_quality_worker_stale_seconds', 90));
    }
}
