<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\Admin\AdminUpdateMetadataService;
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
            'geoflow.app_version' => '2.0',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_metadata_cache_ttl_seconds' => 86400,
        ]);

        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.1',
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
            ->assertSee(__('admin.header.notifications.update_available', ['version' => '2.1']))
            ->assertSee('测试更新摘要');
    }

    public function test_release_link_falls_back_to_the_official_tag_page_when_remote_metadata_is_unsafe(): void
    {
        Cache::flush();
        config([
            'geoflow.app_version' => '2.0.0',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_metadata_cache_ttl_seconds' => 86400,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.1.0',
                'tag' => 'v2.1.0',
                'payload' => [
                    'release_url' => 'javascript:alert(1)',
                ],
            ]),
        ]);

        $notification = app(AdminUpdateMetadataService::class)->buildNotificationPayload();

        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/releases/tag/v2.1.0',
            $notification['links']['release'] ?? null,
        );
    }

    public function test_release_link_cannot_escape_the_official_repository_with_path_traversal(): void
    {
        Cache::flush();
        config([
            'geoflow.app_version' => '2.0.0',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_metadata_cache_ttl_seconds' => 86400,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.1.0',
                'tag' => 'v2.1.0',
                'payload' => [
                    'release_url' => 'https://github.com/yaojingang/GEOFlow/releases/tag/v2.1.0/../../../../../evil/repository',
                ],
            ]),
        ]);

        $notification = app(AdminUpdateMetadataService::class)->buildNotificationPayload();

        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/releases/tag/v2.1.0',
            $notification['links']['release'] ?? null,
        );
    }

    public function test_changelog_links_ignore_untrusted_remote_urls(): void
    {
        Cache::flush();
        config([
            'geoflow.app_version' => '2.0.0',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_metadata_cache_ttl_seconds' => 86400,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.1.0',
                'tag' => 'v2.1.0',
                'payload' => [
                    'changelog_url_zh' => 'javascript:alert(1)',
                    'changelog_url_en' => 'https://attacker.example/phish',
                ],
            ]),
        ]);

        $notification = app(AdminUpdateMetadataService::class)->buildNotificationPayload();

        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/blob/v2.1.0/docs/CHANGELOG.md',
            $notification['links']['changelog']['zh-CN'] ?? null,
        );
        $this->assertSame(
            'https://github.com/yaojingang/GEOFlow/blob/v2.1.0/docs/CHANGELOG_en.md',
            $notification['links']['changelog']['en'] ?? null,
        );
    }

    public function test_unknown_release_type_is_not_mislabeled_as_a_feature(): void
    {
        Cache::flush();
        config([
            'geoflow.app_version' => '2.0.0',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_metadata_cache_ttl_seconds' => 86400,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.1.0',
                'release_type' => 'future-release-type',
            ]),
        ]);

        $notification = app(AdminUpdateMetadataService::class)->buildNotificationPayload();

        $this->assertSame('', $notification['release_notice']['release_type'] ?? null);
    }

    public function test_release_notice_prefers_the_current_locale_summary_and_falls_back_to_english(): void
    {
        Cache::flush();
        config([
            'geoflow.app_version' => '2.0.0',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
            'geoflow.update_metadata_cache_ttl_seconds' => 86400,
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => '2.1.0',
                'tag' => 'v2.1.0',
                'payload' => [
                    'summary_en' => 'English release summary.',
                    'summary_pt_BR' => 'Resumo da versão em português.',
                ],
            ]),
        ]);

        app()->setLocale('pt_BR');
        $localized = app(AdminUpdateMetadataService::class)->buildNotificationPayload();
        $this->assertSame(
            'Resumo da versão em português.',
            $localized['release_notice']['summary'] ?? null,
        );

        app()->setLocale('ja');
        $fallback = app(AdminUpdateMetadataService::class)->buildNotificationPayload();
        $this->assertSame(
            'English release summary.',
            $fallback['release_notice']['summary'] ?? null,
        );
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
