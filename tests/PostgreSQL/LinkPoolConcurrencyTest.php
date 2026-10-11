<?php

namespace Tests\PostgreSQL;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\LinkPoolThemeAdaptation;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\LinkPool\LinkCampaignService;
use App\Services\LinkPool\LinkPoolImportService;
use App\Services\LinkPool\LinkPoolThemeAdaptationService;
use App\Services\Site\LinkPoolRecommendationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class LinkPoolConcurrencyTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_concurrent_refresh_keeps_one_head_and_one_current_batch(): void
    {
        $source = $this->article('race-source', 'source');
        $target = $this->article('race-target', 'target');
        $this->enableSite();
        $targetId = DB::table('link_pool_targets')->insertGetId([
            'target_site_key' => 'primary', 'article_id' => $target->id,
            'input_url' => 'https://primary.test/article/race-target', 'input_title' => 'Target',
            'display_title' => 'Target', 'topic' => '', 'priority' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $this->draft([$targetId]), 1);
        app(LinkCampaignService::class)->publish($campaignId, 1);
        $outcomes = $this->race([
            static fn () => app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id),
            static fn () => app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id),
        ]);
        $this->assertSame(['ok', 'ok'], $outcomes);
        $this->assertSame(1, DB::table('link_selection_heads')->count());
        $this->assertSame(1, DB::table('link_selection_batches')->count());
        $this->assertSame(1, DB::table('link_placements')->count());
        $this->assertSame(
            DB::table('link_placements')->orderBy('id')->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            app(LinkPoolRecommendationService::class)->renderedPlacementIds(['primary']),
        );
    }

    public function test_concurrent_imports_preserve_every_library_revision(): void
    {
        $this->enableSite();
        Queue::fake();
        $outcomes = $this->race([
            static function (): void {
                $imports = app(LinkPoolImportService::class);
                $imports->import($imports->preflight('https://example.com/one,One'), 1, false);
            },
            static function (): void {
                $imports = app(LinkPoolImportService::class);
                $imports->import($imports->preflight('https://example.com/two,Two'), 1, false);
            },
        ]);
        $this->assertSame(['ok', 'ok'], $outcomes);
        $this->assertSame(2, DB::table('link_pool_targets')->count());
        $this->assertSame(2, (int) DB::table('site_settings')->where('setting_key', 'link_pool_library_revision')->value('setting_value'));
    }

    public function test_keyword_preview_matches_source_keyword_case_on_postgresql(): void
    {
        $this->enableSite();
        Queue::fake();
        $source = $this->article('keyword-source', 'Source');
        $source->update(['keywords' => 'AI,SEO']);
        $imports = app(LinkPoolImportService::class);
        $imports->import($imports->preflight('https://example.com/,External'), 1, false);
        $targetId = (int) DB::table('link_pool_targets')->value('id');
        $draft = [...$this->draft([$targetId]), 'scope' => 'keyword', 'keyword' => 'ai'];
        $campaign = app(LinkCampaignService::class)->saveDraft(null, $draft, 1);
        $this->assertSame((int) $source->id, app(LinkPoolRecommendationService::class)->sampleSource('primary', $campaign, true)?->id);
    }

    public function test_concurrent_publish_accepts_one_revision_and_reports_one_conflict(): void
    {
        $this->enableSite();
        $target = $this->article('publish-target', 'target');
        $targetId = DB::table('link_pool_targets')->insertGetId([
            'target_site_key' => 'primary', 'article_id' => $target->id,
            'input_url' => 'https://primary.test/article/publish-target', 'input_title' => 'Target',
            'display_title' => 'Target', 'topic' => '', 'priority' => 1, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $campaignId = app(LinkCampaignService::class)->saveDraft(null, $this->draft([$targetId]), 1);
        $outcomes = $this->race([
            static function () use ($campaignId): void {
                app(LinkCampaignService::class)->publish($campaignId, 1);
            },
            static function () use ($campaignId): void {
                app(LinkCampaignService::class)->publish($campaignId, 1);
            },
        ]);
        sort($outcomes);
        $this->assertSame(['conflict', 'ok'], $outcomes);
        $this->assertSame(1, (int) DB::table('link_campaigns')->where('id', $campaignId)->value('revision'));
        $this->assertSame(1, DB::table('link_campaign_targets')->where('campaign_id', $campaignId)->count());
    }

    public function test_concurrent_adaptation_clicks_create_one_site_task(): void
    {
        $admin = $this->prepareAdaptation();
        $outcomes = $this->race([
            static fn () => app(LinkPoolThemeAdaptationService::class)->start('primary', $admin),
            static fn () => app(LinkPoolThemeAdaptationService::class)->start('primary', $admin),
        ]);

        $this->assertSame(['ok', 'ok'], $outcomes);
        $this->assertSame(1, LinkPoolThemeAdaptation::query()->count());
        $this->assertSame(1, LinkPoolThemeAdaptation::query()->where('active_site_key', 'primary')->count());
    }

    public function test_concurrent_workers_claim_and_publish_the_adaptation_once(): void
    {
        $admin = $this->prepareAdaptation();
        $task = app(LinkPoolThemeAdaptationService::class)->start('primary', $admin);
        $outcomes = $this->race([
            static fn () => app(LinkPoolThemeAdaptationService::class)->run($task->id),
            static fn () => app(LinkPoolThemeAdaptationService::class)->run($task->id),
        ]);

        $this->assertSame(['ok', 'ok'], $outcomes);
        $this->assertSame('completed', $task->fresh()->status, $task->fresh()->error_message ?? '');
        $this->assertSame(1, ThemeRelease::query()->where('kind', 'link_pool_adaptation')->count());
        $this->assertSame(1, (int) SiteThemeBinding::query()->findOrFail('primary')->lock_version);
    }

    public function test_concurrent_external_import_keeps_one_url_identity(): void
    {
        Queue::fake();
        $outcomes = $this->race([
            static function (): void {
                $service = app(LinkPoolImportService::class);
                $service->import($service->preflight('https://www.aichafen.com/,爱查分'), 1, false);
            },
            static function (): void {
                $service = app(LinkPoolImportService::class);
                $service->import($service->preflight('https://WWW.AICHAFEN.COM:443/,爱查分'), 1, false);
            },
        ]);
        $this->assertSame(['ok', 'ok'], $outcomes);
        $this->assertSame(1, DB::table('link_pool_targets')->count());
        $this->assertDatabaseHas('link_pool_targets', ['target_type' => 'external', 'article_id' => null, 'input_url' => 'https://www.aichafen.com/']);
    }

    public function test_concurrent_external_refresh_keeps_distinct_placements(): void
    {
        Queue::fake();
        $this->enableSite();
        $source = $this->article('external-source', 'source');
        $service = app(LinkPoolImportService::class);
        $service->import($service->preflight("https://www.aichafen.com/,爱查分\nhttps://example.com/product,产品"), 1, false);
        $ids = DB::table('link_pool_targets')->pluck('id')->all();
        $campaign = app(LinkCampaignService::class)->saveDraft(null, $this->draft($ids), 1);
        app(LinkCampaignService::class)->publish($campaign, 1);
        $this->assertSame(['ok', 'ok'], $this->race([
            static fn () => app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id),
            static fn () => app(LinkPoolRecommendationService::class)->refresh('primary', (int) $source->id),
        ]));
        $this->assertSame(1, DB::table('link_selection_heads')->count());
        $this->assertSame(1, DB::table('link_selection_batches')->count());
        $this->assertSame(2, DB::table('link_placements')->distinct()->count('target_id'));
    }

    protected function tearDown(): void
    {
        // Remove this test's external fixtures before rolling back the schema.
        if ($this->app !== null && Schema::hasTable('link_pool_targets')) {
            foreach (['link_placements', 'link_pool_events', 'link_metrics_daily', 'link_pool_targets'] as $table) {
                DB::table($table)->where('target_site_key', 'external')->delete();
            }
        }
        parent::tearDown();
    }

    private function prepareAdaptation(): Admin
    {
        Storage::fake('local');
        Queue::fake();
        config()->set('geoflow.link_pool_theme_shared_storage', true);
        config()->set('geoflow.link_pool_theme_readback_base_url', '');
        $theme = (string) config('geoflow.default_theme');
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $theme]);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'article_recommendations'], ['setting_value' => json_encode(['enabled' => false, 'revision' => 0])]);
        $source = "@extends('theme.".$theme.".layout')\n@section('content')\n<article>{{ \$article->title }}</article>\n@if(\$relatedArticles->isNotEmpty())\n<section><h2>相关文章</h2></section>\n@endif\n@endsection";
        $base = app(ThemeRevisionStorage::class)->create((string) Str::uuid(), $theme, [
            'resources/views/theme/'.$theme.'/article.blade.php' => $source,
            'resources/views/theme/'.$theme.'/layout.blade.php' => '<html><head>@stack("head")</head><body>@yield("content")@stack("scripts")</body></html>',
            'resources/views/theme/'.$theme.'/home.blade.php' => '<html><body>home</body></html>',
            'resources/views/site/partials/link-pool.blade.php' => (string) file_get_contents(resource_path('views/site/partials/link-pool.blade.php')),
        ], []);
        SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => $theme, 'revision_id' => $base->id, 'settings' => [], 'lock_version' => 0]);

        return Admin::query()->create(['username' => 'adaptation-pg', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);
    }

    /** @param list<\Closure> $actions @return list<string> */
    private function race(array $actions): array
    {
        $directory = sys_get_temp_dir().'/link-pool-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect('pgsql');
        try {
            foreach ($actions as $index => $action) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork test worker.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge('pgsql');
                        DB::reconnect('pgsql');
                        file_put_contents($directory.'/ready-'.$index, '1');
                        $deadline = microtime(true) + 10;
                        while (! is_file($directory.'/ready-'.(1 - $index))) {
                            if (microtime(true) > $deadline) {
                                throw new RuntimeException('Race barrier timed out.');
                            }
                            usleep(1000);
                        }
                        try {
                            $action();
                            $result = 'ok';
                        } catch (ValidationException) {
                            $result = 'conflict';
                        }
                        file_put_contents($directory.'/result-'.$index, $result);
                        DB::disconnect('pgsql');
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($directory.'/result-'.$index, $exception::class.': '.$exception->getMessage());
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            $outcomes = [];
            foreach ($children as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $result = file_get_contents($directory.'/result-'.$index);
                $this->assertSame(0, pcntl_wexitstatus($status), $result);
                $outcomes[] = $result;
            }
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            return $outcomes;
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            DB::purge('pgsql');
            DB::reconnect('pgsql');
        }
    }

    private function enableSite(): void
    {
        SiteSetting::query()->updateOrCreate(['setting_key' => 'article_recommendations'], [
            'setting_value' => json_encode(['enabled' => true, 'heading' => 'Recommended', 'default_limit' => 4, 'rotation_hours' => 24]),
        ]);
    }

    /** @return array<string,mixed> */
    private function draft(array $targetIds): array
    {
        return ['source_site_key' => 'primary', 'name' => 'Concurrency campaign', 'mode' => 'manual',
            'priority' => 0, 'limit' => 4, 'scope' => 'all', 'target_ids' => $targetIds, 'base_revision' => 0];
    }

    private function article(string $slug, string $categorySlug): Article
    {
        $category = Category::query()->create(['name' => $categorySlug, 'slug' => $categorySlug]);
        $author = Author::query()->firstOrCreate(['email' => 'link-pool-pg@example.test'], ['name' => 'Author']);

        return Article::query()->create([
            'title' => $slug, 'slug' => $slug, 'content' => $slug,
            'category_id' => $category->id, 'author_id' => $author->id,
            'status' => 'published', 'review_status' => 'approved', 'published_at' => now(),
        ]);
    }
}
