<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SensitiveAdminRouteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_issue_60_sensitive_admin_route_requires_super_admin_middleware(): void
    {
        $sensitiveNames = collect(Route::getRoutes()->getRoutes())
            ->map(static fn ($route): ?string => $route->getName())
            ->filter(static fn (?string $name): bool => is_string($name) && (
                str_starts_with($name, 'admin.distribution.')
                || str_starts_with($name, 'admin.url-import')
                || str_starts_with($name, 'admin.site-settings.theme-replications.')
            ))
            ->values();

        $this->assertNotEmpty($sensitiveNames);
        foreach ($sensitiveNames as $name) {
            $middlewares = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];
            $this->assertContains('admin.super', $middlewares, $name.' must require admin.super');
        }
    }

    #[Test]
    public function a_standard_admin_receives_403_for_sensitive_management_pages(): void
    {
        $admin = $this->admin('admin');

        foreach ([
            route('admin.distribution.index'),
            route('admin.url-import'),
            route('admin.site-settings.theme-replications.create'),
        ] as $url) {
            $this->actingAs($admin, 'admin')->get($url)->assertForbidden();
        }
    }

    #[Test]
    public function a_super_admin_can_open_distribution_and_url_import_pages(): void
    {
        $admin = $this->admin('super_admin');

        $this->actingAs($admin, 'admin')->get(route('admin.distribution.index'))->assertOk();
        $this->actingAs($admin, 'admin')->get(route('admin.url-import'))->assertOk();
    }

    private function admin(string $role): Admin
    {
        return Admin::query()->create([
            'username' => 'route_'.$role,
            'password' => 'secret-123',
            'email' => 'route-'.$role.'@example.com',
            'display_name' => 'Route '.$role,
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
