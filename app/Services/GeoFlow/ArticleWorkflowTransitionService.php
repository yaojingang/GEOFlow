<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Models\Article;
use Illuminate\Support\Facades\DB;

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
            $lockedArticle = Article::query()
                ->whereKey($article->getKey())
                ->lockForUpdate()
                ->firstOrFail();

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
                if ($rejectedWorkflowState !== null && ! $preservePublishedArticle) {
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
}
