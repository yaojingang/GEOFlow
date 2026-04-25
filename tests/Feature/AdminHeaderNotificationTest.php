<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminHeaderNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_header_hides_inline_account_text(): void
    {
        $admin = $this->createAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertDontSee('hidden xl:block text-right leading-tight', false)
            ->assertSee('toggleUserMenu()', false);
    }

    public function test_admin_header_shows_update_indicator_when_github_version_is_newer(): void
    {
        Cache::flush();

        config([
            'geoflow.app_version' => '1.2.0',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_metadata_cache_ttl_seconds' => 86400,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '1.3.0',
                'payload' => [
                    'summary_zh' => '测试更新摘要',
                    'changelog_url_zh' => 'https://example.test/changelog',
                ],
            ]),
        ]);

        $admin = $this->createAdmin('update_admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-update-indicator', false)
            ->assertSee(__('admin.header.notifications.update_available', ['version' => '1.3.0']))
            ->assertSee('测试更新摘要');
    }

    private function createAdmin(string $username = 'header_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'Header Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }
}
