<?php

namespace Tests\PostgreSQL;

use App\Jobs\RefreshUrlChange;
use App\Models\Admin;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\UrlChangeRequest;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\UrlChangeReportStore;
use App\Services\Site\UrlChangeService;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

class UrlChangeCategoryScaleTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_large_category_reports_and_hosted_record_refresh_are_bounded(): void
    {
        if (getenv('GEOFLOW_URL_SCALE') !== '1') {
            $this->markTestSkipped('Set GEOFLOW_URL_SCALE=1 for the isolated category and hosted-site scale checks.');
        }
        $this->assertSame('geoflow_hosted_test', config('database.connections.pgsql.database'));
        $this->assertSame('testing', app()->environment());
        Queue::fake();
        config(['filesystems.disks.local.root' => sys_get_temp_dir().'/geoflow-url-category-scale-'.bin2hex(random_bytes(8)), 'queue.default' => 'database']);
        Storage::forgetDisk('local');
        $admin = Admin::query()->create(['username' => 'category-scale', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $target = Category::query()->create(['name' => 'Target', 'slug' => 'target']);
        $other = Category::query()->create(['name' => 'Other', 'slug' => 'other']);
        $author = Author::query()->create(['name' => 'Editor']);
        $modelId = DB::table('ai_models')->insertGetId(['name' => 'Scale fixture', 'api_key' => 'test-only', 'model_id' => 'test-only']);
        $promptId = DB::table('prompts')->insertGetId(['name' => 'Scale fixture', 'type' => 'content', 'content' => 'Test body']);
        $libraryId = DB::table('title_libraries')->insertGetId(['name' => 'Scale fixture']);
        $task = Task::query()->create(['name' => 'Hosted scale', 'title_library_id' => $libraryId, 'prompt_id' => $promptId, 'ai_model_id' => $modelId, 'status' => 'active', 'publish_scope' => 'distribution_only']);
        $service = app(UrlChangeService::class);
        $inserted = 0;
        foreach ([[100000, 10000, 1], [300000, 200000, 10]] as [$total, $affected, $rules]) {
            DB::statement("INSERT INTO articles (id, title, slug, content, category_id, author_id, task_id, status, review_status, created_at, updated_at) SELECT n, 'Category scale ' || n, 'category-scale-' || n, repeat('Body ', 128), ?, ?, ?, 'published', 'approved', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM generate_series(CAST(? AS BIGINT), CAST(? AS BIGINT)) n", [$other->id, $author->id, $task->id, $inserted + 1, $total]);
            DB::table('articles')->where('id', '<=', $affected)->update(['category_id' => $target->id]);
            $inserted = $total;
            $policy = ArticlePermalinkPolicy::defaults()->activate('/{category}/{slug}');
            for ($rule = 1; $rule < $rules; $rule++) {
                $policy = $policy->activate('/{category}/revision-'.$rule.'/{slug}');
            }
            SiteSetting::query()->updateOrCreate(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY], ['setting_value' => json_encode($policy->toArray())]);
            SiteSettingsBag::forget();
            app(ArticlePermalinkService::class)->forgetPolicy();
            if ($total === 300000) {
                $channel = DistributionChannel::query()->create(['name' => 'Scale hosted', 'domain' => 'category-scale.sites.test', 'endpoint_url' => 'https://category-scale.sites.test', 'channel_type' => 'hosted_site', 'status' => 'active', 'site_settings' => [ArticlePermalinkPolicy::SETTING_KEY => ArticlePermalinkPolicy::defaults()->activate('/{category}/{id}')->toArray()]]);
                $profile = HostedSiteProfile::query()->create(['distribution_channel_id' => $channel->id, 'hostname' => 'category-scale.sites.test', 'root_domain' => 'sites.test', 'topic' => 'Scale', 'serving_status' => 'online']);
                DB::statement("INSERT INTO hosted_site_article_assignments (article_id, hosted_site_profile_id, status, content_fingerprint, capacity_date, assigned_at) SELECT id, ?, 'published', md5(id::text), CURRENT_DATE, CURRENT_TIMESTAMP FROM articles WHERE id <= 50000", [$profile->id]);
                DB::statement("INSERT INTO article_distributions (article_id, distribution_channel_id, status, remote_url, idempotency_key) SELECT id, ?, 'synced', 'https://category-scale.sites.test/' || ? || '/' || id, 'category-scale-' || id FROM articles WHERE id <= 50000", [$channel->id, $target->slug]);
            }
            DB::statement('ANALYZE articles');
            memory_reset_peak_usage();
            $memory = memory_get_usage(true);
            $started = microtime(true);
            $change = $service->start($admin, 'category', $target->id, 'renamed-'.$total);
            $batchMax = 0;
            for ($step = 0; $step < 5000 && $change->refresh()->status === 'checking'; $step++) {
                $tick = microtime(true);
                $service->checkSegment($change);
                $batchMax = max($batchMax, microtime(true) - $tick);
            }
            $check = microtime(true) - $started;
            $this->assertSame('ready', $change->refresh()->status, (string) $change->error);
            $this->assertSame($affected, $change->summary['associated']);
            $this->assertSame($affected, $change->summary['changed_articles']);
            $this->assertSame($affected + ($total === 300000 ? 50000 : 0), $change->summary['changed_urls']);
            $this->assertSame($total === 300000 ? 50000 : 0, $change->summary['synced_records']);
            $rows = 0;
            foreach (app(UrlChangeReportStore::class)->rows($change) as $row) {
                $this->assertLessThanOrEqual($affected, $row['id']);
                $rows++;
            }
            $this->assertSame($change->summary['changed_urls'], $rows);
            $tick = microtime(true);
            $service->confirm($admin, $change, $service->credential($change), $service->phrase($change));
            $apply = microtime(true) - $tick;
            $refreshStarted = microtime(true);
            $this->refreshAll($change);
            if ($total === 300000) {
                $this->assertSame(50000, DB::table('article_distributions')->where('remote_url', 'like', 'https://category-scale.sites.test/renamed-300000/%')->count());
            }
            $peak = (memory_get_peak_usage(true) - $memory) / 1024 ** 2;
            fwrite(STDOUT, "\nURL_CATEGORY_SCALE ".json_encode(['articles' => $total, 'target_articles' => $affected, 'category_rules' => $rules, 'hosted_records' => $total === 300000 ? 50000 : 0, 'check_seconds' => round($check, 2), 'batch_max_ms' => round($batchMax * 1000, 2), 'apply_service_ms' => round($apply * 1000, 2), 'refresh_seconds' => round(microtime(true) - $refreshStarted, 2), 'memory_delta_mib' => $peak])."\n");
            $this->assertLessThan(15, $batchMax);
            $this->assertLessThan(1, $apply);
            $this->assertLessThan(256, $peak);
            $target->refresh();
        }
    }

    private function refreshAll(UrlChangeRequest $change): void
    {
        for ($step = 0; $step < 3000 && $change->refresh()->status !== 'completed'; $step++) {
            app()->call([new RefreshUrlChange($change->id), 'handle']);
        }
        $this->assertSame('completed', $change->refresh()->status);
    }
}
