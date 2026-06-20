<?php

namespace Tests\Feature;

use App\Console\Commands\GeoFlowInstallCommand;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\SystemState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class GeoFlowInstallCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_install_command_seeds_empty_database_once_and_writes_marker(): void
    {
        Config::set('geoflow.seed_frontend_demo', false);

        $this->artisan('geoflow:install')
            ->assertExitCode(0);

        $admin = Admin::query()->where('username', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('password', (string) $admin->password));
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Article::query()->count());

        $state = SystemState::query()->where('key', GeoFlowInstallCommand::INSTALLATION_STATE_KEY)->first();
        $this->assertNotNull($state);
        $this->assertSame('fresh_install', $state->value['mode'] ?? null);
    }

    public function test_install_command_skips_when_marker_exists_without_overwriting_admin(): void
    {
        $this->artisan('geoflow:install')->assertExitCode(0);

        $admin = Admin::query()->where('username', 'admin')->firstOrFail();
        $admin->forceFill([
            'email' => 'custom-admin@example.com',
            'password' => 'custom-secret',
        ])->save();
        $originalPasswordHash = (string) $admin->password;

        $this->artisan('geoflow:install')
            ->assertExitCode(0);

        $admin->refresh();
        $this->assertSame('custom-admin@example.com', $admin->email);
        $this->assertSame($originalPasswordHash, (string) $admin->password);
        $this->assertSame(1, Admin::query()->where('username', 'admin')->count());
    }

    public function test_install_command_backfills_marker_for_existing_database_without_seeding(): void
    {
        SiteSetting::query()->create([
            'setting_key' => 'site_name',
            'setting_value' => '用户线上站点',
        ]);

        $this->artisan('geoflow:install')
            ->assertExitCode(0);

        $this->assertSame(0, Admin::query()->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame('用户线上站点', SiteSetting::query()->where('setting_key', 'site_name')->value('setting_value'));

        $state = SystemState::query()->where('key', GeoFlowInstallCommand::INSTALLATION_STATE_KEY)->first();
        $this->assertNotNull($state);
        $this->assertSame('backfilled_existing_database', $state->value['mode'] ?? null);
        $this->assertContains('site_settings', $state->value['detected_tables'] ?? []);
    }

    public function test_install_command_ignores_migration_default_site_settings_when_detecting_empty_database(): void
    {
        $this->assertTrue(SiteSetting::query()->where('setting_key', 'active_theme')->exists());

        $this->artisan('geoflow:install')
            ->assertExitCode(0);

        $this->assertSame(1, Admin::query()->where('username', 'admin')->count());

        $state = SystemState::query()->where('key', GeoFlowInstallCommand::INSTALLATION_STATE_KEY)->first();
        $this->assertNotNull($state);
        $this->assertSame('fresh_install', $state->value['mode'] ?? null);
    }

    public function test_install_command_only_seeds_frontend_demo_when_enabled_on_empty_database(): void
    {
        Config::set('geoflow.seed_frontend_demo', true);
        Config::set('geoflow.seed_frontend_demo_overwrite', false);

        $this->artisan('geoflow:install')
            ->assertExitCode(0);

        $this->assertSame(1, Admin::query()->where('username', 'admin')->count());
        $this->assertGreaterThan(0, Category::query()->where('slug', 'mac')->count());
        $this->assertGreaterThan(0, Article::query()->where('slug', 'how-to-reinstall-macos')->count());
    }
}
