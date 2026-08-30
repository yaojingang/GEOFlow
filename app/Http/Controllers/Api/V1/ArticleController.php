<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Requests\Api\ArticleAiOptimizationActionRequest;
use App\Http\Requests\Api\StartArticleAiOptimizationRequest;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Services\Api\ApiTokenService;
use App\Services\Api\IdempotencyService;
use App\Services\GeoFlow\ArticleAiOptimizationCoordinator;
use App\Services\GeoFlow\ArticleAiOptimizationException;
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
        $aiQualityStatus = $request->query('ai_quality_status');
        if (is_string($aiQualityStatus) && trim($aiQualityStatus) !== '') {
            $filters['ai_quality_status'] = trim($aiQualityStatus);
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

        return IdempotencyService::executeJson($request, 'POST /articles', function () use ($request, $articles): JsonResponse {
            try {
                return $this->success($request, $articles->createArticle(
                    $request->all(),
                    $this->auth($request)->auditAdminId
                ), 201);
            } catch (ApiException $exception) {
                return $this->riskBlockedResponse($request, $exception);
            }
        });
    }

    /**
     * 单篇详情（含关联任务名、作者名、分类名与配图列表）。
     */
    public function show(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        return $this->success($request, $articles->getArticle($article));
    }

    /**
     * 返回轻量 AI 质检进度，不包含文章正文、证据正文或供应商错误。
     */
    public function aiQualityStatus(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        return $this->success($request, $articles->getAiQualityStatus($article));
    }

    /**
     * 部分更新文章。幂等键：PATCH /articles/{id}。
     */
    public function update(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        return IdempotencyService::executeJson(
            $request,
            'PATCH /articles/{id}',
            fn (): JsonResponse => $this->success($request, $articles->updateArticle(
                $article,
                $request->all(),
                $this->auth($request)->auditAdminId
            )),
        );
    }

    /**
     * 提交审核结果。请求体：review_status、review_note，风险放行时显式传 risk_override_reason。
     *
     * audit 管理员 ID 来自 Token 解析的 auditAdminId。幂等键：POST /articles/{id}/review。
     */
    public function review(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        $body = $request->all();

        return IdempotencyService::executeJson($request, 'POST /articles/{id}/review', function () use ($request, $article, $articles, $body): JsonResponse {
            try {
                return $this->success($request, $articles->reviewArticle(
                    $article,
                    trim((string) ($body['review_status'] ?? '')),
                    trim((string) ($body['review_note'] ?? '')),
                    trim((string) ($body['risk_override_reason'] ?? '')),
                    $this->auth($request)->auditAdminId
                ));
            } catch (ApiException $exception) {
                return $this->riskBlockedResponse($request, $exception);
            }
        });
    }

    /**
     * 在审核已通过的前提下将文章置为发布状态。幂等键：POST /articles/{id}/publish。
     */
    public function publish(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        return IdempotencyService::executeJson($request, 'POST /articles/{id}/publish', function () use ($request, $article, $articles): JsonResponse {
            try {
                return $this->success($request, $articles->publishArticle(
                    $article,
                    $this->auth($request)->auditAdminId
                ));
            } catch (ApiException $exception) {
                return $this->riskBlockedResponse($request, $exception);
            }
        });
    }

    /**
     * 按最新文章、知识库、提示词、模型和规则重新执行 AI 质检。
     */
    public function recheckAiQuality(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        $auth = $this->auth($request);

        return IdempotencyService::executeJson(
            $request,
            'POST /articles/{id}/ai-quality/recheck',
            fn (): JsonResponse => $this->success($request, $articles->recheckAiQuality(
                $article,
                $auth->auditAdminId,
                (int) ($auth->token['id'] ?? 0),
            )),
        );
    }

    /**
     * 对达到人工审核最低分的 needs_review 结果记录依据并放行。
     */
    public function overrideAiQuality(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        return IdempotencyService::executeJson(
            $request,
            'POST /articles/{id}/ai-quality/override',
            fn (): JsonResponse => $this->success($request, $articles->overrideAiQuality(
                $article,
                trim((string) $request->input('reason', '')),
                $this->auth($request)->auditAdminId,
            )),
        );
    }

    public function startAiOptimization(
        StartArticleAiOptimizationRequest $request,
        int $article,
        ArticleAiOptimizationCoordinator $coordinator,
    ): JsonResponse {
        return IdempotencyService::executeJson($request, 'POST /articles/{id}/ai-quality/optimization', function () use ($request, $article, $coordinator): JsonResponse {
            $modelArticle = Article::query()->with('task.aiModel')->find($article);
            if (! $modelArticle) {
                throw new ApiException('article_not_found', '文章不存在', 404);
            }
            $model = $modelArticle->task?->aiModel;
            if (! $model && $request->integer('optimization_model_id') > 0) {
                $model = AiModel::query()->find($request->integer('optimization_model_id'));
            }
            if (! $model instanceof AiModel) {
                throw new ApiException('article_ai_optimization_model_required', '请选择有效的内容模型', 422);
            }
            try {
                $coordinator->start(
                    $modelArticle,
                    (string) $request->validated('strategy'),
                    $model,
                    ArticleAiOptimizationRun::TRIGGER_API_MANUAL,
                    $this->auth($request)->auditAdminId,
                );
            } catch (ArticleAiOptimizationException $exception) {
                throw $this->optimizationException($exception);
            }

            return $this->success($request, (array) $coordinator->statusForArticle($article), 202);
        });
    }

    public function aiOptimizationCandidate(
        Request $request,
        int $article,
        int $run,
        ArticleAiOptimizationCoordinator $coordinator,
    ): JsonResponse {
        $this->assertOwnedOptimizationRun($article, $run);
        try {
            return $this->success($request, $coordinator->candidate($run));
        } catch (ArticleAiOptimizationException $exception) {
            throw $this->optimizationException($exception);
        }
    }

    public function latestAiOptimizationCandidate(
        Request $request,
        int $article,
        ArticleAiOptimizationCoordinator $coordinator,
    ): JsonResponse {
        $run = ArticleAiOptimizationRun::query()
            ->where('article_id', $article)
            ->whereNotNull('best_check_id')
            ->latest('id')
            ->first();
        if (! $run) {
            throw new ApiException('article_ai_optimization_not_found', 'AI 优化候选不存在', 404);
        }
        try {
            return $this->success($request, $coordinator->candidate((int) $run->id));
        } catch (ArticleAiOptimizationException $exception) {
            throw $this->optimizationException($exception);
        }
    }

    public function applyAiOptimization(
        ArticleAiOptimizationActionRequest $request,
        int $article,
        int $run,
        ArticleAiOptimizationCoordinator $coordinator,
    ): JsonResponse {
        return IdempotencyService::executeJson($request, 'POST /articles/{id}/ai-quality/optimization/{run}/apply', function () use ($request, $article, $run, $coordinator): JsonResponse {
            $this->assertOwnedOptimizationRun($article, $run);
            try {
                $coordinator->apply($run, (string) $request->validated('candidate_hash'), $this->auth($request)->auditAdminId);
            } catch (ArticleAiOptimizationException $exception) {
                throw $this->optimizationException($exception);
            }

            return $this->success($request, (array) $coordinator->statusForArticle($article));
        });
    }

    public function cancelAiOptimization(
        ArticleAiOptimizationActionRequest $request,
        int $article,
        int $run,
        ArticleAiOptimizationCoordinator $coordinator,
    ): JsonResponse {
        return IdempotencyService::executeJson($request, 'POST /articles/{id}/ai-quality/optimization/{run}/cancel', function () use ($request, $article, $run, $coordinator): JsonResponse {
            $this->assertOwnedOptimizationRun($article, $run);
            $coordinator->cancel(
                $run,
                adminId: $this->auth($request)->auditAdminId,
            );

            return $this->success($request, (array) $coordinator->statusForArticle($article));
        });
    }

    public function rollbackAiOptimization(
        ArticleAiOptimizationActionRequest $request,
        int $article,
        int $run,
        ArticleAiOptimizationCoordinator $coordinator,
    ): JsonResponse {
        return IdempotencyService::executeJson($request, 'POST /articles/{id}/ai-quality/optimization/{run}/rollback', function () use ($request, $article, $run, $coordinator): JsonResponse {
            $this->assertOwnedOptimizationRun($article, $run);
            try {
                $coordinator->rollback($run, $this->auth($request)->auditAdminId);
            } catch (ArticleAiOptimizationException $exception) {
                throw $this->optimizationException($exception);
            }

            return $this->success($request, (array) $coordinator->statusForArticle($article));
        });
    }

    /**
     * 软删除文章（写入 deleted_at）。幂等键：POST /articles/{id}/trash。
     */
    public function trash(Request $request, int $article, ArticleGeoFlowService $articles): JsonResponse
    {
        return IdempotencyService::executeJson(
            $request,
            'POST /articles/{id}/trash',
            fn (): JsonResponse => $this->success($request, $articles->trashArticle($article)),
        );
    }

    private function riskBlockedResponse(Request $request, ApiException $exception): JsonResponse
    {
        if ($exception->getErrorCode() !== 'article_risk_blocked'
            && ! str_starts_with($exception->getErrorCode(), 'article_ai_quality_')) {
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

        return $response;
    }

    private function assertOwnedOptimizationRun(int $articleId, int $runId): ArticleAiOptimizationRun
    {
        $run = ArticleAiOptimizationRun::query()
            ->whereKey($runId)
            ->where('article_id', $articleId)
            ->first();
        if (! $run) {
            throw new ApiException('article_ai_optimization_not_found', 'AI 优化运行不存在', 404);
        }

        return $run;
    }

    private function optimizationException(ArticleAiOptimizationException $exception): ApiException
    {
        return new ApiException(
            $exception->errorCode(),
            $exception->getMessage(),
            $exception->httpStatus(),
        );
    }
}
