<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\DistributionChannelDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminDistributionChannelDeletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_open_guided_delete_preview(): void
    {
        $channel = $this->channel();

        $this->actingAs($this->admin('super_admin'), 'admin')
            ->get(route('admin.distribution.delete', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.delete.heading'))
            ->assertSee($channel->name);
    }

    public function test_delete_preview_guides_admin_to_run_migrations_when_operation_table_is_missing(): void
    {
        $channel = $this->channel();
        $service = \Mockery::mock(DistributionChannelDeletionService::class)->makePartial();
        $service->shouldReceive('isSchemaReady')->once()->andReturnFalse();
        $this->app->instance(DistributionChannelDeletionService::class, $service);

        $this->actingAs($this->admin('super_admin'), 'admin')
            ->get(route('admin.distribution.delete', ['channelId' => (int) $channel->id]))
            ->assertRedirect(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertSessionHasErrors('distribution');
    }

    public function test_final_delete_returns_validation_error_when_operation_table_is_missing(): void
    {
        $channel = $this->channel(['status' => DistributionChannel::STATUS_DELETING]);
        $service = \Mockery::mock(DistributionChannelDeletionService::class)->makePartial();
        $service->shouldReceive('isSchemaReady')->once()->andReturnFalse();
        $service->shouldReceive('inspect')->never();
        $this->app->instance(DistributionChannelDeletionService::class, $service);

        $this->actingAs($this->admin('super_admin'), 'admin')
            ->delete(route('admin.distribution.destroy', ['channelId' => (int) $channel->id]), [
                'confirmation_name' => (string) $channel->name,
                'current_password' => 'secret-123',
                'impact_fingerprint' => str_repeat('a', 64),
                'ack_history' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('distribution');

        $this->assertDatabaseHas('distribution_channels', ['id' => (int) $channel->id]);
    }

    public function test_standard_admin_cannot_open_or_start_channel_deletion(): void
    {
        $channel = $this->channel();
        $admin = $this->admin('admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.delete', ['channelId' => (int) $channel->id]))
            ->assertForbidden();
        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.delete.prepare', ['channelId' => (int) $channel->id]))
            ->assertForbidden();

        $this->assertDatabaseHas('distribution_channels', [
            'id' => (int) $channel->id,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
    }

    public function test_final_delete_requires_password_exact_name_and_relevant_acknowledgements(): void
    {
        Http::fake();
        $channel = $this->channel();
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'delete_form_key',
            'secret_ciphertext' => 'encrypted-secret',
            'status' => 'active',
        ]);
        $admin = $this->admin('super_admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.delete.prepare', ['channelId' => (int) $channel->id]))
            ->assertRedirect(route('admin.distribution.delete', ['channelId' => (int) $channel->id]));
        $impact = app(DistributionChannelDeletionService::class)->inspect($channel->fresh());

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.distribution.destroy', ['channelId' => (int) $channel->id]), [
                'confirmation_name' => '错误名称',
                'current_password' => 'wrong-password',
                'impact_fingerprint' => $impact['impact_fingerprint'],
            ])
            ->assertRedirect()
            ->assertSessionHasErrors([
                'confirmation_name',
                'current_password',
                'ack_credentials',
                'ack_history',
            ]);

        $this->assertDatabaseHas('distribution_channels', ['id' => (int) $channel->id]);

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.distribution.destroy', ['channelId' => (int) $channel->id]), [
                'confirmation_name' => (string) $channel->name,
                'current_password' => 'secret-123',
                'impact_fingerprint' => $impact['impact_fingerprint'],
                'ack_credentials' => '1',
                'ack_history' => '1',
            ])
            ->assertRedirect(route('admin.distribution.index'))
            ->assertSessionHas('message');

        $this->assertDatabaseMissing('distribution_channels', ['id' => (int) $channel->id]);
        Http::assertNothingSent();
    }

    public function test_deleting_channel_cannot_be_reactivated_or_edited_through_regular_routes(): void
    {
        $channel = $this->channel();
        $admin = $this->admin('super_admin');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.delete.prepare', ['channelId' => (int) $channel->id]));

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.activate', ['channelId' => (int) $channel->id]))
            ->assertRedirect(route('admin.distribution.delete', ['channelId' => (int) $channel->id]))
            ->assertSessionHasErrors();
        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.edit', ['channelId' => (int) $channel->id]))
            ->assertRedirect(route('admin.distribution.delete', ['channelId' => (int) $channel->id]))
            ->assertSessionHasErrors();

        $this->assertDatabaseHas('distribution_channels', [
            'id' => (int) $channel->id,
            'status' => DistributionChannel::STATUS_DELETING,
        ]);
    }

    public function test_channel_pages_guide_super_admin_into_the_delete_flow(): void
    {
        $channel = $this->channel();
        $admin = $this->admin('super_admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.index'))
            ->assertOk()
            ->assertSee(route('admin.distribution.delete', ['channelId' => (int) $channel->id]), false);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.delete.prepare', ['channelId' => (int) $channel->id]));

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.show', ['channelId' => (int) $channel->id]))
            ->assertOk()
            ->assertSee(__('admin.distribution.delete.message.prepared'))
            ->assertSee(route('admin.distribution.delete', ['channelId' => (int) $channel->id]), false)
            ->assertDontSee(route('admin.distribution.edit', ['channelId' => (int) $channel->id]), false);
    }

    public function test_delete_flow_copy_is_available_in_every_supported_locale(): void
    {
        foreach (['zh_CN', 'en', 'ja', 'es', 'ru', 'pt_BR'] as $locale) {
            $translations = file_get_contents(lang_path($locale.'/admin.php'));

            $this->assertIsString($translations);
            $this->assertStringContainsString(
                "'distribution' => [",
                $translations,
                "Missing channel deletion copy for locale {$locale}."
            );
            $this->assertStringContainsString(
                "'deleting' =>",
                $translations,
                "Missing deleting status copy for locale {$locale}."
            );
            $this->assertStringContainsString(
                "'operation_wait' =>",
                $translations,
                "Missing channel operation deletion copy for locale {$locale}."
            );
            $this->assertStringContainsString("'sending_retry_blocked' =>", $translations);
            $this->assertStringContainsString("'task_update_stale_error' =>", $translations);
        }
    }

    public function test_final_delete_password_checks_are_rate_limited(): void
    {
        $channel = $this->channel();
        $admin = $this->admin('super_admin');
        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.delete.prepare', ['channelId' => (int) $channel->id]));
        $impact = app(DistributionChannelDeletionService::class)->inspect($channel->fresh());
        $payload = [
            'confirmation_name' => (string) $channel->name,
            'current_password' => 'wrong-password',
            'impact_fingerprint' => $impact['impact_fingerprint'],
            'ack_history' => '1',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->actingAs($admin, 'admin')
                ->delete(route('admin.distribution.destroy', ['channelId' => (int) $channel->id]), $payload)
                ->assertRedirect();
        }

        $this->actingAs($admin, 'admin')
            ->delete(route('admin.distribution.destroy', ['channelId' => (int) $channel->id]), $payload)
            ->assertTooManyRequests();
        $this->assertDatabaseHas('distribution_channels', ['id' => (int) $channel->id]);
    }

    private function channel(): DistributionChannel
    {
        return DistributionChannel::query()->create([
            'name' => '准备删除的渠道',
            'domain' => 'delete.example.com',
            'endpoint_url' => 'https://delete.example.com',
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
    }

    private function admin(string $role): Admin
    {
        return Admin::query()->create([
            'username' => 'delete_'.$role,
            'password' => 'secret-123',
            'email' => 'delete-'.$role.'@example.com',
            'display_name' => 'Delete '.$role,
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
