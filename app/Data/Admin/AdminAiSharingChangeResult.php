<?php

namespace App\Data\Admin;

use App\Models\Admin;

final readonly class AdminAiSharingChangeResult
{
    /**
     * @param  list<int>  $clearedDefaultModelIds
     * @param  array{title_generation_runs: int, article_ai_optimization_runs: int, knowledge_fact_generation_runs: int, ai_workspace_runs: int, url_import_jobs: int, enterprise_knowledge_projects: int, total: int}  $pendingImpactCounts
     */
    public function __construct(
        public Admin $admin,
        public ?int $oldProviderAdminId,
        public ?int $newProviderAdminId,
        public int $oldAccessVersion,
        public int $newAccessVersion,
        public array $clearedDefaultModelIds,
        public ?int $clearedChatDefaultModelId,
        public ?int $clearedEmbeddingDefaultModelId,
        public array $pendingImpactCounts,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'admin_id' => (int) $this->admin->getKey(),
            'old_provider_admin_id' => $this->oldProviderAdminId,
            'new_provider_admin_id' => $this->newProviderAdminId,
            'old_access_version' => $this->oldAccessVersion,
            'new_access_version' => $this->newAccessVersion,
            'cleared_default_model_ids' => $this->clearedDefaultModelIds,
            'cleared_default_ids' => [
                'chat' => $this->clearedChatDefaultModelId,
                'embedding' => $this->clearedEmbeddingDefaultModelId,
            ],
            'cleared_default_count' => count(array_filter([
                $this->clearedChatDefaultModelId,
                $this->clearedEmbeddingDefaultModelId,
            ], static fn (?int $id): bool => $id !== null)),
            'pending_impact_counts' => $this->pendingImpactCounts,
        ];
    }
}
