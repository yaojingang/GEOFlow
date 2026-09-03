<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Services\Admin\AiModelUsageAttempt;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\AiWorkspace\AiModelInvocationLock;
use Closure;
use Laravel\Ai\Responses\AgentResponse;

final readonly class WorkerAiModelInvocationGateway
{
    public const PERSISTENCE_MARGIN_SECONDS = 60;

    public function __construct(
        private AiExecutionAccessGuard $accessGuard,
        private AiModelInvocationLock $invocationLocks,
        private ArticleContentGenerationService $generationService,
        private AiModelUsageAttemptFactory $usageAttempts,
    ) {}

    /**
     * @template TResult
     *
     * @param  Closure(array{model:AiModel,response:AgentResponse,receipt:array{model_id:int,request_id:string,configuration_digest:string}}): TResult  $persistResponse
     * @return TResult
     */
    public function generate(
        AiExecutionContext $executionContext,
        AiModel|int $model,
        string $prompt,
        Closure $persistResponse,
    ): mixed {
        $modelId = $model instanceof AiModel ? (int) $model->getKey() : $model;
        if ($modelId <= 0) {
            throw AiModelAccessException::configAccessRevokedForAdminId($executionContext->modelAccessAdminId);
        }

        $invocationLock = $this->invocationLocks->acquireForInvocation(
            $modelId,
            $this->generationService->providerTimeoutSeconds() + self::PERSISTENCE_MARGIN_SECONDS,
        );
        $usageAttempt = null;
        $providerReturned = false;
        $response = null;

        try {
            $currentModel = $this->accessGuard->assertModelCurrent($executionContext, $modelId);
            $receipt = $this->receiptFor($executionContext, $currentModel);
            $usageRequestId = $this->usageAttempts->requestId();
            $response = $this->generationService->generate(
                $currentModel,
                $prompt,
                function (AiModel $providerModel) use (&$usageAttempt, $executionContext, $usageRequestId, $prompt): void {
                    $usageAttempt = $this->usageAttempts->beginForAdmin(
                        model: $providerModel,
                        executionAdminId: $executionContext->modelAccessAdminId,
                        accessVersion: $executionContext->aiConfigAccessVersion,
                        executionScope: AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN,
                        modelSource: $this->usageAttempts->sourceFor(
                            $providerModel,
                            $executionContext->modelAccessAdminId,
                        ),
                        requestId: $usageRequestId,
                        requestPayload: $prompt,
                        callKey: 'candidate-1',
                        operation: 'article.generate',
                        businessSource: 'worker_article_generation',
                        sourceType: $executionContext->sourceType,
                        sourceId: $executionContext->sourceId,
                    );
                },
            );
            $providerReturned = true;
            $currentModel = $this->assertReceiptCurrent($executionContext, $receipt);

            $result = $persistResponse([
                'model' => $currentModel,
                'response' => $response,
                'receipt' => $receipt,
            ]);
            $usageAttempt?->succeeded($response->usage ?? null);

            return $result;
        } catch (AiModelAccessException $exception) {
            $usageAttempt?->revoked($exception->getErrorCode(), $response?->usage ?? null);

            throw $exception;
        } catch (\Throwable $exception) {
            if ($usageAttempt instanceof AiModelUsageAttempt) {
                $providerReturned
                    ? $usageAttempt->discarded('ai_result_persistence_failed', $response?->usage ?? null)
                    : $usageAttempt->failed('ai_provider_request_failed');
            }

            throw $exception;
        } finally {
            $usageAttempt?->discarded('ai_result_not_committed', $response?->usage ?? null);
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
