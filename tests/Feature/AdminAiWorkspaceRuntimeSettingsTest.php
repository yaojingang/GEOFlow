<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\SiteSetting;
use App\Services\AiWorkspace\AiWorkspaceConnectionStatus;
use App\Services\AiWorkspace\AiWorkspaceRuntimeStatus;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AdminAiWorkspaceRuntimeSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai-workspace.runtime_enabled', false);
        config()->set('ai-workspace.force_disabled', false);

        if (! Schema::hasTable('admin_activity_logs')) {
            Schema::create('admin_activity_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('admin_id')->nullable();
                $table->string('admin_username', 50);
                $table->string('admin_role', 20)->default('admin');
                $table->string('action', 120);
                $table->string('request_method', 10)->default('POST');
                $table->string('page')->default('');
                $table->string('target_type', 50)->default('');
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('ip_address', 64)->default('');
                $table->text('details')->default('');
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function test_super_admin_can_enable_the_workspace_from_site_settings(): void
    {
        $admin = $this->admin('runtime-super', 'super_admin');

        $response = $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee('id="site-settings-ai-workspace"', false)
            ->assertSee(__('admin.site_settings.ai_workspace_runtime.title'));
        self::assertSame(1, substr_count((string) $response->getContent(), 'id="ai-workspace-runtime-help"'));

        $this->post(route('admin.site-settings.ai-workspace.update'), ['enabled' => '1'])
            ->assertRedirect(route('admin.site-settings.index'))
            ->assertSessionHas('message', __('admin.site_settings.ai_workspace_runtime.saved_enabled'))
            ->assertSessionHas('site_settings_open_target', 'site-settings-ai-workspace');

        $page = $this->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee('data-site-settings-open-target="site-settings-ai-workspace"', false);
        self::assertMatchesRegularExpression(
            '/<details id="site-settings-ai-workspace"[^>]*\sopen(?:\s|>)/',
            (string) $page->getContent(),
        );

        $this->assertDatabaseHas('site_settings', [
            'setting_key' => AiWorkspaceRuntimeStatus::SETTING_KEY,
            'setting_value' => '1',
        ]);
        self::assertTrue(app(AiWorkspaceRuntimeStatus::class)->enabled());

        $activity = AdminActivityLog::query()->latest('id')->firstOrFail();
        self::assertSame('admin.site-settings.ai-workspace.update:save', $activity->action);
        self::assertTrue((bool) data_get(json_decode((string) $activity->details, true), 'effective_enabled'));
    }

    public function test_standard_admin_cannot_see_or_change_the_workspace_switch(): void
    {
        $admin = $this->admin('runtime-admin');

        $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertDontSee('id="site-settings-ai-workspace"', false)
            ->assertDontSee(__('admin.site_settings.ai_workspace_runtime.title'));

        $this->post(route('admin.site-settings.ai-workspace.update'), ['enabled' => '1'])
            ->assertForbidden();

        $this->assertDatabaseMissing('site_settings', [
            'setting_key' => AiWorkspaceRuntimeStatus::SETTING_KEY,
        ]);
    }

    public function test_unauthenticated_request_cannot_change_the_workspace_switch(): void
    {
        $this->post(route('admin.site-settings.ai-workspace.update'), ['enabled' => '1'])
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseMissing('site_settings', [
            'setting_key' => AiWorkspaceRuntimeStatus::SETTING_KEY,
        ]);
    }

    public function test_server_force_disable_overrides_the_saved_admin_preference(): void
    {
        $admin = $this->admin('runtime-forced', 'super_admin');
        app(AiWorkspaceRuntimeStatus::class)->save(true);
        config()->set('ai-workspace.force_disabled', true);

        self::assertFalse(app(AiWorkspaceRuntimeStatus::class)->enabled());
        self::assertSame(
            __('admin.ai_workspace.connection_runtime_disabled_admin'),
            app(AiWorkspaceConnectionStatus::class)->forAdmin($admin)['message'],
        );

        $this->actingAs($admin, 'admin')
            ->get(route('admin.site-settings.index'))
            ->assertOk()
            ->assertSee(__('admin.site_settings.ai_workspace_runtime.forced_disabled'))
            ->assertSee(__('admin.site_settings.ai_workspace_runtime.status_disabled'));
    }

    public function test_legacy_environment_value_remains_the_default_until_the_admin_saves_a_preference(): void
    {
        config()->set('ai-workspace.runtime_enabled', true);

        $snapshot = app(AiWorkspaceRuntimeStatus::class)->snapshot();

        self::assertTrue($snapshot['preferred_enabled']);
        self::assertTrue($snapshot['effective_enabled']);
        self::assertSame('environment_default', $snapshot['source']);
    }

    public function test_saved_preference_overrides_the_legacy_environment_default(): void
    {
        config()->set('ai-workspace.runtime_enabled', true);
        app(AiWorkspaceRuntimeStatus::class)->save(false);

        $snapshot = app(AiWorkspaceRuntimeStatus::class)->snapshot();

        self::assertFalse($snapshot['preferred_enabled']);
        self::assertFalse($snapshot['effective_enabled']);
        self::assertSame('site_setting', $snapshot['source']);
    }

    public function test_runtime_checks_read_a_newly_saved_preference_immediately(): void
    {
        $runtimeStatus = app(AiWorkspaceRuntimeStatus::class);
        $runtimeStatus->save(false);
        self::assertFalse($runtimeStatus->enabled());

        SiteSetting::query()
            ->where('setting_key', AiWorkspaceRuntimeStatus::SETTING_KEY)
            ->update(['setting_value' => '1']);

        self::assertTrue($runtimeStatus->enabled());
    }

    public function test_invalid_saved_preference_fails_closed(): void
    {
        SiteSetting::query()->create([
            'setting_key' => AiWorkspaceRuntimeStatus::SETTING_KEY,
            'setting_value' => 'unexpected',
        ]);

        $snapshot = app(AiWorkspaceRuntimeStatus::class)->snapshot();

        self::assertFalse($snapshot['preferred_enabled']);
        self::assertFalse($snapshot['effective_enabled']);
        self::assertSame('site_setting', $snapshot['source']);
    }

    public function test_runtime_setting_requires_an_explicit_boolean_value(): void
    {
        $admin = $this->admin('runtime-validation', 'super_admin');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.site-settings.ai-workspace.update'))
            ->assertSessionHasErrors('enabled');

        $this->assertDatabaseMissing('site_settings', [
            'setting_key' => AiWorkspaceRuntimeStatus::SETTING_KEY,
        ]);
    }

    private function admin(string $username, string $role = 'admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => $username,
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
