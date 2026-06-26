<?php

namespace App\Jobs;

use App\Models\EnterpriseKnowledgeProject;
use App\Models\EnterpriseKnowledgeRevision;
use App\Services\GeoFlow\EnterpriseKnowledgeDraftService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class GenerateEnterpriseKnowledgeDraftJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $projectId,
        public readonly ?int $adminId = null,
    ) {}

    public function handle(EnterpriseKnowledgeDraftService $draftService): void
    {
        $project = EnterpriseKnowledgeProject::query()
            ->with('sources')
            ->whereKey($this->projectId)
            ->first();

        if (! $project) {
            return;
        }

        if (in_array((string) $project->status, ['reviewing', 'published'], true)
            && trim((string) $project->draft_content) !== '') {
            return;
        }

        try {
            $this->updateProgress($project, 'collecting', 20, __('admin.enterprise_knowledge.progress_message.collecting'));
            $this->updateProgress($project, 'cleaning', 35, __('admin.enterprise_knowledge.progress_message.cleaning'));
            $this->updateProgress($project, 'structuring', 58, __('admin.enterprise_knowledge.progress_message.structuring'));

            $freshProject = $project->fresh(['sources']) ?? $project;
            $draft = $draftService->generateDraft($freshProject);
            $content = trim((string) $draft['content']);

            $this->updateProgress($project, 'validating', 78, __('admin.enterprise_knowledge.progress_message.validating'));
            $validationItems = $draftService->validateDraft($content);

            $this->updateProgress($project, 'writing', 92, __('admin.enterprise_knowledge.progress_message.writing'));
            $project->forceFill([
                'status' => 'reviewing',
                'draft_content' => $content,
                'validation_json' => json_encode($validationItems, JSON_UNESCAPED_UNICODE),
                'ai_model_id' => $draft['model_id'],
                'error_message' => $draft['error'],
                'structured_json' => $this->progressJson($project, 'completed', 100, __('admin.enterprise_knowledge.progress_message.completed')),
            ])->save();

            $this->recordRevision($project, $content, (string) $draft['source'], $draft['source'] === 'ai'
                ? __('admin.enterprise_knowledge.revision_ai')
                : __('admin.enterprise_knowledge.revision_fallback'));
        } catch (Throwable $exception) {
            $this->markFailed($project, $exception);
        }
    }

    public function failed(?Throwable $exception = null): void
    {
        $project = EnterpriseKnowledgeProject::query()->whereKey($this->projectId)->first();
        if (! $project || ! $exception) {
            return;
        }

        $this->markFailed($project, $exception);
    }

    private function updateProgress(EnterpriseKnowledgeProject $project, string $step, int $progress, string $message): void
    {
        $project->forceFill([
            'status' => 'processing',
            'structured_json' => $this->progressJson($project, $step, $progress, $message),
        ])->save();
    }

    private function markFailed(EnterpriseKnowledgeProject $project, Throwable $exception): void
    {
        $message = mb_substr($exception->getMessage(), 0, 2000);
        $project->forceFill([
            'status' => 'failed',
            'error_message' => $message,
            'structured_json' => $this->progressJson($project, 'failed', 100, __('admin.enterprise_knowledge.progress_message.failed', ['message' => $message])),
        ])->save();
    }

    private function progressJson(EnterpriseKnowledgeProject $project, string $step, int $progress, string $message): string
    {
        $data = $project->structuredData();
        $previous = is_array($data['draft_generation'] ?? null) ? $data['draft_generation'] : [];
        $startedAt = (string) ($previous['started_at'] ?? now()->toIso8601String());

        $data['draft_generation'] = [
            'step' => $step,
            'progress' => max(0, min(100, $progress)),
            'message' => $message,
            'started_at' => $startedAt,
            'updated_at' => now()->toIso8601String(),
        ];

        return json_encode($data, JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function recordRevision(EnterpriseKnowledgeProject $project, string $content, string $source, string $summary): void
    {
        EnterpriseKnowledgeRevision::query()->create([
            'enterprise_knowledge_project_id' => (int) $project->id,
            'content' => $content,
            'summary' => $summary,
            'source' => $source,
            'created_by_admin_id' => $this->adminId,
            'content_hash' => hash('sha256', $content),
        ]);
    }
}
