<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Models\Task;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsLogQueryService;
use App\Services\Admin\Analytics\AnalyticsOverviewService;
use App\Support\AdminWeb;
use App\Support\Analytics\TrafficClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsOverviewService $overviewService,
        private readonly AnalyticsLogQueryService $logQueryService,
    ) {}

    public function index(Request $request): View
    {
        $filter = AnalyticsFilter::fromRequest($request->query());

        return view('admin.analytics.index', [
            'pageTitle' => __('admin.analytics.page_title'),
            'activeMenu' => 'analytics',
            'adminSiteName' => AdminWeb::siteName(),
            'filters' => $filter,
            'filterOptions' => $this->filterOptions(),
            'globalOverview' => $this->overviewService->globalOverview(),
            'kpis' => $this->overviewService->kpis($filter),
            'publicationTrend' => $this->overviewService->publicationTrend($filter),
            'taskTrend' => $this->overviewService->taskTrend($filter),
            'contentFunnel' => $this->overviewService->contentFunnel($filter),
            'distributionSummary' => $this->overviewService->distributionSummary($filter),
            'topContent' => $this->overviewService->topContent($filter),
            'aiUsageSummary' => $this->overviewService->aiUsageSummary($filter),
            'categoryDistribution' => $this->overviewService->categoryDistribution($filter),
            'performanceStats' => $this->overviewService->performanceStats($filter),
            'latestArticles' => $this->overviewService->latestArticles($filter),
            'taskHealth' => $this->overviewService->taskHealth($filter),
            'materialHealth' => $this->overviewService->materialHealth(),
            'aiHealth' => $this->overviewService->aiHealth(),
            'urlImportHealth' => $this->overviewService->urlImportHealth($filter),
            'logSummary' => $this->logQueryService->summary($filter),
            'growthOverview' => $this->growthOverview(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function filterOptions(): array
    {
        return [
            'channels' => DistributionChannel::query()
                ->orderBy('name')
                ->select('id', 'name')
                ->get(),
            'tasks' => Task::query()
                ->orderByDesc('created_at')
                ->select('id', 'name')
                ->limit(100)
                ->get(),
            'categories' => Category::query()
                ->orderBy('name')
                ->select('id', 'name')
                ->get(),
            'articles' => Article::query()
                ->whereNull('deleted_at')
                ->orderByDesc('created_at')
                ->select('id', 'title')
                ->limit(100)
                ->get(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function growthOverview(): array
    {
        $todayVisits = 0;
        $todayAiVisits = 0;
        if (Schema::hasTable('view_logs')) {
            $todayQuery = DB::table('view_logs')->whereDate('created_at', now()->toDateString());
            if (Schema::hasColumn('view_logs', 'method')) {
                $todayQuery->where('method', 'GET');
            }

            $todayVisits = (int) (clone $todayQuery)->count();
            $todayAiVisits = (int) (clone $todayQuery)
                ->where(function ($query): void {
                    foreach (TrafficClassifier::aiBotPatterns() as $pattern) {
                        $query->orWhereRaw("LOWER(COALESCE(user_agent, '')) LIKE ?", ['%'.$pattern.'%']);
                    }
                })
                ->count();
        }

        $formsTotal = LeadForm::query()->count();
        $activeForms = LeadForm::query()->where('status', LeadForm::STATUS_ACTIVE)->count();
        $submissionsTotal = LeadSubmission::query()->count();
        $newLeads = LeadSubmission::query()->where('status', LeadSubmission::STATUS_NEW)->count();
        $pendingFollowups = LeadSubmission::query()
            ->whereIn('status', [LeadSubmission::STATUS_NEW, LeadSubmission::STATUS_CONTACTED])
            ->count();
        $handledLeads = LeadSubmission::query()
            ->whereIn('status', [
                LeadSubmission::STATUS_CONTACTED,
                LeadSubmission::STATUS_QUALIFIED,
                LeadSubmission::STATUS_CONVERTED,
            ])
            ->count();

        $recentSubmissions = LeadSubmission::query()
            ->with('form:id,name,slug')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        $sourceSummary = LeadSubmission::query()
            ->select(['source_url', 'status'])
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->groupBy(fn (LeadSubmission $submission): string => trim((string) $submission->source_url) !== '' ? (string) $submission->source_url : __('admin.growth_center.direct_source'))
            ->map(fn ($rows, string $source): array => [
                'source' => $source,
                'count' => $rows->count(),
                'converted' => $rows->where('status', LeadSubmission::STATUS_CONVERTED)->count(),
            ])
            ->sortByDesc('count')
            ->values()
            ->take(6)
            ->all();

        return [
            'stats' => [
                'today_visits' => $todayVisits,
                'today_ai_visits' => $todayAiVisits,
                'forms_total' => $formsTotal,
                'active_forms' => $activeForms,
                'submissions_total' => $submissionsTotal,
                'new_leads' => $newLeads,
                'pending_followups' => $pendingFollowups,
                'handled_leads' => $handledLeads,
            ],
            'recent_submissions' => $recentSubmissions,
            'source_summary' => $sourceSummary,
        ];
    }
}
