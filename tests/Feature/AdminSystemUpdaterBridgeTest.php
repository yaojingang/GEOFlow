<?php

namespace Tests\Feature;

use App\Contracts\SystemUpdater\AgentClient;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\SystemUpdateRun;
use App\Services\Admin\SystemUpdateOperationGuard;
use App\Services\Admin\SystemUpdaterBootstrapService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
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
        $this->app->instance(AgentClient::class, new class extends AgentClientStub
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

    public function test_degraded_updater_renders_each_doctor_check_with_its_message(): void
    {
        $this->app->instance(AgentClient::class, new class extends AgentClientStub
        {
            public function status(): array
            {
                return [
                    'schema_version' => 1,
                    'status' => 'warn',
                    'instance' => [
                        'id' => 'primary',
                        'version' => '2.4.0',
                        'release_sequence' => 17,
                    ],
                    'checks' => [
                        ['id' => 'docker-compose', 'status' => 'fail', 'message' => 'Docker Compose v2 is unavailable.'],
                        ['id' => 'control-token', 'status' => 'pass', 'message' => 'Control token permissions are restricted.'],
                    ],
                    'updater_version' => '0.1.0',
                ];
            }
        });

        $this->actingAs($this->createAdmin('degraded_updater_admin'), 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(__('admin.system_updates.updater.status.degraded'))
            ->assertSee(__('admin.system_updates.updater.checks'))
            ->assertSee('docker-compose')
            ->assertSee('Docker Compose v2 is unavailable.')
            ->assertSee('Control token permissions are restricted.');
    }

    public function test_disconnected_updater_shows_safe_package_preparation_action(): void
    {
        $this->app->instance(AgentClient::class, new class extends AgentClientStub
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
        $this->app->instance(AgentClient::class, new class extends AgentClientStub
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

    public function test_connected_updater_shows_transaction_controls_operation_progress_and_recovery_points(): void
    {
        $client = new AgentClientStub;
        $client->current = [
            'schema_version' => 1,
            'id' => '20260827T123456.000000000Z-0011223344556677',
            'instance_id' => 'primary',
            'kind' => 'update',
            'status' => 'running',
            'current_stage' => 'backup',
            'stages' => [
                ['name' => 'quiesce', 'status' => 'succeeded', 'updated_at' => '2026-08-27T12:35:00Z'],
                ['name' => 'backup', 'status' => 'running', 'updated_at' => '2026-08-27T12:35:01Z'],
            ],
            'started_at' => '2026-08-27T12:34:56Z',
        ];
        $client->points = [[
            'schema_version' => 1,
            'id' => '20260827T120000Z-1234abcd',
            'instance_id' => 'primary',
            'reason' => 'update-to-2.5.0',
            'created_at' => '2026-08-27T12:00:00Z',
            'version' => '2.4.0',
            'release_sequence' => 17,
        ]];
        $this->app->instance(AgentClient::class, $client);

        $response = $this->actingAs($this->createAdmin('phase_b_view_admin'), 'admin')
            ->get(route('admin.system-updates.index'))
            ->assertOk()
            ->assertSee(route('admin.system-updates.updater.update'), false)
            ->assertSee(route('admin.system-updates.updater.backup'), false)
            ->assertSee(route('admin.system-updates.updater.verify'), false)
            ->assertSee(route('admin.system-updates.updater.rollback'), false)
            ->assertSee('data-system-updater-auto-reload="5000"', false)
            ->assertDontSee('window.setTimeout(() => window.location.reload(), 5000);', false)
            ->assertSee('backup')
            ->assertSee('20260827T120000Z-1234abcd');

    }

    public function test_super_admin_can_start_a_transactional_update_after_password_confirmation(): void
    {
        if (! Schema::hasTable('admin_activity_logs')) {
            Schema::create('admin_activity_logs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('admin_id')->nullable();
                $table->string('admin_username', 50);
                $table->string('admin_role', 20)->default('admin');
                $table->string('action', 120);
                $table->string('request_method', 10)->default('POST');
                $table->string('page')->default('');
                $table->string('target_type', 50)->default('');
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('ip_address', 64)->default('');
                $table->text('details')->default('');
                $table->timestamp('created_at')->useCurrent();
            });
        }

        $client = new AgentClientStub;
        $this->app->instance(AgentClient::class, $client);

        $this->actingAs($this->createAdmin('phase_b_update_admin'), 'admin')
            ->post(route('admin.system-updates.updater.update'), [
                'current_admin_password' => 'secret-123',
            ])
            ->assertRedirect(route('admin.system-updates.index'));

        $this->assertSame(1, $client->updates);

        $activity = AdminActivityLog::query()->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('secret-123', (string) $activity->details);
        $this->assertStringNotContainsString('current_admin_password', (string) $activity->details);
    }

    public function test_transactional_update_rejects_an_invalid_admin_password(): void
    {
        $client = new AgentClientStub;
        $this->app->instance(AgentClient::class, $client);

        $response = $this->actingAs($this->createAdmin('phase_b_password_admin'), 'admin')
            ->post(route('admin.system-updates.updater.update'), [
                'current_admin_password' => 'incorrect',
            ])
            ->assertSessionHasErrors('current_admin_password');

        $response->assertSessionMissing('_old_input.current_admin_password');

        $this->assertSame(0, $client->updates);
    }

    public function test_super_admin_can_start_a_one_click_rollback_to_a_valid_recovery_point(): void
    {
        $client = new AgentClientStub;
        $this->app->instance(AgentClient::class, $client);
        $recoveryPointId = '20260827T120000Z-1234abcd';

        $this->actingAs($this->createAdmin('phase_b_rollback_admin'), 'admin')
            ->post(route('admin.system-updates.updater.rollback'), [
                'current_admin_password' => 'secret-123',
                'recovery_point_id' => $recoveryPointId,
            ])
            ->assertRedirect(route('admin.system-updates.index'));

        $this->assertSame([$recoveryPointId], $client->rollbacks);
    }

    public function test_updater_failure_is_reported_without_flashing_internal_details(): void
    {
        Exceptions::fake();
        $this->app->instance(AgentClient::class, new class extends AgentClientStub
        {
            public function startUpdate(): array
            {
                throw new \RuntimeException('unix:///run/private-agent.sock returned database credentials');
            }
        });

        $response = $this->actingAs($this->createAdmin('phase_b_failure_admin'), 'admin')
            ->post(route('admin.system-updates.updater.update'), [
                'current_admin_password' => 'secret-123',
            ])
            ->assertRedirect(route('admin.system-updates.index'))
            ->assertSessionHasErrors();

        $message = $response->getSession()->get('errors')->first();
        $this->assertSame(__('admin.system_updates.updater.operation_failed'), $message);
        $this->assertStringNotContainsString('private-agent.sock', $message);
        Exceptions::assertReported(fn (\RuntimeException $exception): bool => str_contains($exception->getMessage(), 'private-agent.sock'));
    }

    public function test_updater_operation_is_blocked_while_a_legacy_executor_run_is_active(): void
    {
        SystemUpdateRun::query()->create([
            'run_uuid' => 'phase-b-legacy-active',
            'action' => 'apply',
            'status' => 'running',
        ]);
        $client = new AgentClientStub;
        $this->app->instance(AgentClient::class, $client);

        $this->actingAs($this->createAdmin('phase_b_cross_guard_admin'), 'admin')
            ->post(route('admin.system-updates.updater.update'), [
                'current_admin_password' => 'secret-123',
            ])
            ->assertRedirect(route('admin.system-updates.index'))
            ->assertSessionHasErrors();

        $this->assertSame(0, $client->updates);
    }

    public function test_legacy_executor_guard_is_blocked_while_an_updater_operation_is_active(): void
    {
        $client = new AgentClientStub;
        $client->current = [
            'schema_version' => 1,
            'id' => '20260827T123456.000000000Z-0011223344556677',
            'instance_id' => 'primary',
            'kind' => 'update',
            'status' => 'running',
            'stages' => [],
            'started_at' => '2026-08-27T12:34:56Z',
        ];
        $this->app->instance(AgentClient::class, $client);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(__('admin.system_updates.error.operation_in_progress'));

        $this->app->make(SystemUpdateOperationGuard::class)->assertNoUpdaterExecution();
    }

    public function test_legacy_executor_guard_fails_closed_when_an_installed_updater_is_unreachable(): void
    {
        $directory = sys_get_temp_dir().'/geoflow-updater-guard-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700, true);
        $socketPath = $directory.'/agent.sock';
        $server = stream_socket_server('unix://'.$socketPath, $errorCode, $errorMessage);
        $this->assertIsResource($server, $errorMessage);
        config(['geoflow.updater_socket' => $socketPath]);
        $this->app->instance(AgentClient::class, new class extends AgentClientStub
        {
            public function currentOperation(): ?array
            {
                throw new \RuntimeException('agent unavailable');
            }
        });

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage(__('admin.system_updates.error.operation_in_progress'));

            $this->app->make(SystemUpdateOperationGuard::class)->assertNoUpdaterExecution();
        } finally {
            fclose($server);
            @unlink($socketPath);
            @rmdir($directory);
        }
    }

    public function test_super_admin_can_start_a_manual_backup_and_verification(): void
    {
        $client = new AgentClientStub;
        $admin = $this->createAdmin('phase_b_backup_admin');
        $this->app->instance(AgentClient::class, $client);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.updater.backup'), [
                'current_admin_password' => 'secret-123',
            ])
            ->assertRedirect(route('admin.system-updates.index'));
        $this->actingAs($admin, 'admin')
            ->post(route('admin.system-updates.updater.verify'))
            ->assertRedirect(route('admin.system-updates.index'));

        $this->assertSame(1, $client->backups);
        $this->assertSame(1, $client->verifications);
    }

    public function test_standard_admin_cannot_start_updater_operations(): void
    {
        $client = new AgentClientStub;
        $this->app->instance(AgentClient::class, $client);

        $this->actingAs($this->createAdmin('phase_b_standard_admin', 'admin'), 'admin')
            ->post(route('admin.system-updates.updater.update'), [
                'current_admin_password' => 'secret-123',
            ])
            ->assertForbidden();

        $this->assertSame(0, $client->updates);
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

class AgentClientStub implements AgentClient
{
    public int $updates = 0;

    public int $backups = 0;

    public int $verifications = 0;

    /** @var list<string> */
    public array $rollbacks = [];

    /** @var array<string, mixed>|null */
    public ?array $current = null;

    /** @var list<array<string, mixed>> */
    public array $points = [];

    public function status(): array
    {
        return [
            'schema_version' => 1,
            'status' => 'pass',
            'instance' => ['id' => 'primary', 'version' => '2.4.0', 'release_sequence' => 17],
            'checks' => [],
            'updater_version' => '0.2.0',
        ];
    }

    public function startUpdate(): array
    {
        $this->updates++;

        return $this->queuedOperation('update');
    }

    public function startBackup(): array
    {
        $this->backups++;

        return $this->queuedOperation('backup');
    }

    public function startRollback(string $recoveryPointId): array
    {
        $this->rollbacks[] = $recoveryPointId;

        return $this->queuedOperation('rollback');
    }

    public function startVerify(): array
    {
        $this->verifications++;

        return $this->queuedOperation('verify');
    }

    public function currentOperation(): ?array
    {
        return $this->current;
    }

    public function recoveryPoints(): array
    {
        return $this->points;
    }

    /** @return array<string, mixed> */
    private function queuedOperation(string $kind): array
    {
        return [
            'schema_version' => 1,
            'id' => '20260827T123456.000000000Z-0011223344556677',
            'instance_id' => 'primary',
            'kind' => $kind,
            'status' => 'queued',
            'stages' => [],
            'started_at' => '2026-08-27T12:34:56Z',
        ];
    }
}
