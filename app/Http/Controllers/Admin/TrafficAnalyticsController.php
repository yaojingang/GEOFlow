<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsFilterOptionsService;
use App\Services\Admin\Analytics\AnalyticsLogFilter;
use App\Services\Admin\Analytics\AnalyticsLogQueryService;
use App\Support\AdminWeb;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TrafficAnalyticsController extends Controller
{
    public function __construct(
        private readonly AnalyticsLogQueryService $analytics,
        private readonly AnalyticsFilterOptionsService $filterOptions,
    ) {}

    public function __invoke(Request $request): View
    {
        $contentFilter = AnalyticsFilter::fromRequest($request->query());
        $logFilter = AnalyticsLogFilter::fromRequest($request->query());

        return view('admin.analytics.traffic', [
            'pageTitle' => __('admin.analytics.pages.traffic.title'),
            'activeMenu' => 'analytics',
            'analyticsPage' => 'traffic',
            'adminSiteName' => AdminWeb::siteName(),
            'filters' => $contentFilter,
            'logFilters' => $logFilter,
            'filterOptions' => $this->filterOptions->get(filter: $contentFilter),
            'logSummary' => $this->analytics->summary($logFilter, $contentFilter),
        ]);
    }
}
