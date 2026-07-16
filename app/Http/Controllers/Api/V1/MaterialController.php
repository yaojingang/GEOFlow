<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Api\StoreMaterialItemRequest;
use App\Services\Api\IdempotencyService;
use App\Services\GeoFlow\MaterialLibraryService;
use Closure;
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
        return IdempotencyService::executeJson(
            $request,
            'POST /materials/{type}',
            fn (): JsonResponse => $this->success($request, $materials->create($type, $request->all()), 201),
        );
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
        return IdempotencyService::executeJson(
            $request,
            'PATCH /materials/{type}/{id}',
            fn (): JsonResponse => $this->success($request, $materials->update($type, $id, $request->all())),
        );
    }

    /**
     * 删除素材库。
     */
    public function destroy(Request $request, string $type, int $id, MaterialLibraryService $materials): JsonResponse
    {
        return $this->success($request, $materials->delete($type, $id));
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
    public function storeItem(StoreMaterialItemRequest $request, string $type, int $id, MaterialLibraryService $materials): JsonResponse
    {
        $routeKey = 'POST /materials/{type}/{id}/items';
        $image = $request->file('image');
        $payload = $request->except('image');
        $operation = function () use ($request, $type, $id, $materials, $image, $payload): JsonResponse {
            $result = $image !== null
                ? $materials->createUploadedImageItem($type, $id, $image)
                : $materials->createItem($type, $id, $payload);

            return $this->success($request, $result, 201);
        };
        $operationGuard = $image !== null
            ? fn (Closure $callback): JsonResponse => $materials->withUploadedImagePathLock($type, $image, $callback)
            : fn (Closure $callback): JsonResponse => $materials->withLegacyImagePathLock($type, $payload, $callback);

        return IdempotencyService::executeJson($request, $routeKey, $operation, $operationGuard);
    }

    /**
     * 删除素材库条目。
     */
    public function destroyItems(Request $request, string $type, int $id, MaterialLibraryService $materials): JsonResponse
    {
        return $this->success($request, $materials->deleteItems($type, $id, $request->all()));
    }
}
