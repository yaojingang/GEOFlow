<?php

namespace App\Services\Admin\Analytics;

use App\Models\LeadSubmission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class LeadAnalyticsService
{
    /** @return array<string, mixed> */
    public function summary(LeadAnalyticsFilter $filter): array
    {
        if (! Schema::hasTable('lead_submissions')) {
            return $this->emptySummary($filter);
        }

        $row = $this->filtered($filter)
            ->selectRaw('COUNT(*) AS submissions')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS new_count', [LeadSubmission::STATUS_NEW])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS pending_count', [LeadSubmission::STATUS_NEW, LeadSubmission::STATUS_CONTACTED])
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS converted_count', [LeadSubmission::STATUS_CONVERTED])
            ->first();
        $submissions = (int) ($row->submissions ?? 0);
        $converted = (int) ($row->converted_count ?? 0);

        return [
            'ready' => true,
            'kpis' => [
                'submissions' => $submissions,
                'new' => (int) ($row->new_count ?? 0),
                'pending' => (int) ($row->pending_count ?? 0),
                'converted' => $converted,
                'conversion_rate' => $submissions > 0 ? round(($converted * 100) / $submissions, 1) : 0.0,
            ],
            'trend' => $this->trend($filter),
            'sources' => $this->sources($filter),
            'recent' => $this->recent($filter),
        ];
    }

    /** @return Builder<LeadSubmission> */
    private function filtered(LeadAnalyticsFilter $filter): Builder
    {
        return LeadSubmission::query()
            ->whereBetween('lead_submissions.created_at', [$filter->start(), $filter->end()])
            ->when($filter->formId !== null, fn (Builder $query) => $query->where('lead_form_id', $filter->formId))
            ->when($filter->status !== 'all', fn (Builder $query) => $query->where('status', $filter->status));
    }

    /** @return list<array{date: string, submissions: int, converted: int}> */
    private function trend(LeadAnalyticsFilter $filter): array
    {
        $rows = $this->filtered($filter)
            ->selectRaw('DATE(created_at) AS trend_date, COUNT(*) AS submissions')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS converted', [LeadSubmission::STATUS_CONVERTED])
            ->groupByRaw('DATE(created_at)')
            ->get()
            ->keyBy(fn (object $row): string => Carbon::parse((string) $row->trend_date)->toDateString());

        return array_map(function (Carbon $day) use ($rows): array {
            $date = $day->toDateString();
            $row = $rows->get($date);

            return [
                'date' => $date,
                'submissions' => (int) ($row->submissions ?? 0),
                'converted' => (int) ($row->converted ?? 0),
            ];
        }, $this->days($filter));
    }

    /** @return list<array{source: string, submissions: int, converted: int}> */
    private function sources(LeadAnalyticsFilter $filter): array
    {
        return $this->filtered($filter)
            ->selectRaw("COALESCE(NULLIF(TRIM(source_url), ''), '') AS source")
            ->selectRaw('COUNT(*) AS submissions')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS converted', [LeadSubmission::STATUS_CONVERTED])
            ->groupByRaw("COALESCE(NULLIF(TRIM(source_url), ''), '')")
            ->orderByDesc('submissions')
            ->limit(10)
            ->get()
            ->map(fn (LeadSubmission $row): array => [
                'source' => (string) $row->source,
                'submissions' => (int) $row->submissions,
                'converted' => (int) $row->converted,
            ])
            ->all();
    }

    /** @return list<LeadSubmission> */
    private function recent(LeadAnalyticsFilter $filter): array
    {
        $query = $this->filtered($filter)
            ->select('id', 'lead_form_id', 'status', 'payload', 'source_url', 'created_at')
            ->orderByDesc('created_at')
            ->limit(10);
        $hasFormsTable = Schema::hasTable('lead_forms');
        if ($hasFormsTable) {
            $query->with('form:id,name,slug');
        }

        $leads = $query->get();
        if (! $hasFormsTable) {
            $leads->each(fn (LeadSubmission $lead) => $lead->setRelation('form', null));
        }

        return $leads->all();
    }

    /** @return array<string, mixed> */
    private function emptySummary(LeadAnalyticsFilter $filter): array
    {
        return [
            'ready' => false,
            'kpis' => ['submissions' => 0, 'new' => 0, 'pending' => 0, 'converted' => 0, 'conversion_rate' => 0.0],
            'trend' => array_map(fn (Carbon $day): array => ['date' => $day->toDateString(), 'submissions' => 0, 'converted' => 0], $this->days($filter)),
            'sources' => [],
            'recent' => [],
        ];
    }

    /** @return list<Carbon> */
    private function days(LeadAnalyticsFilter $filter): array
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
