<?php

namespace App\Http\Controllers\Api\V1;

use App\Services\Api\IdempotencyService;
use App\Services\GeoFlow\MaterialLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 素材库管理：分类、作者、关键词库、标题库、图片库、知识库。
 *
 * 读接口需 materials:read，写接口需 materials:write。写操作支持 X-Idempotency-Key。
 */
class MaterialController extends BaseApiController
{
    /**
     * 素材库类型摘要。
     */
    public function summary(Request $request, MaterialLibraryService $materials): JsonResponse
    {
        return $this->success($request, $materials->summary());
    }

    /**
     * 分页列出某类素材库。
     */
    public function index(Request $request, string $type, MaterialLibraryService $materials): JsonResponse
    {
        $search = $request->query('search');

        return $this->success($request, $materials->list(
            $type,
            $request->integer('page', 1),
            $request->integer('per_page', 20),
            ['search' => is_string($search) ? trim($search) : '']
        ));
    }

    /**
     * 创建素材库。
     */
    public function store(Request $request, string $type, MaterialLibraryService $materials): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /materials/{type}');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success($request, $materials->create($type, $request->all()), 201, 'POST /materials/{type}');
    }

    /**
     * 单个素材库详情。
     */
    public function show(Request $request, string $type, int $id, MaterialLibraryService $materials): JsonResponse
    {
        return $this->success($request, $materials->show($type, $id));
    }

    /**
     * 更新素材库。
     */
    public function update(Request $request, string $type, int $id, MaterialLibraryService $materials): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'PATCH /materials/{type}/{id}');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success($request, $materials->update($type, $id, $request->all()), 200, 'PATCH /materials/{type}/{id}');
    }

    /**
     * 删除素材库。
     */
    public function destroy(Request $request, string $type, int $id, MaterialLibraryService $materials): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'DELETE /materials/{type}/{id}');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success($request, $materials->delete($type, $id), 200, 'DELETE /materials/{type}/{id}');
    }

    /**
     * 列出素材库条目（关键词、标题、图片元数据、知识库切块）。
     */
    public function items(Request $request, string $type, int $id, MaterialLibraryService $materials): JsonResponse
    {
        return $this->success($request, $materials->listItems(
            $type,
            $id,
            $request->integer('page', 1),
            $request->integer('per_page', 20)
        ));
    }

    /**
     * 新增素材库条目。
     */
    public function storeItem(Request $request, string $type, int $id, MaterialLibraryService $materials): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /materials/{type}/{id}/items');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success($request, $materials->createItem($type, $id, $request->all()), 201, 'POST /materials/{type}/{id}/items');
    }

    /**
     * 删除素材库条目。
     */
    public function destroyItems(Request $request, string $type, int $id, MaterialLibraryService $materials): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'DELETE /materials/{type}/{id}/items');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success($request, $materials->deleteItems($type, $id, $request->all()), 200, 'DELETE /materials/{type}/{id}/items');
    }
}
