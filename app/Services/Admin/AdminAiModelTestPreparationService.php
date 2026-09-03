<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiModelTestSnapshot;
use App\Data\AiWorkspace\AiWorkspaceModelProbeResult;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Services\GeoFlow\AiVisibility\AiProviderEndpointPolicy;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AdminAiModelTestPreparationService
{
    public function __construct(
        private readonly AiUsageQuotaService $usageQuota,
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly AiProviderEndpointPolicy $endpointPolicy,
    ) {}

    public function prepare(Admin $authenticatedActor, int $modelId): AdminAiModelTestSnapshot
    {
        return DB::transaction(function () use ($authenticatedActor, $modelId): AdminAiModelTestSnapshot {
            $lockedActor = $this->lockActiveActor($authenticatedActor);
            $lockedModel = $this->lockConfigurableModel($lockedActor, $modelId, true);
            $encryptedApiKey = (string) ($lockedModel->getRawOriginal('api_key') ?? '');
            $decryptedApiKey = $this->apiKeyCrypto->decrypt($encryptedApiKey);
            $modelType = $this->normalizeModelType((string) $lockedModel->model_type);
            $endpoint = $this->resolveEndpoint($lockedModel, $modelType);
            $reservation = $endpoint !== ''
                && $decryptedApiKey !== ''
                && trim((string) $lockedModel->model_id) !== ''
                    ? $this->usageQuota->reserveLockedModelForTest($lockedModel)
                    : null;
            $lockedModel->refresh();

            return new AdminAiModelTestSnapshot(
                adminId: (int) $lockedActor->getKey(),
                adminAccessVersion: (int) $lockedActor->ai_config_access_version,
                modelId: (int) $lockedModel->getKey(),
                ownerAdminId: (int) $lockedModel->owner_admin_id,
                accessScope: (string) $lockedModel->access_scope,
                status: (string) $lockedModel->status,
                archivedAt: $lockedModel->archived_at?->toISOString(),
                configurationDigest: AiModelTestConfigurationDigest::forModel($lockedModel),
                name: (string) $lockedModel->name,
                version: (string) $lockedModel->version,
                modelType: $modelType,
                apiUrl: (string) $lockedModel->api_url,
                endpoint: $endpoint,
                providerModelId: (string) $lockedModel->model_id,
                maxTokens: $lockedModel->max_tokens === null ? null : (int) $lockedModel->max_tokens,
                gemini: OpenAiRuntimeProvider::isGeminiProviderUrl($endpoint),
                usesOpenAiResponses: $modelType === 'chat'
                    && OpenAiRuntimeProvider::resolveChatDriver(
                        (string) $lockedModel->api_url,
                        (string) $lockedModel->model_id,
                    ) === 'openai',
                preparedAsSuperAdmin: $lockedActor->isSuperAdmin(),
                reservation: $reservation,
                encryptedApiKey: $encryptedApiKey,
                decryptedApiKey: $decryptedApiKey,
            );
        }, 3);
    }

    public function prepareSystemBinding(
        Admin $authenticatedActor,
        int $modelId,
        string $bindingType,
    ): AdminAiModelTestSnapshot {
        return DB::transaction(function () use ($authenticatedActor, $modelId, $bindingType): AdminAiModelTestSnapshot {
            $lockedActor = $this->lockActiveActor($authenticatedActor);
            if (! $lockedActor->isSuperAdmin()) {
                throw new AuthorizationException('ai_system_config_super_admin_only');
            }
            $lockedModel = $this->lockConfigurableModel($lockedActor, $modelId, true, true);
            if (! in_array($bindingType, ['ark', 'deepseek'], true)
                || ! $this->endpointPolicy->acceptsModelApi($bindingType, (string) $lockedModel->api_url)
                || trim((string) $lockedModel->model_id) === ''
                || trim((string) $lockedModel->getRawOriginal('api_key')) === '') {
                throw AiModelAccessException::modelUnavailable($lockedActor, $lockedModel);
            }
            $encryptedApiKey = (string) ($lockedModel->getRawOriginal('api_key') ?? '');
            $decryptedApiKey = $this->apiKeyCrypto->decrypt($encryptedApiKey);
            $endpoint = $this->resolveEndpoint($lockedModel, 'chat');
            $reservation = $endpoint !== ''
                && $decryptedApiKey !== ''
                && trim((string) $lockedModel->model_id) !== ''
                    ? $this->usageQuota->reserveLockedModelForTest($lockedModel)
                    : null;
            $lockedModel->refresh();

            return new AdminAiModelTestSnapshot(
                adminId: (int) $lockedActor->getKey(),
                adminAccessVersion: (int) $lockedActor->ai_config_access_version,
                modelId: (int) $lockedModel->getKey(),
                ownerAdminId: (int) $lockedModel->owner_admin_id,
                accessScope: (string) $lockedModel->access_scope,
                status: (string) $lockedModel->status,
                archivedAt: $lockedModel->archived_at?->toISOString(),
                configurationDigest: AiModelTestConfigurationDigest::forModel($lockedModel),
                name: (string) $lockedModel->name,
                version: (string) $lockedModel->version,
                modelType: 'chat',
                apiUrl: (string) $lockedModel->api_url,
                endpoint: $endpoint,
                providerModelId: (string) $lockedModel->model_id,
                maxTokens: $lockedModel->max_tokens === null ? null : (int) $lockedModel->max_tokens,
                gemini: false,
                usesOpenAiResponses: $bindingType === 'ark',
                preparedAsSuperAdmin: true,
                reservation: $reservation,
                encryptedApiKey: $encryptedApiKey,
                decryptedApiKey: $decryptedApiKey,
            );
        }, 3);
    }

    public function revalidateImmediatelyBeforeOutbound(AdminAiModelTestSnapshot $snapshot): bool
    {
        return $this->withValidatedSnapshot(
            $snapshot,
            false,
            static fn (Admin $actor, AiModel $model): bool => $actor->isSuperAdmin(),
        );
    }

    public function revalidateAfterOutbound(AdminAiModelTestSnapshot $snapshot): bool
    {
        return $this->withValidatedSnapshot(
            $snapshot,
            true,
            static fn (Admin $actor, AiModel $model): bool => $actor->isSuperAdmin(),
        );
    }

    public function revalidateWorkspaceAfterOutbound(AdminAiModelTestSnapshot $snapshot): void
    {
        $this->withValidatedSnapshot(
            $snapshot,
            true,
            function (Admin $actor, AiModel $model) use ($snapshot): void {
                $this->assertWorkspaceProbePermission($snapshot, $actor, $model);
            },
        );
    }

    public function persistWorkspaceReadiness(
        AdminAiModelTestSnapshot $snapshot,
        AiWorkspaceModelProbeResult $result,
    ): void {
        $this->withValidatedSnapshot(
            $snapshot,
            true,
            function (Admin $actor, AiModel $model) use ($snapshot, $result): void {
                $this->assertWorkspaceProbePermission($snapshot, $actor, $model);
                $model->forceFill($result->persistenceAttributes())->save();
            },
        );
    }

    public function persistWorkspaceFailure(
        AdminAiModelTestSnapshot $snapshot,
        string $failureCode,
    ): void {
        $allowedFailureCodes = [
            'authentication_failed',
            'plain_text_invalid',
            'provider_timeout',
            'provider_unavailable',
        ];
        $normalizedFailureCode = in_array($failureCode, $allowedFailureCodes, true)
            ? $failureCode
            : 'provider_unavailable';

        $this->withValidatedSnapshot(
            $snapshot,
            true,
            function (Admin $actor, AiModel $model) use ($snapshot, $normalizedFailureCode): void {
                $this->assertWorkspaceProbePermission($snapshot, $actor, $model);
                $model->forceFill([
                    'ai_workspace_structured_output_status' => null,
                    'ai_workspace_structured_output_verified_at' => null,
                    'ai_workspace_readiness_status' => 'failed',
                    'ai_workspace_readiness_profile' => null,
                    'ai_workspace_readiness_checked_at' => now(),
                    'ai_workspace_readiness_expires_at' => null,
                    'ai_workspace_readiness_failure_code' => $normalizedFailureCode,
                ])->save();
            },
        );
    }

    /**
     * @template TResult
     *
     * @param  Closure(Admin, AiModel): TResult  $operation
     * @return TResult
     */
    private function withValidatedSnapshot(
        AdminAiModelTestSnapshot $snapshot,
        bool $afterOutbound,
        Closure $operation,
    ): mixed {
        return DB::transaction(function () use ($snapshot, $afterOutbound, $operation): mixed {
            $actorReference = new Admin;
            $actorReference->setAttribute($actorReference->getKeyName(), $snapshot->adminId);
            $actorReference->exists = true;
            $lockedActor = Admin::query()->whereKey($snapshot->adminId)->lockForUpdate()->first();
            if (! $lockedActor instanceof Admin || (string) $lockedActor->status !== 'active') {
                throw $afterOutbound
                    ? AiModelAccessException::configAccessRevoked($actorReference)
                    : AiModelAccessException::executionAdminInactive($actorReference);
            }
            if ((int) $lockedActor->ai_config_access_version !== $snapshot->adminAccessVersion) {
                throw AiModelAccessException::configAccessRevoked($lockedActor);
            }

            $modelQuery = AiModel::query()->ownedBy($lockedActor);
            if (! $lockedActor->isSuperAdmin()) {
                $modelQuery->userContent();
            }
            $lockedModel = $modelQuery->whereKey($snapshot->modelId)->lockForUpdate()->first();
            if (! $lockedModel instanceof AiModel) {
                throw $afterOutbound
                    ? AiModelAccessException::configAccessRevoked($lockedActor)
                    : AiModelAccessException::modelNotAccessible($lockedActor, $this->modelReference($snapshot->modelId));
            }
            try {
                Gate::forUser($lockedActor)->authorize('test', $lockedModel);
            } catch (AuthorizationException $exception) {
                if (! $afterOutbound) {
                    throw $exception;
                }

                throw AiModelAccessException::configAccessRevoked($lockedActor, $lockedModel);
            }
            if ((int) $lockedModel->owner_admin_id !== $snapshot->ownerAdminId
                || (string) $lockedModel->access_scope !== $snapshot->accessScope
                || (string) $lockedModel->status !== $snapshot->status
                || $lockedModel->archived_at?->toISOString() !== $snapshot->archivedAt
                || ! hash_equals($snapshot->configurationDigest, AiModelTestConfigurationDigest::forModel($lockedModel))) {
                throw AiModelAccessException::configAccessRevoked($lockedActor, $lockedModel);
            }

            return $operation($lockedActor, $lockedModel);
        }, 3);
    }

    private function lockActiveActor(Admin $actor): Admin
    {
        $lockedActor = Admin::query()->whereKey($actor->getKey())->lockForUpdate()->first();
        if (! $lockedActor instanceof Admin || (string) $lockedActor->status !== 'active') {
            throw AiModelAccessException::executionAdminInactive($actor);
        }

        return $lockedActor;
    }

    private function lockConfigurableModel(
        Admin $actor,
        int $modelId,
        bool $hideMissing = false,
        bool $systemOnly = false,
    ): AiModel {
        $query = AiModel::query()->ownedBy($actor)->unarchived();
        if ($systemOnly) {
            $query->systemOnly()->active()->where(function ($query): void {
                $query->whereNull('model_type')->orWhere('model_type', '')->orWhere('model_type', 'chat');
            });
        } elseif (! $actor->isSuperAdmin()) {
            $query->userContent();
        }

        $model = $query->whereKey($modelId)->lockForUpdate()->first();
        if (! $model instanceof AiModel) {
            abort_if($hideMissing, 404);
            $reference = new AiModel;
            $reference->setAttribute($reference->getKeyName(), $modelId);
            $reference->exists = true;

            throw AiModelAccessException::modelNotAccessible($actor, $reference);
        }
        Gate::forUser($actor)->authorize('test', $model);

        return $model;
    }

    private function modelReference(int $modelId): AiModel
    {
        $reference = new AiModel;
        $reference->setAttribute($reference->getKeyName(), $modelId);
        $reference->exists = true;

        return $reference;
    }

    private function assertWorkspaceProbePermission(
        AdminAiModelTestSnapshot $snapshot,
        Admin $actor,
        AiModel $model,
    ): void {
        if (! $snapshot->preparedAsSuperAdmin || ! $actor->isSuperAdmin()) {
            throw AiModelAccessException::configAccessRevoked($actor, $model);
        }
    }

    private function normalizeModelType(string $modelType): string
    {
        return trim($modelType) === 'embedding' ? 'embedding' : 'chat';
    }

    private function resolveEndpoint(AiModel $model, string $modelType): string
    {
        $apiUrl = (string) $model->api_url;
        $baseUrl = $modelType === 'embedding'
            ? OpenAiRuntimeProvider::resolveEmbeddingBaseUrl($apiUrl)
            : OpenAiRuntimeProvider::resolveChatBaseUrl($apiUrl);
        if ($baseUrl === '') {
            return '';
        }
        if (OpenAiRuntimeProvider::isGeminiProviderUrl($baseUrl)) {
            $modelName = preg_replace('#^models/#', '', trim((string) $model->model_id)) ?: trim((string) $model->model_id);

            return rtrim($baseUrl, '/').'/models/'.$modelName.($modelType === 'embedding' ? ':batchEmbedContents' : ':generateContent');
        }
        if ($modelType === 'chat'
            && OpenAiRuntimeProvider::resolveChatDriver($baseUrl, (string) $model->model_id) === 'openai') {
            return rtrim($baseUrl, '/').'/responses';
        }

        return rtrim($baseUrl, '/').($modelType === 'embedding' ? '/embeddings' : '/chat/completions');
    }
}
