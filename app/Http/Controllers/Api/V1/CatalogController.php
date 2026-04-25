<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\GeoFlow\CatalogGeoFlowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 目录/元数据：供创建任务等场景下拉选用（模型、提示词、标题库、知识库、作者、分类等）。
 *
 * 需要 scope：catalog:read。
 */
class CatalogController extends BaseApiController
{
    /**
     * 返回聚合目录：models、prompts、title_libraries、knowledge_bases、authors、categories。
     */
    public function show(Request $request, CatalogGeoFlowService $catalog): JsonResponse
    {
        return $this->success($request, $catalog->getCatalog());
    }
}
