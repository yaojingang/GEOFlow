<?php

namespace Tests\Feature\Topics;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\SiteThemeReplication;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Models\ThemeWorkspace;
use App\Models\Topic;
use App\Services\Admin\SiteThemePackageService;
use App\Services\Admin\SiteThemeReplication\ThemeComplianceGuard;
use App\Services\Admin\SiteThemeReplication\ThemeScaffoldWriter;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\Api\ThemeWorkspaceService;
use App\Services\Topics\TopicReadModel;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicSiteSettings;
use App\Services\Topics\TopicTemplateCatalog;
use App\Services\Topics\TopicThemeCompatibility;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ThemePackageFixture;
use Tests\TestCase;

class TopicPublicTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_have_sources_schema_theme_and_reverse_links_without_optional_score(): void
    {
        $topic = $this->topic('GEO 内容指南');
        $html = $this->get('/topics/'.$topic->slug)->assertOk()->assertSee('有依据的导读')->assertSee('结构化摘要')->assertDontSee('EDITOR SCORE')->getContent();
        $this->assertSame(1, substr_count($html, 'rel="canonical"'));
        $this->assertStringContainsString('CollectionPage', $html);
        $this->assertStringContainsString('ItemList', $html);
        $this->assertStringNotContainsString('AggregateRating', $html);
        $this->get('/topics')->assertOk()->assertSee('GEO 内容指南');
        $this->get('/')->assertOk()->assertSee('/topics/'.$topic->slug, false);
        $this->get('/article/'.Article::query()->first()->slug)->assertOk()->assertSee('/topics/'.$topic->slug, false);
    }

    public function test_withdrawn_source_is_removed_from_all_public_surfaces_immediately(): void
    {
        $topic = $this->topic('安全来源专题');
        $this->get('/topics')->assertOk()->assertSee('安全来源专题');
        Article::query()->first()->update(['status' => 'private']);
        $this->get('/topics/'.$topic->slug)->assertNotFound();
        $this->get('/topics')->assertOk()->assertDontSee('安全来源专题');
        $this->get('/sitemap.txt')->assertOk()->assertDontSee('/topics/'.$topic->slug, false);
        $this->get('/llms.txt')->assertOk()->assertDontSee('安全来源专题');
    }

    public function test_pagination_has_crawlable_paths_unique_canonical_and_filter_noindex(): void
    {
        for ($i = 0; $i < 21; $i++) {
            $this->topic('分页专题 '.$i);
        }
        $this->get('/topics')->assertOk()->assertSee('/topics/page/2', false);
        $this->get('/topics/page/2')->assertOk()->assertSee('href="http://localhost/topics/page/2"', false);
        $this->get('/topics?page=2')->assertRedirect('http://localhost/topics/page/2')->assertStatus(301);
        $this->get('/topics/page/1')->assertRedirect('http://localhost/topics')->assertStatus(301);
        $this->get('/topics/page/99')->assertNotFound();
        $this->get('/topics?search=分页')->assertOk()->assertSee('noindex', false);
    }

    public function test_discovery_inline_and_shards_use_only_safe_topic_views(): void
    {
        $topic = $this->topic('专题地图');
        $this->get('/sitemap.xml')->assertOk()->assertSee('http://localhost/topics/'.$topic->slug, false);
        $this->get('/sitemap.txt')->assertOk()->assertSee('http://localhost/topics/'.$topic->slug, false);
        $this->get('/llms.txt')->assertOk()->assertSee('## Topics')->assertSee('专题地图');
        config(['geoflow.hosted_sites.sitemap_url_limit' => 2]);
        $this->get('/sitemaps/topics-1.xml')->assertOk()->assertSee('http://localhost/topics<', false)->assertSee($topic->slug);
        $this->get('/sitemaps/topics-9.xml')->assertNotFound();
    }

    public function test_old_immutable_theme_is_upgraded_with_preserved_brand_and_complete_topic_assets(): void
    {
        Storage::fake('local');
        $topic = $this->topic('旧模板专题');
        $theme = 'geoflow-template-01-ink-editorial';
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $theme]);
        $contents = app(ThemeWorkspaceService::class)->sourceContents($theme, 'builtin');
        foreach ($contents as $path => $bytes) {
            if (str_contains($path, '/topics/') || str_contains($path, '/topic-') || str_ends_with($path, '/topics.css') || str_ends_with($path, '/topics.js') || str_ends_with($path, '/related-topics.blade.php')) {
                unset($contents[$path]);

                continue;
            }if (str_ends_with($path, '.blade.php')) {
                $contents[$path] = preg_replace("~@include\\(['\"]site\\.partials\\.(?:topic-home|topic-assets|topic-navigation|related-topics)['\"]\\)~", '', $bytes);
            }
        }
        $home = 'resources/views/theme/'.$theme.'/home.blade.php';
        $contents[$home] = str_replace('@endsection', '<p>我的原始品牌内容</p>@endsection', $contents[$home]);
        $workspace = ThemeWorkspace::query()->create(['id' => (string) Str::uuid(), 'instance_id' => (string) Str::uuid(), 'admin_id' => $this->actor()->id, 'site_key' => 'primary', 'theme_id' => $theme, 'source' => 'builtin', 'state' => 'draft']);
        $base = app(ThemeRevisionStorage::class)->create($workspace->id, $theme, $contents, []);
        SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => $theme, 'revision_id' => $base->id, 'settings' => [], 'lock_version' => 1]);
        $next = app(TopicThemeCompatibility::class)->ensure('primary', $this->actor());
        $this->assertNotSame($base->id, $next->id);
        $this->assertSame($base->id, $next->parent_id);
        $after = app(ThemeRevisionStorage::class)->contents($next);
        $this->assertStringContainsString('我的原始品牌内容', $after[$home]);
        $this->assertArrayHasKey('resources/views/site/topics/show.blade.php', $after);
        $this->assertArrayHasKey('public/themes/'.$theme.'/topics.css', $after);
        $this->assertEquals($contents, app(ThemeRevisionStorage::class)->contents($base));
        $this->get('/')->assertOk()->assertSee('我的原始品牌内容');
        $this->get('/topics/'.$topic->slug)->assertOk()->assertSee('旧模板专题')->assertSee('ne-body', false);
        $this->assertSame($next->id, app(TopicThemeCompatibility::class)->ensure('primary', $this->actor())->id);
    }

    public function test_core_topic_design_upgrade_preserves_custom_templates_and_immutable_history(): void
    {
        Storage::fake('local');
        $topic = $this->topic('Core UI upgrade');
        $theme = 'geoflow-template-01-ink-editorial';
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $theme]);
        $contents = app(ThemeWorkspaceService::class)->sourceContents($theme, 'builtin');
        foreach (['topics/show', 'topics/index', 'partials/topic-source'] as $view) {
            $contents['resources/views/site/'.$view.'.blade.php'] = file_get_contents(base_path('tests/Fixtures/topics/core-ui-v1/'.basename($view).'.blade.php.txt'));
        }
        foreach (['css', 'js'] as $extension) {
            $contents['public/themes/'.$theme.'/topics.'.$extension] = file_get_contents(base_path('tests/Fixtures/topics/core-ui-v1/topics.'.$extension.'.txt'));
        }
        $home = 'resources/views/theme/'.$theme.'/home.blade.php';
        $contents[$home] = str_replace('@endsection', '<p>品牌原始首页</p>@endsection', $contents[$home]);
        $workspace = ThemeWorkspace::query()->create(['id' => (string) Str::uuid(), 'instance_id' => (string) Str::uuid(), 'admin_id' => $this->actor()->id, 'site_key' => 'primary', 'theme_id' => $theme, 'source' => 'builtin', 'state' => 'draft']);
        $storage = app(ThemeRevisionStorage::class);
        ksort($contents);
        $base = $storage->create($workspace->id, $theme, $contents, []);
        $binding = SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => $theme, 'revision_id' => $base->id, 'settings' => [], 'lock_version' => 1]);

        $next = app(TopicThemeCompatibility::class)->ensure('primary', $this->actor());

        $this->assertNotSame($base->id, $next->id);
        $this->assertSame($base->id, $next->parent_id);
        $this->assertSame($contents, $storage->contents($base));
        $updated = $storage->contents($next);
        $this->assertSame($contents[$home], $updated[$home]);
        $this->assertSame(file_get_contents(public_path('assets/css/topics.css')), $updated['public/themes/'.$theme.'/topics.css']);
        $this->assertSame(file_get_contents(public_path('assets/js/topics.js')), $updated['public/themes/'.$theme.'/topics.js']);
        $this->get('/topics/'.$topic->slug)->assertOk()->assertSee('data-topic-design="20261001"', false)->assertSee('本页目录')->assertSee('CollectionPage', false);
        $this->get('/')->assertSee('品牌原始首页');
        $this->assertSame($next->id, app(TopicThemeCompatibility::class)->ensure('primary', $this->actor())->id);

        $rollbackState = app(TopicThemeCompatibility::class)->rollbackState('primary');
        $restored = app(TopicThemeCompatibility::class)->rollback('primary', $this->actor(), $rollbackState['binding_version']);
        $restoredContents = $storage->contents($restored);
        foreach (['resources/views/site/topics/show.blade.php', 'resources/views/site/topics/index.blade.php', 'public/themes/'.$theme.'/topics.css', 'public/themes/'.$theme.'/topics.js'] as $path) {
            $this->assertSame($contents[$path], $restoredContents[$path], 'Rollback must preserve the previous visible design');
        }
        $this->get('/topics/'.$topic->slug)->assertOk()->assertDontSee('data-topic-design="20261001"', false);
        $binding->refresh()->increment('lock_version');
        $next = app(TopicThemeCompatibility::class)->ensure('primary', $this->actor());
        $this->assertSame($restored->id, $next->id, 'Later appearance saves and topic publication must respect the selected rollback design');
        $updated = $storage->contents($next);

        $customPath = 'resources/views/site/topics/show.blade.php';
        $updated[$customPath] = str_replace('@endsection', '<p>客户定制专题区域</p>@endsection', $updated[$customPath]);
        $customCssPath = 'public/themes/'.$theme.'/topics.css';
        $updated[$customCssPath] .= "\n/* Customer-owned topic styles */\n";
        $custom = $storage->create($workspace->id, $theme, $updated, [], $next->id);
        $binding->refresh();
        $binding->update(['revision_id' => $custom->id, 'lock_version' => $binding->lock_version + 1]);
        $retained = app(TopicThemeCompatibility::class)->ensure('primary', $this->actor());

        $this->assertSame($custom->id, $retained->id);
        $this->assertSame($updated[$customPath], $storage->contents($retained)[$customPath]);
        $this->assertSame($updated[$customCssPath], $storage->contents($retained)[$customCssPath]);
    }

    public function test_old_custom_topic_files_keep_their_matching_design_bundle(): void
    {
        Storage::fake('local');
        $this->topic('Customer-owned design');
        $theme = 'geoflow-template-01-ink-editorial';
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $theme]);
        $original = app(ThemeWorkspaceService::class)->sourceContents($theme, 'builtin');
        foreach (['topics/show', 'topics/index', 'partials/topic-source'] as $view) {
            $original['resources/views/site/'.$view.'.blade.php'] = file_get_contents(base_path('tests/Fixtures/topics/core-ui-v1/'.basename($view).'.blade.php.txt'));
        }
        foreach (['css', 'js'] as $extension) {
            $original['public/themes/'.$theme.'/topics.'.$extension] = file_get_contents(base_path('tests/Fixtures/topics/core-ui-v1/topics.'.$extension.'.txt'));
        }
        $workspace = ThemeWorkspace::query()->create(['id' => (string) Str::uuid(), 'instance_id' => (string) Str::uuid(), 'admin_id' => $this->actor()->id, 'site_key' => 'primary', 'theme_id' => $theme, 'source' => 'builtin', 'state' => 'draft']);
        $storage = app(ThemeRevisionStorage::class);
        foreach (['resources/views/site/topics/show.blade.php', 'public/themes/'.$theme.'/topics.css', 'public/themes/'.$theme.'/topics.js'] as $customPath) {
            $contents = $original;
            $contents[$customPath] .= "\n/* Customer customization */\n";
            ksort($contents);
            $base = $storage->create($workspace->id, $theme, $contents, []);
            SiteThemeBinding::query()->updateOrCreate(['site_key' => 'primary'], ['theme_id' => $theme, 'revision_id' => $base->id, 'settings' => [], 'lock_version' => 1]);

            $retained = app(TopicThemeCompatibility::class)->ensure('primary', $this->actor());

            $this->assertSame($base->id, $retained->id);
            $this->assertSame($contents, $storage->contents($retained), 'Custom legacy templates and their original CSS/JS must stay together');
        }
    }

    public function test_zero_named_group_has_reachable_links_in_every_core_layout(): void
    {
        $topic = $this->topic('Numeric reading group');
        $service = app(TopicService::class);
        foreach (array_keys(TopicTemplateCatalog::CORE) as $key) {
            $topic = $topic->fresh();
            $payload = $topic->draft_payload;
            $payload['template_key'] = $key;
            foreach ($payload['articles'] as &$source) {
                $source['group'] = '0';
            }
            unset($source);
            $topic = $service->save($topic, $payload, $topic->draft_version);
            $service->publish($topic, $topic->draft_version, $this->actor()->id);
            $html = $this->get('/topics/'.$topic->slug)->assertOk()->assertSee('data-topic-template="'.$key.'"', false)->getContent();
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            $anchors = $xpath->query('//article[contains(concat(" ", normalize-space(@class), " "), " topic-detail ")]//a[starts-with(@href,"#")]');
            $this->assertGreaterThan(0, $anchors->length);
            foreach ($anchors as $anchor) {
                $this->assertNotNull($dom->getElementById(substr($anchor->getAttribute('href'), 1)), $key.' has a dead reading link');
            }
            $this->assertNotNull($dom->getElementById('topic-group-'.$payload['articles'][0]['article_id']));
        }
    }

    public function test_first_draft_can_upgrade_the_theme_before_the_public_channel_opens(): void
    {
        Storage::fake('local');
        $theme = 'geoflow-template-01-ink-editorial';
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $theme]);
        $sources = [$this->article(1), $this->article(2)];
        $topic = app(TopicService::class)->create('primary', ['title' => 'First scheduled topic', 'intro' => '有依据的导读', 'articles' => array_map(fn ($article) => ['article_id' => $article->id], $sources)], $this->actor()->id);
        $contents = app(ThemeWorkspaceService::class)->sourceContents($theme, 'builtin');
        unset($contents['resources/views/site/topics/show.blade.php']);
        $workspace = ThemeWorkspace::query()->create(['id' => (string) Str::uuid(), 'instance_id' => (string) Str::uuid(), 'admin_id' => $this->actor()->id, 'site_key' => 'primary', 'theme_id' => $theme, 'source' => 'builtin', 'state' => 'draft']);
        $base = app(ThemeRevisionStorage::class)->create($workspace->id, $theme, $contents, []);
        SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => $theme, 'revision_id' => $base->id, 'settings' => [], 'lock_version' => 1]);
        $this->get('/topics')->assertNotFound();

        $next = app(TopicThemeCompatibility::class)->ensure('primary', $this->actor());

        $this->assertNotSame($base->id, $next->id);
        $this->assertSame($next->id, SiteThemeBinding::query()->find('primary')->revision_id);
        $released = ThemeWorkspace::query()->findOrFail($next->workspace_id);
        $this->assertSame($next->id, $released->plan['readback']['revision_id']);
        $this->get('/topics')->assertNotFound();
        app(TopicService::class)->publish($topic, 1, $this->actor()->id);
        $this->get('/topics')->assertOk()->assertSee('First scheduled topic');
        $this->get('/topics/'.$topic->slug)->assertOk()->assertSee('有依据的导读');
    }

    public function test_topic_list_reads_share_source_queries_for_a_full_page(): void
    {
        for ($i = 0; $i < 13; $i++) {
            $this->topic('查询专题 '.$i);
        }app(TopicReadModel::class)->reset();
        DB::enableQueryLog();
        $items = app(TopicReadModel::class)->all('primary');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertCount(13, $items);
        $this->assertLessThan(30, count($queries));
    }

    public function test_review_opened_channel_becomes_noindex_when_every_topic_is_withdrawn(): void
    {
        $topic = $this->topic('Withdrawn entire channel');
        app(TopicService::class)->withdraw($topic, $this->actor()->id, $topic->public_revision_id);
        $this->get('/topics')->assertOk()->assertSee('noindex', false);
    }

    public function test_review_three_groups_have_a_working_reading_directory(): void
    {
        $topic = $this->topic('Reading directory');
        $third = $this->article(3);
        $payload = $topic->draft_payload;
        $payload['articles'] = [['article_id' => $payload['articles'][0]['article_id'], 'group' => '基础'], ['article_id' => $payload['articles'][1]['article_id'], 'group' => '进阶'], ['article_id' => $third->id, 'group' => '实战']];
        $topic = app(TopicService::class)->save($topic, $payload, 1);
        app(TopicService::class)->publish($topic, 2, $this->actor()->id);
        $html = $this->get('/topics/'.$topic->slug)->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/href=[\"\']#(?:section|group|topic-group)-/', $html, 'Three groups must produce group navigation and stable anchors');
    }

    public function test_review_generated_topic_theme_can_pass_package_inspection(): void
    {
        Storage::fake('local');
        $replication = new SiteThemeReplication;
        $replication->forceFill(['id' => 1, 'theme_id' => 'topic-scaffold-probe', 'name' => 'Scaffold topic probe', 'home_url' => 'https://example.test', 'category_url' => 'https://example.test/category', 'article_url' => 'https://example.test/article']);
        $written = app(ThemeScaffoldWriter::class)->write($replication, 1, []);
        $this->assertTrue(app(ThemeComplianceGuard::class)->scan($written)['passed']);
        $files = [];
        foreach ($written['files'] as $file) {
            $path = $file['path'];
            $path = str_starts_with($path, 'views/') ? 'resources/views/theme/topic-scaffold-probe/'.substr($path, 6) : 'public/themes/topic-scaffold-probe/'.substr($path, 7);
            $files[$path] = Storage::disk('local')->get($file['storage_path']);
        }
        $archive = ThemePackageFixture::archive($files, ThemePackageFixture::package($files, 'topic-scaffold-probe', ['theme' => ['id' => 'topic-scaffold-probe', 'name' => 'Scaffold topic probe', 'version' => 'draft-1']]));
        $report = app(SiteThemePackageService::class)->inspect($archive, 1);
        $this->assertIsArray($report);
    }

    public function test_channel_name_order_and_first_open_marker_follow_real_publication(): void
    {
        $service = app(TopicService::class);
        $first = $this->topic('First original topic');
        $opened = app(TopicSiteSettings::class)->get('primary')['topics_public_opened_at'];
        $this->assertNotNull($opened);
        $this->travel(1)->day();
        $second = $this->topic('Second newer topic');
        $service->save($first, ['display_order' => -1], 1);
        $this->get('/topics')->assertOk()->assertSeeInOrder(['First original topic', 'Second newer topic']);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'topics'], ['setting_value' => json_encode(['channel_name' => '研究指南'])]);
        $this->get('/topics')->assertOk()->assertSee('<h1>研究指南</h1>', false);
        $this->get('/topics/'.$second->slug)->assertOk()->assertSee('研究指南');
        $this->assertSame($opened, app(TopicSiteSettings::class)->get('primary')['topics_public_opened_at']);
    }

    public function test_readback_failure_restores_the_exact_previous_theme_binding_and_keeps_the_draft(): void
    {
        Storage::fake('local');
        $theme = 'geoflow-template-01-ink-editorial';
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $theme]);
        $topic = $this->topic('Readback rollback');
        $contents = app(ThemeWorkspaceService::class)->sourceContents($theme, 'builtin');
        unset($contents['resources/views/site/topics/show.blade.php']);
        $workspace = ThemeWorkspace::query()->create(['id' => (string) Str::uuid(), 'instance_id' => (string) Str::uuid(), 'admin_id' => $this->actor()->id, 'site_key' => 'primary', 'theme_id' => $theme, 'source' => 'builtin', 'state' => 'draft']);
        $base = app(ThemeRevisionStorage::class)->create($workspace->id, $theme, $contents, []);
        SiteThemeBinding::query()->create(['site_key' => 'primary', 'theme_id' => $theme, 'revision_id' => $base->id, 'settings' => [], 'lock_version' => 1]);
        ThemeRelease::created(function ($release): void {
            if ($release->kind === 'topic_compatibility') {
                $revision = ThemeRevision::query()->findOrFail($release->revision_id);
                $path = app(ThemeRevisionStorage::class)->path($revision, 'resources/views/site/topics/show.blade.php');
                file_put_contents($path, 'corrupted after CAS');
            }
        });
        try {
            app(TopicThemeCompatibility::class)->ensure('primary', $this->actor());
            $this->fail('Corruption accepted');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('template_key', $error->errors());
        }
        $this->assertSame($base->id, SiteThemeBinding::query()->find('primary')->revision_id);
        $this->assertSame('Readback rollback', $topic->fresh()->draft_payload['title']);
        $this->assertEquals($contents, app(ThemeRevisionStorage::class)->contents($base));
    }

    public function test_unopened_channel_is_private_and_filtered_canonical_keeps_its_scope(): void
    {
        $this->get('/topics')->assertNotFound();
        $topic = $this->topic('Filtered scope');
        $this->get('/topics?search=Filtered')->assertOk()->assertSee('href="http://localhost/topics?search=Filtered"', false)->assertSee('noindex', false);
    }

    public function test_installed_theme_admin_preview_links_open_all_topic_pages_and_frames(): void
    {
        Storage::fake('local');
        $actor = $this->actor();
        $packages = app(SiteThemePackageService::class);
        $inspection = $packages->inspect(ThemePackageFixture::archive(ThemePackageFixture::files()), $actor->id);
        $packages->install($actor->id, $inspection['token'], true);
        $topic = $this->topic('真实专题预览');
        $this->actingAs($actor, 'admin');

        foreach (['topic-list', 'topic-show', 'topic-empty'] as $page) {
            $response = $this->get(route('admin.site-settings.theme-packages.preview', ['themeId' => 'fixture-theme', 'page' => $page]))
                ->assertOk()->assertViewHas('selectedPage', $page);
            $frame = $this->get($response->viewData('frameUrl'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            if ($page === 'topic-empty') {
                $frame->assertDontSee($topic->title);
            } else {
                $frame->assertSee($topic->title);
            }
        }

        $this->get(route('admin.site-settings.theme-packages.preview', ['themeId' => 'fixture-theme', 'page' => 'unsupported']))->assertNotFound();
    }

    public function test_all_featured_themes_preview_home_category_article_and_topic_pages_without_mutation(): void
    {
        $topic = $this->topic('精选模板真实专题');
        $this->actingAs($this->actor(), 'admin');
        $views = Article::query()->pluck('view_count', 'id')->all();
        $settings = SiteSetting::query()->pluck('setting_value', 'setting_key')->all();
        foreach (array_keys(config('theme-library.featured')) as $theme) {
            $id = $theme === '' ? SiteThemeCatalog::DEFAULT_PREVIEW_ID : $theme;
            foreach (['home', 'category', 'article', 'topic-list', 'topic-show', 'topic-empty'] as $page) {
                $response = $this->get(route('admin.site-settings.themes.preview', ['themeId' => $id, 'page' => $page]))->assertOk();
                $frame = $this->get($response->viewData('frameUrl'))->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
                if (in_array($page, ['topic-list', 'topic-show'], true)) {
                    $frame->assertSee($topic->title);
                }
                if ($page === 'topic-empty') {
                    $frame->assertDontSee($topic->title);
                }
            }
        }
        $this->assertSame($views, Article::query()->pluck('view_count', 'id')->all());
        $this->assertSame($settings, SiteSetting::query()->pluck('setting_value', 'setting_key')->all());
    }

    private function topic(string $title): Topic
    {
        $sources = Article::query()->get();
        if ($sources->count() < 2) {
            for ($i = 0; $i < 2; $i++) {
                $this->article($i);
            }$sources = Article::query()->get();
        }
        $topic = app(TopicService::class)->create('primary', ['title' => $title, 'intro' => '有依据的导读', 'summary' => ['one_sentence' => '由本站文章整理的结论', 'facts' => [['text' => '可以逐篇查看来源', 'article_ids' => [$sources[0]->id], 'evidence' => [$this->sourceEvidence($sources[0])]]]], 'tags' => ['GEO'], 'articles' => $sources->map(fn ($a) => ['article_id' => $a->id])->all()], $this->actor()->id);
        app(TopicService::class)->publish($topic, 1, $this->actor()->id);

        return $topic->fresh();
    }

    private function article(int $i): Article
    {
        $author = Author::query()->firstOrCreate(['name' => '测试作者']);
        $category = Category::query()->firstOrCreate(['slug' => 'topic-public'], ['name' => '专题内容']);

        return Article::query()->create(['title' => 'GEO 来源 '.$i, 'slug' => 'geo-source-'.$i, 'content' => 'GEO 内容，独立来源 '.$i, 'excerpt' => '来源摘要 '.$i, 'author_id' => $author->id, 'category_id' => $category->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()]);
    }

    private function actor(): Admin
    {
        return Admin::query()->firstOrCreate(['username' => 'topic_public_admin'], ['email' => 'topic-public@example.test', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
    }

    private function sourceEvidence(Article $article): array
    {
        $text = mb_substr((string) $article->content, 0, 1800, 'UTF-8');

        return ['article_id' => (int) $article->id, 'field' => 'content', 'start' => 0, 'end' => mb_strlen($text, 'UTF-8'), 'text' => $text, 'sha256' => hash('sha256', $text)];
    }
}
