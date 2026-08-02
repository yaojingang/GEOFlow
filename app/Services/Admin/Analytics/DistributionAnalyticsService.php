<?php

namespace App\Services\Admin\Analytics;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DistributionAnalyticsService
{
    private ?bool $tablesAvailable = null;

    /** @return array<string, mixed> */
    public function summary(AnalyticsFilter $filter, string $status = 'all'): array
    {
        if (! $this->tablesAvailable()) {
            return $this->emptySummary($filter);
        }

        $status = $this->normalizeStatus($status);
        $base = $this->filtered($filter, $status);
        $row = (clone $base)
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN ad.status = 'synced' THEN 1 ELSE 0 END) AS synced")
            ->selectRaw("SUM(CASE WHEN ad.status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("SUM(CASE WHEN ad.status IN ('queued', 'sending') THEN 1 ELSE 0 END) AS pending")
            ->first();
        $total = (int) ($row->total ?? 0);
        $synced = (int) ($row->synced ?? 0);

        return [
            'ready' => true,
            'status' => $status,
            'kpis' => [
                'total' => $total,
                'synced' => $synced,
                'failed' => (int) ($row->failed ?? 0),
                'pending' => (int) ($row->pending ?? 0),
                'success_rate' => $total > 0 ? round(($synced * 100) / $total, 1) : 0.0,
            ],
            'trend' => $this->trend($filter, $status),
            'channels' => $this->channels($filter, $status),
            'issues' => $this->issues($filter, $status),
        ];
    }

    private function filtered(AnalyticsFilter $filter, string $status): Builder
    {
        $query = DB::table('article_distributions as ad')
            ->join('articles as a', 'ad.article_id', '=', 'a.id')
            ->leftJoin('distribution_channels as dc', 'ad.distribution_channel_id', '=', 'dc.id')
            ->whereNull('a.deleted_at')
            ->whereBetween('ad.created_at', [$filter->start(), $filter->end()])
            ->when($filter->channelId !== null, fn (Builder $query) => $query->where('ad.distribution_channel_id', $filter->channelId))
            ->when($filter->taskId !== null, fn (Builder $query) => $query->where('a.task_id', $filter->taskId))
            ->when($filter->categoryId !== null, fn (Builder $query) => $query->where('a.category_id', $filter->categoryId))
            ->when($filter->articleId !== null, fn (Builder $query) => $query->where('ad.article_id', $filter->articleId));

        if ($status === 'pending') {
            $query->whereIn('ad.status', ['queued', 'sending']);
        } elseif ($status !== 'all') {
            $query->where('ad.status', $status);
        }

        return $query;
    }

    /** @return list<array{date: string, total: int, synced: int, failed: int, pending: int}> */
    private function trend(AnalyticsFilter $filter, string $status): array
    {
        $rows = $this->filtered($filter, $status)
            ->selectRaw('DATE(ad.created_at) AS trend_date, COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN ad.status = 'synced' THEN 1 ELSE 0 END) AS synced")
            ->selectRaw("SUM(CASE WHEN ad.status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("SUM(CASE WHEN ad.status IN ('queued', 'sending') THEN 1 ELSE 0 END) AS pending")
            ->groupByRaw('DATE(ad.created_at)')
            ->get()
            ->keyBy(fn (object $row): string => Carbon::parse((string) $row->trend_date)->toDateString());

        return array_map(function (Carbon $day) use ($rows): array {
            $date = $day->toDateString();
            $row = $rows->get($date);

            return [
                'date' => $date,
                'total' => (int) ($row->total ?? 0),
                'synced' => (int) ($row->synced ?? 0),
                'failed' => (int) ($row->failed ?? 0),
                'pending' => (int) ($row->pending ?? 0),
            ];
        }, $this->days($filter));
    }

    /** @return list<array{name: string, total: int, synced: int, failed: int, pending: int}> */
    private function channels(AnalyticsFilter $filter, string $status): array
    {
        return $this->filtered($filter, $status)
            ->selectRaw("COALESCE(dc.name, '') AS name, COUNT(*) AS total")
            ->selectRaw("SUM(CASE WHEN ad.status = 'synced' THEN 1 ELSE 0 END) AS synced")
            ->selectRaw("SUM(CASE WHEN ad.status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("SUM(CASE WHEN ad.status IN ('queued', 'sending') THEN 1 ELSE 0 END) AS pending")
            ->groupBy('dc.id', 'dc.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'total' => (int) $row->total,
                'synced' => (int) $row->synced,
                'failed' => (int) $row->failed,
                'pending' => (int) $row->pending,
            ])
            ->all();
    }

    /** @return list<object> */
    private function issues(AnalyticsFilter $filter, string $status): array
    {
        return $this->filtered($filter, $status)
            ->where(function (Builder $query): void {
                $query->where('ad.status', 'failed')->orWhereIn('ad.status', ['queued', 'sending']);
            })
            ->select('ad.id', 'ad.status', 'ad.attempt_count', 'ad.last_error_message', 'ad.created_at', 'a.title as article_title', 'dc.name as channel_name')
            ->orderByDesc('ad.created_at')
            ->limit(20)
            ->get()
            ->all();
    }

    private function normalizeStatus(string $status): string
    {
        return in_array($status, ['all', 'synced', 'failed', 'pending'], true) ? $status : 'all';
    }

    private function tablesAvailable(): bool
    {
        return $this->tablesAvailable ??= Schema::hasTable('article_distributions')
            && Schema::hasTable('articles')
            && Schema::hasTable('distribution_channels');
    }

    /** @return array<string, mixed> */
    private function emptySummary(AnalyticsFilter $filter): array
    {
        return [
            'ready' => false,
            'status' => 'all',
            'kpis' => ['total' => 0, 'synced' => 0, 'failed' => 0, 'pending' => 0, 'success_rate' => 0.0],
            'trend' => array_map(fn (Carbon $day): array => ['date' => $day->toDateString(), 'total' => 0, 'synced' => 0, 'failed' => 0, 'pending' => 0], $this->days($filter)),
            'channels' => [],
            'issues' => [],
        ];
    }

    /** @return list<Carbon> */
    private function days(AnalyticsFilter $filter): array
    {
        $days = [];
        $cursor = $filter->dateFrom->copy();
        while ($cursor->lessThanOrEqualTo($filter->dateTo)) {
            $days[] = $cursor->copy();
            $cursor->addDay();
        }

        return $days;
    }
}
