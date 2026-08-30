<?php

namespace App\Services\GeoFlow;

use App\Events\ArticleAiQualityHealthChanged;
use App\Jobs\ArticleAiQualityProbeJob;
use App\Jobs\ProcessArticleAiQualityJob;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiQualityCheck;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class ArticleAiQualityHealthService
{
    public function __construct(
        private readonly ArticleAiQualityWorkerLiveness $liveness,
        private readonly ArticleAiQualityVersionPolicy $versionPolicy,
    ) {}

    /** @return array<string, mixed> */
    public function snapshot(bool $recordTransition = false): array
    {
        $this->liveness->pruneStale();
        $frontQueue = (string) config('geoflow.ai_quality_queue', 'ai-quality');
        $backfillQueue = (string) config('geoflow.ai_quality_backfill_queue', 'ai-quality-backfill');
        $optimizationQueue = (string) config('geoflow.ai_quality_optimization_queue', 'ai-content-optimization');
        $optimizationBulkQueue = (string) config('geoflow.ai_quality_optimization_bulk_queue', 'ai-content-optimization-bulk');
        $counts = $this->liveness->freshCounts();
        $expected = [
            'front' => max(1, (int) config('geoflow.ai_quality_front_workers', 2)),
            'backfill' => max(1, (int) config('geoflow.ai_quality_backfill_workers', 1)),
        ];
        $timeouts = [
            'business' => (int) config('geoflow.ai_quality_deadline_seconds', 180)
                + (int) config('geoflow.ai_quality_sampled_fallback_seconds', 45)
                + (int) config('geoflow.ai_quality_persistence_reserve_seconds', 10),
            'job' => (new ProcessArticleAiQualityJob(0))->timeout,
            'worker' => (int) config('geoflow.ai_quality_worker_timeout_seconds', 250),
            'retry_after' => (int) config('queue.connections.redis.retry_after', 960),
        ];
        $oldestActive = ArticleAiQualityCheck::query()
            ->where('status', 'queued')
            ->oldest('created_at')
            ->limit(200)
            ->get(['created_at', 'execution_meta'])
            ->first(fn (ArticleAiQualityCheck $check): bool => ! in_array(
                (string) data_get($check->execution_meta, 'trigger'),
                ['reconcile', 'backfill'],
                true,
            ));
        $oldestQueueWaitMs = $oldestActive?->created_at
            ? max(0, (int) round($oldestActive->created_at->diffInMilliseconds(now())))
            : 0;
        $expiredActive = ArticleAiQualityCheck::query()
            ->whereIn('status', ['queued', 'running'])
            ->where(function ($query): void {
                $query->where(function ($sampled): void {
                    $sampled->where('inspection_scope', 'fallback_sampled')
                        ->where(function ($deadline): void {
                            $deadline->where('sampled_deadline_at', '<=', now())
                                ->orWhere(function ($legacy): void {
                                    $legacy->whereNull('sampled_deadline_at')->where('deadline_at', '<=', now());
                                });
                        });
                })
                    ->orWhere(function ($full): void {
                        $full->where('inspection_scope', 'full')->where('deadline_at', '<=', now());
                    })
                    ->orWhere(function ($legacy): void {
                        $legacy->whereNull('deadline_at')
                            ->where('created_at', '<=', now()->subSeconds((int) config('geoflow.ai_quality_deadline_seconds', 180)));
                    });
            })
            ->count();
        $rollout = $this->versionPolicy->rolloutState();
        $qualityMetrics = $this->qualityMetrics();
        $optimizationMetrics = $this->optimizationMetrics();

        $issues = [];
        $redisAvailable = $this->redisAvailable();
        if (! $redisAvailable) {
            $issues[] = 'redis_unavailable';
        }
        if ($frontQueue === '' || $backfillQueue === '' || $frontQueue === $backfillQueue) {
            $issues[] = 'queue_configuration_conflict';
        }
        if ($optimizationQueue === '' || $optimizationBulkQueue === '' || $optimizationQueue === $optimizationBulkQueue) {
            $issues[] = 'optimization_queue_configuration_conflict';
        }
        if (! ($timeouts['business'] < $timeouts['job']
            && $timeouts['job'] < $timeouts['worker']
            && $timeouts['worker'] < $timeouts['retry_after'])) {
            $issues[] = 'timeout_configuration_conflict';
        }
        if ($counts['front'] === 0) {
            $issues[] = 'front_consumer_missing';
        } elseif ($counts['front'] < $expected['front']) {
            $issues[] = 'front_consumer_under_capacity';
        }
        if ($counts['backfill'] < $expected['backfill']) {
            $issues[] = 'backfill_consumer_missing';
        }
        if ($this->liveness->lastProbePassed() === false) {
            $issues[] = 'front_probe_failed';
        }
        if ($oldestQueueWaitMs > 5000) {
            $issues[] = 'front_queue_wait_high';
        }
        if ($expiredActive > 0) {
            $issues[] = 'expired_active_checks';
        }
        if ((int) ($optimizationMetrics['expired_deadlines'] ?? 0) > 0) {
            $issues[] = 'expired_active_optimizations';
        }
        if ((int) ($optimizationMetrics['expired_leases'] ?? 0) > 0) {
            $issues[] = 'expired_optimization_leases';
        }
        if ((int) data_get($qualityMetrics, 'all.workflow_exhausted', 0) > 0) {
            $issues[] = 'workflow_apply_exhausted';
        }
        if (trim((string) ($rollout['incident_code'] ?? '')) !== '') {
            $issues[] = 'rollout_incident_active';
        } elseif ((bool) ($rollout['frozen'] ?? false)) {
            $issues[] = 'rollout_frozen';
        }

        $status = match (true) {
            ! $redisAvailable,
            in_array('queue_configuration_conflict', $issues, true),
            in_array('optimization_queue_configuration_conflict', $issues, true),
            in_array('timeout_configuration_conflict', $issues, true),
            in_array('front_probe_failed', $issues, true),
            in_array('rollout_incident_active', $issues, true),
            $counts['front'] === 0 => 'unavailable',
            $issues !== [] => 'degraded',
            default => 'healthy',
        };
        $snapshot = [
            'status' => $status,
            'connection' => 'redis',
            'queues' => [
                'front' => $frontQueue,
                'backfill' => $backfillQueue,
                'optimization' => $optimizationQueue,
                'optimization_bulk' => $optimizationBulkQueue,
            ],
            'workers' => $counts,
            'expected_workers' => $expected,
            'timeouts' => $timeouts,
            'oldest_queue_wait_ms' => $oldestQueueWaitMs,
            'expired_active_checks' => $expiredActive,
            'quality_metrics_24h' => $qualityMetrics,
            'optimization_metrics' => $optimizationMetrics,
            'rollout' => $rollout,
            'issues' => array_values(array_unique($issues)),
            'checked_at' => now()->toIso8601String(),
        ];

        if ($recordTransition) {
            $this->recordTransition($snapshot);
        }

        return $snapshot;
    }

    /** @return array<string,mixed> */
    private function qualityMetrics(): array
    {
        $base = ArticleAiQualityCheck::query()
            ->where('gate_applied', true)
            ->where('created_at', '>=', now()->subDay());

        return [
            'sampled_auto_release_enabled' => $this->versionPolicy->sampledAutoReleaseEnabled(),
            'all' => $this->scopeMetrics(clone $base),
            'full' => $this->scopeMetrics((clone $base)->where('inspection_scope', 'full')),
            'fallback_sampled' => $this->scopeMetrics((clone $base)->where('inspection_scope', 'fallback_sampled')),
        ];
    }

    /** @return array<string,int> */
    private function optimizationMetrics(): array
    {
        if (! Schema::hasTable('article_ai_optimization_runs')) {
            return [
                'active' => 0,
                'expired_deadlines' => 0,
                'expired_leases' => 0,
                'needs_review_24h' => 0,
                'failed_24h' => 0,
            ];
        }
        $active = ArticleAiOptimizationRun::query()
            ->whereIn('status', ArticleAiOptimizationRun::ACTIVE_STATUSES);

        return [
            'active' => (clone $active)->count(),
            'expired_deadlines' => (clone $active)
                ->where('status', '!=', ArticleAiOptimizationRun::STATUS_CANDIDATE_READY)
                ->where('deadline_at', '<=', now())
                ->count(),
            'expired_leases' => (clone $active)->whereNotNull('lease_owner')->where('lease_expires_at', '<=', now())->count(),
            'needs_review_24h' => ArticleAiOptimizationRun::query()
                ->where('status', ArticleAiOptimizationRun::STATUS_NEEDS_REVIEW)
                ->where('updated_at', '>=', now()->subDay())
                ->count(),
            'failed_24h' => ArticleAiOptimizationRun::query()
                ->where('status', ArticleAiOptimizationRun::STATUS_FAILED)
                ->where('updated_at', '>=', now()->subDay())
                ->count(),
        ];
    }

    /** @return array<string,int|float> */
    private function scopeMetrics(Builder $query): array
    {
        $workflowExhausted = (clone $query)
            ->where('execution_meta->workflow_apply->status', 'exhausted')
            ->count();
        $workflowExhaustedPassed = (clone $query)
            ->where('status', 'completed')
            ->where('decision', 'passed')
            ->where('execution_meta->workflow_apply->status', 'exhausted')
            ->count();
        $row = $query->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status IN ('completed', 'failed', 'stale', 'cancelled') THEN 1 ELSE 0 END) as terminal")
            ->selectRaw("SUM(CASE WHEN finished_at IS NOT NULL AND finished_at <= CASE WHEN inspection_scope = 'fallback_sampled' THEN COALESCE(sampled_deadline_at, deadline_at) ELSE deadline_at END THEN 1 ELSE 0 END) as terminal_within_deadline")
            ->selectRaw("SUM(CASE WHEN status = 'completed' AND decision = 'passed' THEN 1 ELSE 0 END) as passed")
            ->selectRaw("SUM(CASE WHEN status = 'completed' AND decision = 'needs_review' THEN 1 ELSE 0 END) as needs_review")
            ->selectRaw("SUM(CASE WHEN status = 'completed' AND decision = 'blocked' THEN 1 ELSE 0 END) as blocked")
            ->selectRaw("SUM(CASE WHEN status = 'failed' OR decision = 'error' THEN 1 ELSE 0 END) as failed")
            ->first();
        $total = max(0, (int) ($row?->total ?? 0));
        $terminal = max(0, (int) ($row?->terminal ?? 0));

        return [
            'total' => $total,
            'terminal' => $terminal,
            'terminal_within_deadline' => max(0, (int) ($row?->terminal_within_deadline ?? 0)),
            'terminal_convergence_rate' => $total > 0 ? round($terminal / $total, 4) : 1.0,
            'passed' => max(0, (int) ($row?->passed ?? 0) - $workflowExhaustedPassed),
            'needs_review' => max(0, (int) ($row?->needs_review ?? 0)),
            'blocked' => max(0, (int) ($row?->blocked ?? 0)),
            'failed' => max(0, (int) ($row?->failed ?? 0)),
            'workflow_exhausted' => $workflowExhausted,
        ];
    }

    /** @return array{passed: bool, queue: string, error: ?string} */
    public function probe(int $waitSeconds = 10): array
    {
        $queue = (string) config('geoflow.ai_quality_queue', 'ai-quality');
        $token = (string) Str::uuid();
        $key = ArticleAiQualityProbeJob::cacheKey($token);
        Cache::forget($key);

        try {
            ArticleAiQualityProbeJob::dispatch($token, $queue)
                ->onConnection('redis')
                ->onQueue($queue);
        } catch (Throwable $exception) {
            return $this->finishProbe(false, $queue, 'probe_dispatch_failed');
        }

        $deadline = microtime(true) + max(1, min(30, $waitSeconds));
        do {
            $acknowledgement = Cache::get($key);
            if (is_array($acknowledgement)) {
                Cache::forget($key);
                $passed = ($acknowledgement['queue'] ?? null) === $queue
                    && ($acknowledgement['connection'] ?? null) === 'redis';

                return $this->finishProbe($passed, $queue, $passed ? null : 'probe_route_mismatch');
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);

        return $this->finishProbe(false, $queue, 'probe_timeout');
    }

    /** @return array{passed: bool, queue: string, error: ?string} */
    private function finishProbe(bool $passed, string $queue, ?string $error): array
    {
        try {
            Cache::put(ArticleAiQualityWorkerLiveness::PROBE_STATE_CACHE_KEY, [
                'passed' => $passed,
                'checked_at' => now()->toIso8601String(),
            ], now()->addMinutes(2));
        } catch (Throwable) {
            // The command result remains authoritative when shared cache is unavailable.
        }

        return ['passed' => $passed, 'queue' => $queue, 'error' => $error];
    }

    protected function redisAvailable(): bool
    {
        try {
            $response = Redis::connection((string) config('queue.connections.redis.connection', 'default'))
                ->command('ping');

            return $response !== false && $response !== null;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $snapshot */
    private function recordTransition(array $snapshot): void
    {
        try {
            $cacheKey = 'geoflow:ai-quality:health:last-status';
            $previous = Cache::get($cacheKey);
            if ($previous === $snapshot['status']) {
                return;
            }

            Cache::put($cacheKey, $snapshot['status'], now()->addDays(7));
            Event::dispatch(new ArticleAiQualityHealthChanged($snapshot));
            Log::log($snapshot['status'] === 'healthy' ? 'info' : 'warning', 'ai_quality_health_changed', [
                'previous_status' => $previous,
                'status' => $snapshot['status'],
                'workers' => $snapshot['workers'],
                'oldest_queue_wait_ms' => $snapshot['oldest_queue_wait_ms'],
                'expired_active_checks' => $snapshot['expired_active_checks'],
                'issues' => $snapshot['issues'],
            ]);
        } catch (Throwable) {
            // Health reporting must not interrupt queue processing or scheduling.
        }
    }
}
