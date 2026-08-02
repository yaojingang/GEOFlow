<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsFilterOptionsService;
use App\Services\Admin\Analytics\DistributionAnalyticsService;
use App\Support\AdminWeb;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DistributionAnalyticsController extends Controller
{
    public function __construct(
        private readonly DistributionAnalyticsService $analytics,
        private readonly AnalyticsFilterOptionsService $filterOptions,
    ) {}

    public function __invoke(Request $request): View
    {
        $query = $request->query();
        if (! array_key_exists('preset', $query) && ! array_key_exists('date_from', $query) && ! array_key_exists('date_to', $query)) {
            $query['preset'] = '30d';
        }
        $filter = AnalyticsFilter::fromRequest($query);
        $status = (string) $request->query('distribution_status', 'all');

        return view('admin.analytics.distribution', [
            'pageTitle' => __('admin.analytics.pages.distribution.title'),
            'activeMenu' => 'analytics',
            'analyticsPage' => 'distribution',
            'adminSiteName' => AdminWeb::siteName(),
            'filters' => $filter,
            'distributionStatus' => $status,
            'filterOptions' => $this->filterOptions->get(includeChannels: true, filter: $filter),
            'summary' => $this->analytics->summary($filter, $status),
        ]);
    }
}
