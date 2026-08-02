<?php

namespace Tests\Unit;

use App\Services\Admin\Analytics\AnalyticsLogFilter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsLogFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_defaults_to_the_last_seven_natural_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00'));

        $filter = AnalyticsLogFilter::fromRequest([]);

        $this->assertSame('7d', $filter->preset);
        $this->assertSame('2026-07-26', $filter->dateFrom->toDateString());
        $this->assertSame('2026-08-01', $filter->dateTo->toDateString());
        $this->assertSame('all', $filter->trafficType);
        $this->assertSame('all', $filter->source);
    }

    public function test_it_supports_thirty_and_sixty_day_presets_without_using_content_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00'));

        $thirtyDays = AnalyticsLogFilter::fromRequest([
            'log_preset' => '30d',
            'date_from' => '2020-01-01',
            'date_to' => '2020-01-02',
        ]);
        $sixtyDays = AnalyticsLogFilter::fromRequest(['log_preset' => '60d']);

        $this->assertSame('2026-07-03', $thirtyDays->dateFrom->toDateString());
        $this->assertSame('2026-08-01', $thirtyDays->dateTo->toDateString());
        $this->assertSame('2026-06-03', $sixtyDays->dateFrom->toDateString());
        $this->assertSame('2026-08-01', $sixtyDays->dateTo->toDateString());
    }

    public function test_it_normalizes_custom_dates_and_accepts_legacy_filter_aliases(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00'));

        $filter = AnalyticsLogFilter::fromRequest([
            'log_date_from' => '2026-08-10',
            'log_date_to' => '2026-07-20',
            'traffic_type' => 'ai_bot',
            'log_source' => 'server',
        ]);

        $this->assertSame('custom', $filter->preset);
        $this->assertSame('2026-07-20', $filter->dateFrom->toDateString());
        $this->assertSame('2026-08-01', $filter->dateTo->toDateString());
        $this->assertSame('ai_bot', $filter->trafficType);
        $this->assertSame('server', $filter->source);
    }

    public function test_new_log_parameters_take_precedence_and_invalid_values_fall_back(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 12:00:00'));

        $filter = AnalyticsLogFilter::fromRequest([
            'log_preset' => 'invalid',
            'log_traffic_type' => 'human',
            'traffic_type' => 'ai_bot',
            'log_source' => 'demo',
        ]);

        $this->assertSame('7d', $filter->preset);
        $this->assertSame('human', $filter->trafficType);
        $this->assertSame('all', $filter->source);
        $this->assertSame(['local', 'server', 'channel'], AnalyticsLogFilter::supportedSources());
    }
}
