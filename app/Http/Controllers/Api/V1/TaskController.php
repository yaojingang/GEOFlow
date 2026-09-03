<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Requests\Api\StoreTaskRequest;
use App\Http\Requests\Api\UpdateTaskRequest;
use App\Models\Admin;
use App\Models\Task;
use App\Services\Api\ApiTokenService;
use App\Services\Api\IdempotencyService;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 任务（tasks）生命周期：列表、创建、详情、更新、启停、入队、子 Job 列表。
 *
 * 读接口需 tasks:read，写接口需 tasks:write。部分写操作支持 X-Idempotency-Key 幂等。
 */
class TaskController extends BaseApiController
{
    /**
     * 分页列出任务（新契约含 task_progress / queue_overview）。
     *
     * 查询参数：page、per_page、status、search（按名称模糊）。
     */
    public function index(Request $request, TaskLifecycleService $tasks): JsonResponse
    {
        $viewer = $this->executionAdmin($request);
        $statusQuery = $request->query('status');
        $searchQuery = $request->query('search');

        $data = $tasks->listTasksForApi(
            page: $request->integer('page', 1),
            perPage: $request->integer('per_page', 20),
            filters: [
                'status' => is_string($statusQuery) ? trim($statusQuery) : null,
                'search' => is_string($searchQuery) ? trim($searchQuery) : null,
            ],
            viewer: $viewer,
        );

        return $this->success($request, $data);
    }

    /**
     * 创建任务；成功 HTTP 201。
     *
     * 幂等键：POST /tasks（请求头 X-Idempotency-Key 可选）。
     */
    public function store(StoreTaskRequest $request, TaskLifecycleService $tasks, ApiTokenService $tokens): JsonResponse
    {
        $viewer = $this->executionAdmin($request);
        $data = $this->reviewBoundTaskData($request, $request->validated(), $tokens);
        $auth = $this->auth($request);

        $response = IdempotencyService::executeJson(
            $request,
            'POST /tasks',
            fn (): JsonResponse => $this->success($request, $tasks->createTaskForApi(
                data: $data,
                auditAdminId: $auth->auditAdminId,
                apiTokenId: (int) ($auth->token['id'] ?? 0),
                viewer: $viewer,
            ), 201),
        );

        return $this->refreshTaskModelProjection($response, $tasks, $viewer);
    }

    /**
     * 任务详情（双层视图：业务进度 + 队列监控摘要）。
     */
    public function show(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $viewer = $this->executionAdmin($request);

        return $this->success($request, $tasks->getTaskForApi(taskId: $task, viewer: $viewer));
    }

    /**
     * 部分更新任务字段。
     *
     * 幂等键：PATCH /tasks/{id}
     */
    public function update(UpdateTaskRequest $request, int $task, TaskLifecycleService $tasks, ApiTokenService $tokens): JsonResponse
    {
        $viewer = $this->executionAdmin($request);
        $data = $this->reviewBoundTaskData($request, $request->validated(), $tokens);
        $auth = $this->auth($request);

        $response = IdempotencyService::executeJson(
            $request,
            'PATCH /tasks/{id}',
            fn (): JsonResponse => $this->success($request, $tasks->updateTaskForApi(
                taskId: $task,
                data: $data,
                canManageHostedTask: $this->canManageHostedTask($viewer),
                auditAdminId: $auth->auditAdminId,
                apiTokenId: (int) ($auth->token['id'] ?? 0),
                viewer: $viewer,
            )),
        );

        return $this->refreshTaskModelProjection($response, $tasks, $viewer);
    }

    /**
     * 删除任务。幂等键：DELETE /tasks/{id}
     */
    public function destroy(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $auth = $this->auth($request);
        $viewer = $this->executionAdmin($request);

        return $this->success($request, $tasks->deleteTask(
            $task,
            $this->canManageHostedTask($viewer),
            $auth->auditAdminId,
            (int) ($auth->token['id'] ?? 0),
        ));
    }

    /**
     * 激活任务并可选择立即入队一条生成任务。
     *
     * 请求体可选 enqueue_now（布尔）。幂等键：POST /tasks/{id}/start
     */
    public function start(Request $request, int $task, TaskLifecycleService $tasks, ApiTokenService $tokens): JsonResponse
    {
        $viewer = $this->executionAdmin($request);
        $this->assertTaskExecutionScope($request, $task, $tokens);
        $enqueueNow = ! empty($request->input('enqueue_now'));

        $response = IdempotencyService::executeJson(
            $request,
            'POST /tasks/{id}/start',
            fn (): JsonResponse => $this->success($request, $tasks->startTaskForApi(
                taskId: $task,
                enqueueNow: $enqueueNow,
                canManageHostedTask: $this->canManageHostedTask($viewer),
                viewer: $viewer,
            )),
        );

        return $this->refreshTaskModelProjection($response, $tasks, $viewer);
    }

    /**
     * 暂停任务并取消待处理 Job。
     *
     * 幂等键：POST /tasks/{id}/stop
     */
    public function stop(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $viewer = $this->executionAdmin($request);
        $response = IdempotencyService::executeJson(
            $request,
            'POST /tasks/{id}/stop',
            fn (): JsonResponse => $this->success($request, $tasks->stopTaskForApi(
                taskId: $task,
                canManageHostedTask: $this->canManageHostedTask($viewer),
                viewer: $viewer,
            )),
        );

        return $this->refreshTaskModelProjection($response, $tasks, $viewer);
    }

    /**
     * 向队列投递一条 Job；成功 HTTP 201。
     *
     * 请求体仅接受业务任务类型；队列来源由服务端写入。幂等键：POST /tasks/{id}/enqueue
     */
    public function enqueue(Request $request, int $task, TaskLifecycleService $tasks, ApiTokenService $tokens): JsonResponse
    {
        $viewer = $this->executionAdmin($request);
        $this->assertTaskExecutionScope($request, $task, $tokens);
        $body = $request->all();
        $jobType = trim((string) ($body['job_type'] ?? 'generate_article'));

        return IdempotencyService::executeJson(
            $request,
            'POST /tasks/{id}/enqueue',
            fn (): JsonResponse => $this->success($request, $tasks->enqueueTaskForApi(
                taskId: $task,
                jobType: $jobType,
                payload: ['source' => 'api_enqueue'],
                canManageHostedTask: $this->canManageHostedTask($viewer),
                viewer: $viewer,
            ), 201),
        );
    }

    private function canManageHostedTask(Admin $admin): bool
    {
        return $admin->isSuperAdmin();
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function reviewBoundTaskData(Request $request, array $data, ApiTokenService $tokens): array
    {
        if (! $tokens->tokenHasScope($this->auth($request)->token, 'articles:publish')) {
            $data['need_review'] = true;
        }

        return $data;
    }

    private function assertTaskExecutionScope(Request $request, int $taskId, ApiTokenService $tokens): void
    {
        if ($tokens->tokenHasScope($this->auth($request)->token, 'articles:publish')) {
            return;
        }
        $task = Task::query()->findOrFail($taskId);
        if (! (bool) $task->need_review) {
            throw new ApiException('forbidden', '该任务可以自动发布，需要 articles:publish scope', 403, [
                'required_scope' => 'articles:publish',
            ]);
        }
    }

    /**
     * 列出某任务下的执行记录（task_runs）。
     *
     * 查询参数：status（可选）、limit（默认 20，最大 100）。
     */
    public function jobs(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $viewer = $this->executionAdmin($request);
        $status = $request->query('status');
        $statusStr = is_string($status) ? trim($status) : '';

        return $this->success($request, $tasks->listTaskJobsForApi(
            taskId: $task,
            status: $statusStr !== '' ? $statusStr : null,
            limit: $request->integer('limit', 20),
            viewer: $viewer,
        ));
    }

    private function refreshTaskModelProjection(
        JsonResponse $response,
        TaskLifecycleService $tasks,
        Admin $viewer,
    ): JsonResponse {
        $payload = $response->getData(true);
        $taskId = is_array($payload) ? data_get($payload, 'data.id') : null;
        if (! is_numeric($taskId) || (int) $taskId <= 0 || $response->getStatusCode() >= 400) {
            return $response;
        }

        $current = $tasks->getTaskForApi(taskId: (int) $taskId, viewer: $viewer);
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        foreach ([
            'ai_model_id',
            'ai_model_name',
            'ai_model_accessible',
            'ai_model_access_reason',
            'ai_quality_model_id',
            'ai_quality_model_name',
            'ai_quality_model_accessible',
            'ai_quality_model_access_reason',
            'batch_error_message',
        ] as $key) {
            $data[$key] = $current[$key] ?? null;
        }
        data_set(
            $data,
            'task_progress.last_error_message',
            data_get($current, 'task_progress.last_error_message'),
        );
        $payload['data'] = $data;
        $response->setData($payload);

        return $response;
    }
}
