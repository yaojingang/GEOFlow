<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\AdminUiRegistry;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminUiV3RouteRegistryTest extends TestCase
{
    public function test_data_center_navigation_opens_the_data_overview(): void
    {
        $registry = app(AdminUiRegistry::class);
        $admin = new Admin(['role' => 'super_admin']);
        $navigationItem = collect($registry->navigation($admin))
            ->flatMap(fn (array $group): array => $group['items'])
            ->firstWhere('key', 'dashboard');

        $this->assertSame('admin.analytics', $navigationItem['route']);
        $this->assertSame('admin.analytics', $registry->currentPage($admin, 'admin.dashboard')['route']);
    }

    public function test_ai_configurator_uses_the_network_module_icon(): void
    {
        $registry = app(AdminUiRegistry::class);
        $admin = new Admin(['role' => 'super_admin']);
        $navigationItem = collect($registry->navigation($admin))
            ->flatMap(fn (array $group): array => $group['items'])
            ->firstWhere('key', 'ai_config');

        $this->assertSame('network', $navigationItem['icon']);
        $this->assertSame(
            'network',
            $registry->currentPage($admin, 'admin.ai.configurator')['icon'],
        );
    }

    public function test_ai_configurator_navigation_tracks_each_management_section(): void
    {
        App::setLocale('zh_CN');
        $registry = app(AdminUiRegistry::class);
        $admin = new Admin(['role' => 'super_admin']);

        $navigation = $registry->aiConfiguratorNavigation($admin, 'admin.ai.configurator');

        $this->assertSame(
            ['models', 'prompts', 'special', 'sources'],
            array_column($navigation, 'key'),
        );
        $this->assertSame(
            ['admin.ai-models.index', 'admin.ai-prompts', 'admin.ai-special-prompts', 'admin.ai-source-providers.index'],
            array_column($navigation, 'route'),
        );
        $this->assertSame([], array_keys(array_filter(array_column($navigation, 'active', 'key'))));

        foreach ([
            'admin.ai-models.create' => 'models',
            'admin.ai-prompts.edit' => 'prompts',
            'admin.ai-special-prompts' => 'special',
            'admin.ai-source-providers.edit' => 'sources',
        ] as $routeName => $activeKey) {
            $activeItems = collect($registry->aiConfiguratorNavigation($admin, $routeName))
                ->where('active', true)
                ->pluck('key')
                ->all();

            $this->assertSame([$activeKey], $activeItems, $routeName);
        }

        foreach ([
            'zh_CN' => 'AI搜索与信源',
            'en' => 'AI Search & Sources',
            'ja' => 'AI検索と情報源',
            'es' => 'Búsqueda IA y fuentes',
            'ru' => 'Поиск ИИ и источники',
            'pt_BR' => 'Busca de IA e Fontes',
        ] as $locale => $sourceLabel) {
            App::setLocale($locale);

            $this->assertSame(
                $sourceLabel,
                collect($registry->aiConfiguratorNavigation($admin, 'admin.ai-source-providers.index'))
                    ->firstWhere('key', 'sources')['label'],
                $locale,
            );
        }
    }

    public function test_known_admin_routes_have_their_expected_page_types(): void
    {
        $registry = app(AdminUiRegistry::class);

        $this->assertSame('redirect', $registry->routeClassification('admin.security-settings.index'));
        $this->assertSame('endpoint', $registry->routeClassification('admin.tasks.health'));
        $this->assertSame('endpoint', $registry->routeClassification('admin.articles.ai-quality.status'));
        $this->assertSame('endpoint', $registry->routeClassification('admin.recent.index'));
        $this->assertSame('endpoint', $registry->routeClassification('admin.ai-workspace.conversations.show'));
        $this->assertSame('download', $registry->routeClassification('admin.system-updates.updater.download'));
        $this->assertSame('binary', $registry->routeClassification('admin.ai-workspace.media.show'));
        $this->assertSame('shell', $registry->routeClassification('admin.ai-workspace'));
        $this->assertSame('shell', $registry->routeClassification('admin.title-libraries.ai-generate'));
        $this->assertSame('shell', $registry->routeClassification('admin.system-updates.backups.show'));
        $this->assertNull($registry->routeClassification('admin.unregistered-page'));
    }

    public function test_every_v3_shell_route_has_a_compact_page_identity(): void
    {
        $registry = app(AdminUiRegistry::class);
        $shellRouteNames = collect(Route::getRoutes())
            ->filter(fn (LaravelRoute $route): bool => in_array('GET', $route->methods(), true))
            ->map(fn (LaravelRoute $route): ?string => $route->getName())
            ->filter(fn (?string $name): bool => is_string($name)
                && $registry->routeClassification($name) === 'shell')
            ->values();

        $this->assertCount(102, $shellRouteNames);
        $shellRouteNames->each(function (string $routeName) use ($registry): void {
            $identity = $registry->pageIdentity($routeName);

            $this->assertIsArray($identity, $routeName);
            $this->assertNotSame('', $identity['title'], $routeName);
            $this->assertNotSame('', $identity['icon'], $routeName);
            $this->assertContains($identity['body_heading'], ['content', 'hidden'], $routeName);
        });
    }

    public function test_page_identity_uses_specific_routes_and_content_modes(): void
    {
        App::setLocale('zh_CN');
        $registry = app(AdminUiRegistry::class);

        $this->assertSame([
            'key' => 'article_edit',
            'title' => '编辑文章',
            'icon' => 'file-pen-line',
            'body_heading' => 'hidden',
        ], $registry->pageIdentity('admin.articles.edit'));
        $this->assertSame('content', $registry->pageIdentity('admin.authors.detail')['body_heading']);
        $this->assertSame('git-compare-arrows', $registry->pageIdentity('admin.distribution.sync-settings-selected.preview')['icon']);
        $this->assertNull($registry->pageIdentity(null));
        $this->assertNull($registry->pageIdentity('admin.unregistered-page'));
    }

    public function test_every_page_identity_has_all_six_localizations(): void
    {
        $registry = app(AdminUiRegistry::class);
        $routeNames = collect(Route::getRoutes())
            ->map(fn (LaravelRoute $route): ?string => $route->getName())
            ->filter(fn (?string $name): bool => is_string($name) && $registry->pageIdentity($name) !== null)
            ->unique()
            ->values();

        $this->assertCount(105, $routeNames);

        foreach (['zh_CN', 'en', 'ja', 'es', 'ru', 'pt_BR'] as $locale) {
            App::setLocale($locale);
            $routeNames->each(function (string $routeName) use ($registry, $locale): void {
                $identity = $registry->pageIdentity($routeName);

                $this->assertFalse(
                    str_starts_with($identity['title'], 'admin_pages.'),
                    $locale.': '.$routeName,
                );
            });
        }
    }

    public function test_recent_page_routes_have_session_locking_without_feature_flag_coupling(): void
    {
        $registry = app(AdminUiRegistry::class);
        $recentRoutes = collect(Route::getRoutes())
            ->filter(fn (LaravelRoute $route): bool => in_array('GET', $route->methods(), true)
                && is_string($route->getName())
                && $registry->shouldRememberRoute($route->getName()))
            ->values();

        $this->assertGreaterThan(8, $recentRoutes->count());
        $this->assertTrue($registry->shouldRememberRoute('admin.tasks.edit'));
        $this->assertTrue($registry->shouldRememberRoute('admin.articles.edit'));
        $this->assertTrue($registry->shouldRememberRoute('admin.site-settings.homepage-modules.edit'));
        $this->assertFalse($registry->shouldRememberRoute('admin.tasks.health'));
        $this->assertFalse($registry->shouldRememberRoute('admin.leads.export'));
        $recentRoutes->each(function (LaravelRoute $route): void {
            $this->assertSame(30, $route->locksFor(), 'Missing session lock for '.$route->getName());
            $this->assertSame(30, $route->waitsFor(), 'Missing session wait for '.$route->getName());
        });
    }

    public function test_every_named_admin_get_route_has_a_ui_classification(): void
    {
        $registry = app(AdminUiRegistry::class);
        $unclassified = collect(Route::getRoutes())
            ->filter(fn (LaravelRoute $route): bool => in_array('GET', $route->methods(), true))
            ->map(fn (LaravelRoute $route): ?string => $route->getName())
            ->filter(fn (?string $name): bool => is_string($name) && str_starts_with($name, 'admin.'))
            ->reject(fn (string $name): bool => $registry->routeClassification($name) !== null)
            ->values()
            ->all();

        $this->assertSame([], $unclassified, 'Unclassified admin GET routes: '.implode(', ', $unclassified));
    }
}
