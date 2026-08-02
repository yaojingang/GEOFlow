<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiVisibilityRun;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsFilter;
use App\Services\Admin\Analytics\AiVisibilityAnalyticsService;
use App\Support\AdminWeb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class AiVisibilityAnalyticsController extends Controller
{
    public function __construct(private readonly AiVisibilityAnalyticsService $analytics) {}

    public function __invoke(Request $request): View
    {
        $filter = AiVisibilityAnalyticsFilter::fromRequest($request->query());

        return view('admin.analytics.ai-visibility', [
            'pageTitle' => __('admin.analytics.pages.ai_visibility.title'),
            'activeMenu' => 'analytics',
            'analyticsPage' => 'ai-visibility',
            'adminSiteName' => AdminWeb::siteName(),
            'filters' => $filter,
            'filterOptions' => [
                'keywords' => Schema::hasTable('ai_visibility_runs')
                    ? AiVisibilityRun::query()->whereNotNull('keyword')->where('keyword', '!=', '')->distinct()->orderBy('keyword')->pluck('keyword')
                    : collect(),
                'providers' => [
                    AiVisibilityRun::PROVIDER_DOUBAO_ARK_RESPONSES,
                    AiVisibilityRun::PROVIDER_DOUBAO_SEARCH_CUSTOM,
                    AiVisibilityRun::PROVIDER_DEEPSEEK_ANALYSIS,
                ],
            ],
            'aiVisibilityOverview' => $this->analytics->overview($filter),
        ]);
    }
}
