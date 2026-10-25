<?php

namespace Tests\Feature;

use App\Contracts\SystemUpdater\AgentClient;
use App\Contracts\SystemUpdater\CoordinatedAgentClient;
use App\Models\Admin;
use App\Services\Api\ManagementInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class ManagementUpdaterTest extends TestCase
{
    use RefreshDatabase;

    private function connect(array $scopes = ['updater:read'], string $role = 'super_admin'): array
    {
        $admin = Admin::query()->create(['username' => 'updater-admin', 'password' => 'updater-password', 'role' => $role, 'status' => 'active']);
        $token = $admin->createToken('updater-test', $scopes)->plainTextToken;
        $this->withToken($token);

        return [$admin, $token];
    }

    private function agent(): MockInterface
    {
        $agent = Mockery::mock(CoordinatedAgentClient::class);
        $this->app->instance(AgentClient::class, $agent);

        return $agent;
    }

    private function capabilities(): array
    {
        return ['schema_version' => 2, 'instance_id' => 'primary', 'protocol_version' => 2, 'updater_protocol' => 5,
            'actions' => ['update', 'backup', 'restore', 'switch-back'],
            'features' => ['plans' => true, 'requests' => true, 'idempotency' => true, 'recovery_epoch' => true],
            'recovery' => ['host_id' => str_repeat('b', 32), 'epoch' => str_repeat('a', 32), 'phase' => 'ready']];
    }

    private function business(): array
    {
        return ['action' => 'backup', 'plan_id' => str_repeat('c', 32), 'plan_sha256' => str_repeat('d', 64),
            'expected_epoch' => str_repeat('a', 32), 'allow_maintenance' => true, 'confirm_host_access' => false];
    }

    private function receipt(): array
    {
        $business = $this->business();
        ksort($business);

        return ['schema_version' => 2, 'instance_id' => 'primary', 'client_request_id' => 'original-request-001',
            'business_sha256' => hash('sha256', json_encode($business, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
            'operation_id' => '20260916T120000.000000001Z-'.str_repeat('f', 16), 'action' => 'backup',
            'plan_id' => str_repeat('c', 32), 'accepted_epoch' => str_repeat('a', 32), 'admission_status' => 'accepted',
            'operation' => ['id' => '20260916T120000.000000001Z-'.str_repeat('f', 16), 'kind' => 'backup', 'status' => 'succeeded'],
            'background_status' => 'ready'];
    }

    private function withManagedInstance(callable $callback): void
    {
        $directory = sys_get_temp_dir().'/geoflow-updater-api-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        config(['geoflow.recovery_contract_required' => true, 'geoflow.recovery_control_directory' => $directory]);
        $state = ['schema_version' => 1, 'instance_id' => 'primary', 'host_id' => str_repeat('b', 32), 'epoch' => str_repeat('a', 32),
            'transaction_id' => null, 'phase' => 'ready', 'minimum_updater_protocol' => 5];
        file_put_contents($directory.'/state.json', json_encode($state));
        try {
            $callback($directory, $state);
        } finally {
            unlink($directory.'/state.json');
            rmdir($directory);
        }
    }

    private function hostPlan(Admin $admin): array
    {
        $admin->refresh();

        return ['schema_version' => 2, 'plan_id' => str_repeat('c', 32), 'plan_sha256' => str_repeat('d', 64),
            'expected_epoch' => str_repeat('a', 32), 'action' => 'backup', 'maintenance_required' => true, 'continuation' => 'remote',
            'expires_at' => now()->addMinutes(10)->toISOString(), 'actor' => [
                'management_instance_id' => app(ManagementInstance::class)->id(), 'admin_id' => (int) $admin->id,
                'identity_sha256' => hash('sha256', json_encode([$admin->id, $admin->username, $admin->email, $admin->created_at?->toIso8601String()], JSON_THROW_ON_ERROR)),
            ]];
    }

    public function test_fresh_admission_rechecks_password_maintenance_and_records_original_business_without_forwarding_password(): void
    {
        $this->withManagedInstance(function (): void {
            [$admin] = $this->connect(['updater:backup']);
            $admin->tokens()->update(['recovery_epoch' => str_repeat('a', 32)]);
            $agent = $this->agent();
            $agent->shouldReceive('requestReceipt')->times(3)->andReturn(null);
            $agent->shouldReceive('capabilities')->times(3)->andReturn($this->capabilities());
            $agent->shouldReceive('actionPlan')->times(3)->andReturn($this->hostPlan($admin));
            $body = $this->business() + ['instance_id' => app(ManagementInstance::class)->id(),
                'client_request_id' => 'original-request-001', 'password' => 'updater-password', 'authorization_code' => '654321'];
            $this->postJson('/api/v1/management/updater/operations', array_replace($body, ['allow_maintenance' => false]))
                ->assertConflict()->assertJsonPath('error.code', 'maintenance_confirmation_required');
            $this->postJson('/api/v1/management/updater/operations', array_replace($body, ['password' => 'wrong']))
                ->assertForbidden()->assertJsonPath('error.code', 'reauthentication_failed');
            $agent->shouldReceive('status')->once()->andReturn(['checks' => [['id' => 'retired-update-worker', 'status' => 'pass']]]);
            $agent->shouldReceive('submitAction')->once()->withArgs(function (array $request, string $code): bool {
                return ! array_key_exists('password', $request) && ! array_key_exists('authorization_code', $request)
                    && $code === '654321' && $request['scope'] === 'updater:backup' && $request['expected_epoch'] === str_repeat('a', 32);
            })->andReturn($this->receipt());
            $this->postJson('/api/v1/management/updater/operations', $body)->assertOk()->assertJsonPath('data.state', 'succeeded');
        });
    }

    public function test_current_role_revocation_during_plan_validation_prevents_admission(): void
    {
        $this->withManagedInstance(function (): void {
            [$admin] = $this->connect(['updater:backup']);
            $admin->tokens()->update(['recovery_epoch' => str_repeat('a', 32)]);
            $agent = $this->agent();
            $agent->shouldReceive('requestReceipt')->once()->andReturn(null);
            $agent->shouldReceive('capabilities')->once()->andReturn($this->capabilities());
            $agent->shouldReceive('actionPlan')->once()->andReturnUsing(function () use ($admin): array {
                $plan = $this->hostPlan($admin);
                $admin->update(['role' => 'admin']);

                return $plan;
            });
            $this->postJson('/api/v1/management/updater/operations', $this->business() + ['instance_id' => app(ManagementInstance::class)->id(),
                'client_request_id' => 'original-request-001', 'password' => 'updater-password', 'authorization_code' => '654321'])
                ->assertForbidden()->assertJsonPath('error.code', 'forbidden');
        });
    }

    public function test_relogin_with_new_epoch_can_retrieve_accepted_old_epoch_request_before_plan_validation(): void
    {
        $this->withManagedInstance(function (): void {
            [$admin] = $this->connect(['updater:backup']);
            $admin->tokens()->update(['recovery_epoch' => str_repeat('a', 32)]);
            $oldBusiness = array_replace($this->business(), ['expected_epoch' => str_repeat('e', 32)]);
            ksort($oldBusiness);
            $receipt = $this->receipt();
            $receipt['business_sha256'] = hash('sha256', json_encode($oldBusiness, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $this->agent()->shouldReceive('requestReceipt')->once()->andReturn($receipt);
            $this->postJson('/api/v1/management/updater/operations', $oldBusiness + [
                'instance_id' => app(ManagementInstance::class)->id(), 'client_request_id' => 'original-request-001',
            ])->assertOk()->assertJsonPath('data.state', 'succeeded');
        });
    }

    public function test_old_wildcard_and_non_super_admin_cannot_use_remote_updater(): void
    {
        [$admin, $token] = $this->connect(['*']);
        $this->getJson('/api/v1/management/updater/status')->assertForbidden();
        $token = $admin->createToken('explicit', ['updater:read'])->plainTextToken;
        $admin->update(['role' => 'admin']);
        $this->withToken($token)->getJson('/api/v1/management/updater/status')->assertForbidden();
    }

    public function test_real_socket_capability_failure_never_advertises_updater_operations(): void
    {
        $this->connect(['updater:read', 'updater:plan']);
        $this->agent()->shouldReceive('capabilities')->once()->andThrow(new \RuntimeException('socket offline'));
        $response = $this->getJson('/api/v1/capabilities')->assertOk()->assertJsonPath('data.updater.supported', false);
        $this->assertSame([], array_filter($response->json('data.operations'), fn ($op) => str_starts_with($op['name'], 'updater.')));
    }

    public function test_status_rechecks_role_after_host_response(): void
    {
        [$admin] = $this->connect();
        $this->agent()->shouldReceive('capabilities')->once()->andReturnUsing(function () use ($admin): array {
            $admin->update(['role' => 'admin']);

            return $this->capabilities();
        });
        $this->getJson('/api/v1/management/updater/status')->assertForbidden();
    }

    public function test_recovery_points_rechecks_role_after_host_response(): void
    {
        [$admin] = $this->connect();
        $agent = $this->agent();
        $agent->shouldReceive('capabilities')->once()->andReturn($this->capabilities());
        $agent->shouldReceive('recoveryPoints')->once()->andReturnUsing(function () use ($admin): array {
            $admin->update(['role' => 'admin']);

            return [];
        });
        $this->getJson('/api/v1/management/updater/recovery-points')->assertForbidden();
    }

    public function test_status_exposes_the_actual_host_contract_without_starting_a_plan(): void
    {
        $this->connect();
        $this->agent()->shouldReceive('capabilities')->once()->andReturn($this->capabilities());
        $this->getJson('/api/v1/management/updater/status')->assertOk()->assertJsonPath('data.capabilities.protocol_version', 2);
    }

    public function test_existing_request_returns_receipt_without_password_or_new_plan_validation(): void
    {
        $this->connect(['updater:backup']);
        $this->agent()->shouldReceive('requestReceipt')->with('original-request-001')->once()->andReturn($this->receipt());
        $this->postJson('/api/v1/management/updater/operations', $this->business() + [
            'client_request_id' => 'original-request-001', 'instance_id' => app(ManagementInstance::class)->id(),
        ])->assertOk()->assertJsonPath('data.state', 'succeeded')->assertJsonPath('data.client_request_id', 'original-request-001');
    }

    public function test_same_request_id_with_changed_business_payload_is_a_conflict(): void
    {
        $this->connect(['updater:backup']);
        $this->agent()->shouldReceive('requestReceipt')->once()->andReturn($this->receipt());
        $this->postJson('/api/v1/management/updater/operations', array_replace($this->business(), ['allow_maintenance' => false]) + [
            'client_request_id' => 'original-request-001', 'instance_id' => app(ManagementInstance::class)->id(),
        ])->assertConflict()->assertJsonPath('error.code', 'request_conflict');
    }

    public function test_receipt_lookup_rechecks_current_privileges_and_retains_original_outcome(): void
    {
        [$admin] = $this->connect();
        $receipt = $this->receipt();
        $receipt['action'] = 'update';
        $receipt['operation']['kind'] = 'update';
        $receipt['operation']['status'] = 'rolled_back';
        $this->agent()->shouldReceive('requestReceipt')->once()->andReturn($receipt);
        $this->getJson('/api/v1/management/updater/requests/original-request-001')->assertOk()
            ->assertJsonPath('data.state', 'rolled_back')->assertJsonPath('data.target_succeeded', false);
        $admin->update(['role' => 'admin']);
        $this->getJson('/api/v1/management/updater/requests/original-request-001')->assertForbidden();
    }
}
