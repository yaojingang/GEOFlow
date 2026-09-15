<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Api\ManagementOperationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagementOperationController extends BaseApiController
{
    public function show(Request $request, string $operation, ManagementOperationService $operations): JsonResponse
    {
        $this->executionAdmin($request);

        return $this->success($request, $operations->find($request, $operation));
    }

    public function lookup(Request $request, ManagementOperationService $operations): JsonResponse
    {
        $this->executionAdmin($request);

        return $this->success($request, $operations->find($request));
    }
}
