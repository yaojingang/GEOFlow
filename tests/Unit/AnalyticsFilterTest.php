<?php

namespace Tests\Unit;

use App\Services\Admin\Analytics\AnalyticsFilter;
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
        $this->assertSame('all', $filter->trafficType);
        $this->assertSame('all', $filter->logSource);

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
            'traffic_type' => 'ai_bot',
            'log_source' => 'server',
        ]);

        $this->assertSame('30d', $filter->preset);
        $this->assertSame('2026-04-22', $filter->dateFrom->toDateString());
        $this->assertSame('2026-05-21', $filter->dateTo->toDateString());
        $this->assertSame(3, $filter->channelId);
        $this->assertSame(7, $filter->taskId);
        $this->assertSame(11, $filter->categoryId);
        $this->assertSame(19, $filter->articleId);
        $this->assertSame('ai_bot', $filter->trafficType);
        $this->assertSame('server', $filter->logSource);

        Carbon::setTestNow();
    }

    public function test_it_normalizes_invalid_and_reversed_dates(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $filter = AnalyticsFilter::fromRequest([
            'date_from' => '2026-05-22',
            'date_to' => '2026-05-20',
            'traffic_type' => 'invalid',
            'log_source' => 'invalid',
        ]);

        $this->assertSame('custom', $filter->preset);
        $this->assertSame('2026-05-20', $filter->dateFrom->toDateString());
        $this->assertSame('2026-05-22', $filter->dateTo->toDateString());
        $this->assertSame('all', $filter->trafficType);
        $this->assertSame('all', $filter->logSource);

        Carbon::setTestNow();
    }
}
