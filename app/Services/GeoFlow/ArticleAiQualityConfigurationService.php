<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use Illuminate\Validation\ValidationException;

class ArticleAiQualityConfigurationService
{
    public function __construct(
        private readonly AiQualityRetrievalReadinessService $readinessService,
    ) {}

    /**
     * Apply article-level retrieval configuration to an already locked article.
     *
     * @param  list<int>|null  $knowledgeBaseIds
     */
    public function apply(
        Article $article,
        ?string $requestedOverride,
        ?array $knowledgeBaseIds,
    ): bool {
        $override = trim((string) $requestedOverride);
        $override = $override === '' ? null : $override;
        if ($override !== null && ! AiQualityRetrievalMode::isValid($override)) {
            throw ValidationException::withMessages([
                'ai_quality_retrieval_mode_override' => 'AI 质检方式无效。',
            ]);
        }

        $attachedToTask = (int) $article->task_id > 0;
        if ($attachedToTask && $knowledgeBaseIds !== null) {
            throw ValidationException::withMessages([
                'ai_quality_knowledge_base_ids' => '任务文章的知识库由所属任务统一管理。',
            ]);
        }
        $currentIds = $article->aiQualityKnowledgeBases()
            ->orderByPivot('sort_order')
            ->pluck('knowledge_bases.id')
            ->map('intval')
            ->all();
        $nextIds = $attachedToTask
            ? $this->taskKnowledgeBaseIds($article)
            : ($knowledgeBaseIds === null ? $currentIds : $this->normalizeIds($knowledgeBaseIds));

        $readiness = $this->readinessService->inspect($nextIds);
        if ($attachedToTask && $override !== null) {
            $this->assertModeAvailable($override, $readiness);
        }
        if (! $attachedToTask) {
            $override ??= $readiness['highest_available_mode'] ?? null;
            $this->assertModeAvailable($override, $readiness);
        }

        $modeChanged = (string) ($article->ai_quality_retrieval_mode_override ?? '') !== (string) ($override ?? '');
        $knowledgeChanged = ! $attachedToTask && $currentIds !== $nextIds;
        if (! $modeChanged && ! $knowledgeChanged) {
            return false;
        }

        if (! $attachedToTask) {
            $article->aiQualityKnowledgeBases()->sync(collect($nextIds)->mapWithKeys(
                static fn (int $id, int $index): array => [$id => ['sort_order' => $index]],
            )->all());
        }

        $article->forceFill([
            'ai_quality_retrieval_mode_override' => $override,
            'ai_quality_policy_version' => max(1, (int) $article->ai_quality_policy_version) + 1,
            'ai_quality_required_at_creation' => $article->ai_quality_required_at_creation || $nextIds !== [],
        ])->save();

        return true;
    }

    /** @return list<int> */
    public function effectiveKnowledgeBaseIds(Article $article): array
    {
        return (int) $article->task_id > 0
            ? $this->taskKnowledgeBaseIds($article)
            : $article->aiQualityKnowledgeBases()
                ->orderByPivot('sort_order')
                ->pluck('knowledge_bases.id')
                ->map('intval')
                ->all();
    }

    /** @return list<int> */
    private function taskKnowledgeBaseIds(Article $article): array
    {
        $task = $article->task()->first(['id', 'knowledge_base_id']);
        if (! $task) {
            return [];
        }
        $ids = $task->knowledgeBases()
            ->orderByPivot('sort_order')
            ->pluck('knowledge_bases.id')
            ->map('intval')
            ->all();

        return $ids !== [] ? $ids : array_values(array_filter([(int) $task->knowledge_base_id]));
    }

    /** @param list<int> $ids @return list<int> */
    private function normalizeIds(array $ids): array
    {
        return collect($ids)
            ->map('intval')
            ->filter()
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $readiness */
    private function assertModeAvailable(?string $mode, array $readiness): void
    {
        if (! AiQualityRetrievalMode::isValid($mode)
            || ! ($readiness['modes'][$mode]['available'] ?? false)) {
            $blocker = $readiness['modes'][$mode]['blockers'][0] ?? null;
            $name = trim((string) ($blocker['knowledge_base_name'] ?? ''));
            $message = trim((string) ($blocker['message'] ?? '当前方式不可用'));

            throw ValidationException::withMessages([
                'ai_quality_retrieval_mode_override' => $name === '' ? $message : $name.'：'.$message,
            ]);
        }
    }
}
