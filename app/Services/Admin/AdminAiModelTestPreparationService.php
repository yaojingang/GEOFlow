<?php

namespace App\Services\Admin;

use App\Data\Admin\AdminAiModelTestSnapshot;
use App\Exceptions\AiModelAccessException;
use App\Models\Admin;
use App\Models\AiModel;
use App\Services\GeoFlow\AiUsageQuotaService;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

final class AdminAiModelTestPreparationService
{
    public function __construct(
        private readonly AiUsageQuotaService $usageQuota,
        private readonly ApiKeyCrypto $apiKeyCrypto,
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
                updatedAt: $lockedModel->updated_at?->toISOString() ?? '',
                configurationDigest: $this->configurationDigest($lockedModel),
                name: (string) $lockedModel->name,
                version: (string) $lockedModel->version,
                modelType: $modelType,
                apiUrl: (string) $lockedModel->api_url,
                endpoint: $endpoint,
                providerModelId: (string) $lockedModel->model_id,
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

    public function revalidateImmediatelyBeforeOutbound(AdminAiModelTestSnapshot $snapshot): bool
    {
        return DB::transaction(function () use ($snapshot): bool {
            $actorReference = new Admin;
            $actorReference->setAttribute($actorReference->getKeyName(), $snapshot->adminId);
            $actorReference->exists = true;
            $lockedActor = $this->lockActiveActor($actorReference);
            if ((int) $lockedActor->ai_config_access_version !== $snapshot->adminAccessVersion) {
                throw AiModelAccessException::configAccessRevoked($lockedActor);
            }

            $lockedModel = $this->lockConfigurableModel($lockedActor, $snapshot->modelId);
            if ((int) $lockedModel->owner_admin_id !== $snapshot->ownerAdminId
                || (string) $lockedModel->access_scope !== $snapshot->accessScope
                || (string) $lockedModel->status !== $snapshot->status
                || $lockedModel->archived_at?->toISOString() !== $snapshot->archivedAt
                || ($lockedModel->updated_at?->toISOString() ?? '') !== $snapshot->updatedAt
                || ! hash_equals($snapshot->configurationDigest, $this->configurationDigest($lockedModel))) {
                throw AiModelAccessException::configAccessRevoked($lockedActor, $lockedModel);
            }

            return $lockedActor->isSuperAdmin();
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

    private function lockConfigurableModel(Admin $actor, int $modelId, bool $hideMissing = false): AiModel
    {
        $query = AiModel::query()->ownedBy($actor);
        if (! $actor->isSuperAdmin()) {
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

    private function configurationDigest(AiModel $model): string
    {
        return hash('sha256', json_encode([
            'owner_admin_id' => (int) $model->owner_admin_id,
            'access_scope' => (string) $model->access_scope,
            'status' => (string) $model->status,
            'archived_at' => $model->archived_at?->toISOString(),
            'updated_at' => $model->updated_at?->toISOString(),
            'model_type' => (string) $model->model_type,
            'api_url' => (string) $model->api_url,
            'model_id' => (string) $model->model_id,
            'api_key_digest' => hash('sha256', (string) $model->getRawOriginal('api_key')),
        ], JSON_THROW_ON_ERROR));
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
