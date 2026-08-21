<?php

namespace Tests\Unit;

use App\Services\Admin\Analytics\AiVisibilityAnalyticsFilter;
use App\Services\Admin\Analytics\LeadAnalyticsFilter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AnalyticsTopicFiltersTest extends TestCase
{
    public function test_ai_visibility_filter_supports_presets_dimensions_and_safe_custom_dates(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');

        $preset = AiVisibilityAnalyticsFilter::fromRequest([
            'ai_preset' => '90d',
            'ai_keyword' => ' GEOFlow ',
            'ai_provider' => 'deepseek_analysis',
        ]);
        $custom = AiVisibilityAnalyticsFilter::fromRequest([
            'ai_preset' => 'custom',
            'ai_date_from' => '2026-08-05',
            'ai_date_to' => '2026-07-30',
        ]);

        $this->assertSame('2026-05-05', $preset->dateFrom->toDateString());
        $this->assertSame('GEOFlow', $preset->keyword);
        $this->assertSame('deepseek_analysis', $preset->provider);
        $this->assertSame('2026-07-30', $custom->dateFrom->toDateString());
        $this->assertSame('2026-08-02', $custom->dateTo->toDateString());
    }

    public function test_lead_filter_defaults_to_thirty_days_and_normalizes_dimensions(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');

        $default = LeadAnalyticsFilter::fromRequest([]);
        $custom = LeadAnalyticsFilter::fromRequest([
            'lead_preset' => 'custom',
            'lead_date_from' => '2026-08-01',
            'lead_date_to' => '2026-07-20',
            'lead_form_id' => '8',
            'lead_status' => 'converted',
        ]);

        $this->assertSame('2026-07-04', $default->dateFrom->toDateString());
        $this->assertSame('2026-08-02', $default->dateTo->toDateString());
        $this->assertSame('2026-07-20', $custom->dateFrom->toDateString());
        $this->assertSame('2026-08-01', $custom->dateTo->toDateString());
        $this->assertSame(8, $custom->formId);
        $this->assertSame('converted', $custom->status);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
