<?php

namespace Tests\Unit;

use App\Services\Admin\Analytics\AiVisibilityAnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsLogFilter;
use App\Services\Admin\Analytics\LeadAnalyticsFilter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsFilterTest extends TestCase
{
    public function test_it_defaults_to_last_seven_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $filter = AnalyticsFilter::fromRequest([]);

        $this->assertSame('7d', $filter->preset);
        $this->assertSame('2026-05-15', $filter->dateFrom->toDateString());
        $this->assertSame('2026-05-21', $filter->dateTo->toDateString());
        $this->assertNull($filter->channelId);

        Carbon::setTestNow();
    }

    public function test_it_supports_presets_and_integer_dimensions(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $filter = AnalyticsFilter::fromRequest([
            'preset' => '30d',
            'channel_id' => '3',
            'task_id' => '7',
            'category_id' => '11',
            'article_id' => '19',
        ]);

        $this->assertSame('30d', $filter->preset);
        $this->assertSame('2026-04-22', $filter->dateFrom->toDateString());
        $this->assertSame('2026-05-21', $filter->dateTo->toDateString());
        $this->assertSame(3, $filter->channelId);
        $this->assertSame(7, $filter->taskId);
        $this->assertSame(11, $filter->categoryId);
        $this->assertSame(19, $filter->articleId);

        Carbon::setTestNow();
    }

    public function test_it_normalizes_invalid_and_reversed_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $filter = AnalyticsFilter::fromRequest([
            'date_from' => '2026-05-22',
            'date_to' => '2026-05-20',
        ]);

        $this->assertSame('custom', $filter->preset);
        $this->assertSame('2026-05-20', $filter->dateFrom->toDateString());
        $this->assertSame('2026-05-21', $filter->dateTo->toDateString());

        Carbon::setTestNow();
    }

    public function test_all_analytics_filters_cap_custom_ranges_at_366_days(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00'));

        $filters = [
            AnalyticsFilter::fromRequest([
                'preset' => 'custom',
                'date_from' => '0001-01-01',
                'date_to' => '2026-08-02',
            ]),
            AnalyticsLogFilter::fromRequest([
                'log_preset' => 'custom',
                'log_date_from' => '0001-01-01',
                'log_date_to' => '2026-08-02',
            ]),
            AiVisibilityAnalyticsFilter::fromRequest([
                'ai_preset' => 'custom',
                'ai_date_from' => '0001-01-01',
                'ai_date_to' => '2026-08-02',
            ]),
            LeadAnalyticsFilter::fromRequest([
                'lead_preset' => 'custom',
                'lead_date_from' => '0001-01-01',
                'lead_date_to' => '2026-08-02',
            ]),
        ];

        foreach ($filters as $filter) {
            $this->assertSame('2025-08-02', $filter->dateFrom->toDateString());
            $this->assertSame('2026-08-02', $filter->dateTo->toDateString());
            $this->assertSame(365, (int) $filter->dateFrom->diffInDays($filter->dateTo));
        }

        Carbon::setTestNow();
    }

    public function test_custom_historical_ranges_within_the_limit_are_preserved(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00'));

        $filter = AnalyticsFilter::fromRequest([
            'preset' => 'custom',
            'date_from' => '2024-01-01',
            'date_to' => '2024-03-01',
        ]);

        $this->assertSame('2024-01-01', $filter->dateFrom->toDateString());
        $this->assertSame('2024-03-01', $filter->dateTo->toDateString());

        Carbon::setTestNow();
    }

    public function test_topic_filters_infer_custom_preset_from_date_inputs(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-02 12:00:00'));

        $ai = AiVisibilityAnalyticsFilter::fromRequest([
            'ai_date_from' => '2026-07-01',
            'ai_date_to' => '2026-07-05',
        ]);
        $leads = LeadAnalyticsFilter::fromRequest([
            'lead_date_from' => '2026-06-01',
            'lead_date_to' => '2026-06-10',
        ]);

        $this->assertSame('custom', $ai->preset);
        $this->assertSame('2026-07-01', $ai->dateFrom->toDateString());
        $this->assertSame('2026-07-05', $ai->dateTo->toDateString());
        $this->assertSame('custom', $leads->preset);
        $this->assertSame('2026-06-01', $leads->dateFrom->toDateString());
        $this->assertSame('2026-06-10', $leads->dateTo->toDateString());

        Carbon::setTestNow();
    }
}
