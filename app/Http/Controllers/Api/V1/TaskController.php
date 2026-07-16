<?php

namespace App\Http\Controllers\Api\V1;

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
        $statusQuery = $request->query('status');
        $searchQuery = $request->query('search');

        $data = $tasks->listTasks(
            $request->integer('page', 1),
            $request->integer('per_page', 20),
            [
                'status' => is_string($statusQuery) ? trim($statusQuery) : null,
                'search' => is_string($searchQuery) ? trim($searchQuery) : null,
            ]
        );

        return $this->success($request, $data);
    }

    /**
     * 创建任务；成功 HTTP 201。
     *
     * 幂等键：POST /tasks（请求头 X-Idempotency-Key 可选）。
     */
    public function store(Request $request, TaskLifecycleService $tasks): JsonResponse
    {
        return IdempotencyService::executeJson(
            $request,
            'POST /tasks',
            fn (): JsonResponse => $this->success($request, $tasks->createTask($request->all()), 201),
        );
    }

    /**
     * 任务详情（双层视图：业务进度 + 队列监控摘要）。
     */
    public function show(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        return $this->success($request, $tasks->getTask($task));
    }

    /**
     * 部分更新任务字段。
     *
     * 幂等键：PATCH /tasks/{id}
     */
    public function update(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        return IdempotencyService::executeJson(
            $request,
            'PATCH /tasks/{id}',
            fn (): JsonResponse => $this->success($request, $tasks->updateTask($task, $request->all())),
        );
    }

    /**
     * 删除任务。幂等键：DELETE /tasks/{id}
     */
    public function destroy(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        return $this->success($request, $tasks->deleteTask($task));
    }

    /**
     * 激活任务并可选择立即入队一条生成任务。
     *
     * 请求体可选 enqueue_now（布尔）。幂等键：POST /tasks/{id}/start
     */
    public function start(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $enqueueNow = ! empty($request->input('enqueue_now'));

        return IdempotencyService::executeJson(
            $request,
            'POST /tasks/{id}/start',
            fn (): JsonResponse => $this->success($request, $tasks->startTask($task, $enqueueNow)),
        );
    }

    /**
     * 暂停任务并取消待处理 Job。
     *
     * 幂等键：POST /tasks/{id}/stop
     */
    public function stop(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        return IdempotencyService::executeJson(
            $request,
            'POST /tasks/{id}/stop',
            fn (): JsonResponse => $this->success($request, $tasks->stopTask($task)),
        );
    }

    /**
     * 向队列投递一条 Job；成功 HTTP 201。
     *
     * 请求体可含 job_type，其余字段进入 payload。幂等键：POST /tasks/{id}/enqueue
     */
    public function enqueue(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $body = $request->all();
        $jobType = trim((string) ($body['job_type'] ?? 'generate_article'));
        $payload = $body;
        unset($payload['job_type']);

        return IdempotencyService::executeJson(
            $request,
            'POST /tasks/{id}/enqueue',
            fn (): JsonResponse => $this->success($request, $tasks->enqueueTask($task, $jobType, $payload), 201),
        );
    }

    /**
     * 列出某任务下的执行记录（task_runs）。
     *
     * 查询参数：status（可选）、limit（默认 20，最大 100）。
     */
    public function jobs(Request $request, int $task, TaskLifecycleService $tasks): JsonResponse
    {
        $status = $request->query('status');
        $statusStr = is_string($status) ? trim($status) : '';

        return $this->success($request, $tasks->listTaskJobs(
            $task,
            $statusStr !== '' ? $statusStr : null,
            $request->integer('limit', 20)
        ));
    }
}
