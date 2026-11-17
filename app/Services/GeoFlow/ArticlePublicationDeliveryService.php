<?php

namespace App\Services\GeoFlow;

use App\Jobs\RecoverPublicationHandoffJob;
use App\Models\Article;
use App\Models\DistributionLog;
use App\Models\Task;
use App\Support\GeoFlow\DistributionErrorSanitizer;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable publication handoff, using the existing distribution audit ledger. */
class ArticlePublicationDeliveryService
{
    public const EVENT = 'publication.delivery_handoff';

    /** The caller holds the article lock inside its publication transaction. */
    public function request(Article $article, array $fence, bool $reconcileCompleted = false): void
    {
        if (DB::transactionLevel() === 0) {
            throw new \LogicException('Publication handoff requires the publication transaction.');
        }
        $receipt = DistributionLog::query()->where('event', self::EVENT)->where('article_id', $article->id)
            ->where('context->fence->workflow_version', $fence['workflow_version'])
            ->where('context->fence->automation_version', $fence['automation_version'])
            ->latest('id')->first();
        $receipt ??= DistributionLog::query()->create([
            'article_id' => $article->id, 'level' => 'info', 'event' => self::EVENT,
            'message' => 'Publication committed; delivery handoff pending.',
            'context' => ['fence' => $fence, 'status' => 'pending', 'attempts' => 0], 'created_at' => now(),
        ]);
        if (data_get($receipt->context, 'status') === 'exhausted'
            || ($reconcileCompleted && data_get($receipt->context, 'status') === 'completed')) {
            $receipt->update(['context' => array_replace((array) $receipt->context, ['status' => 'pending', 'attempts' => 0, 'next_at' => null])]);
        }
        DB::afterCommit(fn () => $this->attempt((int) $receipt->id));
    }

    /** Rebind outstanding automatic handoffs only after an explicit task resume. */
    public function resumeForTask(int $taskId): void
    {
        foreach (DistributionLog::query()->where('event', self::EVENT)->where('context->fence->task_id', $taskId)
            ->where('context->status', '!=', 'completed')->lazyById(100) as $candidate) {
            DB::transaction(function () use ($taskId, $candidate): void {
                $task = Task::query()->whereKey($taskId)->lockForUpdate()->first();
                $article = Article::query()->whereKey($candidate->article_id)->lockForUpdate()->first();
                $receipt = DistributionLog::query()->whereKey($candidate->id)->lockForUpdate()->first();
                $context = (array) $receipt?->context;
                $fence = $context['fence'] ?? [];
                if (! $task || ! $article || ! $receipt || $task->status !== 'active' || ! $task->schedule_enabled
                    || (int) $article->task_id !== $taskId || ($fence['origin'] ?? '') !== 'automatic'
                    || (int) ($fence['workflow_version'] ?? 0) !== (int) $article->workflow_version
                    || (int) ($fence['automation_version'] ?? 0) === (int) $task->automation_version
                    || ! in_array($article->status, ['published', 'private'], true) || $article->publication_intent !== 'none'
                    || $article->review_status === 'rejected' || ($context['status'] ?? '') === 'completed') {
                    return;
                }
                $article->setRelation('task', $task);
                $context['fence'] = app(ArticlePublicationEligibilityService::class)->fence($article);
                $context['status'] = 'pending';
                $context['attempts'] = 0;
                $context['next_at'] = null;
                unset($context['lease']);
                $receipt->update(['context' => $context]);
                DB::afterCommit(fn () => $this->attempt((int) $receipt->id));
            });
        }
    }

    public function recoverPending(int $limit = 100): int
    {
        $recovered = 0;
        foreach (DistributionLog::query()->where('event', self::EVENT)
            ->whereIn('context->status', ['pending', 'sending'])
            ->where(fn ($query) => $query->whereNull('context->next_at')->orWhere('context->next_at', '<=', now()->toIso8601String()))
            ->orderBy('id')->limit(max(1, min(100, $limit)))->get() as $receipt) {
            try {
                $recovered += (int) $this->attempt((int) $receipt->id);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $recovered;
    }

    public function attempt(int $receiptId, ?string $expectedRetryAt = null): bool
    {
        $claim = DB::transaction(function () use ($receiptId, $expectedRetryAt): ?array {
            $receipt = DistributionLog::query()->whereKey($receiptId)->lockForUpdate()->first();
            $context = (array) $receipt?->context;
            if (! $receipt || $receipt->event !== self::EVENT
                || ! in_array($context['status'] ?? '', ['pending', 'sending'], true)
                || ($expectedRetryAt !== null && ($context['next_at'] ?? null) !== $expectedRetryAt)
                || (! empty($context['next_at']) && now()->lt($context['next_at']))) {
                return null;
            }
            if ((int) ($context['attempts'] ?? 0) >= 3) {
                $context['status'] = 'exhausted';
                $context['next_at'] = null;
                $context['error'] = 'publication_handoff_retry_exhausted';
                unset($context['lease']);
                $receipt->update(['context' => $context, 'level' => 'warning']);

                return null;
            }
            $context['status'] = 'sending';
            $context['lease'] = (string) Str::uuid();
            $context['attempts'] = (int) ($context['attempts'] ?? 0) + 1;
            $context['next_at'] = now()->addMinutes(5)->toIso8601String();
            $receipt->update(['context' => $context]);

            return [(int) $receipt->article_id, $context];
        });
        if ($claim === null) {
            return false;
        }
        [$articleId, $context] = $claim;
        try {
            app(DistributionOrchestrator::class)->enqueueForArticle($articleId, throwOnFailure: true, workflowFence: $context['fence']);
            $context['status'] = 'completed';
            $context['next_at'] = null;
            unset($context['error']);
        } catch (\Throwable $exception) {
            $context['status'] = $exception->getMessage() === 'distribution_workflow_superseded' ? 'superseded'
                : ($context['attempts'] >= 3 ? 'exhausted' : 'pending');
            $context['error'] = DistributionErrorSanitizer::from($exception);
            $context['next_at'] = $context['status'] === 'pending'
                ? now()->addSeconds($context['attempts'] === 1 ? 60 : 300)->toIso8601String() : null;
            report($exception);
        }
        $updated = DistributionLog::query()->whereKey($receiptId)->where('context->lease', $context['lease'])
            ->update(['context' => json_encode($context, JSON_THROW_ON_ERROR), 'level' => $context['status'] === 'completed' ? 'info' : 'warning']);
        if ($updated && $context['status'] === 'pending') {
            $this->scheduleHandoffRetry($receiptId, $context['next_at']);
        }

        return $updated > 0 && $context['status'] === 'completed';
    }

    /**
     * 等到交接回执的 next_at 再重试这一条，避免周期扫描全部回执。
     */
    public function scheduleHandoffRetry(int $receiptId, string $nextAt): void
    {
        try {
            $moment = Date::parse($nextAt);
            RecoverPublicationHandoffJob::dispatch($receiptId, $nextAt)->delay($moment > now() ? $moment : now());
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}
