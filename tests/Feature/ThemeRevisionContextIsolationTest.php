<?php

namespace Tests\Feature;

use App\Http\Controllers\Site\ThemeRevisionAssetController;
use App\Http\Middleware\ScopeThemeRevision;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRelease;
use App\Models\ThemeRevision;
use App\Services\Api\ThemeRevisionStorage;
use App\Services\Site\LinkPoolSiteService;
use App\Support\Site\CurrentSite;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use App\Support\Site\ThemeRevisionContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ThemeRevisionContextIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function revision(string $name, ?string $home = null): ThemeRevision
    {
        return app(ThemeRevisionStorage::class)->create((string) Str::uuid(), 'geoflow-template-01-ink-editorial', [
            'resources/views/site/home.blade.php' => $home ?? '<h1>Frozen fallback: {{ $siteTitle }}</h1>',
        ], ['active_theme' => 'geoflow-template-01-ink-editorial', 'site_name' => $name]);
    }

    private function bind(ThemeRevision $revision): void
    {
        SiteThemeBinding::query()->updateOrCreate(['site_key' => 'primary'], ['revision_id' => $revision->id, 'theme_id' => $revision->theme_id, 'settings' => $revision->settings]);
    }

    public function test_unresolved_site_context_does_not_load_primary_revision_state(): void
    {
        $this->bind($this->revision('Resolved primary'));
        app()->instance(CurrentSite::class, new CurrentSite);
        $context = new ThemeRevisionContext;
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $this->assertNull($context->snapshot());
            $this->assertCount(0, DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        app(CurrentSite::class)->setPrimary('localhost');
        $context->reset();
        $this->assertSame('Resolved primary', $context->snapshot()['settings']['site_name']);
    }

    public function test_published_requests_keep_frozen_fallback_and_restore_the_finder_between_versions(): void
    {
        $original = View::getFinder();
        $first = $this->revision('First snapshot');
        $second = $this->revision('Second snapshot');
        $this->bind($first);
        $this->get('/')->assertOk()->assertSee('Frozen fallback: First snapshot');
        $this->assertSame($original, View::getFinder());
        $this->bind($second);
        $this->get('/')->assertOk()->assertSee('Frozen fallback: Second snapshot')->assertDontSee('First snapshot');
        $this->assertSame($original, View::getFinder());
        SiteThemeBinding::query()->delete();
        $this->get('/')->assertOk()->assertDontSee('Frozen fallback:');
        $this->assertSame($original, View::getFinder());
    }

    public function test_nested_preview_preserves_namespaces_and_restores_the_outer_snapshot_after_failure(): void
    {
        Storage::disk('local')->put('namespace/probe.blade.php', 'Namespaced view');
        View::addNamespace('revision_probe', Storage::disk('local')->path('namespace'));
        $original = View::getFinder();
        $first = $this->revision('Outer snapshot');
        $second = $this->revision('Inner snapshot');
        $context = app(ThemeRevisionContext::class);
        $context->preview($first, function () use ($context, $second): void {
            $context->views();
            $outer = View::getFinder();
            $this->assertSame('Namespaced view', view('revision_probe::probe')->render());
            try {
                $context->preview($second, function () use ($context): never {
                    $context->views();
                    $this->assertSame('Inner snapshot', SiteSettingsBag::get('site_name'));
                    $this->assertSame('Namespaced view', view('revision_probe::probe')->render());
                    throw new RuntimeException('Expected nested failure');
                });
                $this->fail('The nested preview must fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Expected nested failure', $exception->getMessage());
            }
            $this->assertSame($outer, View::getFinder());
            $this->assertSame('Outer snapshot', SiteSettingsBag::get('site_name'));
            $this->assertStringContainsString('Frozen fallback: Outer snapshot', SiteThemeViewResolver::first('home', ['siteTitle' => SiteSettingsBag::get('site_name')])->render());
        });
        $this->assertSame($original, View::getFinder());
        $this->assertNull($context->snapshot());
    }

    public function test_render_failure_does_not_leak_revision_state_into_the_next_hosted_request(): void
    {
        $original = View::getFinder();
        $this->bind($this->revision('Broken snapshot', '@php(throw new \\RuntimeException("Expected rendering failure"))'));
        $this->get('/')->assertServerError();
        $this->assertSame($original, View::getFinder());
        app(CurrentSite::class)->setHosted(new HostedSiteProfile(['hostname' => 'hosted.example.test']));
        $response = app(ScopeThemeRevision::class)->handle(Request::create('https://hosted.example.test/'), function () use ($original) {
            $this->assertNull(app(ThemeRevisionContext::class)->snapshot());
            $this->assertSame($original, View::getFinder());

            return response('Hosted request');
        });

        $this->assertSame('Hosted request', $response->getContent());
        $this->assertSame($original, View::getFinder());
    }

    public function test_hosted_site_reads_only_its_matching_theme_revision_without_primary_settings(): void
    {
        $revision = $this->revision('Primary private value');
        $channel = DistributionChannel::query()->create([
            'name' => 'Hosted theme', 'domain' => 'hosted.example.test', 'endpoint_url' => 'https://hosted.example.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
            'template_key' => $revision->theme_id,
            'site_settings' => ['site_name' => 'Hosted name', 'theme_id' => $revision->theme_id],
        ]);
        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id, 'hostname' => 'hosted.example.test', 'root_domain' => 'example.test',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
        ]);
        SiteThemeBinding::query()->create([
            'site_key' => 'hosted:'.$profile->id, 'theme_id' => $revision->theme_id,
            'revision_id' => $revision->id, 'settings' => ['site_name' => 'Primary private value'],
        ]);
        app(CurrentSite::class)->setHosted($profile);
        $context = app(ThemeRevisionContext::class);
        $context->reset();

        $this->assertSame($revision->id, $context->snapshot()['revision_id']);
        $this->assertSame([], $context->snapshot()['settings']);
        $this->assertSame('Hosted name', SiteSettingsBag::get('site_name'));
        $rendered = app(ScopeThemeRevision::class)->handle(
            Request::create('https://hosted.example.test/'),
            fn () => response(SiteThemeViewResolver::first('home', ['siteTitle' => SiteSettingsBag::get('site_name')])->render()),
        );
        $this->assertStringContainsString('Frozen fallback: Hosted name', $rendered->getContent());

        $profile->setRelation('channel', new DistributionChannel(['template_key' => 'default']));
        $context->reset();
        $this->assertNull($context->snapshot());
    }

    public function test_revision_assets_are_scoped_to_the_current_hosted_site_release(): void
    {
        $themeId = 'geoflow-template-01-ink-editorial';
        $previous = app(ThemeRevisionStorage::class)->create((string) Str::uuid(), $themeId, [
            'public/themes/'.$themeId.'/theme.css' => '.theme { color: blue; }',
        ], []);
        $revision = app(ThemeRevisionStorage::class)->create((string) Str::uuid(), $themeId, [
            'public/themes/'.$themeId.'/theme.css' => '.theme { color: red; }',
        ], []);
        ThemeRelease::query()->create([
            'id' => (string) Str::uuid(), 'site_key' => 'hosted:17',
            'workspace_id' => (string) Str::uuid(), 'revision_id' => $revision->id,
            'previous_revision_id' => $previous->id,
            'admin_id' => 1, 'binding_version' => 1, 'changes' => [],
            'plan_sha256' => str_repeat('a', 64),
        ]);
        $profile = new HostedSiteProfile(['hostname' => 'hosted.example.test']);
        $profile->id = 17;
        app(CurrentSite::class)->setHosted($profile);
        $asset = app(ThemeRevisionAssetController::class)(
            Request::create('https://hosted.example.test/theme-assets/'.$revision->id.'/theme.css'),
            $revision->id,
            'theme.css',
            app(ThemeRevisionStorage::class),
        );
        $this->assertSame(200, $asset->getStatusCode());
        $rolledBackAsset = app(ThemeRevisionAssetController::class)(
            Request::create('https://hosted.example.test/theme-assets/'.$previous->id.'/theme.css'),
            $previous->id,
            'theme.css',
            app(ThemeRevisionStorage::class),
        );
        $this->assertSame(200, $rolledBackAsset->getStatusCode());

        $other = new HostedSiteProfile(['hostname' => 'other.example.test']);
        $other->id = 18;
        app(CurrentSite::class)->setHosted($other);
        $this->expectException(HttpException::class);
        $this->expectExceptionCode(0);
        app(ThemeRevisionAssetController::class)(
            Request::create('https://other.example.test/theme-assets/'.$revision->id.'/theme.css'),
            $revision->id,
            'theme.css',
            app(ThemeRevisionStorage::class),
        );
    }

    public function test_hosted_recommendation_capability_uses_the_bound_revision(): void
    {
        $revision = app(ThemeRevisionStorage::class)->create((string) Str::uuid(), 'adapted-hosted-theme', [
            'resources/views/theme/adapted-hosted-theme/article.blade.php' => "<article>Article</article>\n@include('site.partials.link-pool')",
        ], []);
        $channel = DistributionChannel::query()->create([
            'name' => 'Hosted theme', 'domain' => 'theme.example.test', 'endpoint_url' => 'https://theme.example.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
            'template_key' => 'adapted-hosted-theme', 'site_settings' => ['theme_id' => 'adapted-hosted-theme'],
        ]);
        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id, 'hostname' => 'theme.example.test', 'root_domain' => 'example.test',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE, 'indexing_status' => HostedSiteProfile::INDEXING_INDEX,
            'quality_status' => HostedSiteProfile::QUALITY_PASSED,
        ]);
        $siteKey = 'hosted:'.$profile->id;
        SiteThemeBinding::query()->create([
            'site_key' => $siteKey, 'theme_id' => $revision->theme_id, 'revision_id' => $revision->id, 'settings' => [],
        ]);
        $this->assertTrue(app(LinkPoolSiteService::class)->themeSupportsRecommendations($siteKey));

        $withoutPartial = app(ThemeRevisionStorage::class)->create((string) Str::uuid(), 'adapted-hosted-theme', [
            'resources/views/theme/adapted-hosted-theme/article.blade.php' => '<article>Article</article>',
        ], []);
        SiteThemeBinding::query()->whereKey($siteKey)->update(['revision_id' => $withoutPartial->id]);
        $this->assertFalse(app(LinkPoolSiteService::class)->themeSupportsRecommendations($siteKey));
    }
}
