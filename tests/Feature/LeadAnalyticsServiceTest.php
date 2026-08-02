<?php

namespace Tests\Feature;

use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Services\Admin\Analytics\LeadAnalyticsFilter;
use App\Services\Admin\Analytics\LeadAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LeadAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_uses_one_consistent_range_for_kpis_trend_sources_and_recent_leads(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $form = LeadForm::query()->create([
            'name' => '咨询表单',
            'slug' => 'lead-analytics',
            'status' => LeadForm::STATUS_ACTIVE,
            'fields' => [],
        ]);
        $this->insertSubmission($form->id, LeadSubmission::STATUS_NEW, '/pricing', '2026-07-01 09:00:00');
        $this->insertSubmission($form->id, LeadSubmission::STATUS_CONVERTED, '/pricing', '2026-07-02 09:00:00');
        $this->insertSubmission($form->id, LeadSubmission::STATUS_CONTACTED, null, '2026-07-03 09:00:00');
        $this->insertSubmission($form->id, LeadSubmission::STATUS_CONVERTED, '/old', '2026-06-20 09:00:00');

        $summary = app(LeadAnalyticsService::class)->summary(LeadAnalyticsFilter::fromRequest([
            'lead_preset' => 'custom',
            'lead_date_from' => '2026-07-01',
            'lead_date_to' => '2026-07-03',
        ]));

        $this->assertSame([
            'submissions' => 3,
            'new' => 1,
            'pending' => 2,
            'converted' => 1,
            'conversion_rate' => 33.3,
        ], $summary['kpis']);
        $this->assertSame([1, 1, 1], array_column($summary['trend'], 'submissions'));
        $this->assertSame([0, 1, 0], array_column($summary['trend'], 'converted'));
        $this->assertSame('/pricing', $summary['sources'][0]['source']);
        $this->assertSame(2, $summary['sources'][0]['submissions']);
        $this->assertCount(3, $summary['recent']);
    }

    public function test_summary_query_count_is_constant_for_seven_and_ninety_days(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $service = app(LeadAnalyticsService::class);
        $service->summary(LeadAnalyticsFilter::fromRequest([]));

        $sevenDays = $this->queryCount(fn () => $service->summary(LeadAnalyticsFilter::fromRequest(['lead_preset' => '7d'])));
        $ninetyDays = $this->queryCount(fn () => $service->summary(LeadAnalyticsFilter::fromRequest(['lead_preset' => '90d'])));

        $this->assertLessThanOrEqual(7, $sevenDays);
        $this->assertSame($sevenDays, $ninetyDays);
    }

    private function queryCount(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function insertSubmission(int $formId, string $status, ?string $source, string $createdAt): void
    {
        DB::table('lead_submissions')->insert([
            'lead_form_id' => $formId,
            'status' => $status,
            'payload' => json_encode(['name' => '测试访客'], JSON_THROW_ON_ERROR),
            'source_url' => $source,
            'ip_address' => '10.0.0.1',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
