<?php

namespace App\Services\Admin\Analytics;

use App\Support\Analytics\TrafficClassifier;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyticsLogQueryService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(AnalyticsLogFilter $logFilter, AnalyticsFilter $contentFilter): array
    {
        if (! Schema::hasTable('view_logs')) {
            return $this->emptySummary($logFilter);
        }

        $metrics = $this->metrics($logFilter, $contentFilter);
        $topArticles = $this->topArticles($logFilter, $contentFilter);

        return [
            'has_data' => $metrics['pv'] > 0,
            'kpis' => [
                'pv' => $metrics['pv'],
                'unique_ip' => $metrics['unique_ip'],
                'ai_bot_pv' => $metrics['ai_bot_pv'],
                'errors' => $metrics['errors'],
            ],
            'excluded_source_rows' => $this->excludedSourceRows($logFilter, $contentFilter),
            'traffic_trend' => $this->trafficTrend($logFilter, $contentFilter),
            'bot_breakdown' => $this->botBreakdown($metrics),
            'top_paths' => $this->topPaths($logFilter, $contentFilter, $topArticles),
            'top_articles' => $topArticles,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(AnalyticsLogFilter $logFilter): array
    {
        $metrics = [
            'pv' => 0,
            'unique_ip' => 0,
            'ai_bot_pv' => 0,
            'errors' => 0,
            TrafficClassifier::HUMAN => 0,
            TrafficClassifier::SEARCH_BOT => 0,
            TrafficClassifier::AI_BOT => 0,
            TrafficClassifier::OTHER_BOT => 0,
            TrafficClassifier::UNKNOWN => 0,
        ];

        return [
            'has_data' => false,
            'kpis' => array_intersect_key($metrics, array_flip(['pv', 'unique_ip', 'ai_bot_pv', 'errors'])),
            'excluded_source_rows' => 0,
            'traffic_trend' => array_map(fn (Carbon $day): array => $this->emptyTrendDay($day), $this->days($logFilter)),
            'bot_breakdown' => $this->botBreakdown($metrics),
            'top_paths' => [],
            'top_articles' => [],
        ];
    }

    /**
     * @return array{pv: int, unique_ip: int, ai_bot_pv: int, errors: int, human: int, search_bot: int, ai_bot: int, other_bot: int, unknown: int}
     */
    private function metrics(AnalyticsLogFilter $logFilter, AnalyticsFilter $contentFilter): array
    {
        $query = DB::query()->fromSub($this->classifiedQuery($logFilter, $contentFilter), 'classified');
        $this->applySelectedTraffic($query, $logFilter);

        $row = $query
            ->selectRaw('COUNT(*) AS pv')
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(ip_address), '')) AS unique_ip")
            ->selectRaw('SUM(CASE WHEN traffic_type = ? THEN 1 ELSE 0 END) AS ai_bot_pv', [TrafficClassifier::AI_BOT])
            ->selectRaw('SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) AS errors')
            ->selectRaw('SUM(CASE WHEN traffic_type = ? THEN 1 ELSE 0 END) AS human', [TrafficClassifier::HUMAN])
            ->selectRaw('SUM(CASE WHEN traffic_type = ? THEN 1 ELSE 0 END) AS search_bot', [TrafficClassifier::SEARCH_BOT])
            ->selectRaw('SUM(CASE WHEN traffic_type = ? THEN 1 ELSE 0 END) AS ai_bot', [TrafficClassifier::AI_BOT])
            ->selectRaw('SUM(CASE WHEN traffic_type = ? THEN 1 ELSE 0 END) AS other_bot', [TrafficClassifier::OTHER_BOT])
            ->selectRaw('SUM(CASE WHEN traffic_type = ? THEN 1 ELSE 0 END) AS unknown', [TrafficClassifier::UNKNOWN])
            ->first();

        return [
            'pv' => (int) ($row->pv ?? 0),
            'unique_ip' => (int) ($row->unique_ip ?? 0),
            'ai_bot_pv' => (int) ($row->ai_bot_pv ?? 0),
            'errors' => (int) ($row->errors ?? 0),
            TrafficClassifier::HUMAN => (int) ($row->human ?? 0),
            TrafficClassifier::SEARCH_BOT => (int) ($row->search_bot ?? 0),
            TrafficClassifier::AI_BOT => (int) ($row->ai_bot ?? 0),
            TrafficClassifier::OTHER_BOT => (int) ($row->other_bot ?? 0),
            TrafficClassifier::UNKNOWN => (int) ($row->unknown ?? 0),
        ];
    }

    /**
     * @return list<array{date: string, pv: int, unique_ip: int, ai_bot_pv: int, errors: int}>
     */
    private function trafficTrend(AnalyticsLogFilter $logFilter, AnalyticsFilter $contentFilter): array
    {
        $query = DB::query()->fromSub($this->classifiedQuery($logFilter, $contentFilter), 'classified');
        $this->applySelectedTraffic($query, $logFilter);

        $rows = $query
            ->selectRaw('DATE(logged_at) AS trend_date')
            ->selectRaw('COUNT(*) AS pv')
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(ip_address), '')) AS unique_ip")
            ->selectRaw('SUM(CASE WHEN traffic_type = ? THEN 1 ELSE 0 END) AS ai_bot_pv', [TrafficClassifier::AI_BOT])
            ->selectRaw('SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) AS errors')
            ->groupByRaw('DATE(logged_at)')
            ->orderBy('trend_date')
            ->get()
            ->keyBy(fn (object $row): string => Carbon::parse((string) $row->trend_date)->toDateString());

        return array_map(function (Carbon $day) use ($rows): array {
            $date = $day->toDateString();
            $row = $rows->get($date);
            if ($row === null) {
                return $this->emptyTrendDay($day);
            }

            return [
                'date' => $date,
                'pv' => (int) $row->pv,
                'unique_ip' => (int) $row->unique_ip,
                'ai_bot_pv' => (int) $row->ai_bot_pv,
                'errors' => (int) $row->errors,
            ];
        }, $this->days($logFilter));
    }

    /**
     * @param  array<string, int>  $metrics
     * @return list<array{key: string, label: string, count: int}>
     */
    private function botBreakdown(array $metrics): array
    {
        return array_map(fn (string $type): array => [
            'key' => $type,
            'label' => __('admin.analytics.logs_bot.'.$type),
            'count' => (int) ($metrics[$type] ?? 0),
        ], [
            TrafficClassifier::HUMAN,
            TrafficClassifier::SEARCH_BOT,
            TrafficClassifier::AI_BOT,
            TrafficClassifier::OTHER_BOT,
            TrafficClassifier::UNKNOWN,
        ]);
    }

    /**
     * @return list<array{article_id: int, title: string, slug: string, views: int, unique_ip: int}>
     */
    private function topArticles(AnalyticsLogFilter $logFilter, AnalyticsFilter $contentFilter): array
    {
        $query = DB::query()
            ->fromSub($this->classifiedQuery($logFilter, $contentFilter), 'classified')
            ->whereNotNull('resolved_article_id')
            ->whereNull('article_deleted_at');
        $this->applySelectedTraffic($query, $logFilter);

        return $query
            ->select('article_id', 'article_title', 'article_slug')
            ->selectRaw('COUNT(*) AS views')
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(ip_address), '')) AS unique_ip")
            ->groupBy('article_id', 'article_title', 'article_slug')
            ->orderByDesc('views')
            ->orderBy('article_id')
            ->limit(8)
            ->get()
            ->map(fn (object $row): array => [
                'article_id' => (int) $row->article_id,
                'title' => (string) ($row->article_title ?: '#'.$row->article_id),
                'slug' => (string) ($row->article_slug ?? ''),
                'views' => (int) $row->views,
                'unique_ip' => (int) $row->unique_ip,
            ])
            ->all();
    }

    /**
     * @param  list<array{article_id: int, title: string, slug: string, views: int, unique_ip: int}>  $topArticles
     * @return list<array{path: string, views: int, unique_ip: int}>
     */
    private function topPaths(AnalyticsLogFilter $logFilter, AnalyticsFilter $contentFilter, array $topArticles): array
    {
        $query = DB::query()
            ->fromSub($this->classifiedQuery($logFilter, $contentFilter), 'classified')
            ->whereRaw("TRIM(COALESCE(path, '')) != ''");
        $this->applySelectedTraffic($query, $logFilter);

        $paths = $query
            ->select('path')
            ->selectRaw('COUNT(*) AS views')
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(ip_address), '')) AS unique_ip")
            ->groupBy('path')
            ->orderByDesc('views')
            ->orderBy('path')
            ->limit(8)
            ->get()
            ->map(fn (object $row): array => [
                'path' => (string) $row->path,
                'views' => (int) $row->views,
                'unique_ip' => (int) $row->unique_ip,
            ])
            ->all();

        if ($paths !== []) {
            return $paths;
        }

        return array_map(fn (array $article): array => [
            'path' => $article['slug'] !== '' ? '/article/'.$article['slug'] : '/article/'.$article['article_id'],
            'views' => $article['views'],
            'unique_ip' => $article['unique_ip'],
        ], $topArticles);
    }

    private function excludedSourceRows(AnalyticsLogFilter $logFilter, AnalyticsFilter $contentFilter): int
    {
        return (int) $this->baseQuery($logFilter, $contentFilter, false)
            ->where(function (Builder $query): void {
                $query
                    ->whereNull('view_logs.source')
                    ->orWhereNotIn('view_logs.source', AnalyticsLogFilter::supportedSources());
            })
            ->count();
    }

    private function classifiedQuery(AnalyticsLogFilter $logFilter, AnalyticsFilter $contentFilter): Builder
    {
        [$classificationSql, $bindings] = $this->classificationCase();

        return $this->baseQuery($logFilter, $contentFilter)
            ->select([
                'view_logs.created_at as logged_at',
                'view_logs.ip_address',
                'view_logs.status_code',
                'view_logs.path',
                'view_logs.article_id',
                'a.id as resolved_article_id',
                'a.title as article_title',
                'a.slug as article_slug',
                'a.deleted_at as article_deleted_at',
            ])
            ->selectRaw($classificationSql.' AS traffic_type', $bindings);
    }

    private function baseQuery(AnalyticsLogFilter $logFilter, AnalyticsFilter $contentFilter, bool $supportedSourcesOnly = true): Builder
    {
        $query = DB::table('view_logs')
            ->leftJoin('articles as a', 'view_logs.article_id', '=', 'a.id')
            ->whereBetween('view_logs.created_at', [$logFilter->start(), $logFilter->end()])
            ->where('view_logs.method', 'GET');

        if ($supportedSourcesOnly) {
            if ($logFilter->source === 'all') {
                $query->whereIn('view_logs.source', AnalyticsLogFilter::supportedSources());
            } else {
                $query->where('view_logs.source', $logFilter->source);
            }
        }

        if ($contentFilter->articleId !== null) {
            $query->where('view_logs.article_id', $contentFilter->articleId);
        }
        if ($contentFilter->taskId !== null) {
            $query->where('a.task_id', $contentFilter->taskId);
        }
        if ($contentFilter->categoryId !== null) {
            $query->where('a.category_id', $contentFilter->categoryId);
        }

        return $query;
    }

    private function applySelectedTraffic(Builder $query, AnalyticsLogFilter $logFilter): void
    {
        if ($logFilter->trafficType !== 'all') {
            $query->where('traffic_type', $logFilter->trafficType);
        }
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function classificationCase(): array
    {
        [$aiSql, $aiBindings] = $this->patternCondition(TrafficClassifier::aiBotPatterns());
        [$searchSql, $searchBindings] = $this->patternCondition(TrafficClassifier::searchBotPatterns());
        [$otherSql, $otherBindings] = $this->patternCondition(TrafficClassifier::otherBotPatterns());

        return [
            "CASE
                WHEN TRIM(COALESCE(view_logs.user_agent, '')) = '' THEN 'unknown'
                WHEN {$aiSql} THEN 'ai_bot'
                WHEN {$searchSql} THEN 'search_bot'
                WHEN {$otherSql} THEN 'other_bot'
                ELSE 'human'
            END",
            [...$aiBindings, ...$searchBindings, ...$otherBindings],
        ];
    }

    /**
     * @param  list<string>  $patterns
     * @return array{0: string, 1: list<string>}
     */
    private function patternCondition(array $patterns): array
    {
        return [
            '('.implode(' OR ', array_fill(0, count($patterns), "LOWER(COALESCE(view_logs.user_agent, '')) LIKE ?")).')',
            array_map(fn (string $pattern): string => '%'.$pattern.'%', $patterns),
        ];
    }

    /**
     * @return array{date: string, pv: int, unique_ip: int, ai_bot_pv: int, errors: int}
     */
    private function emptyTrendDay(Carbon $day): array
    {
        return [
            'date' => $day->toDateString(),
            'pv' => 0,
            'unique_ip' => 0,
            'ai_bot_pv' => 0,
            'errors' => 0,
        ];
    }

    /**
     * @return list<Carbon>
     */
    private function days(AnalyticsLogFilter $logFilter): array
    {
        $days = [];
        $cursor = $logFilter->dateFrom->copy()->startOfDay();
        $end = $logFilter->dateTo->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        return $days;
    }
}
