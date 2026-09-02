<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelRuntimeEligibilityException;
use App\Models\AiModel;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;

final readonly class DirectAdminAiModelRuntimeGate
{
    public function __construct(private ApiKeyCrypto $apiKeyCrypto) {}

    public function assertExecutable(AiModel $model, string $capability): void
    {
        $modelType = trim((string) $model->model_type);
        $compatible = $capability === AiExecutionContext::CAPABILITY_CHAT
            ? in_array($modelType, ['', AiExecutionContext::CAPABILITY_CHAT], true)
            : $modelType === $capability;
        if (! $compatible) {
            throw AiModelRuntimeEligibilityException::configuration('AI 模型能力不兼容');
        }

        $providerUrl = $capability === AiExecutionContext::CAPABILITY_CHAT
            ? OpenAiRuntimeProvider::resolveChatBaseUrl((string) $model->api_url)
            : OpenAiRuntimeProvider::resolveEmbeddingBaseUrl((string) $model->api_url);
        $scheme = strtolower((string) (parse_url($providerUrl, PHP_URL_SCHEME) ?? ''));
        $host = trim((string) (parse_url($providerUrl, PHP_URL_HOST) ?? ''));
        if ($providerUrl === '') {
            throw AiModelRuntimeEligibilityException::configuration('AI 模型 API 地址为空');
        }
        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw AiModelRuntimeEligibilityException::configuration('AI 模型 API 地址为空或无效');
        }

        $apiKey = $this->apiKeyCrypto->decrypt((string) ($model->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw AiModelRuntimeEligibilityException::configuration('AI 模型密钥为空或无法解密');
        }
        if (trim((string) $model->model_id) === '') {
            throw AiModelRuntimeEligibilityException::configuration('AI 模型标识为空');
        }

        if ((int) $model->daily_limit > 0 && $model->currentUsage() >= (int) $model->daily_limit) {
            throw AiModelRuntimeEligibilityException::quota();
        }

        if ((string) $model->ai_workspace_readiness_status === 'failed'
            && ($model->ai_workspace_readiness_expires_at === null
                || $model->ai_workspace_readiness_expires_at->isFuture())) {
            throw AiModelRuntimeEligibilityException::health();
        }
    }
}
