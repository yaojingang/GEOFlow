<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Task;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsLogQueryService;
use App\Services\Admin\Analytics\AnalyticsOverviewService;
use App\Support\AdminWeb;
use Illuminate\Http\Request;
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
}
