<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsFilterOptionsService;
use App\Services\Admin\Analytics\AnalyticsOverviewService;
use App\Support\AdminWeb;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContentAnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsOverviewService $analytics,
        private readonly AnalyticsFilterOptionsService $filterOptions,
    ) {}

    public function __invoke(Request $request): View
    {
        $filter = AnalyticsFilter::fromRequest($request->query());

        return view('admin.analytics.content', [
            'pageTitle' => __('admin.analytics.pages.content.title'),
            'activeMenu' => 'analytics',
            'analyticsPage' => 'content',
            'adminSiteName' => AdminWeb::siteName(),
            'filters' => $filter,
            'filterOptions' => $this->filterOptions->get(filter: $filter),
            'kpis' => $this->analytics->kpis($filter, false),
            'publicationTrend' => $this->analytics->publicationTrend($filter),
            'taskTrend' => $this->analytics->taskTrend($filter),
            'contentFunnel' => $this->analytics->contentFunnel($filter),
            'topContent' => $this->analytics->topContent($filter),
            'aiUsageSummary' => $this->analytics->aiUsageSummary($filter),
            'categoryDistribution' => $this->analytics->categoryDistribution($filter),
            'performanceStats' => $this->analytics->performanceStats($filter),
            'latestArticles' => $this->analytics->latestArticles($filter),
        ]);
    }
}
