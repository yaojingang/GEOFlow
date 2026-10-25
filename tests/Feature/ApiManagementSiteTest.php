<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\SiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiManagementSiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_read_scope_returns_public_fields_and_a_revision(): void
    {
        $admin = Admin::query()->create(['username' => 'site-reader', 'password' => 'test-only', 'role' => 'admin', 'status' => 'active']);
        SiteSetting::query()->create(['setting_key' => 'site_name', 'setting_value' => 'Readable']);
        SiteSetting::query()->create(['setting_key' => 'private_api_key', 'setting_value' => 'never-return']);
        SiteSetting::query()->create(['setting_key' => 'analytics_code', 'setting_value' => '<script>private()</script>']);
        $token = $admin->createToken('reader', ['sites:read'])->plainTextToken;
        $first = $this->withToken($token)->getJson('/api/v1/management/sites/primary')->assertOk()
            ->assertJsonPath('data.settings.site_name', 'Readable')->assertJsonMissingPath('data.settings.private_api_key')
            ->assertJsonMissingPath('data.settings.analytics_code');
        SiteSetting::query()->where('setting_key', 'site_name')->update(['setting_value' => 'Updated']);
        $second = $this->withToken($token)->getJson('/api/v1/management/sites/primary')->assertOk();
        $this->assertNotSame($first->json('data.settings_revision'), $second->json('data.settings_revision'));
        $this->withToken($token)->getJson('/api/v1/management/sites')->assertOk()->assertJsonCount(1, 'data.items');
        $this->withToken($token)->getJson('/api/v1/management/sites/unknown')->assertNotFound();
    }

    public function test_legacy_wildcard_cannot_read_new_site_endpoints(): void
    {
        $admin = Admin::query()->create(['username' => 'legacy-reader', 'password' => 'test-only', 'role' => 'admin', 'status' => 'active']);
        $this->withToken($admin->createToken('legacy', ['*'])->plainTextToken)->getJson('/api/v1/management/sites')->assertForbidden();
    }
}
