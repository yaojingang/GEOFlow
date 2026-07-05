<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\Admin\Analytics\AnalyticsFilter;
use App\Services\Admin\Analytics\AnalyticsLogQueryService;
use App\Services\Admin\Analytics\AnalyticsOverviewService;
use App\Support\Analytics\TrafficClassifier;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AdminAnalyticsPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_analytics_page_renders_after_dashboard_nav_item(): void
    {
        $response = $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics'));

        $response
            ->assertOk()
            ->assertSee(__('admin.analytics.heading'))
            ->assertSee(__('admin.analytics.subtitle'))
            ->assertSee(__('admin.growth_center.workbench.title'))
            ->assertSee(__('admin.growth_center.priority.no_form_title'))
            ->assertSee(__('admin.growth_center.stage.visit_title'))
            ->assertSee(__('admin.growth_center.stage.touch_title'))
            ->assertSee(__('admin.growth_center.stage.lead_title'))
            ->assertSee(__('admin.growth_center.stage.follow_title'))
            ->assertSee(__('admin.growth_center.inbox.title'))
            ->assertSee(__('admin.growth_center.source.title'))
            ->assertSee(__('admin.growth_center.observation.title'))
            ->assertSee(route('admin.lead-forms.create'), false)
            ->assertSee(route('admin.leads.index'), false)
            ->assertSee(__('admin.analytics.filters.apply'))
            ->assertSee(__('admin.analytics.filters.source_pending', ['source' => __('admin.analytics.filters.server')]))
            ->assertSee(route('admin.analytics'), false)
            ->assertSee(__('admin.analytics.overall_title'))
            ->assertSee(__('admin.analytics.single_site_title'))
            ->assertSee(__('admin.analytics.multi_site_title'))
            ->assertSee(__('admin.analytics.self_log_title'))
            ->assertSee('data-analytics-single-site-section', false)
            ->assertSee('data-analytics-multi-site-section', false)
            ->assertSee('data-analytics-log-section', false)
            ->assertSee('内容运营分析')
            ->assertSee(__('admin.dashboard.category_distribution'))
            ->assertSee(__('admin.dashboard.system_performance'))
            ->assertSee(__('admin.dashboard.latest_articles'))
            ->assertSee(__('admin.dashboard.task_health'))
            ->assertSee(__('admin.dashboard.material_health'))
            ->assertSee(__('admin.dashboard.ai_health'))
            ->assertSee(__('admin.dashboard.url_import_health'))
            ->assertSee('data-analytics-health-grid', false)
            ->assertSee('lg:grid-cols-2', false)
            ->assertSee(route('admin.categories.index'), false)
            ->assertSee(route('admin.articles.index'), false)
            ->assertSee(route('admin.keyword-libraries.index'), false)
            ->assertSee(route('admin.title-libraries.index'), false)
            ->assertSee(route('admin.knowledge-bases.index'), false)
            ->assertSee(route('admin.authors.index'), false)
            ->assertSee(route('admin.url-import.history'), false)
            ->assertSee(__('admin.analytics.logs_title'))
            ->assertSee('暂无日志数据');

        $html = $response->getContent();
        $this->assertStringContainsString(route('admin.dashboard'), $html);
        $this->assertStringContainsString(route('admin.analytics'), $html);
        $this->assertLessThan(
            strpos($html, 'data-analytics-multi-site-section'),
            strpos($html, 'data-analytics-single-site-section')
        );
        $this->assertLessThan(
            strpos($html, 'data-analytics-log-section'),
            strpos($html, 'data-analytics-multi-site-section')
        );
        $this->assertLessThan(
            strpos($html, route('admin.analytics')),
            strpos($html, route('admin.dashboard'))
        );
        $this->assertStringContainsString('text-blue-600 font-medium', $html);
    }

    public function test_analytics_page_applies_date_filters_to_content_metrics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $fixtures = $this->contentFixtures();

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', [
                'date_from' => '2026-05-20',
                'date_to' => '2026-05-21',
                'channel_id' => (int) $fixtures['channel']->id,
            ]))
            ->assertOk()
            ->assertSee('2026-05-20')
            ->assertSee('2026-05-21')
            ->assertSee(__('admin.analytics.overall_title'))
            ->assertSee('data-analytics-global-overview', false)
            ->assertSee(__('admin.dashboard.total_articles'))
            ->assertSee('今日新增', false)
            ->assertSee(__('admin.dashboard.published'))
            ->assertSee(__('admin.dashboard.publish_rate', ['rate' => 66.7]), false)
            ->assertSee(__('admin.dashboard.ai_generated'))
            ->assertSee(__('admin.dashboard.ai_generated_ratio', ['rate' => 100]), false)
            ->assertSee(__('admin.dashboard.total_views'))
            ->assertSee(__('admin.dashboard.active_tasks'))
            ->assertSee(__('admin.dashboard.ai_models'))
            ->assertSee(__('admin.dashboard.material_total'))
            ->assertSee(__('admin.dashboard.pending_review'))
            ->assertSee('筛选范围文章')
            ->assertSee('2')
            ->assertSee('筛选范围发布')
            ->assertSee('1')
            ->assertSee('运行中任务')
            ->assertSee('1')
            ->assertSee('失败任务')
            ->assertSee('1')
            ->assertSee('今日 AI/API 调用')
            ->assertSee('9')
            ->assertSee('分发失败')
            ->assertSee('1')
            ->assertSee('筛选内热门文章')
            ->assertSee('范围内热门文章')
            ->assertSee('分析分类')
            ->assertSee(__('admin.dashboard.latest_articles'))
            ->assertSee(__('admin.dashboard.system_performance'))
            ->assertSee(__('admin.dashboard.task_health'))
            ->assertSee(__('admin.dashboard.material_health'))
            ->assertSee(__('admin.dashboard.ai_health'))
            ->assertSee(__('admin.dashboard.url_import_health'))
            ->assertSee('分发状态概览')
            ->assertSee('已同步')
            ->assertSee('失败');

        Carbon::setTestNow();
    }

    public function test_analytics_filter_presets_and_custom_dates_are_usable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $admin = $this->admin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.analytics', [
                'preset' => '7d',
                'date_from' => '2026-01-01',
                'date_to' => '2026-01-01',
            ]))
            ->assertOk()
            ->assertSee('value="2026-05-15"', false)
            ->assertSee('value="2026-05-21"', false)
            ->assertDontSee('value="2026-01-01"', false);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.analytics', [
                'preset' => 'custom',
                'date_from' => '2026-05-20',
                'date_to' => '2026-05-21',
            ]))
            ->assertOk()
            ->assertSee(__('admin.analytics.filters.custom'))
            ->assertSee('value="2026-05-20"', false)
            ->assertSee('value="2026-05-21"', false);

        Carbon::setTestNow();
    }

    public function test_analytics_quick_time_buttons_stage_dates_until_apply(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics'))
            ->assertOk()
            ->assertSee('id="analytics-filter-form"', false)
            ->assertSee('type="hidden" name="preset" value="7d"', false)
            ->assertSee('data-analytics-preset-button', false)
            ->assertSee('data-preset="today"', false)
            ->assertSee('data-date-from="2026-05-21"', false)
            ->assertSee('data-preset="30d"', false)
            ->assertSee('data-date-from="2026-04-22"', false)
            ->assertDontSee('data-analytics-preset-submit', false)
            ->assertDontSee('requestSubmit()', false);

        Carbon::setTestNow();
    }

    public function test_analytics_page_renders_local_log_data_when_view_logs_exist(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $this->ensureViewLogsTable();
        $fixtures = $this->contentFixtures();
        $admin = $this->admin();

        DB::table('view_logs')->insert([
            [
                'article_id' => (int) $fixtures['article']->id,
                'method' => 'GET',
                'path' => '/article/analytics-hot-article',
                'route_name' => 'site.article',
                'status_code' => 200,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0',
                'created_at' => Carbon::parse('2026-05-20 10:00:00'),
            ],
            [
                'article_id' => (int) $fixtures['article']->id,
                'method' => 'GET',
                'path' => '/article/analytics-hot-article',
                'route_name' => 'site.article',
                'status_code' => 200,
                'ip_address' => '127.0.0.2',
                'user_agent' => 'ChatGPT-User/1.0',
                'created_at' => Carbon::parse('2026-05-21 11:00:00'),
            ],
            [
                'article_id' => (int) $fixtures['article']->id,
                'method' => 'GET',
                'path' => '/article/analytics-hot-article',
                'route_name' => 'site.article',
                'status_code' => 200,
                'ip_address' => '127.0.0.3',
                'user_agent' => 'Googlebot/2.1',
                'created_at' => Carbon::parse('2026-05-21 11:30:00'),
            ],
            [
                'article_id' => (int) $fixtures['article']->id,
                'method' => 'GET',
                'path' => '/article/analytics-hot-article',
                'route_name' => 'site.article',
                'status_code' => 200,
                'ip_address' => '127.0.0.4',
                'user_agent' => 'ChatGPT-User/1.0',
                'created_at' => Carbon::parse('2026-05-01 11:00:00'),
            ],
            [
                'article_id' => null,
                'method' => 'GET',
                'path' => '/',
                'route_name' => 'site.home',
                'status_code' => 404,
                'ip_address' => '127.0.0.5',
                'user_agent' => 'Mozilla/5.0',
                'created_at' => Carbon::parse('2026-05-21 12:00:00'),
            ],
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.analytics', [
                'preset' => 'custom',
                'date_from' => '2026-05-20',
                'date_to' => '2026-05-21',
                'log_source' => 'local',
                'traffic_type' => 'all',
            ]))
            ->assertOk()
            ->assertSee(__('admin.analytics.logs_overview'))
            ->assertSee(__('admin.analytics.logs_kpi.pv'))
            ->assertSee('3')
            ->assertSee(__('admin.analytics.logs_kpi.unique_ip'))
            ->assertSee(__('admin.analytics.logs_kpi.ai_bot_pv'))
            ->assertSee('范围内热门文章')
            ->assertSee(__('admin.analytics.logs_bot.ai_bot'))
            ->assertSee(__('admin.analytics.logs_bot.search_bot'))
            ->assertSee('/article/analytics-hot-article')
            ->assertSee(__('admin.analytics.logs_kpi.errors'))
            ->assertSee('1');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.analytics', [
                'preset' => 'custom',
                'date_from' => '2026-05-20',
                'date_to' => '2026-05-21',
                'log_source' => 'local',
                'traffic_type' => 'ai_bot',
            ]))
            ->assertOk()
            ->assertSee(__('admin.analytics.logs_kpi.pv'))
            ->assertSee('1');

        Carbon::setTestNow();
    }

    public function test_analytics_charts_use_compact_three_point_date_axis(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.analytics', ['preset' => '30d']))
            ->assertOk()
            ->assertSee('data-analytics-axis="compact"', false)
            ->assertSee('data-axis-label="start"', false)
            ->assertSee('data-axis-label="middle"', false)
            ->assertSee('data-axis-label="end"', false)
            ->assertSee('04-22')
            ->assertSee('05-06')
            ->assertSee('05-21')
            ->assertDontSee('04-23 ·', false)
            ->assertDontSee('04-23</div>', false);

        Carbon::setTestNow();
    }

    public function test_analytics_uses_event_dates_and_view_logs_for_range_metrics(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $this->ensureViewLogsTable();
        $fixtures = $this->contentFixtures();
        $author = Author::query()->firstOrFail();
        $category = Category::query()->firstOrFail();

        Article::query()->create([
            'title' => '早创建今天发布文章',
            'slug' => 'delayed-published-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $fixtures['task']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'view_count' => 500,
            'is_ai_generated' => 1,
            'published_at' => Carbon::parse('2026-05-21 10:00:00'),
            'created_at' => Carbon::parse('2026-05-01 09:00:00'),
        ]);
        $oldViewed = Article::query()->create([
            'title' => '范围内访问最多的老文章',
            'slug' => 'old-most-viewed-in-range',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $fixtures['task']->id,
            'status' => 'published',
            'review_status' => 'approved',
            'view_count' => 999,
            'is_ai_generated' => 1,
            'published_at' => Carbon::parse('2026-05-01 10:00:00'),
            'created_at' => Carbon::parse('2026-05-01 09:00:00'),
        ]);

        DB::table('view_logs')->insert([
            ...$this->viewLogRows((int) $fixtures['article']->id, '/article/analytics-hot-article', 2, '2026-05-20 10:00:00'),
            ...$this->viewLogRows((int) $oldViewed->id, '/article/old-most-viewed-in-range', 4, '2026-05-21 10:00:00'),
            [
                'article_id' => null,
                'source' => 'local',
                'method' => 'GET',
                'path' => '/',
                'route_name' => 'site.home',
                'status_code' => 200,
                'ip_address' => '10.0.1.200',
                'user_agent' => 'Mozilla/5.0',
                'created_at' => Carbon::parse('2026-05-21 11:00:00'),
            ],
        ]);

        $filter = AnalyticsFilter::fromRequest([
            'preset' => 'custom',
            'date_from' => '2026-05-20',
            'date_to' => '2026-05-21',
        ]);
        $overview = app(AnalyticsOverviewService::class);

        $this->assertSame(2, $overview->kpis($filter)['published']);
        $this->assertSame(7, $overview->kpis($filter)['total_views']);

        $trend = collect($overview->publicationTrend($filter))->keyBy('date');
        $this->assertSame(1, $trend['2026-05-21']['published']);

        $topContent = $overview->topContent($filter, 2);
        $this->assertSame('范围内访问最多的老文章', $topContent[0]->title);
        $this->assertSame(4, (int) $topContent[0]->view_count);

        Carbon::setTestNow();
    }

    public function test_distribution_metrics_respect_task_and_category_filters(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $fixtures = $this->contentFixtures();
        $otherCategory = Category::query()->create([
            'name' => '其它分析分类',
            'slug' => 'other-analytics-category',
            'status' => 'active',
        ]);
        $otherTask = Task::query()->create([
            'name' => '其它分析任务',
            'status' => 'active',
        ]);
        $otherArticle = Article::query()->create([
            'title' => '其它任务文章',
            'slug' => 'other-task-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $otherCategory->id,
            'author_id' => (int) Author::query()->firstOrFail()->id,
            'task_id' => (int) $otherTask->id,
            'status' => 'published',
            'review_status' => 'approved',
            'created_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $otherArticle->id,
            'distribution_channel_id' => (int) $fixtures['channel']->id,
            'action' => 'publish',
            'status' => 'failed',
            'idempotency_key' => 'analytics-other-failed',
            'created_at' => Carbon::parse('2026-05-21 12:00:00'),
        ]);

        $filter = AnalyticsFilter::fromRequest([
            'preset' => 'custom',
            'date_from' => '2026-05-20',
            'date_to' => '2026-05-21',
            'task_id' => (int) $fixtures['task']->id,
            'category_id' => (int) Category::query()->where('slug', 'analytics-category')->value('id'),
        ]);
        $overview = app(AnalyticsOverviewService::class);

        $this->assertSame(1, $overview->distributionSummary($filter)['failed']);
        $this->assertSame(1, $overview->kpis($filter)['distribution_failed']);

        Carbon::setTestNow();
    }

    public function test_log_analytics_filters_source_method_and_classifies_traffic(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-21 12:00:00'));

        $this->ensureViewLogsTable();

        $this->assertSame('ai_bot', TrafficClassifier::classify('OAI-SearchBot/1.0'));
        $this->assertSame('ai_bot', TrafficClassifier::classify('Claude-SearchBot/1.0'));
        $this->assertSame('other_bot', TrafficClassifier::classify('curl/8.0.1'));
        $this->assertSame('human', TrafficClassifier::classify('Mozilla/5.0 Safari/537.36'));
        $this->assertSame('unknown', TrafficClassifier::classify(''));

        DB::table('view_logs')->insert([
            [
                'article_id' => null,
                'source' => 'local',
                'method' => 'GET',
                'path' => '/',
                'route_name' => 'site.home',
                'status_code' => 200,
                'ip_address' => '10.0.0.1',
                'user_agent' => 'Mozilla/5.0 Safari/537.36',
                'created_at' => Carbon::parse('2026-05-21 09:00:00'),
            ],
            [
                'article_id' => null,
                'source' => 'channel',
                'method' => 'GET',
                'path' => '/',
                'route_name' => 'site.home',
                'status_code' => 200,
                'ip_address' => '10.0.0.2',
                'user_agent' => 'Mozilla/5.0 Safari/537.36',
                'created_at' => Carbon::parse('2026-05-21 09:10:00'),
            ],
            [
                'article_id' => null,
                'source' => 'local',
                'method' => 'GET',
                'path' => '/robots.txt',
                'route_name' => null,
                'status_code' => 200,
                'ip_address' => '10.0.0.3',
                'user_agent' => 'curl/8.0.1',
                'created_at' => Carbon::parse('2026-05-21 09:20:00'),
            ],
            [
                'article_id' => null,
                'source' => 'local',
                'method' => 'GET',
                'path' => '/article/ai',
                'route_name' => 'site.article',
                'status_code' => 200,
                'ip_address' => '10.0.0.4',
                'user_agent' => 'OAI-SearchBot/1.0',
                'created_at' => Carbon::parse('2026-05-21 09:30:00'),
            ],
            [
                'article_id' => null,
                'source' => 'local',
                'method' => 'HEAD',
                'path' => '/article/head',
                'route_name' => 'site.article',
                'status_code' => 200,
                'ip_address' => '10.0.0.5',
                'user_agent' => 'ChatGPT-User/1.0',
                'created_at' => Carbon::parse('2026-05-21 09:40:00'),
            ],
        ]);

        $summary = app(AnalyticsLogQueryService::class)->summary(AnalyticsFilter::fromRequest([
            'preset' => 'custom',
            'date_from' => '2026-05-21',
            'date_to' => '2026-05-21',
            'log_source' => 'local',
            'traffic_type' => 'all',
        ]));
        $breakdown = collect($summary['bot_breakdown'])->keyBy('key');

        $this->assertSame(3, $summary['kpis']['pv']);
        $this->assertSame(1, $summary['kpis']['ai_bot_pv']);
        $this->assertSame(1, $breakdown['human']['count']);
        $this->assertSame(1, $breakdown['ai_bot']['count']);
        $this->assertSame(1, $breakdown['other_bot']['count']);

        $humanSummary = app(AnalyticsLogQueryService::class)->summary(AnalyticsFilter::fromRequest([
            'preset' => 'custom',
            'date_from' => '2026-05-21',
            'date_to' => '2026-05-21',
            'log_source' => 'local',
            'traffic_type' => 'human',
        ]));
        $this->assertSame(1, $humanSummary['kpis']['pv']);

        Carbon::setTestNow();
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'analytics_admin',
            'password' => 'secret-123',
            'email' => 'analytics-admin@example.com',
            'display_name' => 'Analytics Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    /**
     * @return array<string, object>
     */
    private function contentFixtures(): array
    {
        $author = Author::query()->create([
            'name' => '分析作者',
            'slug' => 'analytics-author',
            'status' => 'active',
        ]);
        $category = Category::query()->create([
            'name' => '分析分类',
            'slug' => 'analytics-category',
            'status' => 'active',
        ]);
        $task = Task::query()->create([
            'name' => '分析任务',
            'status' => 'active',
            'article_limit' => 10,
            'created_count' => 2,
            'published_count' => 1,
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => '分析渠道',
            'domain' => 'analytics.example.com',
            'endpoint_url' => 'https://analytics.example.com',
            'status' => 'active',
        ]);
        $article = Article::query()->create([
            'title' => '范围内热门文章',
            'slug' => 'analytics-hot-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'view_count' => 12,
            'is_ai_generated' => 1,
            'published_at' => Carbon::parse('2026-05-20 10:00:00'),
            'created_at' => Carbon::parse('2026-05-20 09:00:00'),
        ]);
        Article::query()->create([
            'title' => '范围内草稿文章',
            'slug' => 'analytics-draft-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'status' => 'draft',
            'review_status' => 'pending',
            'view_count' => 0,
            'is_ai_generated' => 1,
            'created_at' => Carbon::parse('2026-05-21 09:00:00'),
        ]);
        Article::query()->create([
            'title' => '范围外文章',
            'slug' => 'analytics-old-article',
            'excerpt' => '摘要',
            'content' => '正文',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'view_count' => 99,
            'is_ai_generated' => 1,
            'published_at' => Carbon::parse('2026-05-12 10:00:00'),
            'created_at' => Carbon::parse('2026-05-12 09:00:00'),
        ]);
        TaskRun::query()->create([
            'task_id' => (int) $task->id,
            'article_id' => (int) $article->id,
            'status' => 'running',
            'created_at' => Carbon::parse('2026-05-20 11:00:00'),
        ]);
        TaskRun::query()->create([
            'task_id' => (int) $task->id,
            'status' => 'failed',
            'error_message' => '测试失败',
            'created_at' => Carbon::parse('2026-05-21 11:00:00'),
        ]);
        AiModel::query()->create([
            'name' => '分析模型',
            'model_id' => 'gpt-test',
            'model_type' => 'chat',
            'api_url' => 'https://api.example.com',
            'used_today' => 9,
            'total_used' => 18,
            'status' => 'active',
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'idempotency_key' => 'analytics-synced',
            'created_at' => Carbon::parse('2026-05-20 12:00:00'),
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'update',
            'status' => 'failed',
            'idempotency_key' => 'analytics-failed',
            'created_at' => Carbon::parse('2026-05-21 12:00:00'),
        ]);

        return [
            'channel' => $channel,
            'task' => $task,
            'article' => $article,
        ];
    }

    private function ensureViewLogsTable(): void
    {
        if (Schema::hasTable('view_logs')) {
            DB::table('view_logs')->truncate();

            return;
        }

        Schema::create('view_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->string('source', 32)->default('local');
            $table->string('method', 16)->default('GET');
            $table->string('path', 2048)->default('');
            $table->string('route_name', 128)->nullable();
            $table->unsignedSmallInteger('status_code')->default(200);
            $table->string('ip_address', 64)->default('');
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function viewLogRows(int $articleId, string $path, int $count, string $createdAt): array
    {
        $rows = [];

        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'article_id' => $articleId,
                'source' => 'local',
                'method' => 'GET',
                'path' => $path,
                'route_name' => 'site.article',
                'status_code' => 200,
                'ip_address' => '10.0.1.'.$articleId.'.'.$i,
                'user_agent' => 'Mozilla/5.0',
                'created_at' => Carbon::parse($createdAt)->copy()->addMinutes($i),
            ];
        }

        return $rows;
    }
}
