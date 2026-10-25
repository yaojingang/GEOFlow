<?php

namespace App\Services\SystemUpdater;

use App\Contracts\SystemUpdater\AgentClient;
use App\Contracts\SystemUpdater\CoordinatedAgentClient;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Services\Admin\SystemUpdateOperationGuard;
use App\Services\Api\ApiTokenService;
use App\Services\Api\ManagementInstance;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class RemoteUpdaterService
{
    public const SCOPES = ['updater:read', 'updater:plan', 'updater:update', 'updater:backup', 'updater:restore'];

    public const ACTION_SCOPES = ['update' => 'updater:update', 'backup' => 'updater:backup', 'restore' => 'updater:restore', 'switch-back' => 'updater:update'];

    public function __construct(private readonly AgentClient $agent, private readonly ApiTokenService $tokens, private readonly ManagementInstance $instance, private readonly RecoveryState $recovery, private readonly SystemUpdateOperationGuard $operationGuard) {}

    /** Discovery never advertises a socket contract inferred from a PHP interface alone. */
    public function availability(): array
    {
        try {
            $capabilities = $this->client()->capabilities();
            try {
                $this->requireRecovery($capabilities);
                $writable = true;
            } catch (ApiException) {
                $writable = false;
            }

            return ['supported' => true, 'write_supported' => $writable, 'capabilities' => $capabilities];
        } catch (\Throwable) {
            return ['supported' => false, 'reason' => 'updater_v2_unavailable'];
        }
    }

    public function status(Request $request): array
    {
        $this->actor($request, 'updater:read');

        $capabilities = $this->call(fn () => $this->client()->capabilities());
        $this->actor($request, 'updater:read');

        return ['capabilities' => $capabilities];
    }

    public function recoveryPoints(Request $request): array
    {
        $this->actor($request, 'updater:read');
        $this->call(fn () => $this->client()->capabilities());

        $points = array_values(array_filter($this->call(fn () => $this->client()->recoveryPoints()),
            fn (array $point): bool => str_starts_with($point['reason'] ?? '', 'update-to-')));
        usort($points, fn (array $a, array $b): int => strcmp($b['id'], $a['id']));
        $this->actor($request, 'updater:read');

        return ['recovery_points' => array_slice($points, 0, 1), 'restore_policy' => 'latest_update_checkpoint'];
    }

    public function plan(Request $request, array $input): array
    {
        $actor = $this->actor($request, 'updater:plan');
        $this->assertInstance($input['instance_id']);
        $capabilities = $this->call(fn () => $this->client()->capabilities());
        $state = $this->requireRecovery($capabilities);
        if (! in_array($input['action'], $capabilities['actions'], true)) {
            throw new ApiException('updater_action_unavailable', '宿主机当前不支持此动作', 409);
        }
        $this->recovery->assertWriteEpoch($request->routeIs('admin.system-updates.updater.*') ? ($input['expected_epoch'] ?? null) : $request->header('X-GEOFlow-Recovery-Epoch'));
        $payload = ['action' => $input['action'], 'expected_epoch' => $state['epoch'], 'actor' => $this->actorIdentity($actor)];
        if (isset($input['recovery_point_id'])) {
            $payload['recovery_point_id'] = $input['recovery_point_id'];
        }
        $this->actor($request, 'updater:plan');

        return $this->call(fn () => $this->client()->createActionPlan($payload));
    }

    public function submit(Request $request, #[\SensitiveParameter] array $input): array
    {
        $scope = self::ACTION_SCOPES[$input['action']];
        $actor = $this->actor($request, $scope);
        $this->assertInstance($input['instance_id']);
        $business = self::business($input);
        $existing = $this->call(fn () => $this->client()->requestReceipt($input['client_request_id']));
        if ($existing !== null) {
            $this->assertSameBusiness($existing, $business);
            $this->actor($request, $scope);

            return $this->receipt($existing);
        }
        $capabilities = $this->call(fn () => $this->client()->capabilities());
        $this->requireRecovery($capabilities);
        if (! in_array($input['action'], $capabilities['actions'], true)) {
            throw new ApiException('updater_action_unavailable', '宿主机当前不支持此动作', 409);
        }
        $this->recovery->assertWriteEpoch($input['expected_epoch']);
        $plan = $this->call(fn () => $this->client()->actionPlan($input['plan_id']));
        $identity = $this->actorIdentity($actor);
        if ($plan['action'] !== $input['action'] || ! hash_equals($plan['plan_sha256'], $input['plan_sha256'])
            || ! hash_equals($plan['expected_epoch'], $input['expected_epoch'])
            || ($plan['actor']['identity_sha256'] ?? null) !== $identity['identity_sha256']
            || ($plan['actor']['management_instance_id'] ?? null) !== $identity['management_instance_id']
            || ($plan['actor']['admin_id'] ?? null) !== $identity['admin_id']
            || strtotime($plan['expires_at']) <= time()) {
            throw new ApiException('updater_plan_conflict', '计划已过期或与当前动作、实例、账号不匹配，请重新生成计划', 409);
        }
        if ($plan['maintenance_required'] && ! $input['allow_maintenance']) {
            throw new ApiException('maintenance_confirmation_required', '此操作需要明确允许维护停机', 409);
        }
        if ($plan['continuation'] === 'host_only' && ! $input['confirm_host_access']) {
            throw new ApiException('host_access_confirmation_required', '目标版本需通过宿主机续接，请先确认具备宿主机访问条件', 409);
        }
        $actor = $this->actor($request, $scope);
        if ((bool) config('geoflow.update_require_admin_password', true)
            && (! is_string($input['password'] ?? null) || ! Hash::check($input['password'], $actor->password))) {
            throw new ApiException('reauthentication_failed', '当前管理员密码验证失败', 403);
        }
        $code = $input['authorization_code'] ?? null;
        if (! is_string($code) || preg_match('/\A[0-9]{6}\z/', $code) !== 1) {
            throw new ApiException('updater_authorization_required', '需要有效的六位宿主机动态授权码', 422);
        }
        $this->recovery->assertWriteEpoch($input['expected_epoch']);
        $this->actor($request, $scope);
        $status = $this->call(fn () => $this->client()->status());
        $receipt = $this->call(fn () => $this->operationGuard->run(function () use ($request, $scope, $input, $business, $identity, $code): array {
            $this->actor($request, $scope);
            $this->recovery->assertWriteEpoch($input['expected_epoch']);

            return $this->client()->submitAction($business + [
                'client_request_id' => $input['client_request_id'], 'actor' => $identity, 'scope' => $scope,
            ], $code);
        }, $status));
        $this->assertSameBusiness($receipt, $business);
        if ($receipt['client_request_id'] !== $input['client_request_id']) {
            throw new ApiException('updater_receipt_mismatch', '宿主机返回了不同请求的收据，请按原请求 ID 查询', 502);
        }

        return $this->receipt($receipt);
    }

    public function lookup(Request $request, string $id, bool $byRequest): array
    {
        $this->actor($request, 'updater:read');
        $receipt = $this->call(fn () => $byRequest ? $this->client()->requestReceipt($id) : $this->client()->operationReceipt($id));
        if ($receipt === null) {
            throw new ApiException('request_not_found', '暂未找到原请求收据；此结果不允许自动重新执行', 404);
        }
        $this->actor($request, 'updater:read');

        return $this->receipt($receipt);
    }

    /** Current credentials authorize access; historical actor IDs remain audit context only. */
    private function actor(Request $request, string $scope): Admin
    {
        if ($request->routeIs('admin.system-updates.updater.*')) {
            $admin = Admin::query()->whereKey($request->user('admin')?->getKey())->active()->first();
            $state = $this->recovery->assertHttpReady();
            if (! $admin || ! $request->hasSession()
                || (int) $request->session()->get(Admin::AUTH_VERSION_SESSION_KEY) !== (int) $admin->auth_version
                || ($state !== null && $request->session()->get(RecoveryState::SESSION_KEY) !== $state['epoch'])) {
                throw new ApiException('forbidden', '当前管理员会话已失效，请重新登录后查询原请求', 403);
            }
        } else {
            $token = $this->tokens->getActiveTokenByPlaintext((string) $request->bearerToken());
            if ($token === null || ! in_array($scope, $token['scopes'], true)) {
                throw new ApiException('forbidden', '需要当前令牌的显式运维权限', 403, ['required_scope' => $scope]);
            }
            $admin = Admin::query()->whereKey($token['created_by_admin_id'])->active()->first();
        }
        if (! $admin || ! $admin->isSuperAdmin()) {
            throw new ApiException('forbidden', '运维操作需要当前活动超级管理员', 403);
        }

        return $admin;
    }

    private function actorIdentity(Admin $admin): array
    {
        return ['management_instance_id' => $this->instance->id(), 'admin_id' => (int) $admin->id,
            'identity_sha256' => hash('sha256', json_encode([$admin->id, $admin->username, $admin->email, $admin->created_at?->toIso8601String()], JSON_THROW_ON_ERROR))];
    }

    private function assertInstance(string $instance): void
    {
        if (! hash_equals($this->instance->id(), $instance)) {
            throw new ApiException('instance_mismatch', '实例身份已改变，请重新绑定并核对原操作', 409);
        }
    }

    private function requireRecovery(array $capabilities): array
    {
        $state = $this->recovery->assertHttpReady();
        if ($state === null || ($capabilities['recovery']['host_id'] ?? null) !== $state['host_id']
            || ($capabilities['recovery']['epoch'] ?? null) !== $state['epoch']) {
            throw new ApiException('recovery_contract_unavailable', 'Core 与宿主机恢复契约尚未一致，请通过宿主机核验', 409);
        }

        return $state;
    }

    public static function business(array $input): array
    {
        $business = array_intersect_key($input, array_flip(['action', 'plan_id', 'plan_sha256', 'expected_epoch', 'allow_maintenance', 'confirm_host_access']));
        ksort($business);

        return $business;
    }

    private function assertSameBusiness(array $receipt, array $business): void
    {
        $digest = hash('sha256', json_encode($business, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (! hash_equals($receipt['business_sha256'], $digest)) {
            throw new ApiException('request_conflict', '此请求 ID 已绑定不同业务输入，未执行新操作', 409);
        }
    }

    private function receipt(array $envelope): array
    {
        $operation = $envelope['operation'];
        $status = $operation['status'] ?? 'pending';
        $state = $envelope['admission_status'] === 'pending' ? 'pending' : $status;
        if ($state === 'succeeded' && $envelope['background_status'] === 'held') {
            $state = 'recovery_required';
        }

        return ['operation_id' => $envelope['operation_id'], 'client_request_id' => $envelope['client_request_id'],
            'operation' => 'updater.'.$envelope['action'], 'state' => $state, 'host_status' => $status,
            'target_succeeded' => $state === 'succeeded', 'admission' => $envelope];
    }

    private function client(): CoordinatedAgentClient
    {
        if (! $this->agent instanceof CoordinatedAgentClient) {
            throw new ApiException('updater_v2_unavailable', '当前宿主机尚不支持可续接的运维协议', 503);
        }

        return $this->agent;
    }

    private function call(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (ApiException $exception) {
            throw $exception;
        } catch (AgentProtocolException $exception) {
            throw new ApiException($exception->errorCode, '宿主机未完成此请求，请保留原请求 ID 查询结果', in_array($exception->httpStatus, [403, 404, 409, 422, 429, 503], true) ? $exception->httpStatus : 502, $exception->retryAfterSeconds === null ? [] : ['retry_after' => $exception->retryAfterSeconds]);
        } catch (\Throwable) {
            throw new ApiException('updater_unavailable', '宿主机暂不可达或响应无效；请保留原请求 ID 继续查询，勿自动重发', 503);
        }
    }
}
