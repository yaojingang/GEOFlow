<?php

namespace Tests\Feature;

use App\Contracts\SystemUpdater\AgentClient;
use App\Models\Admin;
use App\Services\Admin\SystemUpdaterBootstrapService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminSystemUpdaterBridgeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        config([
            'geoflow.update_center_enabled' => true,
            'geoflow.update_check_enabled' => false,
        ]);
    }

    public function test_super_admin_sees_connected_updater_status_before_legacy_controls(): void
    {
        $this->app->instance(AgentClient::class, new class implements AgentClient
        {
            public function status(): array
            {
                return [
                    'schema_version' => 1,
                    'status' => 'pass',
                    'instance' => [
                        'id' => 'primary',
                        'version' => '2.4.0',
                        'release_sequence' => 17,
                    ],
                    'checks' => [],
                    'updater_version' => '0.1.0',
                ];
            }
        });

        $response = $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.system-updates.index'));

        $response
            ->assertOk()
            ->assertSee(__('admin.system_updates.updater.title'))
            ->assertSee(__('admin.system_updates.updater.status.connected'))
            ->assertSee('0.1.0')
            ->assertSeeInOrder([
                __('admin.system_updates.updater.title'),
                __('admin.system_updates.section.overview'),
            ]);
    }

    public function test_disconnected_updater_shows_safe_package_preparation_action(): void
    {
        $this->app->instance(AgentClient::class, new class implements AgentClient
        {
            public function status(): array
            {
                throw new \RuntimeException('socket unavailable');
            }
        });

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.updater.status.disconnected'))
            ->assertSee(route('admin.system-updates.updater.prepare'), false)
            ->assertSee(__('admin.system_updates.updater.prepare'));
    }

    public function test_super_admin_can_prepare_a_verified_updater_package(): void
    {
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('prepare')->once()->andReturn([
                'version' => '0.1.0',
                'filename' => 'geoflow-updater_0.1.0_linux_amd64.tar.gz',
            ]);
        });

        $this->actingAs($this->createAdmin(), 'admin')
            ->post(route('admin.system-updates.updater.prepare'))
            ->assertRedirect(route('admin.system-updates.index'))
            ->assertSessionHas('message', __('admin.system_updates.updater.prepared', ['version' => '0.1.0']));
    }

    public function test_prepared_package_exposes_a_private_download_entry(): void
    {
        config(['geoflow.updater_host_root' => '/opt/geoflow']);
        $this->app->instance(AgentClient::class, new class implements AgentClient
        {
            public function status(): array
            {
                throw new \RuntimeException('socket unavailable');
            }
        });
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('state')->once()->andReturn([
                'version' => '0.1.0',
                'filename' => 'geoflow-updater_0.1.0_linux_amd64.tar.gz',
                'path' => 'system-updater/bootstrap/0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz',
                'sha256' => str_repeat('a', 64),
                'size' => 1024,
            ]);
        });

        $this->actingAs($this->createAdmin('prepared_updater_admin'), 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(route('admin.system-updates.updater.download'), false)
            ->assertSee(__('admin.system_updates.updater.download'))
            ->assertSee("sudo docker compose --env-file '/opt/geoflow/.env.prod' --env-file /var/lib/geoflow-updater/instances/primary/release.env -f /var/lib/geoflow-updater/instances/primary/docker-compose.managed.yml down")
            ->assertSee("sudo docker compose --env-file '/opt/geoflow/.env.prod' --env-file /var/lib/geoflow-updater/instances/primary/release.env -f /var/lib/geoflow-updater/instances/primary/docker-compose.managed.yml up -d");
    }

    public function test_standard_admin_cannot_prepare_an_updater_package(): void
    {
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('prepare');
        });

        $this->actingAs($this->createAdmin('standard_updater_admin', 'admin'), 'admin')
            ->post(route('admin.system-updates.updater.prepare'))
            ->assertForbidden();
    }

    public function test_super_admin_can_download_the_prepared_private_package(): void
    {
        Storage::fake('local');
        $path = 'system-updater/bootstrap/0.1.0/geoflow-updater_0.1.0_linux_amd64.tar.gz';
        Storage::disk('local')->put($path, 'verified archive');
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock) use ($path): void {
            $mock->shouldReceive('download')->once()->andReturn([
                'path' => $path,
                'filename' => basename($path),
            ]);
        });

        $this->actingAs($this->createAdmin(), 'admin')
            ->get(route('admin.system-updates.updater.download'))
            ->assertOk()
            ->assertDownload(basename($path));
    }

    public function test_standard_admin_cannot_download_the_prepared_package(): void
    {
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('download');
        });

        $this->actingAs($this->createAdmin('standard_updater_download_admin', 'admin'), 'admin')
            ->get(route('admin.system-updates.updater.download'))
            ->assertForbidden();
    }

    public function test_stale_prepared_package_returns_to_the_update_center_with_an_error(): void
    {
        $this->mock(SystemUpdaterBootstrapService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('download')->once()->andThrow(new \RuntimeException('archive changed'));
        });

        $this->actingAs($this->createAdmin('stale_updater_admin'), 'admin')
            ->get(route('admin.system-updates.updater.download'))
            ->assertRedirect(route('admin.system-updates.index'))
            ->assertSessionHasErrors();
    }

    private function createAdmin(string $username = 'system_updater_admin', string $role = 'super_admin'): Admin
    {
        return Admin::query()->create([
            'username' => $username,
            'password' => 'secret-123',
            'email' => $username.'@example.com',
            'display_name' => 'System Updater Admin',
            'role' => $role,
            'status' => 'active',
        ]);
    }
}
