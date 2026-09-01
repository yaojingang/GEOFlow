<?php

namespace App\Data\Admin;

use App\Models\AiModel;

final readonly class GovernanceAiModelData
{
    private function __construct(
        public int $id,
        public string $name,
        public string $version,
        public string $modelType,
        public string $status,
        public int $failoverPriority,
        public bool $isAvailable,
        public string $ownerDisplayName,
        public string $ownerStatus,
    ) {}

    public static function fromModel(AiModel $model): self
    {
        $ownerStatus = (string) ($model->owner?->status ?? 'inactive');

        return new self(
            id: (int) $model->getKey(),
            name: (string) $model->name,
            version: (string) $model->version,
            modelType: (string) $model->model_type,
            status: (string) $model->status,
            failoverPriority: (int) $model->failover_priority,
            isAvailable: $ownerStatus === 'active'
                && (string) $model->status === 'active'
                && $model->archived_at === null
                && (string) $model->access_scope === AiModel::ACCESS_SCOPE_USER_CONTENT,
            ownerDisplayName: (string) ($model->owner?->display_name ?: $model->owner?->username),
            ownerStatus: $ownerStatus,
        );
    }

    /**
     * @return array{
     *   id:int,
     *   name:string,
     *   version:string,
     *   model_type:string,
     *   status:string,
     *   failover_priority:int,
     *   is_available:bool,
     *   owner:array{display_name:string,status:string}
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'version' => $this->version,
            'model_type' => $this->modelType,
            'status' => $this->status,
            'failover_priority' => $this->failoverPriority,
            'is_available' => $this->isAvailable,
            'owner' => [
                'display_name' => $this->ownerDisplayName,
                'status' => $this->ownerStatus,
            ],
        ];
    }
}
