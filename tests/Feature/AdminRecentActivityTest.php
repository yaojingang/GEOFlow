<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiConversation;
use App\Services\AiWorkspace\AiConversationRepository;
use App\Support\AdminUiRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminRecentActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_recent_activity_only_returns_current_admin_conversations_and_paginates_ten_at_a_time(): void
    {
        $admin = $this->admin('recent-owner');
        $other = $this->admin('recent-other');
        $base = CarbonImmutable::parse('2026-08-25 12:00:00');
        $this->createChats($admin, 12, $base);
        $this->createChats($other, 1, $base->addMinute());

        $first = $this->asAdminWithFeatures($admin, $base)
            ->getJson(route('admin.recent.index'))
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('has_more', true);

        $items = $first->json('data');
        self::assertSame(['chat'], array_values(array_unique(array_column($items, 'kind'))));
        self::assertNotContains('数据中心', array_column($items, 'title'));
        self::assertNotContains('任务管理', array_column($items, 'title'));
        self::assertNotContains('recent-other chat 1', array_column($items, 'title'));
        $keys = array_keys($items[0]);
        sort($keys);
        self::assertSame(['archive_url', 'href', 'id', 'kind', 'title'], $keys);

        $second = $this->getJson(route('admin.recent.index', ['cursor' => $first->json('next_cursor')]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('has_more', false)
            ->assertJsonPath('next_cursor', null);

        self::assertEmpty(array_intersect(array_column($items, 'id'), array_column($second->json('data'), 'id')));
    }

    public function test_cursor_remains_valid_when_its_conversation_is_archived(): void
    {
        $admin = $this->admin('stable-cursor-owner');
        $base = CarbonImmutable::parse('2026-08-25 12:00:00');
        $this->createChats($admin, 12, $base);

        $first = $this->asAdminWithFeatures($admin, $base)
            ->getJson(route('admin.recent.index'))
            ->assertOk()
            ->assertJsonCount(10, 'data');

        $cursorConversation = AiConversation::query()->findOrFail($first->json('data.9.id'));
        $cursorConversation->forceFill(['archived_at' => now()])->save();

        $this->getJson(route('admin.recent.index', ['cursor' => $first->json('next_cursor')]))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('has_more', false);
    }

    public function test_recent_activity_ignores_legacy_feature_filter_and_rejects_invalid_input(): void
    {
        $admin = $this->admin('recent-filter-owner');
        $base = CarbonImmutable::parse('2026-08-25 12:00:00');
        $this->createChats($admin, 12, $base);
        $this->asAdminWithFeatures($admin, $base);

        $this->getJson(route('admin.recent.index', ['filter' => 'feature']))
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.kind', 'chat');
        $this->getJson(route('admin.recent.index', ['filter' => 'chat']))
            ->assertOk()
            ->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.kind', 'chat');
        $this->getJson(route('admin.recent.index', ['cursor' => 'invalid']))->assertUnprocessable();
        $this->getJson(route('admin.recent.index', ['limit' => 51]))->assertUnprocessable();
    }

    private function admin(string $username): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function createChats(Admin $admin, int $count, CarbonImmutable $base): void
    {
        $repository = app(AiConversationRepository::class);
        foreach (range(1, $count) as $index) {
            $conversation = $repository->create($admin, $admin->username.' chat '.$index);
            AiConversation::query()->whereKey($conversation->id)->update([
                'created_at' => $base->subMinutes($index),
                'updated_at' => $base->subMinutes($index),
            ]);
        }
    }

    private function asAdminWithFeatures(Admin $admin, CarbonImmutable $base): self
    {
        config()->set('geoflow.admin_ui_v3_enabled', true);
        $sessionKey = AdminUiRegistry::RECENT_SESSION_KEY.'.'.$admin->id;

        return $this->withSession([
            Admin::AUTH_VERSION_SESSION_KEY => (int) $admin->auth_version,
            $sessionKey => [
                [
                    'route' => 'admin.analytics',
                    'label_key' => 'admin.nav.data_center',
                    'tone' => 'blue',
                    'visited_at' => $base->subMinutes(2)->subSeconds(30)->toISOString(),
                ],
                [
                    'route' => 'admin.tasks.index',
                    'label_key' => 'admin.nav.tasks',
                    'tone' => 'green',
                    'visited_at' => $base->subMinutes(8)->subSeconds(30)->toISOString(),
                ],
            ],
        ])->actingAs($admin, 'admin');
    }
}
