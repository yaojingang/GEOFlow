<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\Analytics\GrowthOverviewService;
use App\Support\AdminWeb;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    private const TRAFFIC_QUERY_KEYS = [
        'log_preset',
        'log_date_from',
        'log_date_to',
        'log_traffic_type',
        'log_source',
        'traffic_type',
    ];

    private const CONTENT_QUERY_KEYS = [
        'preset',
        'date_from',
        'date_to',
        'task_id',
        'category_id',
        'article_id',
    ];

    public function __construct(private readonly GrowthOverviewService $overview) {}

    public function index(Request $request): View|RedirectResponse
    {
        if ($request->hasAny(self::TRAFFIC_QUERY_KEYS)) {
            return redirect()->route('admin.analytics.traffic', $request->query());
        }

        if ($request->filled('channel_id')) {
            if ($request->user('admin')?->canManageProtectedWorkflows() !== true) {
                abort(403);
            }

            return redirect()->route('admin.analytics.distribution', $request->query());
        }

        if ($request->hasAny(self::CONTENT_QUERY_KEYS)) {
            return redirect()->route('admin.analytics.content', $request->query());
        }

        $canManageProtectedWorkflows = auth('admin')->user()?->canManageProtectedWorkflows() === true;

        return view('admin.analytics.index', [
            'pageTitle' => __('admin.analytics.page_title'),
            'activeMenu' => 'analytics',
            'analyticsPage' => 'overview',
            'adminSiteName' => AdminWeb::siteName(),
            'canManageProtectedWorkflows' => $canManageProtectedWorkflows,
            'overview' => $this->overview->snapshot($canManageProtectedWorkflows),
        ]);
    }
}
