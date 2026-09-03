<?php

namespace App\Services\AiWorkspace;

use App\Data\AiWorkspace\AiWorkspaceModelProbeAttempt;
use App\Data\AiWorkspace\AiWorkspaceModelProbeResult;
use App\Models\AiModel;
use App\Services\Admin\GovernanceAiModelUsageSession;
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

    public function start(AiModel $model, ?GovernanceAiModelUsageSession $usageSession = null): AiWorkspaceModelProbeAttempt
    {
        $checkedAt = CarbonImmutable::now();
        $deadline = microtime(true) + (int) config('ai-workspace.model_total_timeout_seconds', 90);
        try {
            $result = $this->runtime->probeStreaming(
                $model,
                '请用一句话确认 GEOFlow 后台帮助助手流式回答可用。',
                $this->remainingTimeout($deadline),
                $usageSession,
            );
            $streaming = [
                'status' => 'ready',
                'observed' => true,
                'delta_count' => (int) $result['delta_count'],
            ];
        } catch (Throwable $exception) {
            if ($usageSession instanceof GovernanceAiModelUsageSession
                && ! $usageSession->hasStartedProviderAttempt()
            ) {
                throw $exception;
            }

            return new AiWorkspaceModelProbeAttempt(
                checkedAt: $checkedAt,
                deadline: $deadline,
                providerResult: null,
                streamingProfile: [
                    'status' => 'degraded',
                    'observed' => true,
                    'fallback' => 'non_streaming',
                    'failure_code' => $this->failureCode($exception),
                ],
                streamingFailure: $exception,
            );
        }

        return new AiWorkspaceModelProbeAttempt(
            checkedAt: $checkedAt,
            deadline: $deadline,
            providerResult: $result,
            streamingProfile: $streaming,
            streamingFailure: null,
        );
    }

    public function finish(
        AiModel $model,
        AiWorkspaceModelProbeAttempt $attempt,
        ?GovernanceAiModelUsageSession $usageSession = null,
    ): AiWorkspaceModelProbeResult {
        $result = $attempt->providerResult;
        if ($attempt->requiresPlainTextFallback()) {
            $result = $this->runtime->probePlainText(
                $model,
                '请用一句话确认 GEOFlow 后台帮助助手普通文本回答可用。',
                $this->remainingTimeout($attempt->deadline),
                $usageSession,
            );
        }
        if (! is_array($result)) {
            throw new RuntimeException('AI 工作台模型连接检测未返回可用结果。');
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
            'streaming' => $attempt->streamingProfile,
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
                'streaming_probe_failed' => $attempt->streamingFailure instanceof Throwable ? true : null,
            ], static fn (mixed $value): bool => $value !== null),
            'provider' => (string) $result['provider'],
            'model' => (string) $model->model_id,
            'endpoint_digest' => hash('sha256', OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url)),
        ];

        return new AiWorkspaceModelProbeResult(
            providerResult: $result,
            profile: $profile,
            checkedAt: $attempt->checkedAt,
            expiresAt: $attempt->checkedAt->addDays(7),
        );
    }

    /** @return array<string, mixed> */
    public function probe(AiModel $model): array
    {
        return $this->finish($model, $this->start($model))->responseData();
    }

    public function failureCode(Throwable $exception): string
    {
        $messages = [];
        for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }
        $message = mb_strtolower(implode(' ', $messages));

        return match (true) {
            str_contains($message, 'key'), str_contains($message, '鉴权'), str_contains($message, '401'), str_contains($message, '403') => 'authentication_failed',
            str_contains($message, 'capability'), str_contains($message, '能力'), str_contains($message, 'unsupported') => 'capability_incompatible',
            str_contains($message, 'configuration'), str_contains($message, '配置'), str_contains($message, '400'), str_contains($message, '402'), str_contains($message, '404'), str_contains($message, '422') => 'configuration_invalid',
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
