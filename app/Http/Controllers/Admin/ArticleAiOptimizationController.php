<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ArticleAiOptimizationActionRequest;
use App\Http\Requests\Admin\StartArticleAiOptimizationRequest;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Services\GeoFlow\ArticleAiOptimizationCoordinator;
use App\Services\GeoFlow\ArticleAiOptimizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Lang;

class ArticleAiOptimizationController extends Controller
{
    public function __construct(private readonly ArticleAiOptimizationCoordinator $coordinator) {}

    public function store(StartArticleAiOptimizationRequest $request, int $articleId): JsonResponse
    {
        $article = Article::query()->with('task.aiModel')->whereKey($articleId)->firstOrFail();
        $model = $article->task?->aiModel;
        if (! $model && $request->integer('optimization_model_id') > 0) {
            $model = AiModel::query()->find($request->integer('optimization_model_id'));
        }
        if (! $model instanceof AiModel) {
            return $this->failure(new ArticleAiOptimizationException(
                'article_ai_optimization_model_required',
                httpStatus: 422,
            ));
        }

        try {
            $run = $this->coordinator->start(
                $article,
                (string) $request->validated('strategy'),
                $model,
                ArticleAiOptimizationRun::TRIGGER_ADMIN_MANUAL,
                $request->user('admin')?->id,
                requestKey: (string) $request->validated('request_key'),
            );

            return response()->json(['data' => $this->coordinator->statusForArticle($article)], 202);
        } catch (ArticleAiOptimizationException $exception) {
            return $this->failure($exception);
        }
    }

    public function candidate(Request $request, int $articleId, int $runId): JsonResponse
    {
        $this->ownedRun($articleId, $runId);

        try {
            return response()->json(['data' => $this->coordinator->candidate($runId)]);
        } catch (ArticleAiOptimizationException $exception) {
            return $this->failure($exception);
        }
    }

    public function apply(ArticleAiOptimizationActionRequest $request, int $articleId, int $runId): JsonResponse
    {
        $this->ownedRun($articleId, $runId);

        try {
            $this->coordinator->apply(
                $runId,
                (string) $request->validated('candidate_hash'),
                $request->user('admin')?->id,
            );

            return response()->json(['data' => $this->coordinator->statusForArticle($articleId)]);
        } catch (ArticleAiOptimizationException $exception) {
            return $this->failure($exception);
        }
    }

    public function cancel(ArticleAiOptimizationActionRequest $request, int $articleId, int $runId): JsonResponse
    {
        $this->ownedRun($articleId, $runId);

        try {
            $this->coordinator->cancel(
                $runId,
                adminId: $request->user('admin')?->id,
            );

            return response()->json(['data' => $this->coordinator->statusForArticle($articleId)]);
        } catch (ArticleAiOptimizationException $exception) {
            return $this->failure($exception);
        }
    }

    public function rollback(ArticleAiOptimizationActionRequest $request, int $articleId, int $runId): JsonResponse
    {
        $this->ownedRun($articleId, $runId);

        try {
            $this->coordinator->rollback($runId, $request->user('admin')?->id);

            return response()->json(['data' => $this->coordinator->statusForArticle($articleId)]);
        } catch (ArticleAiOptimizationException $exception) {
            return $this->failure($exception);
        }
    }

    private function ownedRun(int $articleId, int $runId): ArticleAiOptimizationRun
    {
        return ArticleAiOptimizationRun::query()
            ->whereKey($runId)
            ->where('article_id', $articleId)
            ->firstOrFail();
    }

    private function failure(ArticleAiOptimizationException $exception): JsonResponse
    {
        $translationKey = 'admin.articles.ai_optimization.errors.'.$exception->errorCode();

        return response()->json([
            'error' => [
                'code' => $exception->errorCode(),
                'message' => Lang::has($translationKey)
                    ? __($translationKey)
                    : __('admin.articles.ai_optimization.request_failed'),
            ],
        ], $exception->httpStatus());
    }
}
