<?php

namespace App\Services\AiWorkspace;

use App\Data\Ai\AiWorkspaceExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\Schema;

final readonly class AiWorkspaceModelReadiness
{
    public const PROFILE_VERSION = 2;

    public function __construct(private AiWorkspaceExecutionAccessGuard $executionGuard) {}

    /** @return array{ready:bool,reason:string|null,model_id:int|null} */
    public function status(Admin|AiWorkspaceExecutionContext|int|null $actor = null): array
    {
        $conversationConnection = config('ai.conversations.connection');
        if (is_string($conversationConnection)
            && $conversationConnection !== ''
            && $conversationConnection !== (string) config('database.default')) {
            return ['ready' => false, 'reason' => __('admin.ai_workspace.readiness_database_mismatch'), 'model_id' => null];
        }

        if (! Schema::hasTable('ai_models')) {
            return ['ready' => false, 'reason' => __('admin.ai_workspace.readiness_models_table_missing'), 'model_id' => null];
        }

        try {
            $context = $this->context($actor);
            if (! $context instanceof AiWorkspaceExecutionContext) {
                return ['ready' => false, 'reason' => __('admin.ai_workspace.readiness_no_verified_model'), 'model_id' => null];
            }
            $models = $this->executionGuard->resolveCandidates($context);
        } catch (AiModelAccessException) {
            return ['ready' => false, 'reason' => __('admin.ai_workspace.readiness_no_verified_model'), 'model_id' => null];
        }
        $model = null;
        foreach ($models as $candidate) {
            if ($this->hasPermanentFailure($candidate)) {
                return ['ready' => false, 'reason' => __('admin.ai_workspace.readiness_no_verified_model'), 'model_id' => null];
            }
            if ($this->canAttempt($candidate)) {
                $model = $candidate;

                break;
            }
        }

        return $model instanceof AiModel
            ? ['ready' => true, 'reason' => null, 'model_id' => (int) $model->id]
            : ['ready' => false, 'reason' => __('admin.ai_workspace.readiness_no_verified_model'), 'model_id' => null];
    }

    private function context(Admin|AiWorkspaceExecutionContext|int|null $actor): ?AiWorkspaceExecutionContext
    {
        if ($actor instanceof AiWorkspaceExecutionContext) {
            return $actor;
        }
        if (is_int($actor)) {
            $actor = Admin::query()->find($actor);
        }

        return $actor instanceof Admin ? $this->executionGuard->directContext($actor) : null;
    }

    public function canAttempt(AiModel $model): bool
    {
        if (! (bool) config('ai-workspace.require_verified_model', true)) {
            return true;
        }

        if ($this->hasPlainTextReadiness($model)) {
            return true;
        }

        return false;
    }

    public function hasPermanentFailure(AiModel $model): bool
    {
        if (! (bool) config('ai-workspace.require_verified_model', true)
            || (string) $model->ai_workspace_readiness_status !== 'failed') {
            return false;
        }

        $fingerprint = trim((string) data_get($model->ai_workspace_readiness_profile, 'configuration.fingerprint'));
        if ($fingerprint !== '' && ! hash_equals($fingerprint, $this->configurationFingerprint($model))) {
            return false;
        }

        $code = mb_strtolower(trim((string) $model->ai_workspace_readiness_failure_code));
        if ($code === '') {
            return false;
        }

        return in_array($code, [
            'authentication_failed',
            'configuration_invalid',
            'ai_model_configuration_invalid',
            'plain_text_invalid',
            'capability_incompatible',
            'unsupported_capability',
            'parameter_invalid',
            'provider_http_400',
            'provider_http_401',
            'provider_http_402',
            'provider_http_403',
            'provider_http_404',
            'provider_http_422',
        ], true);
    }

    public function prefersPlainTextFallback(AiModel $model): bool
    {
        return data_get($model->ai_workspace_readiness_profile, 'streaming.status') === 'degraded'
            && data_get($model->ai_workspace_readiness_profile, 'streaming.observed') === true
            && data_get($model->ai_workspace_readiness_profile, 'streaming.fallback') === 'non_streaming';
    }

    /** @param array<string, int|null> $performance */
    public function recordRuntimeSuccess(AiModel $model, bool $streamingObserved, array $performance = []): void
    {
        if (! Schema::hasColumn('ai_models', 'ai_workspace_readiness_status')) {
            return;
        }

        $currentModel = AiModel::query()->find($model->getKey());
        if (! $currentModel instanceof AiModel
            || ! hash_equals($this->configurationFingerprint($model), $this->configurationFingerprint($currentModel))) {
            return;
        }

        $checkedAt = now();
        $currentModel->forceFill([
            'ai_workspace_structured_output_status' => null,
            'ai_workspace_structured_output_verified_at' => null,
            'ai_workspace_readiness_status' => 'ready',
            'ai_workspace_readiness_profile' => [
                'version' => self::PROFILE_VERSION,
                'configuration' => [
                    'status' => 'ready',
                    'observed' => true,
                    'fingerprint' => $this->configurationFingerprint($currentModel),
                ],
                'authentication' => ['status' => 'ready', 'observed' => true],
                'plain_text' => ['status' => 'ready', 'observed' => true],
                'streaming' => $streamingObserved
                    ? ['status' => 'ready', 'observed' => true]
                    : $this->streamingProfileAfterPlainTextSuccess($currentModel),
                'structured_output' => ['status' => 'not_required', 'observed' => false],
                'article_quality_structured_output' => data_get(
                    $currentModel->ai_workspace_readiness_profile,
                    'article_quality_structured_output',
                    [
                        'status' => 'unknown',
                        'observed' => false,
                        'probe_mode' => 'lazy_runtime',
                        'configuration_fingerprint' => $this->configurationFingerprint($currentModel),
                    ],
                ),
                'knowledge_fact_structured_output' => data_get(
                    $currentModel->ai_workspace_readiness_profile,
                    'knowledge_fact_structured_output',
                    [
                        'status' => 'unknown',
                        'observed' => false,
                        'probe_mode' => 'lazy_runtime',
                        'configuration_fingerprint' => $this->configurationFingerprint($currentModel),
                    ],
                ),
                'tool_schema' => ['status' => 'not_required', 'observed' => false, 'business_tools_enabled' => false],
                'tool_roundtrip' => ['status' => 'not_required', 'observed' => false, 'business_tools_enabled' => false],
                'cancellation' => ['status' => 'guarded', 'observed' => false],
                'performance' => array_filter([
                    'status' => 'ready',
                    'provider_first_event_ms' => $performance['provider_first_event_ms'] ?? null,
                    'ttft_ms' => $performance['ttft_ms'] ?? null,
                    'total_ms' => $performance['total_ms'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
                'model' => (string) $currentModel->model_id,
                'endpoint_digest' => $this->endpointDigest($currentModel),
            ],
            'ai_workspace_readiness_checked_at' => $checkedAt,
            'ai_workspace_readiness_expires_at' => $checkedAt->copy()->addDays(7),
            'ai_workspace_readiness_failure_code' => null,
        ])->save();
    }

    /** @return array<string, mixed> */
    private function streamingProfileAfterPlainTextSuccess(AiModel $model): array
    {
        if ($this->prefersPlainTextFallback($model)) {
            return array_filter([
                'status' => 'degraded',
                'observed' => true,
                'fallback' => 'non_streaming',
                'failure_code' => data_get($model->ai_workspace_readiness_profile, 'streaming.failure_code'),
            ], static fn (mixed $value): bool => $value !== null && $value !== '');
        }

        return ['status' => 'unknown', 'observed' => false];
    }

    private function hasPlainTextReadiness(AiModel $model): bool
    {
        if (Schema::hasColumn('ai_models', 'ai_workspace_readiness_status')) {
            return (string) $model->ai_workspace_readiness_status === 'ready'
                && $model->ai_workspace_readiness_expires_at?->isFuture()
                && data_get($model->ai_workspace_readiness_profile, 'plain_text.status') === 'ready'
                && $this->readinessMatchesCurrentConfiguration($model);
        }

        return false;
    }

    public function configurationFingerprint(AiModel $model): string
    {
        return hash('sha256', json_encode([
            'version' => trim((string) $model->version),
            'model_id' => trim((string) $model->model_id),
            'model_type' => trim((string) $model->model_type),
            'api_url' => OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url),
            'api_key' => (string) $model->getRawOriginal('api_key'),
            'status' => trim((string) $model->status),
            'max_tokens' => $model->max_tokens,
        ], JSON_THROW_ON_ERROR));
    }

    private function readinessMatchesCurrentConfiguration(AiModel $model): bool
    {
        $fingerprint = trim((string) data_get($model->ai_workspace_readiness_profile, 'configuration.fingerprint'));
        if ($fingerprint !== '') {
            return hash_equals($fingerprint, $this->configurationFingerprint($model));
        }

        $endpointDigest = trim((string) data_get($model->ai_workspace_readiness_profile, 'endpoint_digest'));

        return $endpointDigest !== '' && hash_equals($endpointDigest, $this->endpointDigest($model));
    }

    private function endpointDigest(AiModel $model): string
    {
        return hash('sha256', OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url));
    }
}
