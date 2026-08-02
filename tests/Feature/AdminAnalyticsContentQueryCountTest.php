<?php

namespace Tests\Feature;

use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsOverviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdminAnalyticsContentQueryCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_content_trend_query_count_is_constant_for_seven_and_ninety_days(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $service = app(AnalyticsOverviewService::class);

        $sevenDays = $this->trendQueryCount($service, AnalyticsFilter::fromRequest(['preset' => '7d']));
        $ninetyDays = $this->trendQueryCount($service, AnalyticsFilter::fromRequest(['preset' => '90d']));

        $this->assertLessThanOrEqual(4, $sevenDays);
        $this->assertSame($sevenDays, $ninetyDays);
    }

    private function trendQueryCount(AnalyticsOverviewService $service, AnalyticsFilter $filter): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $service->publicationTrend($filter);
        $service->taskTrend($filter);
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
