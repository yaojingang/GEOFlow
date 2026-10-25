<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Models\ArticleReview;
use App\Models\Task;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ArticleWorkflowTransitionService
{
    public function __construct(
        private readonly ArticlePublicationQualityGate $publicationQualityGate,
        private readonly ArticlePublicationEligibilityService $publicationEligibility,
        private readonly ArticlePublicationDeliveryService $publicationDelivery,
    ) {}

    public function contentChanged(Article $article, ?int $adminId = null): Article
    {
        return DB::transaction(function () use ($article): Article {
            $taskId = (int) Article::query()->whereKey($article->id)->value('task_id');
            $task = $this->lockTaskBeforeArticle($taskId);
            $locked = $this->lockArticleAfterTask((int) $article->id, $taskId);
            $locked->setRelation('task', $task);
            $locked->update([
                'workflow_version' => (int) $locked->workflow_version + 1,
                'review_status' => $locked->review_status === 'rejected' ? 'rejected'
                    : ($this->publicationEligibility->manualReviewRequired($locked) ? 'pending' : 'auto_approved'),
            ]);

            return $locked->refresh();
        }, 3);
    }

    public function recomputeTaskReviews(int $taskId): int
    {
        return DB::transaction(function () use ($taskId): int {
            $task = $this->lockTaskBeforeArticle($taskId);
            if (! $task || $task->trashed()) {
                return 0;
            }
            $count = 0;
            Article::query()->where('task_id', $taskId)->where('status', '!=', 'published')
                ->orderBy('id')->chunkById(100, function ($articles) use ($task, &$count): void {
                    foreach ($articles as $candidate) {
                        $article = $this->lockArticleAfterTask((int) $candidate->id, (int) $task->id);
                        $article->setRelation('task', $task);
                        $status = $this->publicationEligibility->reviewStatus($article);
                        if ($status !== $article->review_status) {
                            $article->update(['review_status' => $status]);
                            $count++;
                        }
                    }
                });

            return $count;
        }, 3);
    }

    /** Re-evaluate durable explicit requests after the current task policy changes. */
    public function reconcileImmediateForTask(int $taskId): void
    {
        foreach (Article::query()->where('task_id', $taskId)->where('publication_intent', 'immediate')
            ->whereIn('status', ['draft', 'private'])->lazyById(100) as $article) {
            try {
                $this->humanAction($article, 'publish', expectedVersion: (int) $article->workflow_version);
            } catch (ArticleAiQualityGateException|ArticleRiskGateException $exception) {
                // The retained request exposes the current blocking reason to the operator.
            } catch (RuntimeException $exception) {
                if (! in_array($exception->getMessage(), ['article_not_publishable', 'workflow_version_conflict'], true)) {
                    report($exception);
                }
            }
        }
    }

    public function humanAction(
        Article $article,
        string $action,
        ?int $adminId = null,
        ?string $note = null,
        ?int $expectedVersion = null,
    ): Article {
        if (! in_array($action, ['approve', 'reject', 'revoke', 'hold', 'private', 'schedule', 'publish'], true)) {
            throw new RuntimeException('unknown_workflow_action');
        }
        $published = false;
        $outboundFence = null;
        $result = DB::transaction(function () use ($article, $action, $adminId, $note, $expectedVersion, &$published, &$outboundFence) {
            $taskId = (int) Article::query()->whereKey($article->id)->value('task_id');
            $task = $this->lockTaskBeforeArticle($taskId);
            $locked = $this->lockArticleAfterTask((int) $article->id, $taskId);
            $locked->setRelation('task', $task && ! $task->trashed() ? $task : null);
            if ($expectedVersion !== null && $expectedVersion !== (int) $locked->workflow_version) {
                throw new RuntimeException('workflow_version_conflict');
            }
            $eligibility = $this->publicationEligibility;
            if ($action === 'schedule' && (! $task || $task->trashed())) {
                throw new RuntimeException('task_unavailable');
            }
            if ($action === 'publish' && ($locked->review_status === 'rejected'
                || ($eligibility->manualReviewRequired($locked) && ! $eligibility->hasCurrentApproval($locked)))) {
                throw new RuntimeException('article_not_publishable');
            }
            $updates = match ($action) {
                'approve' => ['review_status' => 'approved'],
                'reject' => ['review_status' => 'rejected', 'status' => 'draft', 'published_at' => null, 'publication_intent' => 'hold'],
                'revoke' => ['review_status' => 'pending', 'status' => 'draft', 'published_at' => null, 'publication_intent' => 'hold'],
                'hold', 'private' => ['status' => $action === 'private' ? 'private' : 'draft', 'published_at' => null, 'publication_intent' => 'hold'],
                'schedule' => ['status' => 'draft', 'published_at' => null, 'publication_intent' => 'scheduled'],
                'publish' => ['publication_intent' => 'immediate'],
            };
            if ($action === 'publish' && $locked->status === 'published' && $locked->publication_intent === 'none') {
                $published = true;
                $outboundFence = $eligibility->fence($locked, 'manual');
                $this->publicationDelivery->request($locked, $outboundFence, reconcileCompleted: true);

                return $locked;
            }
            $locked->fill($updates);
            $isReview = in_array($action, ['approve', 'reject', 'revoke'], true);
            $needsReviewRecord = $isReview && ! $locked->reviews()
                ->where('review_status', $updates['review_status'])
                ->where('content_hash', $locked->reviewContentHash())->exists();
            if ($locked->isDirty() || $needsReviewRecord) {
                $locked->workflow_version = (int) $locked->workflow_version + 1;
                $locked->save();
                if ($isReview) {
                    ArticleReview::query()->create([
                        'article_id' => $locked->id,
                        'admin_id' => $adminId,
                        'review_status' => $updates['review_status'],
                        'review_note' => $note,
                        'content_hash' => $locked->reviewContentHash(),
                    ]);
                }
            }
            if (in_array($action, ['hold', 'private', 'reject', 'revoke'], true)) {
                $this->cancelPendingDistributionIntent($locked);
            }
            if ($action !== 'publish') {
                return $locked->refresh();
            }

            try {
                $this->publicationQualityGate->check($locked, 'manual_publish', $adminId, $note);
            } catch (ArticleAiQualityGateException $exception) {
                $check = $exception->getCheck();
                if ($check && in_array($check->status, ['queued', 'running'], true)) {
                    $meta = $check->execution_meta ?? [];
                    $meta['workflow_fence'] = $eligibility->fence($locked, 'manual');
                    $meta['requested_workflow_state'] = ['status' => 'published', 'review_status' => $eligibility->reviewStatus($locked), 'published_at' => null];
                    $check->forceFill(['execution_meta' => $meta])->save();

                    return $locked->refresh();
                }

                return $exception;
            } catch (ArticleRiskGateException $exception) {
                return $exception;
            }
            $state = ArticleWorkflow::normalizeForPublishScope([
                'status' => 'published', 'review_status' => $eligibility->reviewStatus($locked), 'published_at' => now(),
            ], $task?->publish_scope);
            $locked->update($state + ['publication_intent' => 'none']);
            $outboundFence = $eligibility->fence($locked, 'manual');
            $this->publicationDelivery->request($locked, $outboundFence);
            $published = true;

            return $locked->refresh();
        }, 3);
        if ($result instanceof ArticleAiQualityGateException || $result instanceof ArticleRiskGateException) {
            throw $result;
        }

        if ($action === 'approve' && $result->publication_intent === 'immediate') {
            try {
                return $this->humanAction($result, 'publish', $adminId, null, (int) $result->workflow_version);
            } catch (ArticleAiQualityGateException|ArticleRiskGateException $exception) {
                return $result->refresh();
            } catch (RuntimeException $exception) {
                if (! in_array($exception->getMessage(), ['article_not_publishable', 'workflow_version_conflict'], true)) {
                    report($exception);
                }
            }
        }

        return $result;
    }

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

            $eligibility = $this->publicationEligibility;
            if ($trigger === 'worker_publish' && (
                $lockedArticle->publication_intent !== 'scheduled'
                || ! $lockedTask || $lockedTask->status !== 'active' || ! $lockedTask->schedule_enabled
                || ($lockedTask->next_publish_at && $lockedTask->next_publish_at->isFuture())
            )) {
                throw new RuntimeException('article_not_publishable');
            }
            if ($distributionRequested && ($lockedArticle->review_status === 'rejected'
                || ($eligibility->manualReviewRequired($lockedArticle) && ! $eligibility->hasCurrentApproval($lockedArticle)))) {
                throw new RuntimeException('article_not_publishable');
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
                $preservePublishedArticle = (string) $lockedArticle->status === 'published';
                if ($preservePublishedArticle && $this->isDistributionOnly($lockedTask)) {
                    $lockedArticle->update([
                        'status' => 'private',
                        'published_at' => null,
                    ]);
                } elseif ($rejectedWorkflowState !== null && ! $preservePublishedArticle) {
                    $lockedArticle->update([
                        'status' => $rejectedWorkflowState['status'],
                        'review_status' => $eligibility->reviewStatus($lockedArticle),
                        'published_at' => $rejectedWorkflowState['published_at'],
                    ]);
                }

                return $exception;
            }

            $lockedArticle->update([
                'status' => $workflowState['status'],
                'review_status' => $eligibility->reviewStatus($lockedArticle),
                'published_at' => $workflowState['published_at'],
                'publication_intent' => $distributionRequested || $trigger === 'worker_publish'
                    ? 'none' : $lockedArticle->publication_intent,
            ]);

            if ($trigger === 'worker_publish') {
                $this->publicationDelivery->request($lockedArticle, $eligibility->fence($lockedArticle));
            }

            return $lockedArticle->refresh();
        });

        if ($result instanceof ArticleRiskGateException || $result instanceof ArticleAiQualityGateException) {
            throw $result;
        }

        return $result;
    }

    /**
     * Cancel an older publish request without replacing the caller's chosen draft/private state.
     * The caller must hold the article row lock before invoking this method.
     */
    public function cancelPendingDistributionIntent(Article $article): int
    {
        if ($article->publication_intent === 'immediate') {
            $article->update(['publication_intent' => 'hold', 'workflow_version' => (int) $article->workflow_version + 1]);
        }
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

            $executionMeta['requested_workflow_state'] = null;
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
