<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\LeadForm;
use App\Models\SiteSetting;
use App\Services\Admin\SiteThemePackageService;
use App\Support\Site\InstalledSiteThemeRepository;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeCatalog;
use App\Support\Site\SiteThemePreviewContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Tests\Support\ThemePackageFixture;
use Tests\TestCase;

class InstalledSiteThemeTest extends TestCase
{
    use RefreshDatabase;

    private const ID = 'runtime-fixture';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_six_preview_pages_restore_context_and_never_count_views(): void
    {
        $this->install();
        $article = $this->article();
        $this->setting('active_theme', 'geoflow-template-21-enterprise-signature');
        $this->setting('analytics_code', '<script>ANALYTICS-SENTINEL</script>');
        $admin = Admin::query()->create(['username' => 'previewer', 'password' => 'fixture-password', 'role' => 'super_admin', 'status' => 'active']);
        $paths = View::getFinder()->getPaths();
        $originalUrl = route('site.about');
        $views = DB::table('view_logs')->count();
        $router = Route::getFacadeRoot();
        foreach (['' => 'home', 'about' => 'about', 'category/general' => 'category', 'article/sample' => 'article', 'archive' => 'archive-index', 'archive/'.$article->published_at->format('Y/m') => 'archive-month'] as $path => $page) {
            $this->actingAs($admin, 'admin')->get($this->frame($path))->assertOk()
                ->assertSee('PAGE:'.$page)->assertSee('FACADE:site.')->assertSee('MATCH:yes')->assertSee('ROUTER:yes')->assertSee('BOUND:yes')->assertSee('INPUT:yes')->assertSee('REQUEST:'.($path === '' ? '/' : $path))->assertSee('ROUTE:site.')->assertDontSee('ANALYTICS-SENTINEL')
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
                ->assertSee($this->frame('about'), false);
            $this->assertFalse(app(SiteThemePreviewContext::class)->isActive());
            $this->assertSame($originalUrl, route('site.about'));
            $this->assertSame($paths, View::getFinder()->getPaths());
            $this->assertSame('geoflow-template-21-enterprise-signature', SiteSettingsBag::get('active_theme'));
            $this->assertSame($router, Route::getFacadeRoot());
            $this->assertSame('admin.site-settings.theme-packages.preview.frame', Route::currentRouteName());
            $this->assertSame(request(), Route::getCurrentRequest());
            $this->assertSame(request()->route(), app(\Illuminate\Routing\Route::class));
        }
        $this->assertSame(0, $article->fresh()->view_count);
        $this->assertSame($views, DB::table('view_logs')->count());
        $this->get(route('site.article', ['slug' => 'sample']))->assertOk();
        $this->assertSame(1, $article->fresh()->view_count);
    }

    public function test_preview_search_pagination_and_shared_readonly_form(): void
    {
        $this->install();
        $first = $this->article();
        $second = $first->replicate();
        $second->fill(['title' => 'Another sample', 'slug' => 'sample-two'])->save();
        $unrelated = $first->replicate();
        $unrelated->fill(['title' => 'Unrelated result', 'slug' => 'unrelated', 'content' => 'Different topic'])->save();
        $this->setting('per_page', '1');
        LeadForm::query()->create(['name' => 'Fixture inquiry', 'slug' => 'fixture-inquiry', 'status' => 'active', 'fields' => [['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'options' => []]]]);
        $admin = Admin::query()->create(['username' => 'previewer', 'password' => 'fixture-password', 'role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($admin, 'admin')->get($this->frame().'?search=sample')->assertOk()
            ->assertSee('data-theme-preview-bridge', false)->assertSee('fieldset class="space-y-4" disabled', false)
            ->assertSee('page=2', false)->assertSee('search=sample', false)->assertDontSee('Unrelated result');
        $this->get($this->frame().'?search=sample&page=2')->assertOk()->assertSee('Sample article')->assertDontSee('Another sample')->assertDontSee('Unrelated result');
        $this->post($this->frame('about'))->assertStatus(405);
        $this->get($this->frame('geo_admin'))->assertNotFound();
    }

    public function test_missing_pages_fall_back_and_installed_assets_survive_new_application_services(): void
    {
        $this->install(false);
        $this->article();
        $this->setting('active_theme', self::ID);
        $this->get(route('site.home'))->assertOk()->assertSee('PAGE:home');
        $this->get(route('site.about'))->assertOk()->assertDontSee('PAGE:about');
        $this->app->forgetInstance(InstalledSiteThemeRepository::class);
        $this->assertNotNull(app(InstalledSiteThemeRepository::class)->find(self::ID));
        $this->assertContains(self::ID, app(SiteThemeCatalog::class)->ids());
        $this->assertNotContains(self::ID, app(SiteThemeCatalog::class)->hostedCompatibleIds());
        $response = $this->get('/themes/'.self::ID.'/theme.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8')->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertStringContainsString('max-age=300', $response->headers->get('Cache-Control'));
        $this->get('/themes/'.self::ID.'/logo@2x.png')->assertOk();
        $this->get('/themes/'.self::ID.'/my%20logo.png')->assertOk();
        foreach (['missing.css', 'manifest.json', 'home.blade.php', '../installation.json'] as $asset) {
            $this->get('/themes/'.self::ID.'/'.$asset)->assertNotFound();
        }
    }

    public function test_installed_asset_filenames_may_contain_dots_without_allowing_traversal(): void
    {
        $files = $this->files(false);
        $files['public/themes/'.self::ID.'/theme..v2.css'] = 'body{color:blue}';
        $this->installFiles($files);
        $this->get('/themes/'.self::ID.'/theme..v2.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        foreach (['../'.self::ID.'/theme.css', '%2e%2e/'.self::ID.'/theme.css', '%252e%252e/'.self::ID.'/theme.css', 'nested/../../theme.css', 'nested%5c..%5ctheme.css'] as $path) {
            $this->get('/themes/'.self::ID.'/'.$path)->assertNotFound();
        }
    }

    public function test_asset_urls_are_decoded_once_and_resolve_the_exact_receipted_filename(): void
    {
        $files = $this->files(false);
        $files['public/themes/'.self::ID.'/logo%20one.png'] = 'literal-percent-image';
        $files['public/themes/'.self::ID.'/logo one.png'] = 'space-image';
        $this->installFiles($files);
        $percent = $this->get('/themes/'.self::ID.'/logo%2520one.png')->assertOk();
        $space = $this->get('/themes/'.self::ID.'/logo%20one.png')->assertOk();
        $this->assertSame('literal-percent-image', file_get_contents($percent->baseResponse->getFile()->getPathname()));
        $this->assertSame('space-image', file_get_contents($space->baseResponse->getFile()->getPathname()));
    }

    public function test_lower_upload_limits_do_not_disable_installed_themes(): void
    {
        $this->install(false);
        $this->setting('active_theme', self::ID);
        config()->set('geoflow.theme_packages.max_files', 1);
        config()->set('geoflow.theme_packages.max_file_bytes', 1);
        config()->set('geoflow.theme_packages.max_total_bytes', 1);
        $this->assertNotNull(app(InstalledSiteThemeRepository::class)->find(self::ID));
        $this->get(route('site.home'))->assertOk()->assertSee('PAGE:home');
        $this->get('/themes/'.self::ID.'/theme.css')->assertOk();
    }

    public function test_unreadable_installed_storage_does_not_break_the_builtin_catalog(): void
    {
        $this->install(false);
        $directory = Storage::disk('local')->path('geoflow-site-themes/installed');
        $permissions = fileperms($directory) & 0777;
        try {
            chmod($directory, 0000);
            clearstatcache(true, $directory);
            if (is_readable($directory)) {
                $this->markTestSkipped('This filesystem grants reads despite removed permissions.');
            }
            $this->assertSame([], app(InstalledSiteThemeRepository::class)->all());
            $this->assertContains('geoflow-template-21-enterprise-signature', app(SiteThemeCatalog::class)->ids());
        } finally {
            chmod($directory, $permissions);
        }
    }

    public function test_preview_render_failure_is_contained_and_restores_routing(): void
    {
        $files = $this->files();
        $files['resources/views/theme/'.self::ID.'/home.blade.php'] = '@php throw new \\RuntimeException("PRIVATE-ERROR-PATH"); @endphp';
        $this->installFiles($files);
        $admin = Admin::query()->create(['username' => 'previewer', 'password' => 'fixture-password', 'role' => 'super_admin', 'status' => 'active']);
        $url = route('site.about');
        $router = Route::getFacadeRoot();
        $this->actingAs($admin, 'admin')->get($this->frame())->assertStatus(422)->assertDontSee('PRIVATE-ERROR-PATH');
        $this->assertSame($url, route('site.about'));
        $this->assertFalse(app(SiteThemePreviewContext::class)->isActive());
        $this->assertSame($router, Route::getFacadeRoot());
        $this->assertSame('admin.site-settings.theme-packages.preview.frame', Route::currentRouteName());
        $this->assertSame(request(), Route::getCurrentRequest());
        $this->assertSame(request()->route(), app(\Illuminate\Routing\Route::class));
    }

    private function files(bool $allPages = true): array
    {
        $files = ThemePackageFixture::files(self::ID);
        $root = 'resources/views/theme/'.self::ID.'/';
        $files['public/themes/'.self::ID.'/logo@2x.png'] = 'fixture image';
        $files['public/themes/'.self::ID.'/my logo.png'] = 'fixture image';
        $files[$root.'layout.blade.php'] = <<<'BLADE'
<!doctype html><html><head><link rel="stylesheet" href="{{ asset('themes/runtime-fixture/theme.css') }}"></head><body>
<nav><a href="{{ route('site.about') }}">About</a>@foreach($navCategories as $navCategory)<span>{{ $navCategory->name }}</span>@endforeach</nav>
{!! $headAnalyticsCode !!}
@yield('content')
</body></html>
BLADE;
        foreach ($allPages ? ['home', 'category', 'article', 'about', 'archive-index', 'archive-month'] : ['home'] as $page) {
            $files[$root.$page.'.blade.php'] = "@extends('theme.runtime-fixture.layout')\n@section('content')\nPAGE:$page\nREQUEST:{{ request()->path() }} ROUTE:{{ request()->route()->getName() }} FACADE:{{ \\Illuminate\\Support\\Facades\\Route::currentRouteName() }} MATCH:{{ \\Illuminate\\Support\\Facades\\Route::is(request()->route()->getName()) ? 'yes' : 'no' }}\n".<<<'BLADE'
ROUTER:{{ \Illuminate\Support\Facades\Route::getCurrentRequest() === request() ? 'yes' : 'no' }}
BOUND:{{ app(\Illuminate\Routing\Route::class) === request()->route() ? 'yes' : 'no' }}
INPUT:{{ \Illuminate\Support\Facades\Route::input('slug') === request()->route('slug') ? 'yes' : 'no' }}
@if(isset($articles))
@foreach($articles as $item)<a href="{{ route('site.article', ['slug' => $item->slug]) }}">{{ $item->title }}</a>@endforeach
{{ $articles->links() }}
@endif
@if(isset($article))<h1>{{ $article->title }}</h1>@endif
@if($form = \App\Models\LeadForm::query()->where('slug', 'fixture-inquiry')->first())
@include('site.partials.lead-form', ['leadForm' => $form])
@endif
<form method="get" action="{{ route('site.home') }}"><input name="search"><button>Search</button></form>
@endsection
BLADE;
        }

        return $files;
    }

    private function install(bool $allPages = true): void
    {
        $this->installFiles($this->files($allPages));
    }

    private function installFiles(array $files): void
    {
        $service = app(SiteThemePackageService::class);
        $report = $service->inspect(ThemePackageFixture::archive($files, ThemePackageFixture::package($files, self::ID)), 1);
        $service->install(1, $report['token'], true);
    }

    private function frame(string $path = ''): string
    {
        return route('admin.site-settings.theme-packages.preview.frame', ['themeId' => self::ID, 'sitePath' => $path]);
    }

    private function article(): Article
    {
        $category = Category::query()->create(['name' => 'General', 'slug' => 'general']);
        $author = Author::query()->create(['name' => 'Fixture editor']);

        return Article::query()->create(['title' => 'Sample article', 'slug' => 'sample', 'content' => '## Sample body', 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now(), 'view_count' => 0]);
    }

    private function setting(string $key, string $value): void
    {
        SiteSetting::query()->updateOrCreate(['setting_key' => $key], ['setting_value' => $value]);
        SiteSettingsBag::forget();
    }
}
