<?php

namespace Tests\Feature;

use App\Http\Middleware\ScopeThemeRevision;
use App\Models\HostedSiteProfile;
use App\Models\SiteThemeBinding;
use App\Models\ThemeRevision;
use App\Services\Api\ThemeRevisionStorage;
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
}
