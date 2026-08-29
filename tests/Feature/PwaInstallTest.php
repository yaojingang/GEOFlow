<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PwaInstallTest extends TestCase
{
    use RefreshDatabase;

    public function test_manifest_and_service_worker_define_a_safe_installable_workspace(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(public_path('manifest.webmanifest')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('./', $manifest['id']);
        self::assertSame('app', $manifest['start_url']);
        self::assertSame('./', $manifest['scope']);
        self::assertSame('standalone', $manifest['display']);
        self::assertSame('GEOFlow', $manifest['name']);
        self::assertContains('192x192', array_column($manifest['icons'], 'sizes'));
        self::assertContains('512x512', array_column($manifest['icons'], 'sizes'));
        self::assertContains('maskable', array_column($manifest['icons'], 'purpose'));

        foreach ([192, 512] as $size) {
            $path = public_path("icons/geoflow-app-{$size}.png");
            self::assertFileExists($path);
            self::assertSame([$size, $size], array_slice((array) getimagesize($path), 0, 2));
        }

        $maskableIcon = public_path('icons/geoflow-app-maskable-512.png');
        self::assertFileExists($maskableIcon);
        self::assertSame([512, 512], array_slice((array) getimagesize($maskableIcon), 0, 2));

        $serviceWorker = (string) file_get_contents(public_path('service-worker.js'));
        self::assertStringContainsString("self.addEventListener('install'", $serviceWorker);
        self::assertStringContainsString("self.addEventListener('activate'", $serviceWorker);
        self::assertStringContainsString("self.addEventListener('fetch'", $serviceWorker);
        self::assertStringContainsString("event.request.mode !== 'navigate'", $serviceWorker);
        self::assertStringContainsString('event.respondWith(fetch(event.request));', $serviceWorker);
        self::assertStringNotContainsString('caches.', $serviceWorker);
    }

    public function test_pwa_launch_route_opens_the_admin_workspace(): void
    {
        $this->get(route('pwa.launch'))
            ->assertRedirect(route('admin.login'));

        $admin = Admin::query()->create([
            'username' => 'pwa_launch_admin',
            'password' => 'secret-123',
            'email' => 'pwa-launch-admin@example.com',
            'display_name' => 'PWA Launch Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('pwa.launch'))
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_primary_site_and_admin_pages_advertise_the_pwa(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);

        $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('/manifest.webmanifest', false)
            ->assertSee('/icons/geoflow-app-192.png', false);

        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('rel="manifest"', false)
            ->assertSee('/manifest.webmanifest', false);

        $admin = Admin::query()->create([
            'username' => 'pwa_admin',
            'password' => 'secret-123',
            'email' => 'pwa-admin@example.com',
            'display_name' => 'PWA Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-pwa-install', false)
            ->assertSee(__('admin.ui_v3.install_workbench'));
    }

    public function test_legacy_admin_shell_keeps_the_same_install_entry(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', false);

        $admin = Admin::query()->create([
            'username' => 'legacy_pwa_admin',
            'password' => 'secret-123',
            'email' => 'legacy-pwa-admin@example.com',
            'display_name' => 'Legacy PWA Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-pwa-install', false)
            ->assertSee(__('admin.ui_v3.install_workbench'));
    }
}
