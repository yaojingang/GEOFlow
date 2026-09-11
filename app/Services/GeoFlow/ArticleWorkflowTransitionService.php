<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\Task;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ArticleWorkflowTransitionService
{
    public function __construct(private readonly ArticlePublicationQualityGate $publicationQualityGate) {}

    /**
     * @param  array{status: string, review_status: string, published_at: mixed}  $workflowState
     * @param  array{status: string, review_status: string, published_at: mixed}|null  $rejectedWorkflowState
     * @param  (callable(Article): void)|null  $lockedGuard
     */
    public function transition(
        Article $article,
        array $workflowState,
        string $trigger,
        ?int $adminId = null,
        ?string $overrideReason = null,
        bool $allowExistingOverride = true,
        ?array $rejectedWorkflowState = null,
        ?callable $lockedGuard = null,
    ): Article {
        $result = DB::transaction(function () use (
            $article,
            $workflowState,
            $trigger,
            $adminId,
            $overrideReason,
            $allowExistingOverride,
            $rejectedWorkflowState,
            $lockedGuard,
        ): Article|ArticleRiskGateException|ArticleAiQualityGateException {
            $distributionRequested = (string) $workflowState['status'] === 'published';
            $taskId = (int) (Article::query()
                ->whereKey($article->getKey())
                ->value('task_id') ?? 0);
            $lockedTask = $this->lockTaskBeforeArticle($taskId);
            $lockedArticle = $this->lockArticleAfterTask((int) $article->getKey(), $taskId);

            if ($lockedTask instanceof Task) {
                $lockedTask->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
                $lockedArticle->setRelation('task', $lockedTask);
            }

            if (! $distributionRequested) {
                $this->cancelPendingDistributionIntent($lockedArticle);
            }

            $workflowState = ArticleWorkflow::normalizeForPublishScope(
                $workflowState,
                $lockedTask?->publish_scope,
            );
            if ($rejectedWorkflowState !== null) {
                $rejectedWorkflowState = ArticleWorkflow::normalizeForPublishScope(
                    $rejectedWorkflowState,
                    $lockedTask?->publish_scope,
                );
            }

            if ($lockedGuard !== null) {
                $lockedGuard($lockedArticle);
            }

            try {
                $this->publicationQualityGate->check(
                    $lockedArticle,
                    $trigger,
                    $adminId,
                    $overrideReason,
                    $allowExistingOverride,
                );
            } catch (ArticleRiskGateException|ArticleAiQualityGateException $exception) {
                $preservePublishedArticle = $exception instanceof ArticleAiQualityGateException
                    && (string) $lockedArticle->status === 'published';
                if ($preservePublishedArticle && $this->isDistributionOnly($lockedTask)) {
                    $lockedArticle->update([
                        'status' => 'private',
                        'published_at' => null,
                    ]);
                } elseif ($rejectedWorkflowState !== null && ! $preservePublishedArticle) {
                    $lockedArticle->update([
                        'status' => $rejectedWorkflowState['status'],
                        'review_status' => $rejectedWorkflowState['review_status'],
                        'published_at' => $rejectedWorkflowState['published_at'],
                    ]);
                }

                return $exception;
            }

            $lockedArticle->update([
                'status' => $workflowState['status'],
                'review_status' => $workflowState['review_status'],
                'published_at' => $workflowState['published_at'],
            ]);

            return $lockedArticle->refresh();
        });

        if ($result instanceof ArticleRiskGateException || $result instanceof ArticleAiQualityGateException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Replace any still-applicable publish request with the caller's newer private intent.
     * The caller must hold the article row lock before invoking this method.
     */
    public function cancelPendingDistributionIntent(Article $article): int
    {
        $updated = 0;
        $checks = ArticleAiQualityCheck::query()
            ->where('article_id', (int) $article->id)
            ->where('gate_applied', true)
            ->where('evaluation_mode', '!=', 'optimization_candidate')
            ->whereIn('status', ['queued', 'running', 'completed'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($checks as $check) {
            $executionMeta = is_array($check->execution_meta) ? $check->execution_meta : [];
            $requestedWorkflowState = is_array($executionMeta['requested_workflow_state'] ?? null)
                ? $executionMeta['requested_workflow_state']
                : null;
            if ((string) ($requestedWorkflowState['status'] ?? '') !== 'published') {
                continue;
            }
            if ((string) data_get($executionMeta, 'workflow_apply.status') === 'succeeded') {
                continue;
            }

            $executionMeta['requested_workflow_state'] = [
                'status' => 'private',
                'review_status' => (string) ($requestedWorkflowState['review_status'] ?? 'approved'),
                'published_at' => null,
            ];
            $executionMeta['distribution_intent_cancelled_at'] = now()->toIso8601String();
            $check->forceFill(['execution_meta' => $executionMeta])->save();
            $updated++;
        }

        return $updated;
    }

    private function lockTaskBeforeArticle(int $taskId): ?Task
    {
        if ($taskId <= 0) {
            return null;
        }

        return Task::withTrashed()
            ->whereKey($taskId)
            ->lockForUpdate()
            ->first();
    }

    private function lockArticleAfterTask(int $articleId, int $expectedTaskId): Article
    {
        $article = Article::query()
            ->whereKey($articleId)
            ->lockForUpdate()
            ->firstOrFail();
        if ((int) ($article->task_id ?? 0) !== $expectedTaskId) {
            throw new RuntimeException('文章所属任务已变更，请重试。');
        }

        return $article;
    }

    private function isDistributionOnly(?Task $task): bool
    {
        return $task instanceof Task && (string) $task->publish_scope === 'distribution_only';
    }
}
