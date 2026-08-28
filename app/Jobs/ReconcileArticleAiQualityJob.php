<?php

namespace App\Jobs;

use App\Models\Article;
use App\Models\ArticleAiQualityCheck;
use App\Services\GeoFlow\ArticleAiQualityFingerprint;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class ReconcileArticleAiQualityJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 180;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $minimumArticleId = 0,
        public readonly int $maximumArticleId = 0,
        public readonly int $limit = 100,
    ) {}

    public function uniqueId(): string
    {
        return $this->minimumArticleId.':'.$this->maximumArticleId;
    }

    public function handle(ArticleAiQualityInspectionService $inspection): void
    {
        $ruleVersion = (string) ($inspection->rules()['version'] ?? '');
        $ruleVersionExpression = $this->ruleVersionExpression();
        $stuckBefore = now()->subMinutes(15);

        $batchLimit = max(1, min(500, $this->limit));
        $articles = Article::query()
            ->with('latestAiQualityCheck')
            ->when($this->minimumArticleId > 0, fn ($query) => $query->where('id', '>=', $this->minimumArticleId))
            ->when($this->maximumArticleId > 0, fn ($query) => $query->where('id', '<=', $this->maximumArticleId))
            ->whereIn('status', ['draft', 'private', 'published'])
            ->where(function ($query): void {
                $query->where('ai_quality_required_at_creation', true)
                    ->orWhereHas('task', fn ($task) => $task->where('ai_quality_enabled', true));
            })
            ->where(function ($query) use ($ruleVersion, $ruleVersionExpression, $stuckBefore): void {
                $query->whereDoesntHave('aiQualityChecks')
                    ->orWhereHas('latestAiQualityCheck', function ($check) use ($ruleVersion, $ruleVersionExpression, $stuckBefore): void {
                        $check->where(function ($candidate) use ($ruleVersion, $ruleVersionExpression, $stuckBefore): void {
                            $candidate->whereIn('status', ['stale', 'failed', 'cancelled'])
                                ->orWhere(function ($stuck) use ($stuckBefore): void {
                                    $stuck->whereIn('status', ['queued', 'running'])
                                        ->where('updated_at', '<=', $stuckBefore);
                                })
                                ->orWhere('algorithm_version', '!=', ArticleAiQualityFingerprint::ALGORITHM_VERSION)
                                ->orWhereRaw("COALESCE({$ruleVersionExpression}, '') <> ?", [$ruleVersion]);
                        });
                    });
            })
            ->orderBy('id')
            ->limit($batchLimit)
            ->get();

        $articles->each(function (Article $article) use ($inspection): void {
            try {
                $latest = $article->latestAiQualityCheck;
                if ($latest instanceof ArticleAiQualityCheck
                    && in_array((string) $latest->status, ['queued', 'running'], true)) {
                    $inspection->recoverStuckCheck($latest);

                    return;
                }
                $inspection->createOrReuse($article, trigger: 'reconcile', force: true);
            } catch (Throwable $exception) {
                report($exception);
            }
        });

        $lastArticleId = (int) ($articles->last()?->id ?? 0);
        if ($articles->count() === $batchLimit
            && $lastArticleId > 0
            && ($this->maximumArticleId === 0 || $lastArticleId < $this->maximumArticleId)) {
            self::dispatch($lastArticleId + 1, $this->maximumArticleId, $batchLimit)
                ->onQueue('geoflow');
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
}
