<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 单条队列执行记录（task_runs）查询：状态、payload、执行摘要等。
 *
 * 需要 scope：jobs:read。路径参数 job 为 task_runs.id。
 */
class JobController extends BaseApiController
{
    /**
     * 按 ID 查询单条 Job 详情。
     */
    public function show(Request $request, int $job, TaskLifecycleService $tasks): JsonResponse
    {
        return $this->success($request, $tasks->getJob($job));
    }
}
