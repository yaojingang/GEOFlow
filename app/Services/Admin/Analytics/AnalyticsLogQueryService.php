<?php

namespace App\Services\Admin\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnalyticsLogQueryService
{
    private const AI_BOT_PATTERNS = [
        'chatgpt',
        'gptbot',
        'openai',
        'claudebot',
        'anthropic',
        'perplexity',
        'ccbot',
        'google-extended',
    ];

    private const SEARCH_BOT_PATTERNS = [
        'googlebot',
        'bingbot',
        'baiduspider',
        'bytespider',
        'yandexbot',
        'duckduckbot',
        'sogou',
        'slurp',
    ];

    private const GENERIC_BOT_PATTERNS = [
        'bot',
        'spider',
        'crawler',
    ];

    /**
     * @return array<string, mixed>
     */
    public function summary(AnalyticsFilter $filter): array
    {
        if (! in_array($filter->logSource, ['all', 'local'], true) || ! Schema::hasTable('view_logs')) {
            return $this->emptySummary();
        }

        $base = $this->baseLocalQuery($filter);
        $this->applyTrafficFilter($base, $filter->trafficType);

        $pv = (int) (clone $base)->count();

        return [
            'has_data' => $pv > 0,
            'kpis' => [
                'pv' => $pv,
                'unique_ip' => (int) (clone $base)->where('view_logs.ip_address', '!=', '')->distinct()->count('view_logs.ip_address'),
                'ai_bot_pv' => (int) $this->withTrafficType(clone $base, 'ai_bot')->count(),
                'errors' => $this->errorCount(clone $base),
            ],
            'traffic_trend' => $this->trafficTrend($filter),
            'bot_breakdown' => $this->botBreakdown($filter),
            'top_paths' => $this->topPaths($filter),
            'top_articles' => $this->topArticles($filter),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'has_data' => false,
            'kpis' => [
                'pv' => 0,
                'unique_ip' => 0,
                'ai_bot_pv' => 0,
                'errors' => 0,
            ],
            'traffic_trend' => [],
            'bot_breakdown' => [],
            'top_paths' => [],
            'top_articles' => [],
        ];
    }

    private function baseLocalQuery(AnalyticsFilter $filter): Builder
    {
        $query = DB::table('view_logs')
            ->leftJoin('articles as a', 'view_logs.article_id', '=', 'a.id')
            ->whereBetween('view_logs.created_at', [$filter->start(), $filter->end()]);

        if ($filter->articleId !== null) {
            $query->where('view_logs.article_id', $filter->articleId);
        }
        if ($filter->taskId !== null) {
            $query->where('a.task_id', $filter->taskId);
        }
        if ($filter->categoryId !== null) {
            $query->where('a.category_id', $filter->categoryId);
        }

        return $query;
    }

    /**
     * @return list<array{date: string, pv: int, ai_bot_pv: int}>
     */
    private function trafficTrend(AnalyticsFilter $filter): array
    {
        return array_map(function (Carbon $day) use ($filter): array {
            $query = $this->baseLocalQuery($filter)
                ->whereBetween('view_logs.created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
            $this->applyTrafficFilter($query, $filter->trafficType);

            return [
                'date' => $day->toDateString(),
                'pv' => (int) (clone $query)->count(),
                'ai_bot_pv' => (int) $this->withTrafficType(clone $query, 'ai_bot')->count(),
            ];
        }, $this->days($filter));
    }

    /**
     * @return list<array{key: string, label: string, count: int}>
     */
    private function botBreakdown(AnalyticsFilter $filter): array
    {
        $base = $this->baseLocalQuery($filter);
        if ($filter->trafficType !== 'all') {
            $this->applyTrafficFilter($base, $filter->trafficType);
        }

        return array_map(fn (string $type): array => [
            'key' => $type,
            'label' => __('admin.analytics.logs_bot.'.$type),
            'count' => (int) $this->withTrafficType(clone $base, $type)->count(),
        ], ['human', 'search_bot', 'ai_bot', 'other_bot', 'unknown']);
    }

    /**
     * @return list<array{article_id: int, title: string, slug: string, views: int, unique_ip: int}>
     */
    private function topArticles(AnalyticsFilter $filter): array
    {
        $query = $this->baseLocalQuery($filter)
            ->whereNotNull('view_logs.article_id')
            ->select('view_logs.article_id', 'a.title', 'a.slug')
            ->selectRaw('COUNT(*) as views')
            ->selectRaw('COUNT(DISTINCT view_logs.ip_address) as unique_ip')
            ->groupBy('view_logs.article_id', 'a.title', 'a.slug')
            ->orderByDesc('views')
            ->limit(8);
        $this->applyTrafficFilter($query, $filter->trafficType);

        return $query->get()
            ->map(fn ($row): array => [
                'article_id' => (int) $row->article_id,
                'title' => (string) ($row->title ?: '#'.$row->article_id),
                'slug' => (string) ($row->slug ?? ''),
                'views' => (int) $row->views,
                'unique_ip' => (int) $row->unique_ip,
            ])
            ->all();
    }

    /**
     * @return list<array{path: string, views: int, unique_ip: int}>
     */
    private function topPaths(AnalyticsFilter $filter): array
    {
        if (Schema::hasColumn('view_logs', 'path')) {
            $query = $this->baseLocalQuery($filter)
                ->whereRaw("TRIM(COALESCE(view_logs.path, '')) != ''")
                ->select('view_logs.path')
                ->selectRaw('COUNT(*) as views')
                ->selectRaw('COUNT(DISTINCT view_logs.ip_address) as unique_ip')
                ->groupBy('view_logs.path')
                ->orderByDesc('views')
                ->limit(8);
            $this->applyTrafficFilter($query, $filter->trafficType);

            $paths = $query->get()
                ->map(fn ($row): array => [
                    'path' => (string) $row->path,
                    'views' => (int) $row->views,
                    'unique_ip' => (int) $row->unique_ip,
                ])
                ->all();

            if ($paths !== []) {
                return $paths;
            }
        }

        return array_map(fn (array $article): array => [
            'path' => $article['slug'] !== '' ? '/article/'.$article['slug'] : '/article/'.$article['article_id'],
            'views' => $article['views'],
            'unique_ip' => $article['unique_ip'],
        ], $this->topArticles($filter));
    }

    private function errorCount(Builder $query): int
    {
        if (! Schema::hasColumn('view_logs', 'status_code')) {
            return 0;
        }

        return (int) $query->where('view_logs.status_code', '>=', 400)->count();
    }

    private function withTrafficType(Builder $query, string $trafficType): Builder
    {
        $this->applyTrafficFilter($query, $trafficType);

        return $query;
    }

    private function applyTrafficFilter(Builder $query, string $trafficType): void
    {
        match ($trafficType) {
            'human' => $this->whereHuman($query),
            'search_bot' => $this->whereAnyPattern($query, self::SEARCH_BOT_PATTERNS),
            'ai_bot' => $this->whereAnyPattern($query, self::AI_BOT_PATTERNS),
            'other_bot' => $this->whereOtherBot($query),
            'unknown' => $query->whereRaw("TRIM(COALESCE(view_logs.user_agent, '')) = ''"),
            default => null,
        };
    }

    /**
     * @param  list<string>  $patterns
     */
    private function whereAnyPattern(Builder $query, array $patterns): void
    {
        $query->where(function (Builder $inner) use ($patterns): void {
            foreach ($patterns as $pattern) {
                $inner->orWhereRaw("LOWER(COALESCE(view_logs.user_agent, '')) LIKE ?", ['%'.$pattern.'%']);
            }
        });
    }

    /**
     * @param  list<string>  $patterns
     */
    private function whereNoPattern(Builder $query, array $patterns): void
    {
        foreach ($patterns as $pattern) {
            $query->whereRaw("LOWER(COALESCE(view_logs.user_agent, '')) NOT LIKE ?", ['%'.$pattern.'%']);
        }
    }

    private function whereHuman(Builder $query): void
    {
        $query->whereRaw("TRIM(COALESCE(view_logs.user_agent, '')) != ''");
        $this->whereNoPattern($query, [
            ...self::AI_BOT_PATTERNS,
            ...self::SEARCH_BOT_PATTERNS,
            ...self::GENERIC_BOT_PATTERNS,
        ]);
    }

    private function whereOtherBot(Builder $query): void
    {
        $this->whereAnyPattern($query, self::GENERIC_BOT_PATTERNS);
        $this->whereNoPattern($query, [
            ...self::AI_BOT_PATTERNS,
            ...self::SEARCH_BOT_PATTERNS,
        ]);
    }

    /**
     * @return list<Carbon>
     */
    private function days(AnalyticsFilter $filter): array
    {
        $days = [];
        $cursor = $filter->dateFrom->copy()->startOfDay();
        $end = $filter->dateTo->copy()->startOfDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        return $days;
    }
}
