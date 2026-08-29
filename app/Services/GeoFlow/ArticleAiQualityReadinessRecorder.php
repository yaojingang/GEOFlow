<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ArticleAiQualityReadinessRecorder
{
    public function __construct(private AiWorkspaceModelReadiness $readiness) {}

    public function prefersJson(AiModel $model): bool
    {
        $profile = data_get($model->ai_workspace_readiness_profile, 'article_quality_structured_output');
        if (! is_array($profile)
            || ! in_array((string) ($profile['status'] ?? ''), ['degraded', 'unsupported'], true)
            || ($profile['observed'] ?? false) !== true) {
            return false;
        }
        $structuredSamples = is_array($profile['_structured_samples'] ?? null)
            ? $profile['_structured_samples']
            : (is_array($profile['_samples'] ?? null) ? $profile['_samples'] : []);
        $lastStructuredSample = collect($structuredSamples)
            ->reverse()
            ->first(static fn (array $sample): bool => ($sample['mode'] ?? '') === 'structured');
        $lastStructuredAt = $profile['last_structured_attempt_at']
            ?? (is_array($lastStructuredSample) ? ($lastStructuredSample['at'] ?? null) : null);
        if (! is_string($lastStructuredAt)) {
            return false;
        }
        try {
            if ((now()->getTimestamp() - Carbon::parse($lastStructuredAt)->getTimestamp()) >= (int) config('geoflow.ai_quality_structured_reprobe_seconds', 86400)) {
                return false;
            }
        } catch (Throwable) {
            return false;
        }
        $fingerprint = trim((string) ($profile['configuration_fingerprint'] ?? ''));

        return $fingerprint !== '' && hash_equals($fingerprint, $this->readiness->configurationFingerprint($model));
    }

    public function recordAttempt(
        AiModel $model,
        string $mode,
        bool $schemaPassed,
        int $latencyMs,
        ?string $errorCode,
    ): void {
        DB::transaction(function () use ($model, $mode, $schemaPassed, $latencyMs, $errorCode): void {
            $current = AiModel::query()->whereKey((int) $model->id)->lockForUpdate()->first();
            if (! $current instanceof AiModel
                || ! hash_equals(
                    $this->readiness->configurationFingerprint($model),
                    $this->readiness->configurationFingerprint($current),
                )) {
                return;
            }

            $profile = is_array($current->ai_workspace_readiness_profile)
                ? $current->ai_workspace_readiness_profile
                : ['version' => AiWorkspaceModelReadiness::PROFILE_VERSION];
            $quality = is_array($profile['article_quality_structured_output'] ?? null)
                ? $profile['article_quality_structured_output']
                : [];
            $samples = is_array($quality['_samples'] ?? null) ? $quality['_samples'] : [];
            $structuredSamples = is_array($quality['_structured_samples'] ?? null)
                ? $quality['_structured_samples']
                : array_values(array_filter(
                    $samples,
                    static fn (array $sample): bool => ($sample['mode'] ?? '') === 'structured',
                ));
            $attemptedAt = now()->toIso8601String();
            $sample = [
                'mode' => $mode,
                'schema_passed' => $schemaPassed,
                'latency_ms' => max(0, $latencyMs),
                'error_code' => $errorCode,
                'at' => $attemptedAt,
            ];
            $samples[] = $sample;
            $samples = array_slice($samples, -20);
            if ($mode === 'structured') {
                $structuredSamples[] = $sample;
            }
            $structuredSamples = array_slice($structuredSamples, -20);
            $latencies = array_map(static fn (array $sample): int => (int) ($sample['latency_ms'] ?? 0), $samples);
            $structuredPassed = count(array_filter(
                $structuredSamples,
                static fn (array $sample): bool => ($sample['schema_passed'] ?? false) === true,
            ));
            $latestStructured = collect($samples)->reverse()->first(
                static fn (array $sample): bool => ($sample['mode'] ?? '') === 'structured',
            );
            $structuredSuccess = is_array($latestStructured)
                && ($latestStructured['schema_passed'] ?? false) === true;
            $structuredCapabilityFailure = is_array($latestStructured)
                && ($latestStructured['schema_passed'] ?? false) !== true
                && in_array((string) ($latestStructured['error_code'] ?? ''), [
                    'structured_output_unsupported', 'invalid_model_output',
                ], true);
            $previousStatus = in_array((string) ($quality['status'] ?? ''), ['ready', 'degraded', 'unsupported'], true)
                ? (string) $quality['status']
                : 'unknown';
            $status = $structuredSuccess
                ? 'ready'
                : ($structuredCapabilityFailure ? 'degraded' : $previousStatus);
            $lastSuccess = collect($samples)->reverse()->first(
                static fn (array $sample): bool => ($sample['schema_passed'] ?? false) === true,
            );
            $quality = [
                'status' => $status,
                'observed' => true,
                'probe_mode' => 'lazy_runtime',
                'schema_pass_rate' => round($structuredPassed / max(1, count($structuredSamples)), 4),
                'latency_ms' => [
                    'p50' => $this->percentile($latencies, 50),
                    'p95' => $this->percentile($latencies, 95),
                ],
                'recent_error_rate' => round(
                    (count($structuredSamples) - $structuredPassed) / max(1, count($structuredSamples)),
                    4,
                ),
                'last_success_at' => is_array($lastSuccess) ? ($lastSuccess['at'] ?? null) : null,
                'last_structured_attempt_at' => $mode === 'structured'
                    ? $attemptedAt
                    : ($quality['last_structured_attempt_at'] ?? ($latestStructured['at'] ?? null)),
                'last_error_code' => $errorCode ?: ($quality['last_error_code'] ?? null),
                'configuration_fingerprint' => $this->readiness->configurationFingerprint($current),
                '_samples' => $samples,
                '_structured_samples' => $structuredSamples,
            ];
            $profile['article_quality_structured_output'] = $quality;
            $current->forceFill(['ai_workspace_readiness_profile' => $profile])->save();
        });
    }

    /** @param list<int> $values */
    private function percentile(array $values, int $percentile): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values, SORT_NUMERIC);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return (int) $values[max(0, min(count($values) - 1, $index))];
    }
}
