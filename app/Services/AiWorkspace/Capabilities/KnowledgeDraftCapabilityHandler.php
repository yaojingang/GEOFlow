<?php

namespace App\Services\AiWorkspace\Capabilities;

use App\Ai\Workspace\AiCapabilityResult;
use App\Models\Admin;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;

final readonly class KnowledgeDraftCapabilityHandler implements AiCapabilityHandler
{
    public function __construct(private EnterpriseKnowledgeDraftService $knowledge) {}

    public function execute(array $parameters, Admin $admin, ?string $executionKey = null): AiCapabilityResult
    {
        $project = $this->knowledge->createWorkspaceDraft($parameters, $admin);

        return new AiCapabilityResult(
            summary: sprintf('知识草稿“%s”已创建，可继续校验和发布。', $project->name),
            payload: ['project_id' => (int) $project->id, 'status' => (string) $project->status],
            artifactType: 'knowledge_draft',
            artifactName: (string) $project->name,
            sourceRoute: 'admin.enterprise-knowledge.show',
            sourceUrl: route('admin.enterprise-knowledge.show', ['projectId' => $project->id]),
        );
    }
}
