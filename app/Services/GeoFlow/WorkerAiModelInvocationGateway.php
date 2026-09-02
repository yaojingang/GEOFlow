<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\AiModel;
use App\Services\AiWorkspace\AiModelInvocationLock;
use Laravel\Ai\Responses\AgentResponse;

final readonly class WorkerAiModelInvocationGateway
{
    public const PERSISTENCE_MARGIN_SECONDS = 60;

    public function __construct(
        private AiExecutionAccessGuard $accessGuard,
        private AiModelInvocationLock $invocationLocks,
        private ArticleContentGenerationService $generationService,
    ) {}

    /**
     * @return array{model:AiModel,response:AgentResponse,receipt:array{model_id:int,request_id:string,configuration_digest:string}}
     */
    public function generate(
        AiExecutionContext $executionContext,
        AiModel|int $model,
        string $prompt,
    ): array {
        $modelId = $model instanceof AiModel ? (int) $model->getKey() : $model;
        if ($modelId <= 0) {
            throw AiModelAccessException::configAccessRevokedForAdminId($executionContext->modelAccessAdminId);
        }

        $invocationLock = $this->invocationLocks->acquireForInvocation(
            $modelId,
            $this->generationService->providerTimeoutSeconds() + self::PERSISTENCE_MARGIN_SECONDS,
        );

        try {
            $currentModel = $this->accessGuard->assertModelCurrent($executionContext, $modelId);
            $receipt = $this->receiptFor($executionContext, $currentModel);
            $response = $this->generationService->generate($currentModel, $prompt);
            $currentModel = $this->assertReceiptCurrent($executionContext, $receipt);

            return [
                'model' => $currentModel,
                'response' => $response,
                'receipt' => $receipt,
            ];
        } finally {
            $this->invocationLocks->release($invocationLock);
        }
    }

    /**
     * @param  array{model_id:int,request_id:string,configuration_digest:string}  $receipt
     */
    public function assertReceiptCurrent(
        AiExecutionContext $executionContext,
        array $receipt,
    ): AiModel {
        if (! hash_equals($executionContext->requestId, (string) ($receipt['request_id'] ?? ''))) {
            throw AiModelAccessException::configAccessRevokedForAdminId($executionContext->modelAccessAdminId);
        }

        $currentModel = $this->accessGuard->assertModelCurrent(
            $executionContext,
            (int) ($receipt['model_id'] ?? 0),
        );
        if (! hash_equals(
            $this->configurationDigest($currentModel),
            (string) ($receipt['configuration_digest'] ?? ''),
        )) {
            throw AiModelAccessException::configAccessRevokedForAdminId($executionContext->modelAccessAdminId);
        }

        return $currentModel;
    }

    public function maxTokens(AiModel $model): int
    {
        return $this->generationService->maxTokens($model);
    }

    /** @return array{model_id:int,request_id:string,configuration_digest:string} */
    private function receiptFor(AiExecutionContext $executionContext, AiModel $model): array
    {
        return [
            'model_id' => (int) $model->getKey(),
            'request_id' => $executionContext->requestId,
            'configuration_digest' => $this->configurationDigest($model),
        ];
    }

    private function configurationDigest(AiModel $model): string
    {
        return hash('sha256', json_encode([
            'owner_admin_id' => (int) $model->owner_admin_id,
            'access_scope' => (string) $model->access_scope,
            'version' => trim((string) $model->version),
            'model_id' => trim((string) $model->model_id),
            'model_type' => trim((string) $model->model_type),
            'api_url' => trim((string) $model->api_url),
            'api_key' => (string) $model->getRawOriginal('api_key'),
            'status' => trim((string) $model->status),
            'archived_at' => $model->archived_at?->toISOString(),
            'max_tokens' => $model->max_tokens,
        ], JSON_THROW_ON_ERROR));
    }
}
