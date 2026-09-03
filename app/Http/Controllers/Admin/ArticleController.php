<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ExportArticlesMarkdownRequest;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\ManualPublication;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Models\TitleLibrary;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\GeoFlow\AiQualityAuditService;
use App\Services\GeoFlow\AiQualityRetrievalReadinessService;
use App\Services\GeoFlow\ArticleAiOptimizationCoordinator;
use App\Services\GeoFlow\ArticleAiQualityConfigurationService;
use App\Services\GeoFlow\ArticleAiQualityGate;
use App\Services\GeoFlow\ArticleAiQualityInspectionService;
use App\Services\GeoFlow\ArticleAiQualityInvalidationService;
use App\Services\GeoFlow\ArticleCitationMarkerCleaner;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\ArticleMarkdownExportService;
use App\Services\GeoFlow\ArticleRiskScanner;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\HostedSites\HostedSiteArticleFingerprintService;
use App\Support\Admin\ArticleAiQualityProgressPresenter;
use App\Support\AdminWeb;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * 文章管理页（按 bak/admin/articles.php 行为迁移）：
 * - GET 展示列表、筛选、统计与批量操作区
 * - POST 处理批量状态/审核更新与批量删除
 * - create/edit 共用同一 Blade 表单页
 */
class ArticleController extends Controller
{
    private const MAX_BATCH_DELETE_ARTICLES = 500;

    public function __construct(
        private readonly DistributionOrchestrator $distributionOrchestrator,
        private readonly ArticleRiskScanner $articleRiskScanner,
        private readonly ArticleMarkdownExportService $articleMarkdownExportService,
        private readonly ArticleWorkflowTransitionService $articleWorkflowTransitionService,
        private readonly HostedSiteArticleFingerprintService $hostedFingerprints,
        private readonly ArticleAiQualityInspectionService $articleAiQualityInspectionService,
        private readonly ArticleAiQualityInvalidationService $articleAiQualityInvalidationService,
        private readonly ArticleAiQualityGate $articleAiQualityGate,
        private readonly ArticleAiQualityProgressPresenter $articleAiQualityProgressPresenter,
        private readonly ArticleCitationMarkerCleaner $articleCitationMarkerCleaner,
        private readonly ArticleAiOptimizationCoordinator $articleAiOptimizationCoordinator,
        private readonly ArticleAiQualityConfigurationService $articleAiQualityConfigurationService,
        private readonly AiQualityRetrievalReadinessService $aiQualityRetrievalReadinessService,
        private readonly AiQualityAuditService $aiQualityAuditService,
        private readonly ArticleGeoFlowService $articleGeoFlowService,
        private readonly AdminAiModelAccessResolver $adminAiModelAccessResolver,
    ) {}

    /**
     * 文章管理首页：渲染筛选与列表。
     */
    public function index(Request $request): View
    {
        $filters = $this->buildFilters($request);
        $articles = $this->queryArticles($filters);
        $isTrashView = (bool) ($filters['trashed'] ?? false);

        return view('admin.articles.index', [
            'pageTitle' => $isTrashView
                ? __('admin.articles.trash.title')
                : __('admin.articles.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'articles' => $articles,
            'stats' => $isTrashView ? $this->loadTrashStats() : $this->loadStats(),
            'filters' => $filters,
            'tasks' => $this->loadTaskOptions(),
            'authors' => $this->loadAuthorOptions(),
            'distributionChannels' => $this->loadDistributionChannelOptions(),
            'articlesI18n' => $this->articlesI18n(),
            'isTrashView' => $isTrashView,
            'trashI18n' => $this->trashI18n(),
            'articleBatchRoutes' => $this->articleBatchRoutes($isTrashView),
            'articleExportMaxArticles' => ArticleMarkdownExportService::MAX_ARTICLES,
            'canCreateManualPublication' => $this->canCreateManualPublication($request),
        ]);
    }

    /**
     * 批量更新发布状态。
     */
    public function batchUpdateStatus(Request $request): RedirectResponse
    {
        $riskOverrideReason = $this->validateRiskOverrideReason($request);
        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            return $this->handleBatchUpdateStatus($request, $articleIds, $riskOverrideReason);
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage());
        }
    }

    /**
     * 批量更新审核状态。
     */
    public function batchUpdateReview(Request $request): RedirectResponse
    {
        $riskOverrideReason = $this->validateRiskOverrideReason($request);
        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            return $this->handleBatchUpdateReview($request, $articleIds, $riskOverrideReason);
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage());
        }
    }

    /**
     * 批量删除文章。
     */
    public function batchDelete(Request $request): RedirectResponse
    {
        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            return $this->handleBatchDelete($articleIds, $this->authenticatedAdminId($request));
        } catch (Throwable $e) {
            return back()->withErrors($e->getMessage());
        }
    }

    public function prepareMarkdownExport(ExportArticlesMarkdownRequest $request): JsonResponse
    {
        $adminId = $this->authenticatedAdminId($request);
        $articleIds = $request->articleIds();
        $adminLock = Cache::lock(
            'geoflow:article-markdown-export:admin:'.$adminId,
            ArticleMarkdownExportService::BUILD_LOCK_SECONDS,
        );
        $capacityLock = null;
        $adminLockAcquired = false;
        $capacityLockAcquired = false;

        try {
            if (! $adminLock->get()) {
                return response()->json([
                    'message' => __('admin.articles.export.errors.in_progress'),
                    'code' => 'article_export_in_progress',
                ], 409);
            }
            $adminLockAcquired = true;

            $capacityLock = Cache::lock(
                'geoflow:article-markdown-export:capacity',
                ArticleMarkdownExportService::BUILD_LOCK_SECONDS,
            );
            if (! $capacityLock->get()) {
                return response()->json([
                    'message' => __('admin.articles.export.errors.in_progress'),
                    'code' => 'article_export_capacity_busy',
                ], 409);
            }
            $capacityLockAcquired = true;

            $export = $this->articleMarkdownExportService->prepare($adminId, $articleIds);
            $expiresAt = now()->addMinutes(ArticleMarkdownExportService::DOWNLOAD_TTL_MINUTES);
            $downloadUrl = AdminWeb::appPath(
                URL::temporarySignedRoute(
                    'admin.articles.batch.export-markdown.download',
                    $expiresAt,
                    [
                        'exportToken' => $export['token'],
                        'owner' => $adminId,
                        'filename' => $export['filename'],
                    ],
                    absolute: false,
                ),
            );

            return response()->json([
                'data' => [
                    'count' => $export['count'],
                    'filename' => $export['filename'],
                    'download_url' => $downloadUrl,
                    'expires_at' => $expiresAt->toIso8601String(),
                ],
            ])->header('Cache-Control', 'no-store');
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('Article Markdown export failed.', [
                'exception_type' => $exception::class,
                'error_code' => 'article_export_failed',
                'admin_id' => $adminId,
                'article_count' => count($articleIds),
            ]);

            return response()->json([
                'message' => __('admin.articles.export.errors.build_failed'),
                'code' => 'article_export_failed',
            ], 500);
        } finally {
            if ($capacityLockAcquired && $capacityLock !== null) {
                try {
                    $capacityLock->release();
                } catch (Throwable $exception) {
                    Log::warning('Article Markdown export capacity lock release failed.', [
                        'exception_type' => $exception::class,
                        'admin_id' => $adminId,
                    ]);
                }
            }

            if ($adminLockAcquired) {
                try {
                    $adminLock->release();
                } catch (Throwable $exception) {
                    Log::warning('Article Markdown export admin lock release failed.', [
                        'exception_type' => $exception::class,
                        'admin_id' => $adminId,
                    ]);
                }
            }
        }
    }

    public function downloadMarkdownExport(Request $request, string $exportToken): BinaryFileResponse
    {
        $adminId = $this->authenticatedAdminId($request);
        $owner = filter_var($request->query('owner'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX],
        ]);
        $filename = (string) $request->query('filename', '');

        abort_unless(is_int($owner) && $owner === $adminId, 404);
        abort_unless(preg_match('/\Ageoflow-articles-\d{8}-\d{6}\.zip\z/D', $filename) === 1, 404);

        $path = $this->articleMarkdownExportService->resolveDownload($adminId, $exportToken);
        abort_if($path === null, 404);

        $response = response()->download($path, $filename, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store, max-age=0',
        ]);
        $response->setPrivate();

        return $response;
    }

    /**
     * 批量恢复已软删除的文章。
     */
    public function batchRestore(Request $request): RedirectResponse
    {
        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            $adminId = $this->authenticatedAdminId($request);
            $count = DB::transaction(function () use ($articleIds, $adminId): int {
                $articles = Article::onlyTrashed()
                    ->whereIn('id', $articleIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                foreach ($articles as $article) {
                    $article->restore();
                    $article->forceFill([
                        'ai_quality_policy_version' => max(1, (int) $article->ai_quality_policy_version) + 1,
                    ])->save();
                    $this->aiQualityAuditService->record('article_restored', [
                        'article_id' => (int) $article->id,
                        'task_id' => $article->task_id ? (int) $article->task_id : null,
                        'admin_id' => $adminId,
                        'policy_version' => (int) $article->ai_quality_policy_version,
                        'reason_code' => 'article_soft_restored',
                    ]);
                    $this->articleAiQualityInvalidationService->invalidateArticle(
                        $article,
                        'article_restored',
                    );
                }

                return $articles->count();
            });

            return back()->with('message', __('admin.articles.trash.message.restore_success', ['count' => $count]));
        } catch (Throwable $e) {
            return back()->withErrors(__('admin.articles.trash.message.restore_failed'));
        }
    }

    /**
     * 批量永久删除（垃圾箱内）。
     */
    public function batchForceDelete(Request $request): RedirectResponse
    {
        $request->validate([
            'article_ids' => ['array', 'max:'.self::MAX_BATCH_DELETE_ARTICLES],
            'article_ids.*' => ['bail', 'integer', 'min:1', 'distinct'],
        ]);

        $articleIds = $this->extractArticleIds($request);
        if (empty($articleIds)) {
            return back()->withErrors(__('admin.articles.message.select_articles'));
        }

        try {
            $count = DB::transaction(function () use ($articleIds): int {
                $models = Article::onlyTrashed()
                    ->whereIn('id', $articleIds)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $models->each(function (Article $article): void {
                    $article->forceDelete();
                });

                return $models->count();
            });

            return back()->with('message', __('admin.articles.trash.message.delete_success', ['count' => $count]));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(__('admin.articles.message.delete_failed_refresh'));
        }
    }

    /**
     * 清空文章垃圾箱（全部永久删除）。
     */
    public function emptyTrash(): RedirectResponse
    {
        try {
            $total = DB::transaction(
                static fn (): int => (int) Article::onlyTrashed()->forceDelete(),
            );

            if ($total === 0) {
                return back()->with('message', __('admin.articles.trash.message.empty_already'));
            }

            return back()->with('message', __('admin.articles.trash.message.empty_success', ['count' => $total]));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(__('admin.articles.message.delete_failed_refresh'));
        }
    }

    /**
     * 恢复单篇已删除文章。
     */
    public function restore(Request $request, int $articleId): RedirectResponse
    {
        $adminId = $this->authenticatedAdminId($request);
        DB::transaction(function () use ($articleId, $adminId): void {
            $article = Article::onlyTrashed()
                ->whereKey($articleId)
                ->lockForUpdate()
                ->firstOrFail();
            $article->restore();
            $article->forceFill([
                'ai_quality_policy_version' => max(1, (int) $article->ai_quality_policy_version) + 1,
            ])->save();
            $this->aiQualityAuditService->record('article_restored', [
                'article_id' => (int) $article->id,
                'task_id' => $article->task_id ? (int) $article->task_id : null,
                'admin_id' => $adminId,
                'policy_version' => (int) $article->ai_quality_policy_version,
                'reason_code' => 'article_soft_restored',
            ]);
            $this->articleAiQualityInvalidationService->invalidateArticle(
                $article,
                'article_restored',
            );
        });

        return back()->with('message', __('admin.articles.trash.message.restore_success', ['count' => 1]));
    }

    /**
     * 永久删除单篇已删除文章。
     */
    public function forceDelete(int $articleId): RedirectResponse
    {
        try {
            DB::transaction(function () use ($articleId): void {
                $article = Article::onlyTrashed()
                    ->whereKey($articleId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $article->forceDelete();
            });
        } catch (ModelNotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(__('admin.articles.message.delete_failed_refresh'));
        }

        return back()->with('message', __('admin.articles.trash.message.delete_success', ['count' => 1]));
    }

    /**
     * 文章创建页：与编辑页共用一个 Blade 模板。
     */
    public function create(Request $request): View
    {
        return view('admin.articles.form', [
            'pageTitle' => __('admin.article_create.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => false,
            'articleId' => null,
            'articleForm' => null,
            'riskScan' => null,
            'aiQualityCheck' => null,
            'aiQualityHistory' => collect(),
            'formOptions' => $this->loadFormOptions(true, $request->user('admin')),
            'canCreateManualPublication' => $this->canCreateManualPublication($request),
        ]);
    }

    /**
     * 创建文章：手动写入内容并按统一工作流校正状态。
     */
    public function store(Request $request): RedirectResponse
    {
        $payload = $this->validateArticleForm($request, false);
        $workflowState = ArticleWorkflow::normalizeState(
            $payload['status'],
            $payload['review_status']
        );
        $article = null;

        try {
            $adminId = $this->authenticatedAdminId($request);
            $gateRejection = DB::transaction(function () use (&$article, $payload, $workflowState, $adminId): ArticleRiskGateException|ArticleAiQualityGateException|null {
                $sourceTitle = null;
                if ((int) ($payload['source_title_id'] ?? 0) > 0) {
                    $candidate = Title::query()
                        ->whereKey((int) $payload['source_title_id'])
                        ->lockForUpdate()
                        ->first(['id', 'title']);
                    if ($candidate && trim((string) $candidate->title) === trim((string) $payload['title'])) {
                        $sourceTitle = $candidate;
                    }
                }

                $article = Article::query()->create([
                    'title' => $payload['title'],
                    'slug' => ArticleWorkflow::generateUniqueSlug($payload['title']),
                    'content' => $payload['content'],
                    'excerpt' => $payload['excerpt'] !== '' ? $payload['excerpt'] : mb_substr(strip_tags($payload['content']), 0, 200, 'UTF-8'),
                    'keywords' => $payload['keywords'],
                    'meta_description' => $payload['meta_description'],
                    'category_id' => (int) $payload['category_id'],
                    'author_id' => (int) $payload['author_id'],
                    'source_title_id' => $sourceTitle?->id,
                    'status' => 'draft',
                    'review_status' => 'pending',
                    'published_at' => null,
                    'is_ai_generated' => (bool) ($payload['is_ai_generated'] ?? false),
                    'is_hot' => (bool) ($payload['is_hot'] ?? false),
                    'is_featured' => (bool) ($payload['is_featured'] ?? false),
                ]);

                if ($sourceTitle) {
                    Title::query()->whereKey((int) $sourceTitle->id)->update([
                        'used_count' => DB::raw('COALESCE(used_count,0)+1'),
                        'usage_count' => DB::raw('COALESCE(usage_count,0)+1'),
                    ]);
                }

                $this->articleRiskScanner->record($article, 'admin_save', $adminId);
                if ($this->requiresRiskGate($payload)) {
                    try {
                        $article = $this->transitionGatedArticle($article, $workflowState, $payload, 'admin_save', $adminId);
                    } catch (ArticleRiskGateException|ArticleAiQualityGateException $exception) {
                        return $exception;
                    }
                } else {
                    $article->update([
                        'status' => $workflowState['status'],
                        'review_status' => $workflowState['review_status'],
                        'published_at' => $workflowState['published_at'],
                    ]);
                }

                return null;
            });

            if ($gateRejection instanceof ArticleRiskGateException || $gateRejection instanceof ArticleAiQualityGateException) {
                throw $gateRejection;
            }
            if ($article->status === 'published') {
                $this->distributionOrchestrator->enqueueForArticle($article);
            }
        } catch (ArticleRiskGateException|ArticleAiQualityGateException $e) {
            return redirect()
                ->route('admin.articles.edit', ['articleId' => (int) $article?->id])
                ->withInput()
                ->withErrors($e->getMessage());
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(__('admin.article_create.error.create_exception', ['message' => $e->getMessage()]));
        }

        return redirect()
            ->route('admin.articles.edit', ['articleId' => (int) $article->id])
            ->with('message', __('admin.button.create_article'));
    }

    /**
     * 文章编辑页：复用创建页模板并回填现有数据。
     */
    public function edit(Request $request, int $articleId): View|RedirectResponse
    {
        $article = Article::query()
            ->with([
                'task:id,name,ai_quality_enabled,ai_model_id,knowledge_base_id,ai_quality_retrieval_mode',
                'task.knowledgeBases:id,name',
                'task.distributionChannels:id,channel_type',
                'aiQualityKnowledgeBases:id,name',
                'author:id,name',
                'category:id,name',
                'latestAiQualityCheck.prompt:id,name',
                'latestAiQualityCheck.aiModel:id,name',
            ])
            ->whereKey($articleId)
            ->firstOrFail();

        $aiQualityCheck = $article->latestAiQualityCheck;
        $qualityRetrieval = $this->articleAiQualityRetrievalViewData($request, $article);

        return view('admin.articles.form', [
            'pageTitle' => __('admin.article_edit.page_title'),
            'activeMenu' => 'articles',
            'adminSiteName' => AdminWeb::siteName(),
            'isEdit' => true,
            'articleId' => $articleId,
            'articleForm' => [
                'title' => (string) $article->title,
                'excerpt' => (string) ($article->excerpt ?? ''),
                'content' => (string) $article->content,
                'keywords' => (string) ($article->keywords ?? ''),
                'meta_description' => (string) ($article->meta_description ?? ''),
                'status' => (string) $article->status,
                'review_status' => (string) $article->review_status,
                'category_id' => (string) $article->category_id,
                'author_id' => (string) $article->author_id,
                'slug' => (string) $article->slug,
                'published_at' => $article->published_at?->format('Y-m-d H:i:s'),
                'task_name' => (string) ($article->task->name ?? ''),
                'ai_quality_enabled' => (bool) $article->ai_quality_required_at_creation
                    || (bool) ($article->task->ai_quality_enabled ?? false),
                'ai_quality_retrieval_mode_override' => (string) ($article->ai_quality_retrieval_mode_override ?? ''),
                'is_hot' => (bool) ($article->is_hot ?? false),
                'is_featured' => (bool) ($article->is_featured ?? false),
                'is_ai_generated' => (bool) ($article->is_ai_generated ?? false),
            ],
            'riskScan' => $this->riskScanViewData($article),
            'aiQualityCheck' => $aiQualityCheck,
            'aiQualityProgress' => $this->articleAiQualityProgressPresenter->snapshot($aiQualityCheck),
            'aiOptimization' => $this->articleAiOptimizationCoordinator->statusForArticle($article),
            'aiQualityHistory' => $article->aiQualityChecks()
                ->with(['prompt:id,name', 'aiModel:id,name'])
                ->latest('id')
                ->limit(10)
                ->get(),
            'formOptions' => $this->loadFormOptions(false),
            'canCreateManualPublication' => $this->canCreateManualPublication($request),
            'aiQualityRetrieval' => $qualityRetrieval,
        ]);
    }

    private function canCreateManualPublication(Request $request): bool
    {
        $admin = $request->user('admin');

        return $admin instanceof Admin
            && Gate::forUser($admin)->allows('create', ManualPublication::class);
    }

    /**
     * 从编辑页手动重新执行当前文章的风险扫描。
     */
    public function recheckRisk(Request $request, int $articleId): RedirectResponse
    {
        $adminId = $this->authenticatedAdminId($request);
        $downgraded = DB::transaction(function () use ($articleId, $adminId): bool {
            $article = Article::query()->whereKey($articleId)->lockForUpdate()->firstOrFail();
            $scan = $article->latestRiskScan()->first();

            if ($scan === null || ! $this->articleRiskScanner->isFresh($article, $scan)) {
                $scan = $this->articleRiskScanner->record($article, 'admin_recheck', $adminId);
            }

            $requiresDowngrade = $scan->status !== 'clean'
                && ! ($scan->status === 'warning' && $scan->is_overridden)
                && $this->workflowStateRequiresRiskGate([
                    'status' => (string) $article->status,
                    'review_status' => (string) $article->review_status,
                    'published_at' => $article->published_at,
                ]);

            if ($requiresDowngrade) {
                $fallback = ArticleWorkflow::normalizeState('draft', 'pending');
                $article->update($fallback);
            }

            return $requiresDowngrade;
        });

        $response = redirect()
            ->route('admin.articles.edit', ['articleId' => $articleId])
            ->with('message', __('admin.articles.quality_scorecard.risk_recheck_success'));

        return $downgraded
            ? $response->withErrors(__('admin.articles.quality_scorecard.risk_recheck_downgraded'))
            : $response;
    }

    /**
     * 为单篇文章启用或重新执行 AI 质检，同时保留历史结果。
     */
    public function recheckAiQuality(Request $request, int $articleId): RedirectResponse
    {
        $article = Article::query()->with('task')->whereKey($articleId)->firstOrFail();

        try {
            $this->articleAiQualityInspectionService->requestManualInspection(
                $article,
                trigger: 'admin_manual',
                auditAdminId: $this->authenticatedAdminId($request),
                rejectWhenOptimizationActive: true,
            );

            return redirect()
                ->route('admin.articles.edit', ['articleId' => $articleId])
                ->with('message', __('admin.articles.ai_quality.recheck_queued'));
        } catch (Throwable $exception) {
            report($exception);

            return back()->withErrors(__('admin.articles.ai_quality.recheck_failed', [
                'message' => $this->aiQualityRequestFailureMessage($exception),
            ]));
        }
    }

    /**
     * 返回当前文章最新 AI 质检的真实执行进度。
     */
    public function aiQualityStatus(int $articleId): JsonResponse
    {
        $article = Article::query()
            ->with('latestAiQualityCheck')
            ->whereKey($articleId)
            ->firstOrFail();

        $snapshot = $this->articleAiQualityProgressPresenter->snapshot($article->latestAiQualityCheck);
        $snapshot['optimization'] = $this->articleAiOptimizationCoordinator->statusForArticle($article);

        return response()
            ->json($snapshot)
            ->header('Cache-Control', 'no-cache, private, no-store, max-age=0, must-revalidate');
    }

    public function retryAiQualityWorkflow(int $articleId): RedirectResponse
    {
        $article = Article::query()->with('latestAiQualityCheck')->whereKey($articleId)->firstOrFail();
        $check = $article->latestAiQualityCheck;
        if (! $check || ! $this->articleAiQualityInspectionService->retryCompletedWorkflow($check)) {
            return back()->withErrors(__('admin.articles.ai_quality.workflow_retry_unavailable'));
        }

        return redirect()
            ->route('admin.articles.edit', ['articleId' => $articleId])
            ->with('message', __('admin.articles.ai_quality.workflow_retry_started'));
    }

    private function aiQualityRequestFailureMessage(Throwable $exception): string
    {
        return match ($exception->getMessage()) {
            'article_ai_optimization_recheck_conflict' => __('admin.articles.ai_quality.recheck_optimization_conflict'),
            'ai_quality_knowledge_unavailable' => __('admin.articles.ai_quality.manual_unavailable_knowledge'),
            'ai_quality_prompt_unavailable' => __('admin.articles.ai_quality.manual_unavailable_prompt'),
            'ai_quality_model_unavailable' => __('admin.articles.ai_quality.manual_unavailable_model'),
            default => __('admin.articles.ai_quality.failed'),
        };
    }

    /**
     * 管理员对允许人工复核的质检结果填写依据并放行。
     */
    public function overrideAiQuality(Request $request, int $articleId): RedirectResponse
    {
        $validated = $request->validate([
            'ai_quality_override_reason' => ['required', 'string', 'min:4', 'max:1000'],
        ]);
        try {
            $this->articleGeoFlowService->overrideAiQuality(
                $articleId,
                (string) $validated['ai_quality_override_reason'],
                $this->authenticatedAdminId($request),
            );

            return redirect()
                ->route('admin.articles.edit', ['articleId' => $articleId])
                ->with('message', __('admin.articles.ai_quality.override_success'));
        } catch (ApiException $exception) {
            if ($exception->getErrorCode() === 'forbidden') {
                $this->aiQualityAuditService->record('article_quality_decision_authorization_denied', [
                    'article_id' => $articleId,
                    'admin_id' => $this->authenticatedAdminId($request),
                    'authorization_result' => 'denied',
                    'reason_code' => (string) ($exception->getDetails()['reason_code'] ?? 'quality_decision_permission_required'),
                ]);
            }

            return back()->withInput()->withErrors($exception->getMessage());
        } catch (ArticleAiQualityGateException $exception) {
            return back()->withInput()->withErrors($exception->getMessage());
        }
    }

    /**
     * 更新文章：保持创建/编辑一致的字段校验与状态归一化。
     */
    public function update(Request $request, int $articleId): RedirectResponse
    {
        $runAiQualityAfterSave = $request->boolean('run_ai_quality_after_save');
        $article = Article::query()->whereKey($articleId)->firstOrFail();
        $payload = $this->validateArticleForm($request, true, (bool) $article->is_ai_generated);
        $canManageProtectedWorkflows = $request->user('admin')?->canManageProtectedWorkflows() === true;

        $workflowState = ArticleWorkflow::normalizeState(
            $payload['status'],
            $payload['review_status'],
            $article->published_at?->format('Y-m-d H:i:s')
        );

        try {
            $adminId = $this->authenticatedAdminId($request);
            $gateRejection = DB::transaction(function () use (&$article, $payload, $workflowState, $adminId, $runAiQualityAfterSave, $canManageProtectedWorkflows): ArticleRiskGateException|ArticleAiQualityGateException|null {
                $lockedArticle = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
                $slug = $payload['title'] === $lockedArticle->title
                    ? $lockedArticle->slug
                    : ArticleWorkflow::generateUniqueSlug($payload['title'], (int) $lockedArticle->id);
                $excerpt = $payload['excerpt'] !== '' ? $payload['excerpt'] : mb_substr(strip_tags($payload['content']), 0, 200, 'UTF-8');
                $currentRiskHash = $this->articleRiskScanner->contentHash([
                    'title' => $lockedArticle->title,
                    'excerpt' => $lockedArticle->excerpt,
                    'content' => $lockedArticle->content,
                    'keywords' => $lockedArticle->keywords,
                    'meta_description' => $lockedArticle->meta_description,
                ]);
                $nextRiskHash = $this->articleRiskScanner->contentHash([
                    'title' => $payload['title'],
                    'excerpt' => $excerpt,
                    'content' => $payload['content'],
                    'keywords' => $payload['keywords'],
                    'meta_description' => $payload['meta_description'],
                ]);
                $contentChanged = ! hash_equals($currentRiskHash, $nextRiskHash);
                $preservePublishedWorkflow = $runAiQualityAfterSave
                    && ! $contentChanged
                    && in_array((string) $lockedArticle->status, ['private', 'published'], true);
                if (! $runAiQualityAfterSave
                    && (string) $lockedArticle->status === 'published'
                    && $contentChanged) {
                    try {
                        $this->articleAiQualityGate->check($lockedArticle, 'published_content_update');
                    } catch (ArticleAiQualityGateException $exception) {
                        $article = $lockedArticle;

                        return $exception;
                    }
                }
                $lockedArticle->fill([
                    'title' => $payload['title'],
                    'slug' => $slug,
                    'content' => $payload['content'],
                    'excerpt' => $excerpt,
                    'keywords' => $payload['keywords'],
                    'meta_description' => $payload['meta_description'],
                    'category_id' => (int) $payload['category_id'],
                    'author_id' => (int) $payload['author_id'],
                    'status' => $preservePublishedWorkflow ? $lockedArticle->status : 'draft',
                    'review_status' => $preservePublishedWorkflow ? $lockedArticle->review_status : 'pending',
                    'published_at' => $preservePublishedWorkflow ? $lockedArticle->published_at : null,
                    'is_hot' => (bool) ($payload['is_hot'] ?? false),
                    'is_featured' => (bool) ($payload['is_featured'] ?? false),
                    'ai_quality_policy_version' => $contentChanged
                        ? max(1, (int) $lockedArticle->ai_quality_policy_version) + 1
                        : max(1, (int) $lockedArticle->ai_quality_policy_version),
                ])->save();

                if (array_key_exists('ai_quality_retrieval_mode_override', $payload)
                    || array_key_exists('ai_quality_knowledge_base_ids', $payload)) {
                    if ((int) $lockedArticle->task_id > 0
                        && ! $canManageProtectedWorkflows
                        && Task::query()
                            ->whereKey((int) $lockedArticle->task_id)
                            ->whereHas('distributionChannels', static fn ($query) => $query->where(
                                'channel_type',
                                DistributionChannel::TYPE_HOSTED_SITE,
                            ))
                            ->exists()) {
                        throw ValidationException::withMessages([
                            'ai_quality_retrieval_mode_override' => '当前账号无权修改托管任务文章的质检方式。',
                        ]);
                    }

                    $beforeConfigurationHash = hash('sha256', json_encode([
                        'mode' => $lockedArticle->ai_quality_retrieval_mode_override,
                        'knowledge_base_ids' => $this->articleAiQualityConfigurationService
                            ->effectiveKnowledgeBaseIds($lockedArticle),
                    ], JSON_THROW_ON_ERROR));
                    $configurationChanged = $this->articleAiQualityConfigurationService->apply(
                        $lockedArticle,
                        array_key_exists('ai_quality_retrieval_mode_override', $payload)
                            ? $payload['ai_quality_retrieval_mode_override']
                            : $lockedArticle->ai_quality_retrieval_mode_override,
                        array_key_exists('ai_quality_knowledge_base_ids', $payload)
                            ? $payload['ai_quality_knowledge_base_ids']
                            : null,
                    );
                    if ($configurationChanged) {
                        $afterConfigurationHash = hash('sha256', json_encode([
                            'mode' => $lockedArticle->fresh()->ai_quality_retrieval_mode_override,
                            'knowledge_base_ids' => $this->articleAiQualityConfigurationService
                                ->effectiveKnowledgeBaseIds($lockedArticle),
                        ], JSON_THROW_ON_ERROR));
                        $this->aiQualityAuditService->record('article_quality_configuration_changed', [
                            'article_id' => (int) $lockedArticle->id,
                            'task_id' => $lockedArticle->task_id ? (int) $lockedArticle->task_id : null,
                            'admin_id' => $adminId,
                            'policy_version' => (int) $lockedArticle->fresh()->ai_quality_policy_version,
                            'before_hash' => $beforeConfigurationHash,
                            'after_hash' => $afterConfigurationHash,
                            'metadata' => [
                                'retrieval_mode' => (string) ($lockedArticle->fresh()->ai_quality_retrieval_mode_override ?? ''),
                            ],
                        ]);
                        $this->articleAiQualityInvalidationService->invalidateArticle(
                            $lockedArticle,
                            'article_quality_configuration_changed',
                        );
                    }
                }

                if ($contentChanged) {
                    $this->articleAiQualityInvalidationService->invalidateArticle(
                        $lockedArticle,
                        'article_content_changed',
                    );
                }

                $latestScan = $lockedArticle->latestRiskScan()->first();
                if (
                    $contentChanged
                    || $latestScan === null
                    || ! $this->articleRiskScanner->isFresh($lockedArticle, $latestScan)
                ) {
                    $this->articleRiskScanner->record($lockedArticle, 'admin_save', $adminId);
                }
                if ($this->requiresRiskGate($payload)) {
                    try {
                        $lockedArticle = $this->transitionGatedArticle($lockedArticle, $workflowState, $payload, 'admin_save', $adminId);
                    } catch (ArticleRiskGateException|ArticleAiQualityGateException $exception) {
                        $article = $lockedArticle;
                        if ($runAiQualityAfterSave && $exception instanceof ArticleAiQualityGateException) {
                            $this->hostedFingerprints->synchronizeLockedArticle($lockedArticle);

                            return null;
                        }

                        return $exception;
                    }
                } else {
                    $lockedArticle->update([
                        'status' => $workflowState['status'],
                        'review_status' => $workflowState['review_status'],
                        'published_at' => $workflowState['published_at'],
                    ]);
                }
                $this->hostedFingerprints->synchronizeLockedArticle($lockedArticle);
                $article = $lockedArticle;

                return null;
            });

            if ($gateRejection instanceof ArticleRiskGateException || $gateRejection instanceof ArticleAiQualityGateException) {
                throw $gateRejection;
            }
            if ($runAiQualityAfterSave) {
                try {
                    $this->articleAiQualityInspectionService->requestManualInspection(
                        $article,
                        trigger: 'admin_manual',
                        auditAdminId: $adminId,
                        requestedWorkflowState: $workflowState,
                        rejectWhenOptimizationActive: true,
                    );
                } catch (Throwable $exception) {
                    report($exception);

                    return redirect()
                        ->route('admin.articles.edit', ['articleId' => $articleId])
                        ->withErrors(__('admin.articles.ai_quality.recheck_failed', [
                            'message' => $this->aiQualityRequestFailureMessage($exception),
                        ]));
                }

                return redirect()
                    ->route('admin.articles.edit', ['articleId' => $articleId])
                    ->with('message', __('admin.articles.ai_quality.recheck_queued'));
            }
            if ($article->status === 'published') {
                $this->distributionOrchestrator->enqueueForArticle($article);
            }
        } catch (ArticleRiskGateException|ArticleAiQualityGateException $e) {
            return redirect()
                ->route('admin.articles.edit', ['articleId' => $articleId])
                ->withInput()
                ->withErrors($e->getMessage());
        } catch (Throwable $e) {
            return back()->withInput()->withErrors(__('admin.article_edit.error.update_exception', ['message' => $e->getMessage()]));
        }

        return redirect()
            ->route('admin.articles.edit', ['articleId' => $articleId])
            ->with('message', __('admin.article_edit.message.update_success'));
    }

    /**
     * @return array<string,mixed>
     */
    private function articleAiQualityRetrievalViewData(Request $request, Article $article): array
    {
        $attachedToTask = $article->task instanceof Task;
        $selectedIds = $this->articleAiQualityConfigurationService->effectiveKnowledgeBaseIds($article);
        $knowledgeBases = KnowledgeBase::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get()
            ->map(static fn (KnowledgeBase $knowledgeBase): array => [
                'id' => (int) $knowledgeBase->id,
                'name' => (string) $knowledgeBase->name,
            ])
            ->all();
        $readiness = $this->aiQualityRetrievalReadinessService->inspect(array_column($knowledgeBases, 'id'));
        $readinessByKnowledgeBase = collect($readiness['knowledge_bases'] ?? [])
            ->mapWithKeys(static fn (array $row): array => [(string) $row['id'] => $row])
            ->all();
        $selectedReadiness = $this->aiQualityRetrievalReadinessService->inspect($selectedIds);
        $override = (string) ($article->ai_quality_retrieval_mode_override ?? '');
        if (! $attachedToTask && ! AiQualityRetrievalMode::isValid($override)) {
            $override = (string) ($selectedReadiness['highest_available_mode'] ?? '');
        }
        $hostedTask = $attachedToTask && $article->task->distributionChannels
            ->contains(static fn (DistributionChannel $channel): bool => $channel->isHostedSite());

        return [
            'attached_to_task' => $attachedToTask,
            'selected_knowledge_base_ids' => $selectedIds,
            'knowledge_bases' => $knowledgeBases,
            'readiness_by_knowledge_base' => $readinessByKnowledgeBase,
            'value' => $override,
            'inherited_mode' => (string) ($article->task?->ai_quality_retrieval_mode ?: AiQualityRetrievalMode::legacyDefault()),
            'can_edit' => ! $hostedTask || $request->user('admin')?->canManageProtectedWorkflows() === true,
        ];
    }

    /**
     * @return array{
     *     task_id: int,
     *     status: string,
     *     review_status: string,
     *     ai_quality_status: string,
     *     author_id: int,
     *     distribution_channel_ids: array<int, int>,
     *     date_from: string,
     *     date_to: string,
     *     search: string,
     *     per_page: int,
     *     trashed: bool
     * }
     */
    private function buildFilters(Request $request): array
    {
        $status = (string) $request->query('status', '');
        $reviewStatus = (string) $request->query('review_status', '');
        $aiQualityStatus = (string) $request->query('ai_quality_status', '');

        if (! in_array($status, ['draft', 'published', 'private'], true)) {
            $status = '';
        }

        if (! in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            $reviewStatus = '';
        }

        if (! in_array($aiQualityStatus, ['passed', 'needs_review', 'blocked', 'pending', 'failed', 'stale', 'disabled'], true)) {
            $aiQualityStatus = '';
        }

        return [
            'task_id' => max(0, (int) $request->query('task_id', 0)),
            'status' => $status,
            'review_status' => $reviewStatus,
            'ai_quality_status' => $aiQualityStatus,
            'author_id' => max(0, (int) $request->query('author_id', 0)),
            'distribution_channel_ids' => $this->extractDistributionChannelIds($request),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
            'search' => trim((string) $request->query('search', '')),
            'per_page' => min(100, max(10, (int) $request->query('per_page', 20) ?: 20)),
            'trashed' => $request->boolean('trashed'),
        ];
    }

    /**
     * @param  array{
     *     task_id: int,
     *     status: string,
     *     review_status: string,
     *     ai_quality_status: string,
     *     author_id: int,
     *     distribution_channel_ids: array<int, int>,
     *     date_from: string,
     *     date_to: string,
     *     search: string,
     *     per_page: int,
     *     trashed: bool
     * }  $filters
     */
    private function queryArticles(array $filters): LengthAwarePaginator
    {
        $query = ($filters['trashed'] ?? false)
            ? Article::onlyTrashed()
            : Article::query();

        $query->with([
            'task:id,name,need_review,ai_quality_enabled',
            'author:id,name',
            'category:id,name',
            'latestAiQualityCheck' => fn ($qualityQuery) => $qualityQuery->select([
                'article_ai_quality_checks.id',
                'article_ai_quality_checks.article_id',
                'article_ai_quality_checks.status',
                'article_ai_quality_checks.decision',
                'article_ai_quality_checks.score',
                'article_ai_quality_checks.is_overridden',
                'article_ai_quality_checks.input_fingerprint',
                'article_ai_quality_checks.finished_at',
            ]),
            'distributions.channel:id,name,domain',
            'syncedRemoteDistributions.channel:id,name,domain',
        ])->withCount([
            'distributions as distribution_total_count',
            'distributions as distribution_synced_count' => fn ($distributionQuery) => $distributionQuery->where('status', 'synced'),
            'distributions as distribution_failed_count' => fn ($distributionQuery) => $distributionQuery->where('status', 'failed'),
        ]);

        if ($filters['trashed'] ?? false) {
            $query->orderByDesc('deleted_at');
        } else {
            $query->orderByDesc('created_at');
        }

        if ($filters['task_id'] > 0) {
            $query->where('task_id', $filters['task_id']);
        }

        if (($filters['trashed'] ?? false) === false && $filters['status'] !== '') {
            $query->where('status', $filters['status']);
        }

        if (($filters['trashed'] ?? false) === false && $filters['review_status'] !== '') {
            $query->where('review_status', $filters['review_status']);
        }

        if (($filters['trashed'] ?? false) === false && $filters['ai_quality_status'] !== '') {
            $qualityStatus = $filters['ai_quality_status'];
            if (in_array($qualityStatus, ['passed', 'needs_review', 'blocked'], true)) {
                $query->whereHas('latestAiQualityCheck', fn ($checkQuery) => $checkQuery
                    ->where('status', 'completed')
                    ->where('decision', $qualityStatus));
            } elseif ($qualityStatus === 'pending') {
                $query->where(function ($enabledQuery): void {
                    $enabledQuery->where('ai_quality_required_at_creation', true)
                        ->orWhereHas('task', fn ($taskQuery) => $taskQuery->where('ai_quality_enabled', true));
                })
                    ->where(function ($pendingQuery): void {
                        $pendingQuery
                            ->whereDoesntHave('latestAiQualityCheck')
                            ->orWhereHas('latestAiQualityCheck', fn ($checkQuery) => $checkQuery
                                ->whereIn('status', ['queued', 'running']));
                    });
            } elseif ($qualityStatus === 'failed') {
                $query->whereHas('latestAiQualityCheck', fn ($checkQuery) => $checkQuery
                    ->where('status', 'failed')
                    ->orWhere('decision', 'error'));
            } elseif ($qualityStatus === 'stale') {
                $query->whereHas('latestAiQualityCheck', fn ($checkQuery) => $checkQuery
                    ->where('status', 'stale'));
            } else {
                $query->where('ai_quality_required_at_creation', false)
                    ->whereDoesntHave('task', fn ($taskQuery) => $taskQuery->where('ai_quality_enabled', true));
            }
        }

        if ($filters['author_id'] > 0) {
            $query->where('author_id', $filters['author_id']);
        }

        if (! empty($filters['distribution_channel_ids'])) {
            $query->whereHas('distributions', function ($distributionQuery) use ($filters): void {
                $distributionQuery->whereIn('distribution_channel_id', $filters['distribution_channel_ids']);
            });
        }

        if ($filters['date_from'] !== '') {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to'] !== '') {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if ($filters['search'] !== '') {
            $query->where(function ($subQuery) use ($filters): void {
                $subQuery->where('title', 'like', '%'.$filters['search'].'%')
                    ->orWhere('content', 'like', '%'.$filters['search'].'%');
            });
        }

        return $query->paginate($filters['per_page'])->withQueryString();
    }

    /**
     * 测试环境缺少 articles 表时，返回空分页并保持页面可渲染。
     */
    private function emptyArticlesPaginator(int $perPage): LengthAwarePaginator
    {
        return new LengthAwarePaginator(
            items: collect(),
            total: 0,
            perPage: $perPage,
            currentPage: max(1, (int) request()->query('page', 1)),
            options: [
                'path' => request()->url(),
                'query' => request()->query(),
            ],
        );
    }

    /**
     * @return array{total: int, published: int, draft: int, pending_review: int, observed: int, today: int}
     */
    private function loadStats(): array
    {
        $baseQuery = Article::query();

        return [
            'total' => (clone $baseQuery)->count(),
            'published' => (clone $baseQuery)->where('status', 'published')->count(),
            'draft' => (clone $baseQuery)->where('status', 'draft')->count(),
            'pending_review' => (clone $baseQuery)->where('review_status', 'pending')->count(),
            'observed' => (clone $baseQuery)->where('view_count', '>', 0)->count(),
            'today' => (clone $baseQuery)->whereDate('created_at', Carbon::today())->count(),
        ];
    }

    /**
     * @return array{trashed_total: int}
     */
    private function loadTrashStats(): array
    {
        return [
            'trashed_total' => Article::onlyTrashed()->count(),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string, domain: string, status: string}>
     */
    private function loadDistributionChannelOptions(): array
    {
        try {
            return DistributionChannel::query()
                ->select(['id', 'name', 'domain', 'status'])
                ->orderByRaw("CASE WHEN status = 'active' THEN 0 ELSE 1 END")
                ->orderBy('name')
                ->get()
                ->map(fn (DistributionChannel $channel): array => [
                    'id' => (int) $channel->id,
                    'name' => (string) $channel->name,
                    'domain' => (string) ($channel->domain ?? ''),
                    'status' => (string) ($channel->status ?? ''),
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @return array<int, int>
     */
    private function extractDistributionChannelIds(Request $request): array
    {
        $rawIds = $request->query('distribution_channel_ids', []);
        if (! is_array($rawIds)) {
            $rawIds = [$rawIds];
        }

        $legacyId = (int) $request->query('distribution_channel_id', 0);
        if ($legacyId > 0) {
            $rawIds[] = $legacyId;
        }

        return collect($rawIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function loadTaskOptions(): array
    {
        try {
            return Task::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Task $task): array => [
                    'id' => (int) $task->id,
                    'name' => (string) $task->name,
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private function loadAuthorOptions(): array
    {
        try {
            return Author::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Author $author): array => [
                    'id' => (int) $author->id,
                    'name' => (string) $author->name,
                ])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * @return array{
     *     categories: array<int, array{id: int, name: string}>,
     *     authors: array<int, array{id: int, name: string}>,
     *     title_libraries: array<int, array{id: int, name: string, count: int}>,
     *     knowledge_bases: array<int, array{id: int, name: string}>,
     *     content_prompts: array<int, array{id: int, name: string}>,
     *     ai_models: array<int, array{id: int, name: string}>
     * }
     */
    private function loadFormOptions(bool $includeAssistantOptions, ?Admin $actor = null): array
    {
        $categories = [];
        $authors = $this->loadAuthorOptions();
        $titleLibraries = [];
        $knowledgeBases = [];
        $contentPrompts = [];
        $aiModels = [];

        try {
            $categories = Category::query()
                ->select(['id', 'name'])
                ->orderBy('name')
                ->get()
                ->map(fn (Category $category): array => [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                ])
                ->all();
        } catch (QueryException) {
            $categories = [];
        }

        if (! $includeAssistantOptions) {
            return [
                'categories' => $categories,
                'authors' => $authors,
                'title_libraries' => [],
                'knowledge_bases' => [],
                'content_prompts' => [],
                'ai_models' => [],
            ];
        }

        try {
            $titleLibraries = TitleLibrary::query()
                ->select(['id', 'name'])
                ->withCount('titles')
                ->orderBy('name')
                ->get()
                ->map(fn (TitleLibrary $library): array => [
                    'id' => (int) $library->id,
                    'name' => (string) $library->name,
                    'count' => (int) $library->titles_count,
                ])
                ->all();
        } catch (QueryException) {
            $titleLibraries = [];
        }

        try {
            $knowledgeBases = KnowledgeBase::query()
                ->select(['id', 'name'])
                ->whereHas('chunks')
                ->orderBy('name')
                ->get()
                ->map(fn (KnowledgeBase $knowledgeBase): array => [
                    'id' => (int) $knowledgeBase->id,
                    'name' => (string) $knowledgeBase->name,
                ])
                ->all();
        } catch (QueryException) {
            $knowledgeBases = [];
        }

        try {
            $contentPrompts = Prompt::query()
                ->select(['id', 'name'])
                ->where('type', 'content')
                ->orderBy('name')
                ->get()
                ->map(fn (Prompt $prompt): array => [
                    'id' => (int) $prompt->id,
                    'name' => (string) $prompt->name,
                ])
                ->all();
        } catch (QueryException) {
            $contentPrompts = [];
        }

        try {
            $aiModels = $this->adminAiModelAccessResolver
                ->usableQuery($actor)
                ->where(function ($query): void {
                    $query->whereNull('model_type')
                        ->orWhere('model_type', '')
                        ->orWhere('model_type', 'chat');
                })
                ->orderBy('name')
                ->get()
                ->map(fn (AiModel $model): array => [
                    'id' => (int) $model->id,
                    'name' => (string) $model->name,
                ])
                ->all();
        } catch (QueryException) {
            $aiModels = [];
        }

        return [
            'categories' => $categories,
            'authors' => $authors,
            'title_libraries' => $titleLibraries,
            'knowledge_bases' => $knowledgeBases,
            'content_prompts' => $contentPrompts,
            'ai_models' => $aiModels,
        ];
    }

    /**
     * @return array{
     *     title: string,
     *     excerpt: string,
     *     content: string,
     *     keywords: string,
     *     meta_description: string,
     *     category_id: int,
     *     author_id: int,
     *     status: string,
     *     review_status: string,
     *     risk_override_reason: ?string,
     *     is_hot: bool,
     *     is_featured: bool,
     *     source_title_id: ?int,
     *     is_ai_generated: bool
     * }
     */
    private function validateArticleForm(Request $request, bool $isEdit, bool $cleanAiGenerated = false): array
    {
        $keyPrefix = $isEdit ? 'admin.article_edit.error' : 'admin.article_create.error';

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:500'],
            'excerpt' => ['nullable', 'string', 'max:'.ArticleRiskScanner::MAX_EXCERPT_CHARACTERS],
            'content' => ['required', 'string', 'max:'.ArticleRiskScanner::MAX_CONTENT_CHARACTERS],
            'keywords' => ['nullable', 'string', 'max:500'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'category_id' => ['required', 'integer', 'min:1'],
            'author_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', 'in:draft,published,private'],
            'review_status' => ['required', 'string', 'in:pending,approved,rejected,auto_approved'],
            'risk_override_reason' => ['nullable', 'string', 'max:1000'],
            'is_hot' => ['nullable', 'boolean'],
            'is_featured' => ['nullable', 'boolean'],
            'source_title_id' => ['nullable', 'integer', 'min:1', 'exists:titles,id'],
            'is_ai_generated' => ['nullable', 'boolean'],
            'ai_quality_retrieval_mode_override' => ['nullable', 'string', 'in:'.implode(',', AiQualityRetrievalMode::values())],
            'ai_quality_knowledge_base_ids' => ['nullable', 'array', 'max:5'],
            'ai_quality_knowledge_base_ids.*' => ['integer', 'min:1', 'distinct', 'exists:knowledge_bases,id'],
        ], [
            'title.required' => __($keyPrefix.'.title_required'),
            'content.required' => __($keyPrefix.'.content_required'),
            'category_id.required' => __($keyPrefix.'.category_required'),
            'category_id.min' => __($keyPrefix.'.category_required'),
            'author_id.required' => __($keyPrefix.'.author_required'),
            'author_id.min' => __($keyPrefix.'.author_required'),
        ]);

        if ($cleanAiGenerated || (bool) ($validated['is_ai_generated'] ?? false)) {
            $validated = $this->articleCitationMarkerCleaner->cleanArticleFields($validated);
            if (trim((string) $validated['content']) === '') {
                throw ValidationException::withMessages([
                    'content' => __($keyPrefix.'.content_required'),
                ]);
            }
        }

        return $validated;
    }

    /**
     * @return array{state:string,status:string,match_count:int,matches:array<int,array<string,mixed>>,is_overridden:bool,override_reason:string,scanned_at:string}|null
     */
    private function riskScanViewData(Article $article): ?array
    {
        $scan = $article->latestRiskScan()->first();
        if ($scan === null) {
            return null;
        }

        return [
            'state' => $this->articleRiskScanner->isFresh($article, $scan) ? 'fresh' : 'stale',
            'status' => (string) $scan->status,
            'match_count' => (int) $scan->match_count,
            'matches' => is_array($scan->matches) ? $scan->matches : [],
            'is_overridden' => (bool) $scan->is_overridden,
            'override_reason' => (string) ($scan->override_reason ?? ''),
            'scanned_at' => (string) ($scan->scanned_at?->format('Y-m-d H:i:s') ?? ''),
        ];
    }

    private function validateRiskOverrideReason(Request $request): ?string
    {
        $validated = $request->validate([
            'risk_override_reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $reason = trim((string) ($validated['risk_override_reason'] ?? ''));

        return $reason === '' ? null : $reason;
    }

    /** @param array<string, mixed> $payload */
    private function requiresRiskGate(array $payload): bool
    {
        return $payload['status'] === 'published'
            || in_array($payload['review_status'], ['approved', 'auto_approved'], true);
    }

    /**
     * @param  array{status: string, review_status: string, published_at: mixed}  $workflowState
     * @param  array<string, mixed>  $payload
     */
    private function transitionGatedArticle(
        Article $article,
        array $workflowState,
        array $payload,
        string $trigger,
        int $adminId,
    ): Article {
        $allowsOverride = $payload['review_status'] === 'approved';

        return $this->articleWorkflowTransitionService->transition(
            $article,
            $workflowState,
            $trigger,
            $allowsOverride ? $adminId : null,
            $allowsOverride ? ($payload['risk_override_reason'] ?? null) : null,
            $allowsOverride,
        );
    }

    private function authenticatedAdminId(Request $request): int
    {
        return (int) $request->user('admin')->getAuthIdentifier();
    }

    /** @param array{status: string, review_status: string, published_at: mixed} $workflowState */
    private function workflowStateRequiresRiskGate(array $workflowState): bool
    {
        return in_array($workflowState['status'], ['published', 'private'], true)
            || in_array($workflowState['review_status'], ['approved', 'auto_approved'], true);
    }

    /**
     * @return array<int, int>
     */
    private function extractArticleIds(Request $request): array
    {
        return collect($request->input('article_ids', []))
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();
    }

    /**
     * @param  array<int, int>  $articleIds
     */
    private function handleBatchUpdateStatus(Request $request, array $articleIds, ?string $riskOverrideReason): RedirectResponse
    {
        $newStatus = (string) $request->input('new_status', '');
        if (! in_array($newStatus, ['draft', 'published', 'private'], true)) {
            return back()->withErrors(__('admin.articles.message.select_status'));
        }

        $articles = Article::query()
            ->select(['id', 'review_status', 'published_at'])
            ->whereIn('id', $articleIds)
            ->get();
        $adminId = $this->authenticatedAdminId($request);
        $rejectedCount = 0;
        $allowedCount = 0;
        $rejectedWorkflowState = ArticleWorkflow::normalizeState('draft', 'pending');

        foreach ($articles as $article) {
            $workflowState = ArticleWorkflow::normalizeState(
                $newStatus,
                (string) ($article->review_status ?? 'pending'),
                $article->published_at?->format('Y-m-d H:i:s')
            );

            try {
                if (in_array($workflowState['status'], ['published', 'private'], true)) {
                    $allowsOverride = $workflowState['review_status'] === 'approved';
                    $article = $this->articleWorkflowTransitionService->transition(
                        $article,
                        $workflowState,
                        'admin_batch_status',
                        $allowsOverride ? $adminId : null,
                        $allowsOverride ? $riskOverrideReason : null,
                        $allowsOverride,
                        $rejectedWorkflowState,
                    );
                } else {
                    Article::query()->whereKey((int) $article->id)->update([
                        'status' => $workflowState['status'],
                        'review_status' => $workflowState['review_status'],
                        'published_at' => $workflowState['published_at'],
                    ]);
                }
            } catch (ArticleRiskGateException|ArticleAiQualityGateException) {
                $rejectedCount++;

                continue;
            }

            if ($workflowState['status'] === 'published') {
                $this->distributionOrchestrator->enqueueForArticle($article);
            }
            $allowedCount++;
        }

        $response = back()->with('message', __('admin.articles.message.batch_status_updated', ['count' => $allowedCount]));

        return $rejectedCount > 0
            ? $response->withErrors("Risk gate rejected {$rejectedCount} article(s).")
            : $response;
    }

    /**
     * @param  array<int, int>  $articleIds
     */
    private function handleBatchUpdateReview(Request $request, array $articleIds, ?string $riskOverrideReason): RedirectResponse
    {
        $reviewStatus = (string) $request->input('review_status', '');
        if (! in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            return back()->withErrors(__('admin.articles.message.select_review'));
        }

        $articles = Article::query()
            ->with(['task:id,need_review'])
            ->select(['id', 'status', 'review_status', 'published_at', 'task_id'])
            ->whereIn('id', $articleIds)
            ->get();
        $adminId = $this->authenticatedAdminId($request);
        $rejectedCount = 0;
        $allowedCount = 0;
        $rejectedWorkflowState = ArticleWorkflow::normalizeState('draft', 'pending');

        foreach ($articles as $article) {
            $desiredStatus = (string) ($article->status ?? 'draft');
            $needsReview = (int) ($article->task->need_review ?? 0);
            if (in_array($reviewStatus, ['approved', 'auto_approved'], true) && ($reviewStatus === 'auto_approved' || $needsReview === 0)) {
                $desiredStatus = 'published';
            }

            $workflowState = ArticleWorkflow::normalizeState(
                $desiredStatus,
                $reviewStatus,
                $article->published_at?->format('Y-m-d H:i:s')
            );

            try {
                if ($this->workflowStateRequiresRiskGate($workflowState)) {
                    $allowsOverride = $workflowState['review_status'] === 'approved';
                    $article = $this->articleWorkflowTransitionService->transition(
                        $article,
                        $workflowState,
                        'admin_batch_review',
                        $allowsOverride ? $adminId : null,
                        $allowsOverride ? $riskOverrideReason : null,
                        $allowsOverride,
                        $rejectedWorkflowState,
                    );
                } else {
                    Article::query()->whereKey((int) $article->id)->update([
                        'status' => $workflowState['status'],
                        'review_status' => $workflowState['review_status'],
                        'published_at' => $workflowState['published_at'],
                    ]);
                }
            } catch (ArticleRiskGateException|ArticleAiQualityGateException) {
                $rejectedCount++;

                continue;
            }

            if ($workflowState['status'] === 'published') {
                $this->distributionOrchestrator->enqueueForArticle($article);
            }
            $allowedCount++;
        }

        $response = back()->with('message', __('admin.articles.message.batch_review_updated', ['count' => $allowedCount]));

        return $rejectedCount > 0
            ? $response->withErrors("Risk gate rejected {$rejectedCount} article(s).")
            : $response;
    }

    /**
     * @param  array<int, int>  $articleIds
     */
    private function handleBatchDelete(array $articleIds, int $adminId): RedirectResponse
    {
        DB::transaction(function () use ($articleIds, $adminId): void {
            $articles = Article::query()
                ->whereIn('id', $articleIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            foreach ($articles as $article) {
                $article->forceFill([
                    'ai_quality_policy_version' => max(1, (int) $article->ai_quality_policy_version) + 1,
                ])->save();
                $this->aiQualityAuditService->record('article_deleted', [
                    'article_id' => (int) $article->id,
                    'task_id' => $article->task_id ? (int) $article->task_id : null,
                    'admin_id' => $adminId,
                    'policy_version' => (int) $article->ai_quality_policy_version,
                    'reason_code' => 'article_soft_deleted',
                ]);
                $article->delete();
                $this->articleAiQualityInvalidationService->cancelArticle($article);
            }
        });

        return back()->with('message', __('admin.articles.message.batch_delete_success', ['count' => count($articleIds)]));
    }

    /**
     * 前端批量栏与快捷动作使用的文案字典。
     *
     * @return array<string, string>
     */
    private function articlesI18n(): array
    {
        return [
            'selectArticles' => __('admin.articles.message.select_articles'),
            'selectAction' => __('admin.articles.message.select_action'),
            'selectStatus' => __('admin.articles.message.select_status'),
            'selectReview' => __('admin.articles.message.select_review'),
            'confirmDeleteSelected' => __('admin.articles.confirm.delete_selected', ['count' => '__COUNT__']),
            'reviewApproved' => __('admin.articles.review.approved'),
            'reviewRejected' => __('admin.articles.review.rejected'),
            'confirmQuickReview' => __('admin.articles.confirm.quick_review', ['action' => '__ACTION__']),
            'confirmDelete' => __('admin.articles.confirm.delete'),
        ];
    }

    /**
     * 垃圾箱视图脚本使用的确认与操作文案。
     *
     * @return array<string, string>
     */
    private function trashI18n(): array
    {
        return [
            'alertSelect' => __('admin.articles.trash.alert_select'),
            'confirmBatchRestore' => __('admin.articles.trash.confirm_batch_restore', ['count' => '__COUNT__']),
            'confirmBatchForceDelete' => __('admin.articles.trash.confirm_batch_delete', ['count' => '__COUNT__']),
            'confirmEmpty' => __('admin.articles.trash.confirm_empty'),
        ];
    }

    /**
     * 批量操作表单提交目标 URL（普通列表与垃圾箱不同）。
     *
     * @return array<string, string>
     */
    private function articleBatchRoutes(bool $isTrashView): array
    {
        if ($isTrashView) {
            return [
                'batch_restore' => AdminWeb::routePath('admin.articles.batch.restore'),
                'batch_force_delete' => AdminWeb::routePath('admin.articles.batch.force-delete'),
            ];
        }

        return [
            'batch_update_status' => AdminWeb::routePath('admin.articles.batch.update-status'),
            'batch_update_review' => AdminWeb::routePath('admin.articles.batch.update-review'),
            'delete_articles' => AdminWeb::routePath('admin.articles.batch.delete'),
        ];
    }
}
