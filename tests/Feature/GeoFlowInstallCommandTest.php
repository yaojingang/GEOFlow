<?php

namespace Tests\Feature;

use App\Console\Commands\GeoFlowInstallCommand;
use App\Models\Admin;
use App\Models\Article;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\SystemState;
use Database\Seeders\FrontendReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class GeoFlowInstallCommandTest extends TestCase
{
    use RefreshDatabase;

    private const BAIDU_ANALYTICS_EXAMPLE = <<<'HTML'
<script>
var _hmt = _hmt || [];
(function() {
  var hm = document.createElement("script");
  hm.src = "https://hm.baidu.com/hm.js?1743638f313788caa4cb55e299444a87";
  var s = document.getElementsByTagName("script")[0];
  s.parentNode.insertBefore(hm, s);
})();
</script>
HTML;

    public function test_install_command_seeds_empty_database_once_and_writes_marker(): void
    {
        Config::set('geoflow.seed_frontend_demo', false);

        $this->artisan('geoflow:install')
            ->assertExitCode(0);

        $this->assertTrue(Schema::hasColumns('api_idempotency_keys', ['fingerprint_version', 'state', 'owner_token', 'lease_expires_at']));
        $this->assertTrue(Schema::hasTable('managed_image_paths'));
        $this->assertTrue(Schema::hasColumn('images', 'managed_path_hash'));

        $admin = Admin::query()->where('username', 'admin')->first();
        $this->assertNotNull($admin);
        $this->assertTrue(Hash::check('password', (string) $admin->password));
        $this->assertSame(2, Category::query()->count());
        $this->assertSame(50, Article::query()->count());
        $this->assertSame(
            'geoflow-template-21-enterprise-signature',
            SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value'),
        );
        $this->assertSame(
            self::BAIDU_ANALYTICS_EXAMPLE,
            SiteSetting::query()->where('setting_key', 'analytics_code')->value('setting_value'),
        );
        $response = $this->get(route('site.home'))
            ->assertOk()
            ->assertSee('https://hm.baidu.com/hm.js?1743638f313788caa4cb55e299444a87', false);
        $this->assertSame(
            1,
            substr_count($response->getContent(), 'https://hm.baidu.com/hm.js?1743638f313788caa4cb55e299444a87'),
        );

        $state = SystemState::query()->where('key', GeoFlowInstallCommand::INSTALLATION_STATE_KEY)->first();
        $this->assertNotNull($state);
        $this->assertSame('fresh_install', $state->value['mode'] ?? null);
        $this->assertSame('frontend-reference-v1', $state->value['reference_content_version'] ?? null);
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
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => 'analytics_code'],
            ['setting_value' => '<script>userAnalytics()</script>'],
        );

        $this->artisan('geoflow:install')
            ->assertExitCode(0);

        $admin->refresh();
        $this->assertSame('custom-admin@example.com', $admin->email);
        $this->assertSame($originalPasswordHash, (string) $admin->password);
        $this->assertSame(1, Admin::query()->where('username', 'admin')->count());
        $this->assertSame(
            '<script>userAnalytics()</script>',
            SiteSetting::query()->where('setting_key', 'analytics_code')->value('setting_value'),
        );
    }

    public function test_install_command_backfills_marker_for_existing_database_without_seeding(): void
    {
        SiteSetting::query()->create([
            'setting_key' => 'site_name',
            'setting_value' => '用户线上站点',
        ]);
        SiteSetting::query()->where('setting_key', 'active_theme')->update([
            'setting_value' => 'user-owned-theme',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'analytics_code',
            'setting_value' => '<script>existingAnalytics()</script>',
        ]);

        $this->artisan('geoflow:install')
            ->assertExitCode(0);

        $this->assertSame(0, Admin::query()->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame('用户线上站点', SiteSetting::query()->where('setting_key', 'site_name')->value('setting_value'));
        $this->assertSame('user-owned-theme', SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value'));
        $this->assertSame(
            '<script>existingAnalytics()</script>',
            SiteSetting::query()->where('setting_key', 'analytics_code')->value('setting_value'),
        );

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
        $this->assertSame(50, Article::query()->count());
        $this->assertSame(
            'geoflow-template-21-enterprise-signature',
            SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value'),
        );

        $state = SystemState::query()->where('key', GeoFlowInstallCommand::INSTALLATION_STATE_KEY)->first();
        $this->assertNotNull($state);
        $this->assertSame('fresh_install', $state->value['mode'] ?? null);
    }

    public function test_install_command_preserves_a_custom_theme_as_existing_site_data(): void
    {
        SiteSetting::query()->where('setting_key', 'active_theme')->update([
            'setting_value' => 'user-owned-theme',
        ]);

        $this->artisan('geoflow:install')->assertSuccessful();

        $this->assertSame(0, Admin::query()->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Article::query()->count());
        $this->assertSame(
            'user-owned-theme',
            SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value'),
        );

        $state = SystemState::query()->where('key', GeoFlowInstallCommand::INSTALLATION_STATE_KEY)->firstOrFail();
        $this->assertSame('backfilled_existing_database', $state->value['mode'] ?? null);
        $this->assertContains('site_settings', $state->value['detected_tables'] ?? []);
    }

    public function test_failed_reference_seed_rolls_back_and_a_retry_completes_the_fresh_install(): void
    {
        $this->app->bind(FrontendReferenceSeeder::class, fn () => new class extends FrontendReferenceSeeder
        {
            public function run(): void
            {
                Category::query()->create([
                    'name' => '中断测试',
                    'slug' => 'interrupted-install',
                ]);

                throw new RuntimeException('Simulated reference pack failure.');
            }
        });

        $this->artisan('geoflow:install')->assertFailed();

        $this->assertSame(0, Admin::query()->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Article::query()->count());
        $this->assertFalse(SiteSetting::query()->where('setting_key', 'analytics_code')->exists());
        $this->assertFalse(SystemState::query()->where('key', GeoFlowInstallCommand::INSTALLATION_STATE_KEY)->exists());

        $this->app->bind(FrontendReferenceSeeder::class, fn () => new FrontendReferenceSeeder);

        $this->artisan('geoflow:install')->assertSuccessful();

        $this->assertSame(1, Admin::query()->where('username', 'admin')->count());
        $this->assertSame(2, Category::query()->count());
        $this->assertSame(50, Article::query()->count());
        $this->assertSame(
            'geoflow-template-21-enterprise-signature',
            SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value'),
        );
        $this->assertSame(
            'fresh_install',
            SystemState::query()->where('key', GeoFlowInstallCommand::INSTALLATION_STATE_KEY)->firstOrFail()->value['mode'] ?? null,
        );
    }

    public function test_install_command_can_skip_reference_content_on_a_minimal_fresh_install(): void
    {
        $this->artisan('geoflow:install', ['--without-demo' => true])
            ->assertExitCode(0);

        $this->assertSame(1, Admin::query()->where('username', 'admin')->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame(0, Article::query()->count());
        $this->assertNotSame(
            'geoflow-template-21-enterprise-signature',
            SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value'),
        );
        $this->assertSame(
            self::BAIDU_ANALYTICS_EXAMPLE,
            SiteSetting::query()->where('setting_key', 'analytics_code')->value('setting_value'),
        );

        $state = SystemState::query()->where('key', GeoFlowInstallCommand::INSTALLATION_STATE_KEY)->firstOrFail();
        $this->assertFalse($state->value['seed_frontend_reference'] ?? true);
    }

    public function test_force_on_an_existing_install_does_not_import_reference_content_or_change_theme_by_default(): void
    {
        SiteSetting::query()->where('setting_key', 'active_theme')->update([
            'setting_value' => 'user-owned-theme',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'site_name',
            'setting_value' => '已部署站点',
        ]);
        SiteSetting::query()->create([
            'setting_key' => 'analytics_code',
            'setting_value' => '<script>existingAnalytics()</script>',
        ]);

        $this->artisan('geoflow:install', ['--force' => true])->assertSuccessful();

        $this->assertSame(0, Article::query()->count());
        $this->assertSame(0, Category::query()->count());
        $this->assertSame('user-owned-theme', SiteSetting::query()->where('setting_key', 'active_theme')->value('setting_value'));
        $this->assertSame(
            '<script>existingAnalytics()</script>',
            SiteSetting::query()->where('setting_key', 'analytics_code')->value('setting_value'),
        );
    }

    public function test_install_command_reports_installation_and_later_version_change(): void
    {
        Config::set([
            'geoflow.seed_frontend_demo' => false,
            'geoflow.telemetry_enabled' => true,
            'geoflow.telemetry_endpoint' => 'https://monitor.example/api/pulse',
            'geoflow.app_version' => '2.3.0',
        ]);
        Http::fake([
            'https://monitor.example/api/pulse' => Http::response('', 204),
        ]);

        $this->artisan('geoflow:install')->assertSuccessful();
        Config::set('geoflow.app_version', '2.3.1');
        $this->artisan('geoflow:install')->assertSuccessful();

        $events = [];
        Http::assertSent(function ($request) use (&$events): bool {
            $events[] = $request->data()['event'] ?? null;

            return true;
        });

        $this->assertSame(['installed', 'updated'], $events);
    }
}
