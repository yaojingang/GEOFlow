<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\AdminUiRegistry;
use Illuminate\Routing\Route as LaravelRoute;
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

    public function test_known_admin_routes_have_their_expected_page_types(): void
    {
        $registry = app(AdminUiRegistry::class);

        $this->assertSame('redirect', $registry->routeClassification('admin.security-settings.index'));
        $this->assertSame('endpoint', $registry->routeClassification('admin.tasks.health'));
        $this->assertSame('endpoint', $registry->routeClassification('admin.recent.index'));
        $this->assertSame('endpoint', $registry->routeClassification('admin.ai-workspace.conversations.show'));
        $this->assertSame('download', $registry->routeClassification('admin.system-updates.updater.download'));
        $this->assertSame('binary', $registry->routeClassification('admin.ai-workspace.media.show'));
        $this->assertSame('shell', $registry->routeClassification('admin.ai-workspace'));
        $this->assertSame('shell', $registry->routeClassification('admin.title-libraries.ai-generate'));
        $this->assertSame('shell', $registry->routeClassification('admin.system-updates.backups.show'));
        $this->assertNull($registry->routeClassification('admin.unregistered-page'));
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
