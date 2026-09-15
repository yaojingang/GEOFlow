<?php

namespace App\Services\Api;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use App\Models\Admin;
use App\Models\ManagementOperation;
use App\Models\Task;
use App\Models\TaskRun;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ManagementOperationService
{
    public function __construct(private ManagementInstance $instance, private ApiTokenService $tokens) {}

    /** The existing queue service commits its TaskRun and schedules dispatch after this outer transaction. */
    public function enqueue(Request $request, int $task, string $jobType, callable $enqueue): array
    {
        $auth = $this->auth($request);
        $requestId = $this->requestId($request->header('X-Client-Request-Id'));
        $identity = ['instance_id' => $this->instance->id(), 'admin_id' => $auth->auditAdminId, 'client_request_id' => $requestId];
        $hash = hash('sha256', json_encode(['operation' => 'tasks.enqueue', 'task_id' => $task, 'job_type' => $jobType], JSON_THROW_ON_ERROR));
        $existing = ManagementOperation::query()->where($identity)->first();
        if ($existing) {
            return $this->replay($request, $existing, $hash);
        }
        try {
            $operation = DB::transaction(function () use ($identity, $hash, $task, $enqueue): ManagementOperation {
                $operation = ManagementOperation::query()->create(array_merge($identity, [
                    'id' => (string) Str::uuid(), 'operation' => 'tasks.enqueue', 'request_hash' => $hash,
                    'required_scopes' => (bool) Task::query()->findOrFail($task)->need_review ? ['tasks:write'] : ['tasks:write', 'articles:publish'], 'state' => 'queued', 'task_id' => $task,
                ]));
                $result = $enqueue();
                $operation->update(['task_run_id' => $result['job_id'], 'result' => $result]);

                return $operation;
            });
        } catch (UniqueConstraintViolationException $exception) {
            $existing = ManagementOperation::query()->where($identity)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->replay($request, $existing, $hash);
        }

        return $this->present($request, $operation) + ['replayed' => false];
    }

    public function find(Request $request, ?string $id = null): array
    {
        $auth = $this->auth($request);
        $query = ManagementOperation::query()->where('instance_id', $this->instance->id())->where('admin_id', $auth->auditAdminId);
        $operation = $id === null
            ? $query->where('client_request_id', $this->requestId($request->query('client_request_id')))->first()
            : $query->whereKey($id)->first();
        if (! $operation) {
            throw new ApiException('operation_not_found', '当前账号没有此操作收据', 404);
        }

        return $this->present($request, $operation);
    }

    private function replay(Request $request, ManagementOperation $operation, string $hash): array
    {
        if (! hash_equals($operation->request_hash, $hash)) {
            throw new ApiException('client_request_conflict', '同一客户端请求 ID 已用于不同操作或输入', 409);
        }

        return $this->present($request, $operation) + ['replayed' => true];
    }

    private function present(Request $request, ManagementOperation $operation): array
    {
        foreach ($operation->required_scopes as $scope) {
            if (! $this->tokens->tokenHasScope($this->auth($request)->token, $scope)) {
                throw new ApiException('forbidden_scope', '当前凭据缺少该操作所需权限', 403);
            }
        }
        if ($operation->task_id !== null) {
            $admin = Admin::query()->active()->findOrFail($this->auth($request)->auditAdminId);
            app(TaskLifecycleService::class)->getTaskForApi($operation->task_id, $admin);
            if (! (bool) Task::query()->findOrFail($operation->task_id)->need_review
                && ! $this->tokens->tokenHasScope($this->auth($request)->token, 'articles:publish')) {
                throw new ApiException('forbidden_scope', '当前任务已允许自动发布，需要 articles:publish 权限', 403);
            }
        }
        $state = $operation->state;
        if ($operation->task_run_id !== null) {
            $run = TaskRun::query()->find($operation->task_run_id, ['id', 'status']);
            $state = match ($run?->status) {
                'pending' => 'queued', 'running' => 'running', 'success', 'completed' => 'succeeded',
                'failed' => 'failed', 'cancelled' => 'cancelled', default => 'unavailable',
            };
        }

        return [
            'operation_id' => $operation->id, 'client_request_id' => $operation->client_request_id,
            'operation' => $operation->operation, 'state' => $state,
            'result' => $operation->result, 'created_at' => $operation->created_at->toIso8601String(),
        ];
    }

    private function auth(Request $request): ApiAuthContext
    {
        $auth = $request->attributes->get('api_auth');
        if (! $auth instanceof ApiAuthContext) {
            throw new ApiException('unauthorized', '未认证', 401);
        }

        return $auth;
    }

    private function requestId(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/D', $value) !== 1) {
            throw new ApiException('invalid_client_request_id', '客户端请求 ID 应为 8 至 128 位字母、数字、点、下划线或连字符', 422);
        }

        return $value;
    }
}
