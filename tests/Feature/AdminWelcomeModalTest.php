<?php

namespace Tests\Feature;

use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AdminWelcomeModalTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_intro_revision_does_not_advertise_an_update_when_installed_version_is_current(): void
    {
        $this->fakeRelease('3.2.1');
        $admin = $this->admin('intro:2.1');

        $response = $this->actingAs($admin, 'admin')->get(route('admin.dashboard'));

        $modal = $this->modalPayload($response);
        $this->assertSame('intro', $modal['state']['mode']);
        $this->assertFalse($modal['state']['shouldAutoOpen']);
        $this->assertSame('欢迎使用 GEOFlow 3.2.1', $modal['copy']['zh-CN']['letter']['title']);
        Http::assertSentCount(1);
    }

    public function test_a_local_version_ahead_of_the_release_does_not_offer_a_downgrade(): void
    {
        $this->fakeRelease('3.2.1');
        config()->set('geoflow.app_version', '3.3.0');
        $admin = $this->admin('intro:2.1');

        $modal = $this->modalPayload($this->actingAs($admin, 'admin')->get(route('admin.dashboard')));

        $this->assertSame('intro', $modal['state']['mode']);
        $this->assertFalse($modal['state']['shouldAutoOpen']);
        $this->assertSame('欢迎使用 GEOFlow 3.3.0', $modal['copy']['zh-CN']['letter']['title']);
    }

    public function test_a_genuinely_newer_release_shows_accurate_metadata_and_opens_once(): void
    {
        $this->fakeRelease('3.3.0');
        $admin = $this->admin('intro:2.1');

        $modal = $this->modalPayload($this->actingAs($admin, 'admin')->get(route('admin.dashboard')));

        $this->assertSame('update', $modal['state']['mode']);
        $this->assertTrue($modal['state']['shouldAutoOpen']);
        $this->assertSame('当前版本 v3.2.1，最新版本 v3.3.0。', $modal['copy']['zh-CN']['letter']['subtitle']);
        $this->assertContains('发布日期：2026-11-08', $modal['copy']['zh-CN']['letter']['blocks'][2]['items']);
        $this->assertSame('https://github.com/yaojingang/GEOFlow/blob/v3.3.0/docs/CHANGELOG.md', $modal['state']['links']['changelog']['zh-CN']);
        $this->assertSame('update:3.3.0', $admin->fresh()->welcome_seen_version);

        $again = $this->modalPayload($this->get(route('admin.dashboard')));

        $this->assertFalse($again['state']['shouldAutoOpen']);
    }

    public function test_disabled_checks_keep_the_existing_intro_acknowledgement(): void
    {
        $this->fakeRelease('3.3.0');
        config()->set('geoflow.update_check_enabled', false);
        $admin = $this->admin('intro:2.1');

        $modal = $this->modalPayload($this->actingAs($admin, 'admin')->get(route('admin.dashboard')));

        $this->assertSame('intro', $modal['state']['mode']);
        $this->assertFalse($modal['state']['shouldAutoOpen']);
        Http::assertNothingSent();
    }

    private function fakeRelease(string $latest): void
    {
        Cache::flush();
        config([
            'geoflow.app_version' => '3.2.1',
            'geoflow.welcome_intro_version' => '2.1',
            'geoflow.update_check_enabled' => true,
            'geoflow.update_metadata_url' => 'https://example.test/version.json',
        ]);
        Http::preventStrayRequests();
        Http::fake([
            'https://example.test/version.json' => Http::response([
                'version' => $latest,
                'tag' => 'v'.$latest,
                'release_date' => '2026-11-08',
                'release_type' => 'minor',
                'payload' => ['summary_zh' => '测试版本摘要'],
            ]),
        ]);
    }

    private function admin(string $seen): Admin
    {
        return Admin::query()->create([
            'username' => 'welcome_admin',
            'password' => 'secret-123',
            'email' => 'welcome@example.com',
            'display_name' => 'Welcome Admin',
            'role' => 'super_admin',
            'status' => 'active',
            'welcome_seen_version' => $seen,
        ]);
    }

    /** @return array<string, mixed> */
    private function modalPayload(TestResponse $response): array
    {
        $response->assertOk();
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent(), LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET);
        $node = (new \DOMXPath($document))->query('//script[@id="admin-welcome-payload"]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return json_decode($node->textContent, true, flags: JSON_THROW_ON_ERROR);
    }
}
