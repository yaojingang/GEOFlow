<?php

namespace App\Data\Admin;

use App\Models\AiModel;

final readonly class SharedAiModelData
{
    private function __construct(
        public int $id,
        public string $name,
        public string $version,
        public string $modelType,
        public string $status,
        public int $failoverPriority,
        public bool $isAvailable,
        public bool $isShared,
    ) {}

    public static function fromModel(AiModel $model, bool $isShared): self
    {
        $modelType = trim((string) $model->model_type);

        return new self(
            id: (int) $model->getKey(),
            name: (string) $model->name,
            version: (string) $model->version,
            modelType: $modelType === '' ? 'chat' : $modelType,
            status: (string) $model->status,
            failoverPriority: (int) $model->failover_priority,
            isAvailable: (string) $model->status === 'active'
                && $model->archived_at === null
                && (string) $model->access_scope === AiModel::ACCESS_SCOPE_USER_CONTENT,
            isShared: $isShared,
        );
    }

    /** @return array{id: int, name: string, version: string, model_type: string, status: string, failover_priority: int, is_available: bool, is_shared: bool} */
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
            'is_shared' => $this->isShared,
        ];
    }
}
