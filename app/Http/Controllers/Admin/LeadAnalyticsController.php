<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LeadForm;
use App\Services\Admin\Analytics\LeadAnalyticsFilter;
use App\Services\Admin\Analytics\LeadAnalyticsService;
use App\Support\AdminWeb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class LeadAnalyticsController extends Controller
{
    public function __construct(private readonly LeadAnalyticsService $analytics) {}

    public function __invoke(Request $request): View
    {
        $filter = LeadAnalyticsFilter::fromRequest($request->query());

        return view('admin.analytics.leads', [
            'pageTitle' => __('admin.analytics.pages.leads.title'),
            'activeMenu' => 'analytics',
            'analyticsPage' => 'leads',
            'adminSiteName' => AdminWeb::siteName(),
            'filters' => $filter,
            'filterOptions' => [
                'forms' => Schema::hasTable('lead_forms')
                    ? LeadForm::query()->orderBy('name')->select('id', 'name')->get()
                    : collect(),
            ],
            'summary' => $this->analytics->summary($filter),
        ]);
    }
}
