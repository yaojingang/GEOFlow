<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleAiQualityRuntimeException;
use App\Models\AiModel;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

final class ArticleAiQualityProviderCircuitBreaker
{
    public function beforeRequest(AiModel $model): void
    {
        $key = $this->key($model);
        $state = $this->state($key);
        $openUntil = (int) ($state['open_until'] ?? 0);
        if ($openUntil > time()) {
            throw new ArticleAiQualityRuntimeException(
                'provider_circuit_open',
                $this->allowsCandidateFailover($state),
            );
        }
        if ($openUntil <= 0) {
            return;
        }

        $probeKey = $key.':half-open-probe';
        if (! Cache::add($probeKey, true, $this->openSeconds())) {
            throw new ArticleAiQualityRuntimeException(
                'provider_circuit_open',
                $this->allowsCandidateFailover($state),
            );
        }
    }

    public function recordSuccess(AiModel $model): void
    {
        $key = $this->key($model);
        $this->mutate($key, function (array $state): array {
            $events = $this->events($state);
            $events[] = false;

            return [
                'consecutive_failures' => 0,
                'events' => array_slice($events, -1 * $this->sampleSize()),
                'open_until' => 0,
                'updated_at' => now()->toISOString(),
            ];
        });
        Cache::forget($key.':half-open-probe');
    }

    public function recordFailure(AiModel $model, ArticleAiQualityRuntimeException $exception): void
    {
        if (! str_starts_with($exception->safeCode(), 'provider_')
            && $exception->safeCode() !== 'structured_output_unsupported') {
            return;
        }

        $key = $this->key($model);
        $this->mutate($key, function (array $state) use ($exception): array {
            $events = $this->events($state);
            $events[] = true;
            $events = array_slice($events, -1 * $this->sampleSize());
            $consecutiveFailures = $exception->retryable()
                ? ((int) ($state['consecutive_failures'] ?? 0)) + 1
                : 0;
            $failurePercent = count($events) >= $this->sampleSize()
                ? (int) round((count(array_filter($events)) / count($events)) * 100)
                : 0;
            $open = $consecutiveFailures >= $this->consecutiveFailureThreshold()
                || $failurePercent >= $this->failurePercentThreshold();

            return [
                'consecutive_failures' => $consecutiveFailures,
                'events' => $events,
                'open_until' => $open ? time() + $this->openSeconds() : (int) ($state['open_until'] ?? 0),
                'last_error_code' => $exception->safeCode(),
                'updated_at' => now()->toISOString(),
            ];
        });
        Cache::forget($key.':half-open-probe');
    }

    public function isOpen(AiModel $model): bool
    {
        return (int) ($this->state($this->key($model))['open_until'] ?? 0) > time();
    }

    /** @return array<string,mixed> */
    private function state(string $key): array
    {
        $state = Cache::get($key);

        return is_array($state) ? $state : [];
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $callback */
    private function mutate(string $key, callable $callback): void
    {
        try {
            Cache::lock($key.':state-lock', 5)->block(1, function () use ($key, $callback): void {
                Cache::put($key, $callback($this->state($key)), max(300, $this->openSeconds() * 5));
            });
        } catch (LockTimeoutException) {
            // Circuit metrics are protective state; a short lock conflict must not fail the request itself.
        }
    }

    /** @param array<string,mixed> $state @return list<bool> */
    private function events(array $state): array
    {
        return array_values(array_map('boolval', is_array($state['events'] ?? null) ? $state['events'] : []));
    }

    private function key(AiModel $model): string
    {
        return 'geoflow:ai-quality:circuit:'.hash('sha256', implode("\0", [
            (string) $model->id,
            mb_strtolower(trim((string) $model->api_url)),
            trim((string) $model->model_id),
        ]));
    }

    private function consecutiveFailureThreshold(): int
    {
        return max(1, (int) config('geoflow.ai_quality_circuit_consecutive_failures', 5));
    }

    private function sampleSize(): int
    {
        return max(2, (int) config('geoflow.ai_quality_circuit_sample_size', 10));
    }

    private function failurePercentThreshold(): int
    {
        return max(1, min(100, (int) config('geoflow.ai_quality_circuit_failure_percent', 50)));
    }

    private function openSeconds(): int
    {
        return max(1, (int) config('geoflow.ai_quality_circuit_open_seconds', 60));
    }

    /** @param array<string,mixed> $state */
    private function allowsCandidateFailover(array $state): bool
    {
        return in_array((string) ($state['last_error_code'] ?? ''), [
            'provider_timeout',
            'provider_rate_limited',
            'provider_gateway_error',
            'provider_error',
        ], true);
    }
}
