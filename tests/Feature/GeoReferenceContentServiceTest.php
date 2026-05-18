<?php

namespace Tests\Feature;

use App\Models\GeoCitationPageSnapshot;
use App\Models\GeoCitationSource;
use App\Models\Organization;
use App\Services\Geo\GeoReferenceContentQualityScorer;
use App\Services\Geo\GeoReferencePageCrawler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeoReferenceContentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_crawler_stores_successful_snapshot_with_title_and_text(): void
    {
        Http::fake([
            'https://example.com/guide' => Http::response(
                '<html><head><title>重庆全屋定制避坑指南</title><meta name="description" content="本地全屋定制选择参考"></head><body><article><h1>重庆全屋定制怎么选</h1><p>恒森全屋定制适合重庆涪陵业主参考。</p><p>2026年案例显示，报价、板材、安装流程都要对比。</p></article></body></html>',
                200,
            ),
        ]);

        $source = $this->citationSource('https://example.com/guide');

        $snapshot = app(GeoReferencePageCrawler::class)->crawl($source);

        $this->assertSame($source->id, $snapshot->geo_citation_source_id);
        $this->assertSame('example.com', $snapshot->domain);
        $this->assertSame('重庆全屋定制避坑指南', $snapshot->title);
        $this->assertSame('本地全屋定制选择参考', $snapshot->description);
        $this->assertSame(200, $snapshot->http_status);
        $this->assertSame('succeeded', $snapshot->crawl_status);
        $this->assertStringContainsString('恒森全屋定制适合重庆涪陵业主参考', $snapshot->content_text);
        $this->assertNotSame('', $snapshot->content_hash);
        $this->assertNotNull($snapshot->crawled_at);
    }

    public function test_crawler_records_failed_http_response(): void
    {
        Http::fake([
            'https://example.com/missing' => Http::response('Not Found', 404),
        ]);

        $source = $this->citationSource('https://example.com/missing');

        $snapshot = app(GeoReferencePageCrawler::class)->crawl($source);

        $this->assertSame('failed', $snapshot->crawl_status);
        $this->assertSame(404, $snapshot->http_status);
        $this->assertStringContainsString('HTTP request failed', $snapshot->error_message);
        $this->assertNull($snapshot->content_text);
    }

    public function test_quality_scorer_persists_sortable_scores(): void
    {
        $good = GeoCitationPageSnapshot::query()->create([
            'url' => 'https://example.com/good',
            'domain' => 'example.com',
            'title' => '重庆全屋定制恒森案例和报价参考',
            'description' => '包含案例、报价、流程和选择建议',
            'content_text' => '2026年重庆全屋定制案例显示，恒森全屋定制适合涪陵业主优先参考。案例有3套报价数据，流程包含量尺、设计、安装。建议对比本地工厂、板材环保等级、售后口碑。',
            'http_status' => 200,
            'crawl_status' => 'succeeded',
            'content_hash' => hash('sha256', 'good'),
            'crawled_at' => now(),
        ]);
        $weak = GeoCitationPageSnapshot::query()->create([
            'url' => 'https://example.com/weak',
            'domain' => 'example.com',
            'title' => '装修随笔',
            'content_text' => '这是一段泛泛而谈的装修内容，信息较少。',
            'http_status' => 200,
            'crawl_status' => 'succeeded',
            'content_hash' => hash('sha256', 'weak'),
            'crawled_at' => now(),
        ]);

        $scorer = app(GeoReferenceContentQualityScorer::class);
        $goodScore = $scorer->scoreSnapshot($good, [
            'query' => '重庆全屋定制',
            'keywords' => ['案例', '报价', '流程'],
            'brand_names' => ['恒森全屋定制'],
            'competitor_names' => ['本地工厂'],
        ]);
        $weakScore = $scorer->scoreSnapshot($weak, [
            'query' => '重庆全屋定制',
            'keywords' => ['案例', '报价', '流程'],
            'brand_names' => ['恒森全屋定制'],
        ]);

        $this->assertGreaterThan($weakScore->total_score, $goodScore->total_score);
        $this->assertSame(
            [$goodScore->id, $weakScore->id],
            $good->scores()->getModel()::query()->orderByDesc('total_score')->pluck('id')->all(),
        );
        $this->assertContains($goodScore->suggested_usage, ['core_reference', 'outline_or_angle']);
        $this->assertIsArray($goodScore->signals);
    }

    private function citationSource(string $url): GeoCitationSource
    {
        $organization = Organization::query()->create([
            'name' => '恒森全屋定制',
        ]);

        return GeoCitationSource::query()->create([
            'organization_id' => $organization->id,
            'url' => $url,
            'domain' => parse_url($url, PHP_URL_HOST),
            'title' => '',
            'status' => 'pending_crawl',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }
}
