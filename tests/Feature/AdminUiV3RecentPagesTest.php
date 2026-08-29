<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Support\AdminUiRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUiV3RecentPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_pages_are_scoped_sanitized_and_deduplicated(): void
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $admin = Admin::query()->create([
            'username' => 'recent_owner',
            'password' => 'secret-123',
            'email' => 'recent@example.com',
            'display_name' => 'Recent Owner',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $sessionKey = AdminUiRegistry::RECENT_SESSION_KEY.'.'.$admin->id;

        $this->withSession([Admin::AUTH_VERSION_SESSION_KEY => 1])->actingAs($admin, 'admin');
        $this->get(route('admin.analytics'))->assertOk();
        $this->assertSame(['admin.analytics'], array_column(session($sessionKey), 'route'));
        $this->get(route('admin.dashboard').'?search=private-token')->assertOk();
        $this->get(route('admin.tasks.index'))->assertOk();
        $this->get(route('admin.articles.index'))->assertOk();
        $this->get(route('admin.materials.index'))->assertOk();

        $entries = session($sessionKey);
        $this->assertCount(4, $entries);
        $this->assertSame([
            'admin.materials.index',
            'admin.articles.index',
            'admin.tasks.index',
            'admin.analytics',
        ], array_column($entries, 'route'));
        $this->assertNotEmpty(array_filter(array_column($entries, 'visited_at')));
        $this->assertStringNotContainsString('private-token', json_encode($entries, JSON_THROW_ON_ERROR));
    }
}
