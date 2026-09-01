<?php

namespace App\Data\Admin;

use App\Models\AiModel;
use App\Services\GeoFlow\AiUsageReservation;
use JsonSerializable;
use LogicException;

final class AdminAiModelTestSnapshot implements JsonSerializable
{
    public function __construct(
        public readonly int $adminId,
        public readonly int $adminAccessVersion,
        public readonly int $modelId,
        public readonly int $ownerAdminId,
        public readonly string $accessScope,
        public readonly string $status,
        public readonly ?string $archivedAt,
        public readonly string $configurationDigest,
        public readonly string $name,
        public readonly string $version,
        public readonly string $modelType,
        public readonly string $apiUrl,
        public readonly string $endpoint,
        public readonly string $providerModelId,
        public readonly ?int $maxTokens,
        public readonly bool $gemini,
        public readonly bool $usesOpenAiResponses,
        public readonly bool $preparedAsSuperAdmin,
        public readonly ?AiUsageReservation $reservation,
        private readonly string $encryptedApiKey,
        private readonly string $decryptedApiKey,
    ) {}

    public function apiKey(): string
    {
        return $this->decryptedApiKey;
    }

    public function modelForWorkspaceProbe(): AiModel
    {
        $model = new AiModel;
        $model->setRawAttributes([
            'id' => $this->modelId,
            'owner_admin_id' => $this->ownerAdminId,
            'name' => $this->name,
            'version' => $this->version,
            'model_id' => $this->providerModelId,
            'model_type' => $this->modelType,
            'api_url' => $this->apiUrl,
            'api_key' => $this->encryptedApiKey,
            'status' => $this->status,
            'access_scope' => $this->accessScope,
            'archived_at' => $this->archivedAt,
            'max_tokens' => $this->maxTokens,
        ], true);
        $model->exists = true;

        return $model;
    }

    /** @return array<string, int|string|bool|null> */
    public function jsonSerialize(): array
    {
        return [
            'admin_id' => $this->adminId,
            'model_id' => $this->modelId,
            'model_type' => $this->modelType,
            'prepared_as_super_admin' => $this->preparedAsSuperAdmin,
        ];
    }

    /** @return array<string, int|string|bool|null> */
    public function __debugInfo(): array
    {
        return $this->jsonSerialize();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('AI model test snapshots cannot be serialized.');
    }
}
