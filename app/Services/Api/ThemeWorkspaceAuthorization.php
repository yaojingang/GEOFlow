<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Admin;
use App\Models\ThemeWorkspace;
use App\Services\SystemUpdater\RecoveryState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

final class ThemeWorkspaceAuthorization
{
    public function __construct(private ManagementInstance $instance, private RecoveryState $recovery) {}

    public function actor(ApiAuthContext $auth, string $scope): Admin
    {
        $state = $this->recovery->assertHttpReady();
        $admin = Admin::query()->whereKey($auth->auditAdminId)->active()->first();
        $token = PersonalAccessToken::query()->whereKey($auth->token['id'] ?? 0)
            ->where('tokenable_type', Admin::class)->where('tokenable_id', $auth->auditAdminId)->first();
        // Native templates execute as the application. Match the existing protected package workflow.
        if (! $admin || ! $admin->canManageProtectedWorkflows() || ! $token
            || ($state !== null && $token->recovery_epoch !== $state['epoch'])
            || ($token->expires_at !== null && $token->expires_at->isPast())
            || ! in_array($scope, $token->abilities ?? [], true)) {
            throw new ApiException('forbidden', '当前账号或令牌未获得此原生主题操作权限', 403);
        }

        return $admin;
    }

    public function workspace(ApiAuthContext $auth, string $id, string $scope): ThemeWorkspace
    {
        $this->actor($auth, $scope);
        $workspace = ThemeWorkspace::query()->whereKey($id)->where('admin_id', $auth->auditAdminId)
            ->where('instance_id', $this->instance->id())->first();
        if (! $workspace || $workspace->state !== 'draft') {
            throw new ApiException('workspace_not_found', '当前账号没有此可编辑草稿', 404);
        }

        return $workspace;
    }

    public function grant(ApiAuthContext $auth, string $id, string $password): array
    {
        $admin = $this->actor($auth, 'themes:code');
        if (! Hash::check($password, $admin->password)) {
            throw new ApiException('reauthentication_failed', '当前密码验证失败', 403);
        }

        return DB::transaction(function () use ($auth, $id, $password): array {
            $workspace = $this->workspace($auth, $id, 'themes:code');
            $workspace = ThemeWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            if ($workspace->state !== 'draft') {
                throw new ApiException('workspace_not_found', '草稿已关闭，请重新读取', 404);
            }
            $admin = $this->actor($auth, 'themes:code');
            if (! Hash::check($password, $admin->password)) {
                throw new ApiException('reauthentication_failed', '当前密码验证失败', 403);
            }
            $until = now()->addMinutes(30);
            $workspace->update(['code_token_id' => (int) $auth->token['id'], 'code_authorized_until' => $until, 'code_recovery_epoch' => $this->recovery->assertHttpReady()['epoch'] ?? null, 'plan' => null]);

            return ['workspace_id' => $workspace->id, 'authorized_until' => $until->toIso8601String(), 'revision_id' => $workspace->revision_id];
        });
    }

    public function assertCode(ApiAuthContext $auth, ThemeWorkspace $workspace): void
    {
        $this->workspace($auth, $workspace->id, 'themes:code');
        $workspace->refresh();
        $state = $this->recovery->assertHttpReady();
        if (($state !== null && $workspace->code_recovery_epoch !== $state['epoch']) || $workspace->code_token_id !== (int) $auth->token['id'] || $workspace->code_authorized_until === null || $workspace->code_authorized_until->isPast()) {
            throw new ApiException('code_authorization_required', '请为当前草稿重新验证密码，建立 30 分钟代码授权', 403);
        }
    }
}
