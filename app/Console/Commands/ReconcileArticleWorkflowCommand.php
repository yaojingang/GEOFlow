<?php

namespace App\Console\Commands;

use App\Models\AiQualityAuditEvent;
use App\Models\Article;
use App\Models\Task;
use App\Services\GeoFlow\AiQualityAuditService;
use App\Services\GeoFlow\ArticlePublicationEligibilityService;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconcileArticleWorkflowCommand extends Command
{
    protected $signature = 'geoflow:reconcile-article-workflow
        {--task= : Limit preview to one task}
        {--article=* : Limit preview to article IDs}
        {--limit=100 : Maximum articles per manifest, at most 500}
        {--manifest= : JSON preview output or reviewed apply input}
        {--apply : Apply the reviewed manifest with locked version checks}';

    protected $description = 'Preview article workflow recovery; apply requires a reviewed manifest and never approves content';

    public function handle(ArticlePublicationEligibilityService $eligibility): int
    {
        $path = (string) $this->option('manifest');
        if (! $this->option('apply')) {
            $articles = Article::query()->with('task')
                ->when($this->option('task'), fn ($query) => $query->where('task_id', (int) $this->option('task')))
                ->when($this->option('article'), fn ($query) => $query->whereIn('id', array_map('intval', $this->option('article'))))
                ->where('status', '!=', 'published')->orderBy('id')
                ->limit(max(1, min(500, (int) $this->option('limit'))))->get();
            $manifest = [
                'format_version' => 1,
                'manifest_id' => (string) Str::uuid(),
                'generated_at' => now()->toIso8601String(),
                'dry_run' => true,
                'items' => $articles->map(fn (Article $article): array => [
                    'article_id' => (int) $article->id,
                    'before' => $this->snapshot($article),
                    'proposed_review_status' => $eligibility->reviewStatus($article),
                    'publication_intent' => $article->publication_intent,
                    'release_hold' => false,
                    'reviewed' => false,
                    'excluded_reason' => $article->review_status === 'rejected' ? 'manual_rejected'
                        : ($article->publication_intent === 'hold' ? 'intent_requires_human_confirmation' : null),
                    'blocking_reasons' => $eligibility->evaluate($article)['blocking_reasons'],
                ])->all(),
            ];
            $json = json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            if ($path !== '') {
                if (file_exists($path)) {
                    $this->error('Manifest already exists; choose a new preview path.');

                    return self::FAILURE;
                }
                if (file_put_contents($path, $json."\n") === false) {
                    return self::FAILURE;
                }
            }
            $this->line($json);

            return self::SUCCESS;
        }
        if ($path === '' || ! is_file($path)) {
            $this->error('--apply requires --manifest with a reviewed preview.');

            return self::FAILURE;
        }
        try {
            $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            $this->error('Invalid manifest JSON.');

            return self::FAILURE;
        }
        $items = $manifest['items'] ?? null;
        if (($manifest['format_version'] ?? null) !== 1 || ! Str::isUuid($manifest['manifest_id'] ?? '')
            || ! is_array($items) || count($items) > 500
            || count(array_unique(array_column($items, 'article_id'))) !== count($items)) {
            $this->error('Invalid manifest format or duplicate article IDs.');

            return self::FAILURE;
        }
        $results = [];
        foreach ($items as $item) {
            $id = (int) ($item['article_id'] ?? 0);
            try {
                $results[] = DB::transaction(function () use ($id, $item, $manifest, $eligibility): array {
                    $result = ['article_id' => $id, 'status' => 'blocked'];
                    $taskId = (int) Article::query()->whereKey($id)->value('task_id');
                    $task = $taskId ? Task::query()->whereKey($taskId)->lockForUpdate()->first() : null;
                    $article = Article::query()->whereKey($id)->lockForUpdate()->first();
                    if (! $article || (int) $article->task_id !== $taskId) {
                        return $result + ['reason' => 'article_unavailable'];
                    }
                    $article->setRelation('task', $task);
                    if (AiQualityAuditEvent::query()->where('event_type', 'article_workflow_reconciled')
                        ->where('correlation_id', $manifest['manifest_id'])->where('article_id', $id)->exists()) {
                        return ['article_id' => $id, 'status' => 'unchanged', 'reason' => 'already_applied'];
                    }
                    if (($item['before'] ?? null) !== $this->snapshot($article)) {
                        return ['article_id' => $id, 'status' => 'conflict', 'reason' => 'preview_changed'];
                    }
                    if (($item['reviewed'] ?? false) !== true) {
                        return $result + ['reason' => 'manifest_review_required'];
                    }
                    if ($article->review_status === 'rejected' || $article->status === 'published') {
                        return $result + ['reason' => 'manual_rejected_or_published'];
                    }
                    $before = $this->snapshot($article);
                    if (($item['release_hold'] ?? false) === true) {
                        if (! $task || $article->publication_intent !== 'hold') {
                            return $result + ['reason' => 'hold_release_not_applicable'];
                        }
                        $article = app(ArticleWorkflowTransitionService::class)->humanAction(
                            $article, 'schedule', expectedVersion: (int) $article->workflow_version,
                        );
                    } elseif ($article->publication_intent === 'hold') {
                        return $result + ['reason' => 'human_hold_preserved'];
                    }
                    $reviewStatus = $eligibility->reviewStatus($article);
                    if ($reviewStatus !== $article->review_status) {
                        $article->update(['review_status' => $reviewStatus, 'workflow_version' => (int) $article->workflow_version + 1]);
                    }
                    $after = $this->snapshot($article->fresh());
                    app(AiQualityAuditService::class)->record('article_workflow_reconciled', [
                        'correlation_id' => $manifest['manifest_id'], 'article_id' => $id, 'task_id' => $taskId ?: null,
                        'before_hash' => hash('sha256', json_encode($before)), 'after_hash' => hash('sha256', json_encode($after)),
                        'reason_code' => 'reviewed_recovery_manifest',
                        'metadata' => ['release_hold' => (bool) ($item['release_hold'] ?? false), 'workflow_version' => (int) $article->workflow_version],
                    ]);

                    return ['article_id' => $id, 'status' => $before === $after ? 'unchanged' : 'success', 'before' => $before, 'after' => $after];
                }, 3);
            } catch (\Throwable $exception) {
                report($exception);
                $results[] = ['article_id' => $id, 'status' => 'failed', 'reason' => 'recovery_failed'];
            }
        }
        $this->line(json_encode(['dry_run' => false, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return collect($results)->contains('status', 'failed') ? self::FAILURE : self::SUCCESS;
    }

    private function snapshot(Article $article): array
    {
        return [
            'task_id' => $article->task_id ? (int) $article->task_id : null,
            'workflow_version' => (int) $article->workflow_version,
            'article_policy_hash' => hash('sha256', json_encode($article->only([
                'ai_quality_policy_version', 'ai_quality_retrieval_mode_override',
                'ai_quality_policy_snapshot', 'ai_quality_required_at_creation',
            ]), JSON_THROW_ON_ERROR)),
            'status' => $article->status, 'review_status' => $article->review_status,
            'publication_intent' => $article->publication_intent,
            'content_hash' => $article->reviewContentHash(),
            'policy_hash' => hash('sha256', json_encode($article->task?->only([
                'automation_version', 'ai_quality_config_version', 'status', 'schedule_enabled',
                'need_review', 'ai_quality_enabled', 'publish_scope', 'publish_interval',
            ]) ?? $article->ai_quality_policy_snapshot, JSON_THROW_ON_ERROR)),
        ];
    }
}
