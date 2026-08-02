<?php

namespace App\Services\Admin\Analytics;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Models\TaskRun;
use App\Support\Analytics\TrafficClassifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GrowthOverviewService
{
    public function __construct(private readonly AiVisibilityAnalyticsService $aiVisibility) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(bool $includeDistribution): array
    {
        $traffic = $this->trafficSnapshot();
        $content = $this->contentSnapshot();
        $ai = $this->aiVisibility->snapshot(60);
        $leads = $this->leadSnapshot();
        $distribution = $includeDistribution ? $this->distributionSnapshot() : [];

        return [
            'metrics' => [
                'today_visits' => $traffic['pv'],
                'published_7d' => $content['published'],
                'brand_visibility_60d' => (float) ($ai['kpis']['brand_visibility'] ?? 0),
                'new_leads' => $leads['new'],
                'pending_followups' => $leads['pending'],
            ],
            'cards' => [
                'content' => $content,
                'traffic' => $traffic,
                'ai_visibility' => [
                    'visibility' => (float) ($ai['kpis']['brand_visibility'] ?? 0),
                    'top3' => (float) ($ai['kpis']['top3_rate'] ?? 0),
                    'samples' => (int) ($ai['sampled_runs'] ?? 0),
                    'configured' => (bool) ($ai['configured'] ?? false),
                ],
                'leads' => $leads,
                'distribution' => $distribution,
            ],
            'alert' => $this->alert($leads, $distribution, $content['failed_tasks'], (bool) ($ai['configured'] ?? false), $includeDistribution),
        ];
    }

    /** @return array{pv: int, unique_ip: int, ai: int} */
    private function trafficSnapshot(): array
    {
        if (! Schema::hasTable('view_logs')) {
            return ['pv' => 0, 'unique_ip' => 0, 'ai' => 0];
        }

        $query = DB::table('view_logs')
            ->whereDate('created_at', now()->toDateString())
            ->where('method', 'GET')
            ->whereIn('source', AnalyticsLogFilter::supportedSources());
        $aiCondition = $this->aiUserAgentCondition();
        $row = $query
            ->selectRaw('COUNT(*) AS pv')
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(ip_address), '')) AS unique_ip")
            ->selectRaw("SUM(CASE WHEN {$aiCondition['sql']} THEN 1 ELSE 0 END) AS ai", $aiCondition['bindings'])
            ->first();

        return [
            'pv' => (int) ($row->pv ?? 0),
            'unique_ip' => (int) ($row->unique_ip ?? 0),
            'ai' => (int) ($row->ai ?? 0),
        ];
    }

    /** @return array{published: int, created: int, failed_tasks: int} */
    private function contentSnapshot(): array
    {
        if (! Schema::hasTable('articles')) {
            return ['published' => 0, 'created' => 0, 'failed_tasks' => 0];
        }

        $start = now()->copy()->startOfDay()->subDays(6);
        $end = now()->copy()->endOfDay();

        return [
            'published' => (int) Article::query()
                ->whereNull('deleted_at')
                ->where('status', 'published')
                ->whereBetween('published_at', [$start, $end])
                ->count(),
            'created' => (int) Article::query()
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$start, $end])
                ->count(),
            'failed_tasks' => Schema::hasTable('task_runs')
                ? (int) TaskRun::query()->where('status', 'failed')->whereBetween('created_at', [$start, $end])->count()
                : 0,
        ];
    }

    /** @return array{new: int, new_30d: int, pending: int, converted_30d: int, submissions_30d: int, active_forms: int} */
    private function leadSnapshot(): array
    {
        if (! Schema::hasTable('lead_submissions')) {
            return ['new' => 0, 'new_30d' => 0, 'pending' => 0, 'converted_30d' => 0, 'submissions_30d' => 0, 'active_forms' => 0];
        }

        $start = now()->copy()->startOfDay()->subDays(29);
        $row = LeadSubmission::query()
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS new_count', [LeadSubmission::STATUS_NEW])
            ->selectRaw('SUM(CASE WHEN status = ? AND created_at >= ? THEN 1 ELSE 0 END) AS new_30d', [LeadSubmission::STATUS_NEW, $start])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) AS pending_count', [LeadSubmission::STATUS_NEW, LeadSubmission::STATUS_CONTACTED])
            ->selectRaw('SUM(CASE WHEN created_at >= ? THEN 1 ELSE 0 END) AS submissions_30d', [$start])
            ->selectRaw('SUM(CASE WHEN status = ? AND created_at >= ? THEN 1 ELSE 0 END) AS converted_30d', [LeadSubmission::STATUS_CONVERTED, $start])
            ->first();

        return [
            'new' => (int) ($row->new_count ?? 0),
            'new_30d' => (int) ($row->new_30d ?? 0),
            'pending' => (int) ($row->pending_count ?? 0),
            'converted_30d' => (int) ($row->converted_30d ?? 0),
            'submissions_30d' => (int) ($row->submissions_30d ?? 0),
            'active_forms' => Schema::hasTable('lead_forms')
                ? (int) LeadForm::query()->where('status', LeadForm::STATUS_ACTIVE)->count()
                : 0,
        ];
    }

    /** @return array{total: int, synced: int, failed: int, pending: int} */
    private function distributionSnapshot(): array
    {
        if (! Schema::hasTable('article_distributions') || ! Schema::hasTable('articles')) {
            return ['total' => 0, 'synced' => 0, 'failed' => 0, 'pending' => 0];
        }

        $row = ArticleDistribution::query()
            ->join('articles', 'article_distributions.article_id', '=', 'articles.id')
            ->whereNull('articles.deleted_at')
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw("SUM(CASE WHEN article_distributions.status = 'synced' THEN 1 ELSE 0 END) AS synced")
            ->selectRaw("SUM(CASE WHEN article_distributions.status = 'failed' THEN 1 ELSE 0 END) AS failed")
            ->selectRaw("SUM(CASE WHEN article_distributions.status IN ('queued', 'sending') THEN 1 ELSE 0 END) AS pending")
            ->first();

        return [
            'total' => (int) ($row->total ?? 0),
            'synced' => (int) ($row->synced ?? 0),
            'failed' => (int) ($row->failed ?? 0),
            'pending' => (int) ($row->pending ?? 0),
        ];
    }

    /**
     * @param  array<string, int>  $leads
     * @param  array<string, int>  $distribution
     * @return array<string, mixed>|null
     */
    private function alert(array $leads, array $distribution, int $failedTasks, bool $aiConfigured, bool $includeDistribution): ?array
    {
        if ($leads['new'] > 0) {
            return ['type' => 'new_leads', 'count' => $leads['new'], 'href' => route('admin.leads.index', ['status' => LeadSubmission::STATUS_NEW])];
        }

        if ($includeDistribution && ($distribution['failed'] ?? 0) > 0) {
            return ['type' => 'distribution_failed', 'count' => $distribution['failed'], 'href' => route('admin.distribution.jobs', ['status' => 'failed'])];
        }

        if ($failedTasks > 0) {
            return ['type' => 'content_failed', 'count' => $failedTasks, 'href' => route('admin.analytics.content')];
        }

        if ($leads['active_forms'] === 0) {
            return ['type' => 'no_forms', 'count' => 0, 'href' => route('admin.lead-forms.create')];
        }

        if (! $aiConfigured) {
            return ['type' => 'ai_unconfigured', 'count' => 0, 'href' => route('admin.analytics.ai-visibility')];
        }

        return null;
    }

    /** @return array{sql: string, bindings: list<string>} */
    private function aiUserAgentCondition(): array
    {
        $parts = [];
        $bindings = [];
        foreach (TrafficClassifier::aiBotPatterns() as $pattern) {
            $parts[] = "LOWER(COALESCE(user_agent, '')) LIKE ?";
            $bindings[] = '%'.$pattern.'%';
        }

        return ['sql' => $parts === [] ? '1 = 0' : '('.implode(' OR ', $parts).')', 'bindings' => $bindings];
    }
}
