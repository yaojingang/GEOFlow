<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\SystemUpdater\UpdaterPlanRequest;
use App\Http\Requests\SystemUpdater\UpdaterSubmissionRequest;
use App\Services\SystemUpdater\RemoteUpdaterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagementUpdaterController extends BaseApiController
{
    public function status(Request $request, RemoteUpdaterService $updater): JsonResponse
    {
        return $this->success($request, $updater->status($request));
    }

    public function recoveryPoints(Request $request, RemoteUpdaterService $updater): JsonResponse
    {
        return $this->success($request, $updater->recoveryPoints($request));
    }

    public function plan(UpdaterPlanRequest $request, RemoteUpdaterService $updater): JsonResponse
    {
        return $this->success($request, $updater->plan($request, $request->validated()), 201);
    }

    public function submit(UpdaterSubmissionRequest $request, RemoteUpdaterService $updater): JsonResponse
    {
        return $this->success($request, $updater->submit($request, $request->businessInput()));
    }

    public function lookup(Request $request, string $requestId, RemoteUpdaterService $updater): JsonResponse
    {
        return $this->success($request, $updater->lookup($request, $requestId, true));
    }

    public function operation(Request $request, string $operationId, RemoteUpdaterService $updater): JsonResponse
    {
        return $this->success($request, $updater->lookup($request, $operationId, false));
    }
}
