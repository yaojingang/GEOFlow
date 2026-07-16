<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Services\Api\ApiTokenService;
use App\Services\Api\IdempotencyService;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * API v1 文章（articles）管理：列表、创建、详情、更新、审核、发布、软删除。
 *
 * 读：articles:read；写：articles:write；审核/发布：articles:publish。
 * 部分写操作支持幂等键，与遗留路由键一致。
 */
class ArticleController extends BaseApiController
{
    /**
     * 分页列表，支持多维筛选。
     *
     * 查询参数：page、per_page、task_id、status、review_status、author_id、search（标题/正文模糊）。
     */
    public function index(Request $request, ArticleGeoFlowService $articles): JsonResponse
    {
        $taskId = $request->integer('task_id', 0);
        $authorId = $request->integer('author_id', 0);

        $filters = [];
        if ($taskId > 0) {
            $filters['task_id'] = $taskId;
        }
        if ($authorId > 0) {
            $filters['author_id'] = $authorId;
        }
        $status = $request->query('status');
        if (is_string($status) && trim($status) !== '') {
            $filters['status'] = trim($status);
        }
        $reviewStatus = $request->query('review_status');
        if (is_string($reviewStatus) && trim($reviewStatus) !== '') {
            $filters['review_status'] = trim($reviewStatus);
        }
        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $filters['search'] = trim($search);
        }

        return $this->success($request, $articles->listArticles(
            $request->integer('page', 1),
            $request->integer('per_page', 20),
            $filters
        ));
    }

    /**
     * 创建文章；成功 HTTP 201。幂等键：POST /articles。
     */
    public function store(Request $request, ArticleGeoFlowService $articles, ApiTokenService $tokens): JsonResponse
    {
        $body = $request->all();
        $requestsPublication = in_array(trim((string) ($body['status'] ?? 'draft')), ['published', 'private'], true)
            || in_array(trim((string) ($body['review_status'] ?? 'pending')), ['approved', 'auto_approved'], true)
            || trim((string) ($body['risk_override_reason'] ?? '')) !== '';
        if ($requestsPublication && ! $tokens->tokenHasScope($this->auth($request)->token, 'articles:publish')) {
            throw new ApiException('forbidden', '当前 Token 没有发布或风险放行权限', 403, [
                'required_scope' => 'articles:publish',
            ]);
        }

        $cached = IdempotencyService::maybeReplayJson($request, 'POST /articles');
        if ($cached !== null) {
            return $cached;
        }

        try {
            return $this->success($request, $articles->createArticle(
                $request->all(),
                $this->auth($request)->auditAdminId
            ), 201, 'POST /articles');
        } catch (ApiException $exception) {
            return $this->riskBlockedResponse($request, 'POST /articles', $exception);
        }
    }

    /**
     * 单篇详情（含关联任务名、作者名、分类名与配图列表）。
     */
    public function show(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        return $this->success($request, $articles->getArticle($article));
    }

    /**
     * 部分更新文章。幂等键：PATCH /articles/{id}。
     */
    public function update(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'PATCH /articles/{id}');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success($request, $articles->updateArticle(
            $article,
            $request->all(),
            $this->auth($request)->auditAdminId
        ), 200, 'PATCH /articles/{id}');
    }

    /**
     * 提交审核结果。请求体：review_status、review_note，风险放行时显式传 risk_override_reason。
     *
     * audit 管理员 ID 来自 Token 解析的 auditAdminId。幂等键：POST /articles/{id}/review。
     */
    public function review(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /articles/{id}/review');
        if ($cached !== null) {
            return $cached;
        }

        $body = $request->all();

        try {
            return $this->success($request, $articles->reviewArticle(
                $article,
                trim((string) ($body['review_status'] ?? '')),
                trim((string) ($body['review_note'] ?? '')),
                trim((string) ($body['risk_override_reason'] ?? '')),
                $this->auth($request)->auditAdminId
            ), 200, 'POST /articles/{id}/review');
        } catch (ApiException $exception) {
            return $this->riskBlockedResponse($request, 'POST /articles/{id}/review', $exception);
        }
    }

    /**
     * 在审核已通过的前提下将文章置为发布状态。幂等键：POST /articles/{id}/publish。
     */
    public function publish(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /articles/{id}/publish');
        if ($cached !== null) {
            return $cached;
        }

        try {
            return $this->success($request, $articles->publishArticle(
                $article,
                $this->auth($request)->auditAdminId
            ), 200, 'POST /articles/{id}/publish');
        } catch (ApiException $exception) {
            return $this->riskBlockedResponse($request, 'POST /articles/{id}/publish', $exception);
        }
    }

    /**
     * 软删除文章（写入 deleted_at）。幂等键：POST /articles/{id}/trash。
     */
    public function trash(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        $cached = IdempotencyService::maybeReplayJson($request, 'POST /articles/{id}/trash');
        if ($cached !== null) {
            return $cached;
        }

        return $this->success($request, $articles->trashArticle($article), 200, 'POST /articles/{id}/trash');
    }

    private function riskBlockedResponse(Request $request, string $routeKey, ApiException $exception): JsonResponse
    {
        if ($exception->getErrorCode() !== 'article_risk_blocked') {
            throw $exception;
        }

        $requestId = $this->requestId($request);
        $response = ApiResponse::error(
            $exception->getErrorCode(),
            $exception->getMessage(),
            $requestId,
            $exception->getHttpStatus(),
            $exception->getDetails(),
        )->withHeaders(['X-Request-Id' => $requestId]);
        IdempotencyService::rememberFromResponse($request, $routeKey, $response);

        return $response;
    }
}
