<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\DistributionAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DistributionAnalyticsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_aggregates_statuses_dates_channels_and_actionable_rows(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $author = Author::query()->create(['name' => '作者', 'slug' => 'distribution-author', 'status' => 'active']);
        $category = Category::query()->create(['name' => '分类', 'slug' => 'distribution-category', 'status' => 'active']);
        $article = Article::query()->create([
            'title' => '分发文章',
            'slug' => 'distribution-article',
            'content' => '正文',
            'author_id' => $author->id,
            'category_id' => $category->id,
            'status' => 'published',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '主渠道',
            'domain' => 'distribution.example.com',
            'endpoint_url' => 'https://distribution.example.com',
            'status' => 'active',
        ]);
        $this->insertDistribution($article->id, $channel->id, 'synced', '2026-07-01 09:00:00', 'synced');
        $this->insertDistribution($article->id, $channel->id, 'failed', '2026-07-02 09:00:00', 'failed');
        $this->insertDistribution($article->id, $channel->id, 'queued', '2026-07-03 09:00:00', 'queued');
        $this->insertDistribution($article->id, $channel->id, 'failed', '2026-06-01 09:00:00', 'old');

        $filter = AnalyticsFilter::fromRequest([
            'preset' => 'custom',
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-03',
            'category_id' => $category->id,
        ]);
        $service = app(DistributionAnalyticsService::class);
        $summary = $service->summary($filter, 'all');

        $this->assertSame([
            'total' => 3,
            'synced' => 1,
            'failed' => 1,
            'pending' => 1,
            'success_rate' => 33.3,
        ], $summary['kpis']);
        $this->assertSame([1, 1, 1], array_column($summary['trend'], 'total'));
        $this->assertSame('主渠道', $summary['channels'][0]['name']);
        $this->assertCount(2, $summary['issues']);
        $this->assertSame([], $service->summary($filter, 'synced')['issues']);
    }

    public function test_summary_query_count_is_constant_for_seven_and_ninety_days(): void
    {
        Carbon::setTestNow('2026-08-02 12:00:00');
        $service = app(DistributionAnalyticsService::class);
        $service->summary(AnalyticsFilter::fromRequest(['preset' => '30d']));

        $sevenDays = $this->queryCount(fn () => $service->summary(AnalyticsFilter::fromRequest(['preset' => '7d'])));
        $ninetyDays = $this->queryCount(fn () => $service->summary(AnalyticsFilter::fromRequest(['preset' => '90d'])));

        $this->assertLessThanOrEqual(5, $sevenDays);
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

    private function insertDistribution(int $articleId, int $channelId, string $status, string $createdAt, string $key): void
    {
        DB::table('article_distributions')->insert([
            'article_id' => $articleId,
            'distribution_channel_id' => $channelId,
            'action' => $key,
            'status' => $status,
            'idempotency_key' => 'analytics-'.$key,
            'attempt_count' => 0,
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
