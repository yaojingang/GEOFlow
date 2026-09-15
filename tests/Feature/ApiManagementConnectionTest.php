<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Services\Api\ApiTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiManagementConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(string $role = 'admin'): Admin
    {
        return Admin::query()->create(['username' => 'connection-admin', 'password' => 'connection-test-password', 'role' => $role, 'status' => 'active']);
    }

    public function test_any_valid_token_can_inspect_its_own_session_without_a_new_scope(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('read', ['articles:read'])->plainTextToken;
        $first = $this->withToken($token)->getJson('/api/v1/auth/session')->assertOk()
            ->assertJsonPath('data.admin.id', $admin->id)->assertJsonPath('data.scopes', ['articles:read'])
            ->assertJsonMissingPath('data.token')->assertJsonMissingPath('data.admin.password');
        $this->withToken($token)->getJson('/api/v1/capabilities')->assertOk()
            ->assertJsonPath('data.instance_id', $first->json('data.instance_id'))
            ->assertJsonPath('data.protocol_version', '1.0');
        $this->assertNotEmpty($first->json('data.core_version'));
    }

    public function test_default_login_keeps_exactly_the_legacy_scopes(): void
    {
        $this->admin();
        $this->postJson('/api/v1/auth/login', ['username' => 'connection-admin', 'password' => 'connection-test-password'])
            ->assertOk()->assertJsonPath('data.scopes', app(ApiTokenService::class)->getCliLoginScopes());
    }

    public function test_password_login_can_explicitly_request_read_scope_and_a_narrower_legacy_set(): void
    {
        $this->admin();
        $this->postJson('/api/v1/auth/login', ['username' => 'connection-admin', 'password' => 'connection-test-password', 'requested_scopes' => ['sites:read', 'articles:read']])
            ->assertOk()->assertJsonPath('data.scopes', ['sites:read', 'articles:read']);
    }

    public function test_unknown_and_device_protocol_scopes_do_not_issue_a_token(): void
    {
        $admin = $this->admin();
        foreach (['invented:write', 'browser-operations:execute', '*'] as $scope) {
            $this->postJson('/api/v1/auth/login', ['username' => 'connection-admin', 'password' => 'connection-test-password', 'requested_scopes' => [$scope]])
                ->assertUnprocessable()->assertJsonPath('error.code', 'invalid_scope');
        }
        $this->assertSame(0, $admin->tokens()->count());
    }

    public function test_legacy_wildcard_does_not_expand_into_new_management_scopes(): void
    {
        $admin = $this->admin();
        $token = $admin->createToken('legacy', ['*'])->plainTextToken;
        $data = $this->withToken($token)->getJson('/api/v1/auth/session')->assertOk()->json('data');
        $this->assertContains('articles:write', $data['scopes']);
        $this->assertNotContains('sites:read', $data['scopes']);
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $admin = $this->admin();
        $first = $admin->createToken('first', ['articles:read'])->plainTextToken;
        $second = $admin->createToken('second', ['articles:read'])->plainTextToken;
        $this->withToken($first)->postJson('/api/v1/auth/logout')->assertOk()->assertJsonPath('data.revoked', true);
        $this->withToken($first)->getJson('/api/v1/auth/session')->assertUnauthorized();
        $this->withToken($second)->getJson('/api/v1/auth/session')->assertOk();
    }

    public function test_workspace_scopes_require_protected_role_and_explicit_password_login(): void
    {
        $admin = $this->admin();
        $scopes = ['themes:read', 'themes:write', 'themes:code'];
        $this->postJson('/api/v1/auth/login', ['username' => $admin->username, 'password' => 'connection-test-password', 'requested_scopes' => $scopes])->assertForbidden();
        $this->assertSame(0, $admin->tokens()->count());
        $admin->update(['role' => 'super_admin']);
        $this->postJson('/api/v1/auth/login', ['username' => $admin->username, 'password' => 'connection-test-password', 'requested_scopes' => $scopes])
            ->assertOk()->assertJsonPath('data.scopes', $scopes);
        $legacy = $admin->createToken('legacy-super', ['*'])->plainTextToken;
        $effective = $this->withToken($legacy)->getJson('/api/v1/auth/session')->assertOk()->json('data.scopes');
        foreach ($scopes as $scope) {
            $this->assertNotContains($scope, $effective);
        }
        $this->withToken($legacy)->getJson('/api/v1/management/themes')->assertForbidden();
        $explicit = $admin->createToken('explicit', $scopes)->plainTextToken;
        $admin->update(['role' => 'admin']);
        $this->withToken($explicit)->getJson('/api/v1/auth/session')->assertOk()->assertJsonPath('data.scopes', []);
        $this->withToken($explicit)->getJson('/api/v1/management/themes')->assertForbidden();
    }

    public function test_capabilities_reject_missing_credentials_and_a_disabled_owner(): void
    {
        $this->getJson('/api/v1/capabilities')->assertUnauthorized();
        $admin = $this->admin();
        $token = $admin->createToken('test', ['sites:read'])->plainTextToken;
        $admin->update(['status' => 'disabled']);
        $this->withToken($token)->getJson('/api/v1/capabilities')->assertUnauthorized();
    }
}
