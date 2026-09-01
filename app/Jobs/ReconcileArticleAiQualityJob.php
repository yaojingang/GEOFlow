<?php

namespace App\Jobs;

use App\Models\AiModel;
use App\Models\Article;
use App\Services\GeoFlow\ArticleAiQualityBackfillGuard;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleAiQualityPolicyResolver;
use App\Services\GeoFlow\ArticleAiQualityReconciliationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileArticleAiQualityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 70;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $minimumArticleId = 0,
        public readonly int $maximumArticleId = 0,
        public readonly int $limit = 100,
        /** @var list<int> */
        public readonly array $articleIds = [],
    ) {}

    public function handle(ArticleAiQualityInspectionService $inspection): void
    {
        $reconciliation = app(ArticleAiQualityReconciliationService::class);
        if ($this->articleIds === []) {
            $reconciliation->recoverCompletedWorkflows($this->limit);
        } else {
            $reconciliation->recoverCompletedWorkflowsForArticles($this->articleIds, $this->limit);
        }
        $guard = app(ArticleAiQualityBackfillGuard::class);
        $ruleVersion = (string) ($inspection->rules()['version'] ?? '');
        $ruleVersionExpression = $this->ruleVersionExpression();
        $batchLimit = max(1, min(25, $this->limit));
        $articles = Article::query()
            ->with('latestAiQualityCheck')
            ->when($this->articleIds !== [], fn ($query) => $query->whereIn('id', $this->articleIds))
            ->when($this->minimumArticleId > 0, fn ($query) => $query->where('id', '>=', $this->minimumArticleId))
            ->when($this->maximumArticleId > 0, fn ($query) => $query->where('id', '<=', $this->maximumArticleId))
            ->whereIn('status', ['draft', 'private', 'published'])
            ->where(function ($query): void {
                $query->where('ai_quality_required_at_creation', true)
                    ->orWhereHas('task', fn ($task) => $task->where('ai_quality_enabled', true));
            })
            ->where(function ($query) use ($ruleVersion, $ruleVersionExpression): void {
                $query->whereDoesntHave('aiQualityChecks')
                    ->orWhereHas('latestAiQualityCheck', function ($check) use ($ruleVersion, $ruleVersionExpression): void {
                        $check->where(function ($candidate) use ($ruleVersion, $ruleVersionExpression): void {
                            $candidate->whereIn('status', ['stale', 'cancelled'])
                                ->orWhereRaw("COALESCE({$ruleVersionExpression}, '') <> ?", [$ruleVersion]);
                        });
                    });
            })
            ->orderBy('id')
            ->limit($batchLimit)
            ->get();

        foreach ($articles as $article) {
            try {
                $policy = app(ArticleAiQualityPolicyResolver::class)->resolve($article);
                $model = ($policy['model'] ?? null) instanceof AiModel ? $policy['model'] : null;
                if ($guard->pauseReason($model) !== null) {
                    if ($this->isFullBackfill()) {
                        $guard->preserveCursor((int) $article->id);
                    }

                    return;
                }
                $inspection->createOrReuse($article, trigger: 'reconcile', force: true);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        $lastArticleId = (int) ($articles->last()?->id ?? 0);
        if ($this->articleIds === []
            && $articles->count() === $batchLimit
            && $lastArticleId > 0
            && ($this->maximumArticleId === 0 || $lastArticleId < $this->maximumArticleId)) {
            self::dispatch($lastArticleId + 1, $this->maximumArticleId, $batchLimit)
                ->onConnection('redis')
                ->onQueue((string) config('geoflow.ai_quality_backfill_queue', 'ai-quality-backfill'));
            if ($this->isFullBackfill()) {
                $guard->preserveCursor($lastArticleId + 1);
            }
        } elseif ($this->isFullBackfill()) {
            $guard->clearCursor();
        }
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['ai-quality', 'ai-quality-reconcile'];
    }

    private function ruleVersionExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "(article_ai_quality_checks.advertising_rules_snapshot::jsonb ->> 'version')",
            'mysql', 'mariadb' => "JSON_UNQUOTE(JSON_EXTRACT(article_ai_quality_checks.advertising_rules_snapshot, '$.version'))",
            'sqlsrv' => "JSON_VALUE(article_ai_quality_checks.advertising_rules_snapshot, '$.version')",
            default => "json_extract(article_ai_quality_checks.advertising_rules_snapshot, '$.version')",
        };
    }

    private function isFullBackfill(): bool
    {
        return $this->articleIds === [] && $this->maximumArticleId === 0;
    }
}
