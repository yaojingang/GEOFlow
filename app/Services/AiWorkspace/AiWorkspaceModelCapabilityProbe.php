<?php

namespace App\Services\AiWorkspace;

use App\Models\AiModel;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

final readonly class AiWorkspaceModelCapabilityProbe
{
    public const PROFILE_VERSION = AiWorkspaceModelReadiness::PROFILE_VERSION;

    public function __construct(
        private AiWorkspaceModelRuntime $runtime,
        private AiWorkspaceModelReadiness $readiness,
    ) {}

    /** @return array<string,mixed> */
    public function probe(AiModel $model): array
    {
        $checkedAt = CarbonImmutable::now();
        $deadline = microtime(true) + (int) config('ai-workspace.model_total_timeout_seconds', 90);
        $streamingFailure = null;
        try {
            $result = $this->runtime->probeStreaming(
                $model,
                '请用一句话确认 GEOFlow 后台帮助助手流式回答可用。',
                $this->remainingTimeout($deadline),
            );
            $streaming = [
                'status' => 'ready',
                'observed' => true,
                'delta_count' => (int) $result['delta_count'],
            ];
        } catch (Throwable $exception) {
            $streamingFailure = $exception;
            $result = $this->runtime->probePlainText(
                $model,
                '请用一句话确认 GEOFlow 后台帮助助手普通文本回答可用。',
                $this->remainingTimeout($deadline),
            );
            $streaming = [
                'status' => 'degraded',
                'observed' => true,
                'fallback' => 'non_streaming',
                'failure_code' => $this->failureCode($exception),
            ];
        }
        $profile = [
            'version' => self::PROFILE_VERSION,
            'configuration' => [
                'status' => 'ready',
                'observed' => true,
                'fingerprint' => $this->readiness->configurationFingerprint($model),
            ],
            'authentication' => ['status' => 'ready', 'observed' => true],
            'plain_text' => ['status' => 'ready', 'observed' => true],
            'streaming' => $streaming,
            'structured_output' => ['status' => 'not_required', 'observed' => false],
            'article_quality_structured_output' => [
                'status' => 'unknown',
                'observed' => false,
                'probe_mode' => 'lazy_runtime',
                'schema_pass_rate' => null,
                'latency_ms' => ['p50' => null, 'p95' => null],
                'recent_error_rate' => null,
                'last_success_at' => null,
                'configuration_fingerprint' => $this->readiness->configurationFingerprint($model),
            ],
            'knowledge_fact_structured_output' => data_get(
                $model->ai_workspace_readiness_profile,
                'knowledge_fact_structured_output',
                [
                    'status' => 'unknown',
                    'observed' => false,
                    'probe_mode' => 'lazy_runtime',
                    'configuration_fingerprint' => $this->readiness->configurationFingerprint($model),
                ],
            ),
            'tool_schema' => ['status' => 'not_required', 'observed' => false, 'business_tools_enabled' => false],
            'tool_roundtrip' => ['status' => 'not_required', 'observed' => false, 'business_tools_enabled' => false],
            'cancellation' => ['status' => 'guarded', 'observed' => false],
            'performance' => array_filter([
                'status' => 'ready',
                'latency_ms' => (int) $result['latency_ms'],
                'streaming_probe_failed' => $streamingFailure instanceof Throwable ? true : null,
            ], static fn (mixed $value): bool => $value !== null),
            'provider' => (string) $result['provider'],
            'model' => (string) $model->model_id,
            'endpoint_digest' => hash('sha256', OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url)),
        ];
        $expiresAt = $checkedAt->addDays(7);

        $model->forceFill([
            'ai_workspace_structured_output_status' => null,
            'ai_workspace_structured_output_verified_at' => null,
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => $profile,
            'ai_workspace_readiness_checked_at' => $checkedAt,
            'ai_workspace_readiness_expires_at' => $expiresAt,
            'ai_workspace_readiness_failure_code' => null,
        ])->save();

        return $result + ['readiness_status' => 'ready', 'profile' => $profile, 'expires_at' => $expiresAt->toISOString()];
    }

    public function recordFailure(AiModel $model, Throwable $exception): void
    {
        $model->forceFill([
            'ai_workspace_structured_output_status' => null,
            'ai_workspace_structured_output_verified_at' => null,
            'ai_workspace_readiness_status' => 'failed',
            'ai_workspace_readiness_profile' => null,
            'ai_workspace_readiness_checked_at' => now(),
            'ai_workspace_readiness_expires_at' => null,
            'ai_workspace_readiness_failure_code' => $this->failureCode($exception),
        ])->save();
    }

    private function failureCode(Throwable $exception): string
    {
        $message = mb_strtolower($exception->getMessage());

        return match (true) {
            str_contains($message, 'key'), str_contains($message, '鉴权'), str_contains($message, '401'), str_contains($message, '403') => 'authentication_failed',
            str_contains($message, '内容'), str_contains($message, '文本') => 'plain_text_invalid',
            str_contains($message, 'timeout'), str_contains($message, '超时') => 'provider_timeout',
            default => 'provider_unavailable',
        };
    }

    private function remainingTimeout(float $deadline): int
    {
        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining < 1) {
            throw new RuntimeException('AI 工作台模型连接检测已达到共享时间预算。');
        }

        return min(
            (int) config('ai-workspace.model_attempt_timeout_seconds', 30),
            $remaining,
        );
    }
}
