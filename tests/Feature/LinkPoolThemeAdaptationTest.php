<?php

namespace Tests\Feature;

use App\Ai\Agents\LinkPoolThemeAnchorAgent;
use App\Exceptions\ApiException;
use App\Http\Middleware\NormalizeRequestHost;
use App\Jobs\AdaptLinkPoolTheme;
use App\Models\Admin;
use App\Models\AdminAiSetting;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\LinkPoolThemeAdaptation;
use App\Models\SiteSetting;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRevision;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\Api\ThemeWorkspaceService;
use App\Services\Deployment\UpgradeJournal;
use App\Services\LinkPool\LinkPoolThemeAdaptationService;
use App\Services\LinkPool\LinkPoolThemePatchPlanner;
use App\Services\LinkPool\LinkPoolThemeValidator;
use App\Support\AdminUiRegistry;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\Site\LinkPoolThemeReadbackSignature;
use App\Support\Site\SiteThemeCatalog;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use RuntimeException;
use Tests\TestCase;

class LinkPoolThemeAdaptationTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        RefreshDatabaseState::$migrated = false;
    }

    public function test_repeated_request_returns_one_task_and_worker_publishes_then_rolls_back(): void
    {
        [$admin, $base] = $this->prepareSite('primary', 'adaptation-fixture');

        $service = app(LinkPoolThemeAdaptationService::class);
        $first = $service->start('primary', $admin);
        $second = $service->start('primary', $admin);
        $this->assertSame($first->id, $second->id);
        Queue::assertPushed(AdaptLinkPoolTheme::class, 1);

        $service->run($first->id);
        $finished = LinkPoolThemeAdaptation::query()->findOrFail($first->id);
        $this->assertSame('completed', $finished->status, $finished->error_message ?? '');
        $this->assertSame($finished->candidate_revision_id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
        $this->assertTrue(json_decode((string) SiteSetting::query()->where('setting_key', 'article_recommendations')->value('setting_value'), true)['enabled']);

        $service->rollback($finished, $admin);
        $this->assertSame($base->id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
        $this->assertFalse(json_decode((string) SiteSetting::query()->where('setting_key', 'article_recommendations')->value('setting_value'), true)['enabled']);
    }

    public function test_hosted_site_without_articles_adapts_and_restores_its_own_theme(): void
    {
        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.primary_hosts', ['localhost']);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
        $channel = DistributionChannel::query()->create([
            'name' => 'Hosted fixture', 'domain' => 'fixture.sites.test', 'endpoint_url' => 'https://fixture.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
            'template_key' => 'default', 'site_settings' => ['theme_id' => 'default', 'site_name' => 'Hosted fixture', 'article_recommendations' => ['enabled' => false]],
        ]);
        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id, 'hostname' => 'fixture.sites.test', 'root_domain' => 'sites.test',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE, 'indexing_status' => HostedSiteProfile::INDEXING_INDEX,
            'quality_status' => HostedSiteProfile::QUALITY_PASSED,
        ]);
        $siteKey = 'hosted:'.$profile->id;
        [$admin, $base] = $this->prepareSite($siteKey, 'default');
        $service = app(LinkPoolThemeAdaptationService::class);
        $task = $service->start($siteKey, $admin);
        $service->run($task->id);
        $finished = $task->fresh();
        $this->assertSame('completed', $finished->status, $finished->error_message ?? '');
        $this->assertSame($finished->candidate_revision_id, SiteThemeBinding::query()->findOrFail($siteKey)->revision_id);
        $this->assertTrue($channel->fresh()->site_settings['article_recommendations']['enabled']);
        $this->get('https://fixture.sites.test/theme-assets/'.$finished->candidate_revision_id.'/theme.css')
            ->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8')->assertHeader('ETag');
        $this->get('http://localhost/theme-assets/'.$finished->candidate_revision_id.'/theme.css')->assertNotFound();
        $service->rollback($finished, $admin);
        $this->assertSame($base->id, SiteThemeBinding::query()->findOrFail($siteKey)->revision_id);
        $this->assertFalse($channel->fresh()->site_settings['article_recommendations']['enabled']);
        $this->get('https://fixture.sites.test/theme-assets/'.$base->id.'/theme.css')->assertOk();
    }

    public function test_failed_http_readback_restores_the_original_theme_and_switch(): void
    {
        [$admin, $base] = $this->prepareSite('primary', 'adaptation-fixture');
        config()->set('geoflow.link_pool_theme_readback_base_url', 'http://127.0.0.1:18080');
        config()->set('geoflow.outbound_private_targets', ['127.0.0.1:18080']);
        Http::fake(['http://127.0.0.1:18080/*' => Http::response('unavailable', 503)]);

        $service = app(LinkPoolThemeAdaptationService::class);
        $task = $service->start('primary', $admin);
        $service->run($task->id);

        $this->assertSame('failed', $task->fresh()->status);
        $this->assertSame('theme_http_readback_failed', $task->fresh()->error_code, $task->fresh()->error_message);
        $this->assertSame($base->id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
        $this->assertFalse(json_decode((string) SiteSetting::query()->where('setting_key', 'article_recommendations')->value('setting_value'), true)['enabled']);
        $this->assertNotEmpty(Http::recorded()->all());
    }

    public function test_current_wikipedia_article_gets_the_fixed_include_after_related_entries(): void
    {
        $themeId = 'geoflow-private-wikipedia';
        $path = 'resources/views/theme/'.$themeId.'/article.blade.php';
        $installed = storage_path('app/private/geoflow-site-themes/installed/'.$themeId.'/'.$path);
        if (! is_file($installed)) {
            $this->markTestSkipped('The private Wikipedia theme is not installed in this checkout.');
        }
        $admin = Admin::query()->create(['username' => 'adaptation-admin', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);
        $original = (string) file_get_contents($installed);
        $patched = app(LinkPoolThemePatchPlanner::class)->patch([$path => $original], $themeId, $admin)['files'][$path];

        $this->assertSame(1, substr_count($patched, "@include('site.partials.link-pool')"));
        $this->assertLessThan(strpos($patched, "@include('site.partials.link-pool')"), strpos($patched, '相关条目'));
        $this->assertSame($original, str_replace("\n    @include('site.partials.link-pool')", '', $patched));
    }

    public function test_current_wikipedia_theme_renders_with_a_link_and_an_empty_pool(): void
    {
        $themeId = 'geoflow-private-wikipedia';
        if (! is_file(storage_path('app/private/geoflow-site-themes/installed/'.$themeId.'/resources/views/theme/'.$themeId.'/article.blade.php'))) {
            $this->markTestSkipped('The private Wikipedia theme is not installed in this checkout.');
        }
        $source = app(ThemeWorkspaceService::class)->sourceContents($themeId, 'installed');
        Storage::fake('local');
        SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $themeId]);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'article_recommendations'], ['setting_value' => json_encode(['enabled' => false, 'revision' => 0])]);
        $admin = Admin::query()->create(['username' => 'adaptation-admin', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);
        $patched = app(LinkPoolThemePatchPlanner::class)->patch($source, $themeId, $admin)['files'];
        $candidate = app(ThemeRevisionStorage::class)->create((string) Str::uuid(), $themeId, $patched, []);

        $evidence = app(LinkPoolThemeValidator::class)->validate($candidate, 'primary');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $evidence['article_html_sha256']);
        $this->assertNotSame($evidence['article_html_sha256'], $evidence['empty_html_sha256']);
        $this->assertNull($evidence['article_url']);
    }

    public function test_ai_uses_the_authorized_default_model_and_only_inserts_the_fixed_partial(): void
    {
        [$admin] = $this->prepareSite('primary', 'adaptation-fixture');
        $this->chatModel($admin, 'fallback', 1);
        $preferred = $this->chatModel($admin, 'preferred', 100);
        (new AdminAiSetting)->forceFill(['admin_id' => $admin->id, 'default_chat_model_id' => $preferred->id])->save();
        $source = "@section('content')\n@if(\$relatedArticles->isNotEmpty())\n<section><h2>Related</h2></section>\n@endif\n@endsection";
        LinkPoolThemeAnchorAgent::fake([['anchor' => "</section>\n@endif", 'reason' => 'Related section end']])->preventStrayPrompts();
        $path = 'resources/views/theme/adaptation-fixture/article.blade.php';

        $result = app(LinkPoolThemePatchPlanner::class)->patch([$path => $source], 'adaptation-fixture', $admin);

        $this->assertSame($preferred->id, $result['model_id']);
        $this->assertSame($source, str_replace("\n    @include('site.partials.link-pool')", '', $result['files'][$path]));
        LinkPoolThemeAnchorAgent::assertPrompted(fn ($prompt) => $prompt->model === 'preferred');
    }

    public function test_ai_unavailable_reports_a_specific_actionable_reason(): void
    {
        [$admin] = $this->prepareSite('primary', 'adaptation-fixture');
        try {
            app(LinkPoolThemePatchPlanner::class)->patch(['resources/views/site/article.blade.php' => "@section('content')<article>content</article>@endsection"], 'adaptation-fixture', $admin);
            $this->fail('Missing AI configuration should block adaptation.');
        } catch (ApiException $exception) {
            $this->assertSame('theme_ai_model_unavailable', $exception->getErrorCode());
        }
    }

    public function test_settings_validation_message_is_rendered_once_in_each_admin_shell(): void
    {
        [$admin] = $this->prepareSite('primary', 'adaptation-fixture');
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['revision' => '站点设置冲突测试提示']));
        foreach ([false, true] as $v3) {
            config()->set('geoflow.admin_ui_v3_enabled', $v3);
            $response = $this->actingAs($admin, 'admin')->withSession(['errors' => $errors])->get(route('admin.link-pool.settings', ['site' => 'primary']))->assertOk();
            $this->assertSame(1, substr_count($response->getContent(), '站点设置冲突测试提示'));
        }
    }

    public function test_ai_anchor_inside_the_related_condition_is_rejected(): void
    {
        [$admin] = $this->prepareSite('primary', 'adaptation-fixture');
        $this->chatModel($admin, 'chat', 1);
        LinkPoolThemeAnchorAgent::fake([['anchor' => '</section>', 'reason' => 'unsafe location']])->preventStrayPrompts();
        try {
            app(LinkPoolThemePatchPlanner::class)->patch(['resources/views/site/article.blade.php' => "@section('content')\n@if(\$relatedArticles->isNotEmpty())<section>Related</section>@endif\n@endsection"], 'adaptation-fixture', $admin);
            $this->fail('Conditional insertion should be rejected.');
        } catch (ApiException $exception) {
            $this->assertSame('theme_anchor_ambiguous', $exception->getErrorCode());
        }
    }

    public function test_failed_queue_job_recovers_a_committed_release_and_clears_the_site_claim(): void
    {
        [$admin, $base] = $this->prepareSite('primary', 'adaptation-fixture');
        $service = app(LinkPoolThemeAdaptationService::class);
        $task = $service->start('primary', $admin);
        $service->run($task->id);
        $task->refresh()->update(['status' => 'running', 'phase' => 'publishing', 'active_site_key' => 'primary']);

        (new AdaptLinkPoolTheme($task->id))->failed(new RuntimeException('worker timeout'));

        $this->assertSame('failed', $task->fresh()->status);
        $this->assertNull($task->fresh()->active_site_key);
        $this->assertSame('theme_worker_interrupted', $task->fresh()->error_code);
        $this->assertSame($base->id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
    }

    public function test_recovery_suspends_only_the_failed_revision_when_settings_changed(): void
    {
        [$admin] = $this->prepareSite('primary', 'adaptation-fixture');
        $service = app(LinkPoolThemeAdaptationService::class);
        $task = $service->start('primary', $admin);
        $service->run($task->id);
        $task->refresh()->update(['status' => 'running', 'active_site_key' => 'primary']);
        SiteSetting::query()->where('setting_key', 'article_recommendations')->update(['setting_value' => json_encode(['enabled' => true, 'heading' => 'Concurrent edit', 'revision' => 2])]);

        $service->recoverFailedTask($task->id);

        $settings = json_decode(SiteSetting::query()->where('setting_key', 'article_recommendations')->value('setting_value'), true);
        $this->assertFalse($settings['enabled']);
        $this->assertSame('Concurrent edit', $settings['heading']);
        $this->assertSame(3, $settings['revision']);
        $this->assertSame($task->candidate_revision_id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
    }

    public function test_upgrade_lock_prevents_publication_and_preserves_the_baseline(): void
    {
        [$admin, $base] = $this->prepareSite('primary', 'adaptation-fixture');
        $lock = app(UpgradeJournal::class)->acquire();
        try {
            $service = app(LinkPoolThemeAdaptationService::class);
            $task = $service->start('primary', $admin);
            $service->run($task->id);
            $this->assertSame('theme_upgrade_conflict', $task->fresh()->error_code);
            $this->assertSame($base->id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
        } finally {
            app(UpgradeJournal::class)->release($lock);
        }
    }

    public function test_read_only_admin_can_view_status_but_cannot_adapt_or_rollback(): void
    {
        $registry = app(AdminUiRegistry::class);
        $this->assertSame('endpoint', $registry->routeClassification('admin.link-pool.theme-adaptations.show'));
        $this->assertFalse($registry->shouldRememberRoute('admin.link-pool.theme-adaptations.show'));
        [$admin] = $this->prepareSite('primary', 'adaptation-fixture');
        $task = app(LinkPoolThemeAdaptationService::class)->start('primary', $admin);
        $reader = Admin::query()->create(['username' => 'reader', 'password' => 'test-password', 'role' => 'admin', 'status' => 'active']);
        $this->actingAs($reader, 'admin')->getJson(route('admin.link-pool.theme-adaptations.show', $task->id))->assertOk()->assertJsonPath('id', $task->id);
        $this->get(route('admin.link-pool.settings', ['site' => 'primary']))->assertOk()->assertDontSee('data-adaptation-start', false)->assertDontSee('data-adaptation-rollback', false);
        $this->postJson(route('admin.link-pool.theme-adaptations.store'), ['site_key' => 'primary'])->assertForbidden();
        $this->postJson(route('admin.link-pool.theme-adaptations.rollback', $task->id))->assertForbidden();
    }

    public function test_deployment_and_emergency_errors_are_json_and_do_not_create_tasks(): void
    {
        [$admin] = $this->prepareSite('primary', 'adaptation-fixture');
        $this->actingAs($admin, 'admin');
        config()->set('geoflow.link_pool_theme_shared_storage', false);
        $this->postJson(route('admin.link-pool.theme-adaptations.store'), ['site_key' => 'primary'])->assertStatus(503)->assertJsonPath('error_code', 'theme_adaptation_unavailable');
        config()->set('geoflow.link_pool_theme_shared_storage', true);
        SiteSetting::query()->updateOrCreate(['setting_key' => 'link_pool_emergency_disabled'], ['setting_value' => '1']);
        $this->postJson(route('admin.link-pool.theme-adaptations.store'), ['site_key' => 'primary'])->assertStatus(409)->assertJsonPath('error_code', 'link_pool_emergency_disabled');
        $this->assertDatabaseCount('link_pool_theme_adaptations', 0);
    }

    public function test_readback_signature_is_bound_to_host_path_method_and_expiration(): void
    {
        $url = 'https://primary.test/article/source';
        $signature = LinkPoolThemeReadbackSignature::forUrl($url);
        $valid = Request::create($url);
        $valid->headers->set('X-GEOFlow-Theme-Readback', $signature);
        $this->assertTrue(LinkPoolThemeReadbackSignature::valid($valid));
        $transport = Request::create('http://web/article/source');
        $transport->headers->set('X-GEOFlow-Theme-Readback', $signature);
        $transport->headers->set('X-GEOFlow-Theme-Readback-Host', 'primary.test');
        $this->assertTrue(LinkPoolThemeReadbackSignature::valid($transport));
        $transport->headers->set('X-GEOFlow-Theme-Readback-Host', 'other.test');
        $this->assertFalse(LinkPoolThemeReadbackSignature::valid($transport));
        $transport->headers->set('X-GEOFlow-Theme-Readback-Host', 'primary.test');
        $transport->headers->set('X-Forwarded-Host', 'web');
        $transport->server->set('REMOTE_ADDR', '127.0.0.1');
        $oldProxies = Request::getTrustedProxies();
        $oldHeaders = Request::getTrustedHeaderSet();
        try {
            Request::setTrustedProxies(['127.0.0.1'], Request::HEADER_X_FORWARDED_HOST);
            $response = (new NormalizeRequestHost)->handle($transport, static fn (Request $request) => response()->json(['host' => $request->getHost()]));
            $this->assertSame('primary.test', $response->getData(true)['host']);
        } finally {
            Request::setTrustedProxies($oldProxies, $oldHeaders);
        }
        foreach (['https://other.test/article/source', 'https://primary.test/article/target'] as $other) {
            $request = Request::create($other);
            $request->headers->set('X-GEOFlow-Theme-Readback', $signature);
            $this->assertFalse(LinkPoolThemeReadbackSignature::valid($request));
        }
        $valid->setMethod('POST');
        $this->assertFalse(LinkPoolThemeReadbackSignature::valid($valid));
        $valid->setMethod('GET');
        $valid->headers->set('X-GEOFlow-Theme-Readback', (time() - 1).':'.str_repeat('a', 64));
        $this->assertFalse(LinkPoolThemeReadbackSignature::valid($valid));
    }

    private function chatModel(Admin $admin, string $modelId, int $priority): AiModel
    {
        $model = AiModel::query()->create(['name' => $modelId, 'version' => 'test', 'api_key' => app(ApiKeyCrypto::class)->encrypt('test-key'),
            'model_id' => $modelId, 'model_type' => 'chat', 'api_url' => 'https://ai.test', 'status' => 'active', 'failover_priority' => $priority]);
        $model->forceFill(['owner_admin_id' => $admin->id])->save();

        return $model;
    }

    public function test_signed_internal_readback_routes_to_the_site_without_counting_a_reader(): void
    {
        $this->prepareSite('primary', 'adaptation-fixture');
        config()->set('geoflow.site_url', 'https://primary.test');
        config()->set('geoflow.hosted_sites.primary_hosts', ['primary.test']);
        config()->set('geoflow.link_pool_theme_readback_base_url', 'http://web');
        $category = Category::query()->create(['name' => 'Reader fixture', 'slug' => 'reader-fixture']);
        $author = Author::query()->create(['name' => 'Reader fixture']);
        $article = Article::query()->create(['title' => 'Reader fixture', 'slug' => 'reader-fixture', 'content' => 'Reader fixture',
            'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()]);
        $headers = ['X-GEOFlow-Theme-Readback-Host' => 'primary.test', 'X-GEOFlow-Theme-Readback' => LinkPoolThemeReadbackSignature::forUrl('https://primary.test/article/reader-fixture')];

        $this->withHeaders($headers)->get('http://web/article/reader-fixture')->assertOk()->assertSee('Reader fixture');
        $this->assertSame(0, (int) $article->fresh()->view_count);
        $this->assertSame(0, DB::table('view_logs')->count());
        $this->withHeaders(['X-GEOFlow-Theme-Readback' => 'invalid'])->get('http://web/article/reader-fixture')->assertNotFound();
        $this->withHeaders(['X-GEOFlow-Theme-Readback' => '', 'X-GEOFlow-Theme-Readback-Host' => ''])->get('https://primary.test/article/reader-fixture')->assertOk();
        $this->assertSame(1, (int) $article->fresh()->view_count);
        $this->assertSame(1, DB::table('view_logs')->count());
    }

    public function test_concurrent_settings_edit_blocks_publish_and_preserves_the_edit(): void
    {
        [$admin, $base] = $this->prepareSite('primary', 'adaptation-fixture');
        ThemeRevision::created(static function (ThemeRevision $revision): void {
            if ($revision->parent_id !== null) {
                SiteSetting::query()->where('setting_key', 'article_recommendations')->update(['setting_value' => json_encode(['enabled' => false, 'heading' => 'Concurrent edit', 'revision' => 1])]);
            }
        });
        $service = app(LinkPoolThemeAdaptationService::class);
        $task = $service->start('primary', $admin);
        $service->run($task->id);
        $this->assertSame('site_settings_conflict', $task->fresh()->error_code);
        $this->assertSame($base->id, SiteThemeBinding::query()->findOrFail('primary')->revision_id);
        $settings = json_decode(SiteSetting::query()->where('setting_key', 'article_recommendations')->value('setting_value'), true);
        $this->assertSame('Concurrent edit', $settings['heading']);
        $this->assertFalse($settings['enabled']);
    }

    public function test_queue_dispatch_failure_releases_claim_and_allows_retry(): void
    {
        [$admin] = $this->prepareSite('primary', 'adaptation-fixture');
        $dispatcher = app(Dispatcher::class);
        $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Queue unavailable'));
        $service = app(LinkPoolThemeAdaptationService::class);
        $failed = $service->start('primary', $admin);
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->active_site_key);
        $this->assertSame('theme_queue_unavailable', $failed->error_code);
        $this->app->instance(Dispatcher::class, $dispatcher);
        $retry = $service->start('primary', $admin);
        $this->assertNotSame($failed->id, $retry->id);
        $this->assertSame('queued', $retry->status);
        Queue::assertPushed(AdaptLinkPoolTheme::class, 1);
    }

    public function test_comment_markers_do_not_block_adaptation_or_narrow_patch_publication(): void
    {
        [$admin, $base] = $this->prepareSite('primary', 'adaptation-fixture');
        $storage = app(ThemeRevisionStorage::class);
        $files = $storage->contents($base);
        $path = 'resources/views/theme/adaptation-fixture/article.blade.php';
        $files[$path] = "{{-- @include('site.partials.link-pool') --}}\n<!-- recommendationBlocks -->\n".$files[$path];
        $commented = $storage->create((string) Str::uuid(), 'adaptation-fixture', $files, []);
        SiteThemeBinding::query()->whereKey('primary')->update(['revision_id' => $commented->id]);
        $service = app(LinkPoolThemeAdaptationService::class);
        $task = $service->start('primary', $admin);
        $service->run($task->id);
        $this->assertSame('completed', $task->fresh()->status, $task->fresh()->error_message ?? '');
        $this->assertStringContainsString("{{-- @include('site.partials.link-pool') --}}", $storage->contents(ThemeRevision::query()->findOrFail($task->fresh()->candidate_revision_id))[$path]);
    }

    private function prepareSite(string $siteKey, string $themeId): array
    {
        Storage::fake('local');
        config()->set('geoflow.link_pool_theme_shared_storage', true);
        Queue::fake();
        app()->instance(SiteThemeCatalog::class, new class extends SiteThemeCatalog
        {
            public function all(): array
            {
                return [
                    ['id' => 'adaptation-fixture', 'source' => 'builtin', 'name' => 'Fixture', 'version' => '1', 'description' => ''],
                    ['id' => 'default', 'source' => 'builtin', 'name' => 'Default', 'version' => '1', 'description' => ''],
                ];
            }
        });
        $admin = Admin::query()->create(['username' => 'adaptation-admin', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);
        if ($siteKey === 'primary') {
            SiteSetting::query()->updateOrCreate(['setting_key' => 'active_theme'], ['setting_value' => $themeId]);
            SiteSetting::query()->updateOrCreate(['setting_key' => 'article_recommendations'], ['setting_value' => json_encode(['enabled' => false, 'revision' => 0])]);
        }
        $source = <<<'BLADE'
@extends('theme.__THEME__.layout')
@section('content')
<article>{{ $article->title }}</article>
@if($relatedArticles->isNotEmpty())
    <section><h2>相关文章</h2></section>
@endif
@endsection
BLADE;
        $source = str_replace('__THEME__', $themeId, $source);
        $base = app(ThemeRevisionStorage::class)->create((string) Str::uuid(), $themeId, [
            'resources/views/theme/'.$themeId.'/article.blade.php' => $source,
            'resources/views/theme/'.$themeId.'/layout.blade.php' => '<html><head><link rel="stylesheet" href="{{ asset(\'themes/'.$themeId.'/theme.css\') }}">@stack("head")</head><body>@yield("content")@stack("scripts")</body></html>',
            'resources/views/theme/'.$themeId.'/home.blade.php' => '<html><body><h1>Fixture home</h1></body></html>',
            'resources/views/site/partials/link-pool.blade.php' => (string) file_get_contents(resource_path('views/site/partials/link-pool.blade.php')),
            'public/themes/'.$themeId.'/theme.css' => 'article { color: #111827; }',
        ], []);
        SiteThemeBinding::query()->create(['site_key' => $siteKey, 'theme_id' => $themeId, 'revision_id' => $base->id, 'settings' => [], 'lock_version' => 0]);

        return [$admin, $base];
    }
}
