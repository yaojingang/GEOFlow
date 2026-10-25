<?php

namespace Tests\Feature;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Admin;
use App\Models\ThemeWorkspace;
use App\Services\Api\ApiTokenService;
use App\Services\Api\ManagementInstance;
use App\Services\Api\ThemeWorkspaceAuthorization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ThemeWorkspaceAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = Admin::query()->create(['username' => 'theme-super', 'password' => 'test-password', 'role' => 'super_admin', 'status' => 'active']);
        $plain = $admin->createToken('theme-test', ['themes:read', 'themes:write', 'themes:code', 'themes:publish'])->plainTextToken;
        $auth = new ApiAuthContext(app(ApiTokenService::class)->getActiveTokenByPlaintext($plain), $admin->id);
        $workspace = ThemeWorkspace::query()->create(['id' => (string) Str::uuid(), 'instance_id' => app(ManagementInstance::class)->id(), 'admin_id' => $admin->id, 'site_key' => 'primary', 'theme_id' => 'default', 'source' => 'builtin']);

        return [$admin, $auth, $workspace, app(ThemeWorkspaceAuthorization::class)];
    }

    private function denied(callable $action, string $code): void
    {
        try {
            $action();
            $this->fail('Expected authorization denial');
        } catch (ApiException $exception) {
            $this->assertSame($code, $exception->getErrorCode());
        }
    }

    public function test_code_requires_password_and_unexpired_workspace_specific_grant(): void
    {
        [$admin, $auth, $workspace, $authorization] = $this->fixture();
        $this->denied(fn () => $authorization->assertCode($auth, $workspace), 'code_authorization_required');
        $this->denied(fn () => $authorization->grant($auth, $workspace->id, 'wrong'), 'reauthentication_failed');
        $grant = $authorization->grant($auth, $workspace->id, 'test-password');
        $this->assertSame($workspace->id, $grant['workspace_id']);
        $authorization->assertCode($auth, $workspace);
        $other = $workspace->replicate();
        $other->id = (string) Str::uuid();
        $other->code_token_id = null;
        $other->code_authorized_until = null;
        $other->save();
        $this->denied(fn () => $authorization->assertCode($auth, $other), 'code_authorization_required');
        $this->travel(31)->minutes();
        $this->denied(fn () => $authorization->assertCode($auth, $workspace), 'code_authorization_required');
    }

    public function test_relogin_role_change_revocation_and_instance_change_invalidate_code_grant(): void
    {
        [$admin, $auth, $workspace, $authorization] = $this->fixture();
        $authorization->grant($auth, $workspace->id, 'test-password');
        $new = $admin->createToken('new', ['themes:code']);
        $replacement = new ApiAuthContext(app(ApiTokenService::class)->getActiveTokenByPlaintext($new->plainTextToken), $admin->id);
        $this->denied(fn () => $authorization->assertCode($replacement, $workspace), 'code_authorization_required');
        $admin->update(['role' => 'admin']);
        $this->denied(fn () => $authorization->assertCode($auth, $workspace), 'forbidden');
        $admin->update(['role' => 'super_admin']);
        $workspace->update(['instance_id' => (string) Str::uuid()]);
        $this->denied(fn () => $authorization->assertCode($auth, $workspace), 'workspace_not_found');
        $workspace->update(['instance_id' => app(ManagementInstance::class)->id()]);
        $admin->tokens()->delete();
        $this->denied(fn () => $authorization->assertCode($auth, $workspace), 'forbidden');
    }

    public function test_wildcard_and_different_owner_never_grant_code_access(): void
    {
        [$admin, $auth, $workspace, $authorization] = $this->fixture();
        $token = $admin->tokens()->findOrFail($auth->token['id']);
        $token->update(['abilities' => ['*']]);
        $this->denied(fn () => $authorization->grant($auth, $workspace->id, 'test-password'), 'forbidden');
        $token->update(['abilities' => ['themes:code']]);
        $workspace->update(['admin_id' => $admin->id + 1]);
        $this->denied(fn () => $authorization->grant($auth, $workspace->id, 'test-password'), 'workspace_not_found');
    }
}
