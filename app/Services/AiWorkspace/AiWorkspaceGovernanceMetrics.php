<?php

namespace App\Services\AiWorkspace;

use App\Models\AiWorkspaceRun;
use App\Models\AiWorkspaceTraceEvent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class AiWorkspaceGovernanceMetrics
{
    /** @return array<string,mixed> */
    public function snapshot(int $days = 7): array
    {
        $days = min(90, max(1, $days));
        $from = now()->subDays($days);
        $base = AiWorkspaceRun::query()->where('created_at', '>=', $from);
        $total = (clone $base)->count();
        $completed = (clone $base)->where('state', 'completed')->count();
        $partial = (clone $base)->where('state', 'partially_completed')->count();
        $failed = (clone $base)->where('state', 'failed')->count();
        $unknown = (clone $base)->where('state', 'outcome_unknown')->count();
        $terminal = (clone $base)->whereIn('state', AiWorkspaceRun::TERMINAL_STATES)->count();
        $runs = (clone $base)->latest('created_at')->limit(5000)->get([
            'id', 'created_at', 'queued_at', 'resolution_started_at', 'resolution_finished_at',
            'first_token_at', 'finished_at', 'last_event_at', 'usage',
        ]);
        $runIds = $runs->pluck('id');
        $eventCounts = $runIds->isEmpty()
            ? collect()
            : AiWorkspaceTraceEvent::query()
                ->whereIn('run_id', $runIds)
                ->whereNotNull('event_type')
                ->selectRaw('event_type, COUNT(*) AS aggregate')
                ->groupBy('event_type')
                ->pluck('aggregate', 'event_type')
                ->map(static fn ($count): int => (int) $count);
        $queueDurations = $runs->map(fn (AiWorkspaceRun $run): ?int => $this->durationMs(
            $run->queued_at ?? $run->created_at,
            $run->resolution_started_at,
        ))->filter(static fn (?int $duration): bool => $duration !== null)->values();
        $ttftDurations = $runs->map(fn (AiWorkspaceRun $run): ?int => $this->durationMs(
            $run->resolution_started_at,
            $run->first_token_at,
        ))->filter(static fn (?int $duration): bool => $duration !== null)->values();
        $totalDurations = $runs->map(fn (AiWorkspaceRun $run): ?int => $this->durationMs(
            $run->created_at,
            $run->finished_at ?? $run->last_event_at,
        ))->filter(static fn (?int $duration): bool => $duration !== null)->values();
        $firstEventDurations = $runs->map(fn (AiWorkspaceRun $run): ?int => $this->durationMs(
            $run->created_at,
            $run->first_token_at ?? $run->resolution_started_at ?? $run->last_event_at,
        ))->filter(static fn (?int $duration): bool => $duration !== null)->values();
        $usage = $runs->reduce(function (array $totals, AiWorkspaceRun $run): array {
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens', 'cache_read_tokens', 'cache_write_tokens', 'model_calls'] as $key) {
                $totals[$key] = (int) ($totals[$key] ?? 0) + (int) data_get($run->usage, $key, 0);
            }

            return $totals;
        }, []);

        return [
            'window_days' => $days,
            'generated_at' => Carbon::now()->toISOString(),
            'runs' => [
                'total' => $total,
                'terminal' => $terminal,
                'completed' => $completed,
                'partially_completed' => $partial,
                'failed' => $failed,
                'outcome_unknown' => $unknown,
                'success_rate' => $terminal > 0 ? round(($completed / $terminal) * 100, 2) : null,
            ],
            'failure_codes' => (clone $base)
                ->whereNotNull('failure_code')
                ->selectRaw('failure_code, COUNT(*) AS aggregate')
                ->groupBy('failure_code')
                ->orderByDesc('aggregate')
                ->pluck('aggregate', 'failure_code')
                ->map(static fn ($count): int => (int) $count)
                ->all(),
            'performance_ms' => [
                'queue_wait' => $this->distribution($queueDurations),
                'queue_wait_ms' => $this->distribution($queueDurations),
                'first_event_ms' => $this->distribution($firstEventDurations),
                'ttft' => $this->distribution($ttftDurations),
                'total' => $this->distribution($totalDurations),
                'first_render_ms' => [
                    'native' => $this->clientDistribution('native', 'first_render_ms', $days),
                ],
            ],
            'surfaces' => [
                'native' => ['reconnect_count' => $this->clientSum('native', 'reconnect_count', $days)],
            ],
            'usage' => $usage,
            'events' => [
                'sampled_runs' => $runs->count(),
                'total' => $eventCounts->sum(),
                'per_run' => $runs->isNotEmpty() ? round($eventCounts->sum() / $runs->count(), 2) : null,
                'model_failed' => (int) $eventCounts->get('model.failed', 0),
                'authorization_revoked' => (int) $eventCounts->get('authorization.revoked', 0),
                'runtime_disabled' => (int) $eventCounts->get('runtime.disabled', 0),
                'outcome_unknown' => (int) $eventCounts->get('external.outcome_unknown', 0),
            ],
            'realtime_failures' => [
                'snapshot_broadcast' => $this->realtimeFailures('snapshot_broadcast', $days),
                'answer_delta_circuit' => $this->realtimeFailures('answer_delta_circuit', $days),
            ],
            'states' => (clone $base)
                ->selectRaw('state, COUNT(*) AS aggregate')
                ->groupBy('state')
                ->pluck('aggregate', 'state')
                ->map(static fn ($count): int => (int) $count)
                ->all(),
        ];
    }

    /** @param array<string,mixed> $metric */
    public function recordClientMetric(array $metric): void
    {
        $surface = (string) ($metric['surface'] ?? 'native');
        if ($surface !== 'native') {
            return;
        }
        $key = 'ai-workspace:client-metrics:'.$surface.':'.now()->toDateString();
        try {
            Cache::lock($key.':lock', 3)->block(1, function () use ($key, $metric): void {
                $samples = (array) Cache::get($key, []);
                $samples[] = collect($metric)->only(['first_render_ms', 'reconnect_count'])->all();
                Cache::put($key, array_slice($samples, -5000), now()->addDays(100));
            });
        } catch (Throwable) {
            // Client telemetry must never interrupt a workspace interaction.
        }
    }

    /** @param Collection<int,int> $durations @return array{count:int,average:int|null,p95:int|null} */
    private function distribution(Collection $durations): array
    {
        if ($durations->isEmpty()) {
            return ['count' => 0, 'average' => null, 'p95' => null];
        }
        $sorted = $durations->sort()->values();
        $index = max(0, (int) ceil($sorted->count() * 0.95) - 1);

        return [
            'count' => $sorted->count(),
            'average' => (int) round($sorted->average()),
            'p95' => (int) $sorted->get($index),
        ];
    }

    private function durationMs(?CarbonInterface $start, ?CarbonInterface $finish): ?int
    {
        if (! $start instanceof CarbonInterface || ! $finish instanceof CarbonInterface || $finish->lessThan($start)) {
            return null;
        }

        return (int) round($start->diffInMilliseconds($finish));
    }

    private function realtimeFailures(string $type, int $days): int
    {
        return collect(range(0, max(0, $days - 1)))->sum(
            static fn (int $offset): int => (int) Cache::get(
                'ai-workspace:realtime-failure:'.$type.':'.now()->subDays($offset)->toDateString(),
                0,
            ),
        );
    }

    /** @return Collection<int,int> */
    private function clientValues(string $surface, string $field, int $days): Collection
    {
        return collect(range(0, max(0, $days - 1)))
            ->flatMap(static fn (int $offset): array => (array) Cache::get(
                'ai-workspace:client-metrics:'.$surface.':'.now()->subDays($offset)->toDateString(),
                [],
            ))
            ->pluck($field)
            ->filter(static fn (mixed $value): bool => is_int($value) || ctype_digit((string) $value))
            ->map(static fn (mixed $value): int => (int) $value)
            ->values();
    }

    /** @return array{count:int,average:int|null,p95:int|null} */
    private function clientDistribution(string $surface, string $field, int $days): array
    {
        return $this->distribution($this->clientValues($surface, $field, $days));
    }

    private function clientSum(string $surface, string $field, int $days): int
    {
        return $this->clientValues($surface, $field, $days)->sum();
    }
}
