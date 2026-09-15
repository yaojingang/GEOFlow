<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Api\ManagementSiteQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ManagementSiteController extends BaseApiController
{
    public function index(Request $request, ManagementSiteQuery $sites): JsonResponse
    {
        $this->executionAdmin($request);

        return $this->success($request, ['items' => [$sites->show('primary')]]);
    }

    public function show(Request $request, string $site, ManagementSiteQuery $sites): JsonResponse
    {
        $this->executionAdmin($request);

        return $this->success($request, $sites->show($site));
    }
}
