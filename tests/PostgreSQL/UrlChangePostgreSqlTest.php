<?php

namespace Tests\PostgreSQL;

use App\Jobs\RefreshUrlChange;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\UrlChangeRequest;
use App\Services\GeoFlow\CategorySlugRegistry;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\UrlChangeService;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class UrlChangePostgreSqlTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function createApplication()
    {
        $app = parent::createApplication();
        if ($app['config']->get('database.connections.pgsql.database') !== 'geoflow_hosted_test'
            || ! in_array((string) $app['config']->get('database.connections.pgsql.port'), ['55439', '5432'], true)
            || $app['config']->get('app.env') !== 'testing') {
            throw new \RuntimeException('URL tests require the isolated geoflow_hosted_test database in the testing environment.');
        }

        return $app;
    }

    public function test_bulk_url_versions_and_typed_confirmation_on_postgresql(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = Admin::query()->create(['username' => 'risk-pg', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor']);
        Article::query()->create(['title' => 'Example', 'slug' => 'example', 'content' => 'Content', 'status' => 'published', 'category_id' => $category->id, 'author_id' => $author->id]);
        $service = app(UrlChangeService::class);
        $change = $service->start($admin, 'category', $category->id, 'new-news');
        while ($change->refresh()->status === 'checking') {
            $service->checkSegment($change);
        }
        $this->assertSame('ready', $change->status, (string) $change->error);
        $service->confirm($admin, $change, $service->credential($change), $service->phrase($change));
        $this->assertSame('new-news', $category->refresh()->slug);
        $this->assertDatabaseHas('category_slug_histories', ['slug' => 'news', 'category_id' => $category->id]);
        $this->assertNotNull($change->refresh()->applied_at);
        Queue::assertPushed(RefreshUrlChange::class);
    }

    public function test_concurrent_categories_cannot_claim_the_same_slug(): void
    {
        $categories = collect(['first', 'second'])->map(fn ($slug) => Category::query()->create(['name' => $slug, 'slug' => $slug]));
        $children = [];
        $start = microtime(true) + 0.2;
        DB::disconnect('pgsql');
        foreach ($categories as $category) {
            $pid = pcntl_fork();
            $this->assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                DB::purge('pgsql');
                usleep((int) max(0, ($start - microtime(true)) * 1_000_000));
                try {
                    DB::statement("SET lock_timeout = '3s'");
                    app(CategorySlugRegistry::class)->change(Category::query()->findOrFail($category->id), 'shared');
                    exit(0);
                } catch (ValidationException) {
                    exit(10);
                } catch (\Throwable $exception) {
                    fwrite(STDERR, $exception->getMessage());
                    exit(20);
                }
            }
            $children[] = $pid;
        }
        $results = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $results[] = pcntl_wexitstatus($status);
        }
        DB::purge('pgsql');
        sort($results);
        $this->assertSame([0, 10], $results);
        $this->assertSame(1, Category::query()->where('slug', 'shared')->count());
    }

    public function test_real_database_scale_check_and_short_apply(): void
    {
        if (getenv('GEOFLOW_URL_SCALE') !== '1') {
            $this->markTestSkipped('Set GEOFLOW_URL_SCALE=1 to run 100k, 300k and 500k database checks.');
        }
        Queue::fake();
        config(['filesystems.disks.local.root' => sys_get_temp_dir().'/geoflow-url-scale-'.bin2hex(random_bytes(8))]);
        Storage::forgetDisk('local');
        config(['queue.default' => 'database']);
        $admin = Admin::query()->create(['username' => 'scale', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor']);
        $service = app(UrlChangeService::class);
        $inserted = 0;
        $counts = getenv('GEOFLOW_URL_SCALE_ONLY') === '500000' ? [500000] : [100000, 300000, 500000];
        foreach ($counts as $count) {
            DB::statement("INSERT INTO articles (title, slug, content, category_id, author_id, status, created_at, updated_at) SELECT 'Scale article ' || n, 'scale-' || n, repeat('Content ', 128), ?, ?, 'published', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM generate_series(CAST(? AS BIGINT), CAST(? AS BIGINT)) n", [$category->id, $author->id, $inserted + 1, $count]);
            $inserted = $count;
            $historyRules = $count === 100000 ? 1 : ($count === 300000 ? 10 : 30);
            $policy = ArticlePermalinkPolicy::defaults();
            for ($rule = 1; $rule < $historyRules; $rule++) {
                $policy = $policy->activate(($rule % 2 === 0 ? '/{category}' : '/{year}').'/revision-'.$rule.'/{slug}');
            }
            SiteSetting::query()->updateOrCreate(['setting_key' => ArticlePermalinkPolicy::SETTING_KEY], ['setting_value' => json_encode($policy->toArray())]);
            SiteSettingsBag::forget();
            app(ArticlePermalinkService::class)->forgetPolicy();
            if ($count === 500000 && getenv('GEOFLOW_URL_HISTORY_STRESS') === '1') {
                DB::statement("INSERT INTO article_slug_histories (article_id, slug, created_at) SELECT id, 'history-' || id || '-' || n, CURRENT_TIMESTAMP FROM articles CROSS JOIN generate_series(1, 5) n");
                DB::statement("INSERT INTO article_slug_histories (article_id, slug, created_at) SELECT 1, 'long-history-' || n, CURRENT_TIMESTAMP FROM generate_series(1, 10000) n");
                DB::statement('ANALYZE article_slug_histories');
            }
            DB::statement('ANALYZE articles');
            $articlePath = $policy->currentPattern === '/article/{slug}' ? '/article/scale-1' : str_replace(['{category}', '{year}', '{slug}'], ['news', now()->format('Y'), 'scale-1'], $policy->currentPattern);
            $this->get($articlePath)->assertOk();
            $baseline = [];
            for ($sample = 0; $sample < 20; $sample++) {
                $tick = microtime(true);
                $this->get($articlePath)->assertOk();
                $baseline[] = (microtime(true) - $tick) * 1000;
            }
            $probePath = tempnam(sys_get_temp_dir(), 'geoflow-url-load-');
            DB::disconnect('pgsql');
            $probePid = pcntl_fork();
            $this->assertGreaterThanOrEqual(0, $probePid);
            if ($probePid === 0) {
                DB::purge('pgsql');
                $samples = [];
                try {
                    for ($sample = 0; $sample < 100; $sample++) {
                        $tick = microtime(true);
                        $response = $this->get($articlePath);
                        $samples[] = ['ms' => (microtime(true) - $tick) * 1000, 'status' => $response->status()];
                        usleep(100000);
                    }
                    file_put_contents($probePath, json_encode($samples, JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($probePath, $exception->getMessage());
                    exit(20);
                }
            }
            DB::purge('pgsql');
            $started = microtime(true);
            $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version])->actingAs($admin, 'admin')
                ->post(route('admin.url-changes.store'), ['operation' => 'primary', 'value' => '/scale-'.$count.'/{category}/{slug}'])
                ->assertRedirect();
            $change = UrlChangeRequest::query()->latest('id')->firstOrFail();
            $startMs = (microtime(true) - $started) * 1000;
            memory_reset_peak_usage();
            $memory = memory_get_usage(true);
            $batchMaxMs = 0;
            $segments = 0;
            $lockWaitSamples = 0;
            $statusSamples = [];
            while ($change->refresh()->status === 'checking') {
                $batchStart = microtime(true);
                $service->checkSegment($change);
                $batchMaxMs = max($batchMaxMs, (microtime(true) - $batchStart) * 1000);
                $segments++;
                if ($segments % 20 === 0) {
                    $lockWaitSamples += DB::table('pg_stat_activity')->where('datname', 'geoflow_hosted_test')->where('wait_event_type', 'Lock')->count();
                    $tick = microtime(true);
                    $this->getJson(route('admin.url-changes.status', $change))->assertOk();
                    $statusSamples[] = (microtime(true) - $tick) * 1000;
                }
                $this->assertLessThan(40000, $segments);
            }
            $checkSeconds = microtime(true) - $started;
            $memoryDelta = (memory_get_peak_usage(true) - $memory) / 1024 ** 2;
            $this->assertSame('ready', $change->status, (string) $change->error);
            $this->assertSame($count, $change->summary['changed_urls']);
            $applyStart = microtime(true);
            $this->post(route('admin.url-changes.confirm', $change), ['credential' => $service->credential($change), 'confirmation' => $service->phrase($change)])->assertSessionHasNoErrors()->assertRedirect();
            $applyMs = (microtime(true) - $applyStart) * 1000;
            $refreshStarted = microtime(true);
            $refreshSteps = 0;
            while ($change->refresh()->status !== 'completed') {
                app()->call([new RefreshUrlChange($change->id), 'handle']);
                $this->assertLessThan(2000, ++$refreshSteps);
            }
            pcntl_waitpid($probePid, $probeStatus);
            $this->assertSame(0, pcntl_wexitstatus($probeStatus), (string) file_get_contents($probePath));
            $loaded = json_decode(file_get_contents($probePath), true, flags: JSON_THROW_ON_ERROR);
            unlink($probePath);
            $this->assertSame([200], array_values(array_unique(array_column($loaded, 'status'))));
            $p95 = static function (array $values): float {
                sort($values);

                return $values[(int) ceil(count($values) * 0.95) - 1];
            };
            $plan = DB::select('EXPLAIN (ANALYZE, BUFFERS, FORMAT JSON) SELECT id, slug, category_id, created_at FROM articles WHERE category_id = ? AND id > ? ORDER BY id LIMIT 500', [$category->id, $count - 1000]);
            fwrite(STDOUT, "\nURL_SCALE ".json_encode(['articles' => $count, 'rules' => $historyRules, 'slug_histories' => DB::table('article_slug_histories')->count(), 'start_http_ms' => round($startMs, 2), 'status_http_p95_ms' => round($p95($statusSamples), 2), 'check_seconds' => round($checkSeconds, 2), 'segments' => $segments, 'batch_max_ms' => round($batchMaxMs, 2), 'memory_delta_mib' => round($memoryDelta, 2), 'apply_http_ms' => round($applyMs, 2), 'refresh_seconds' => round(microtime(true) - $refreshStarted, 2), 'front_baseline_p95_ms' => round($p95($baseline), 2), 'front_loaded_p95_ms' => round($p95(array_column($loaded, 'ms')), 2), 'lock_wait_samples' => $lockWaitSamples, 'query_plan' => $plan])."\n");
            $this->assertLessThan(2000, $startMs);
            $this->assertLessThan(15000, $batchMaxMs);
            $this->assertLessThan(256, $memoryDelta);
            $this->assertLessThan(1000, $applyMs);
        }
    }
}
