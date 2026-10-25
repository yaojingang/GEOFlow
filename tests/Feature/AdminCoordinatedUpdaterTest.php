<?php

namespace Tests\Feature;

use App\Contracts\SystemUpdater\AgentClient;
use App\Contracts\SystemUpdater\CoordinatedAgentClient;
use App\Models\Admin;
use App\Services\Api\ManagementInstance;
use App\Services\SystemUpdater\RecoveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

class AdminCoordinatedUpdaterTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->directory = sys_get_temp_dir().'/geoflow-updater-web-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        config(['geoflow.recovery_contract_required' => true, 'geoflow.recovery_control_directory' => $this->directory]);
        file_put_contents($this->directory.'/state.json', json_encode(['schema_version' => 1, 'instance_id' => 'primary',
            'host_id' => str_repeat('b', 32), 'epoch' => str_repeat('a', 32), 'transaction_id' => null,
            'phase' => 'ready', 'minimum_updater_protocol' => 5]));
        $this->admin = Admin::query()->create(['username' => 'coordinated-web-admin', 'password' => 'updater-password', 'role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($this->admin, 'admin')->withSession([RecoveryState::SESSION_KEY => str_repeat('a', 32)]);
    }

    protected function tearDown(): void
    {
        unlink($this->directory.'/state.json');
        rmdir($this->directory);
        parent::tearDown();
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
            'actions' => ['update', 'backup', 'restore', 'switch-back'], 'features' => ['plans' => true, 'requests' => true, 'idempotency' => true, 'recovery_epoch' => true],
            'recovery' => ['host_id' => str_repeat('b', 32), 'epoch' => str_repeat('a', 32), 'phase' => 'ready']];
    }

    public function test_plan_is_bound_to_current_actor_and_request_id_is_shown_before_submission(): void
    {
        $agent = $this->agent();
        $agent->shouldReceive('capabilities')->times(3)->andReturn($this->capabilities());
        $agent->shouldReceive('createActionPlan')->once()->withArgs(function (array $input) {
            return $input['action'] === 'backup' && $input['expected_epoch'] === str_repeat('a', 32)
                && $input['actor']['admin_id'] === $this->admin->id;
        })->andReturn(['schema_version' => 2, 'plan_id' => str_repeat('c', 32), 'plan_sha256' => str_repeat('d', 64), 'action' => 'backup',
            'expected_epoch' => str_repeat('a', 32), 'expires_at' => now()->addMinutes(10)->toIso8601String(), 'maintenance_required' => true, 'continuation' => 'remote']);
        $agent->shouldReceive('recoveryPoints')->once()->andReturn([]);
        $response = $this->post(route('admin.system-updates.updater.action-plan'), ['instance_id' => app(ManagementInstance::class)->id(),
            'expected_epoch' => str_repeat('a', 32), 'action' => 'backup', 'recovery_point_id' => '']);
        $response->assertSessionHasNoErrors()->assertRedirect(route('admin.system-updates.updater.console'));
        $id = session('coordinated_updater_plan.client_request_id');
        $this->assertNotEmpty($id);
        $this->get(route('admin.system-updates.updater.console'))->assertOk()->assertSee($id)->assertSee('data-updater-admission', false)->assertSee('<button type="submit" disabled', false)->assertSee(__('updater.initializing'));
    }

    public function test_lost_submission_response_redirects_to_original_receipt_without_persisting_secrets(): void
    {
        $agent = $this->agent();
        $agent->shouldReceive('requestReceipt')->once()->with('browser-request-001')->andThrow(new \RuntimeException('connection lost'));
        $this->post(route('admin.system-updates.updater.submit'), [
            'instance_id' => app(ManagementInstance::class)->id(), 'client_request_id' => 'browser-request-001', 'action' => 'backup',
            'plan_id' => str_repeat('c', 32), 'plan_sha256' => str_repeat('d', 64), 'expected_epoch' => str_repeat('a', 32),
            'allow_maintenance' => '1', 'confirm_host_access' => '0', 'password' => 'must-not-flash', 'authorization_code' => '123456',
        ])->assertRedirect(route('admin.system-updates.updater.console', ['request' => 'browser-request-001']))
            ->assertSessionHasErrors('updater')->assertSessionMissing('_old_input.password')->assertSessionMissing('_old_input.authorization_code');
    }

    public function test_receipt_query_survives_new_login_without_rechecking_old_plan_or_otp(): void
    {
        $this->agent()->shouldReceive('requestReceipt')->once()->with('browser-request-001')->andReturn([
            'operation_id' => '20260916T120000.000000001Z-'.str_repeat('f', 16), 'client_request_id' => 'browser-request-001',
            'action' => 'update', 'admission_status' => 'accepted', 'background_status' => 'held', 'operation' => ['status' => 'succeeded'],
        ]);
        $this->get(route('admin.system-updates.updater.console', ['request' => 'browser-request-001']))
            ->assertOk()->assertSee(__('updater.state_recovery_required'))->assertSee(__('updater.background_held'))->assertDontSee('data-updater-admission', false);
    }

    public function test_old_web_write_routes_are_retired_without_any_v1_fallback(): void
    {
        $this->agent();
        foreach (['update', 'backup', 'rollback', 'switch-back', 'plan'] as $route) {
            $this->post(route('admin.system-updates.updater.'.$route), ['password' => 'updater-password', 'updater_authorization_code' => '123456'])
                ->assertRedirect(route('admin.system-updates.updater.console'))->assertSessionHasErrors('updater');
        }
    }

    public function test_web_session_revocation_at_last_host_read_prevents_submission(): void
    {
        $this->admin->refresh();
        $agent = $this->agent();
        $instance = app(ManagementInstance::class)->id();
        $business = ['action' => 'backup', 'plan_id' => str_repeat('c', 32), 'plan_sha256' => str_repeat('d', 64),
            'expected_epoch' => str_repeat('a', 32), 'allow_maintenance' => true, 'confirm_host_access' => false];
        $agent->shouldReceive('requestReceipt')->once()->andReturn(null);
        $agent->shouldReceive('capabilities')->once()->andReturn($this->capabilities());
        $agent->shouldReceive('actionPlan')->once()->andReturn($business + ['expires_at' => now()->addMinutes(10)->toISOString(),
            'maintenance_required' => true, 'continuation' => 'remote', 'actor' => [
                'management_instance_id' => $instance, 'admin_id' => $this->admin->id,
                'identity_sha256' => hash('sha256', json_encode([$this->admin->id, $this->admin->username, $this->admin->email, $this->admin->created_at?->toIso8601String()], JSON_THROW_ON_ERROR)),
            ]]);
        $agent->shouldReceive('status')->once()->andReturnUsing(function (): array {
            $this->admin->revokeAuthenticationCredentials();

            return ['checks' => []];
        });
        $agent->shouldNotReceive('submitAction');
        $this->post(route('admin.system-updates.updater.submit'), $business + ['instance_id' => $instance,
            'client_request_id' => 'revoked-web-request', 'password' => 'updater-password', 'authorization_code' => '654321'])
            ->assertSessionHasErrors('updater');
        $this->assertSame(2, $this->admin->fresh()->auth_version);
    }

    public function test_non_super_admin_cannot_open_receipts_or_submit_plans(): void
    {
        $this->admin->update(['role' => 'admin']);
        $this->get(route('admin.system-updates.updater.console'))->assertForbidden();
        $this->post(route('admin.system-updates.updater.action-plan'), [])->assertForbidden();
    }
}
