<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ApiException;
use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\ArticleReview;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Support\Admin\ArticleAiQualityProgressPresenter;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Support\Facades\DB;

class ArticleGeoFlowService
{
    public function __construct(
        private readonly ArticleRiskScanner $articleRiskScanner,
        private readonly ArticleWorkflowTransitionService $articleWorkflowTransitionService,
        private readonly ArticleAiQualityPolicyResolver $articleAiQualityPolicyResolver,
        private readonly ArticleAiQualityInspectionService $articleAiQualityInspectionService,
        private readonly ArticleAiQualityInvalidationService $articleAiQualityInvalidationService,
        private readonly ArticleAiQualityGate $articleAiQualityGate,
        private readonly ArticleCitationMarkerCleaner $articleCitationMarkerCleaner,
        private readonly ArticleAiQualityProgressPresenter $articleAiQualityProgressPresenter,
        private readonly ArticleAiOptimizationCoordinator $articleAiOptimizationCoordinator,
    ) {}

    public function listArticles(int $page = 1, int $perPage = 20, array $filters = []): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $query = Article::query();

        $qualityFilter = trim((string) ($filters['ai_quality_status'] ?? ''));
        if ($qualityFilter !== '') {
            $this->applyAiQualityFilter($query, $qualityFilter);
        }

        foreach (['task_id', 'status', 'review_status', 'author_id'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        if (! empty($filters['search'])) {
            $s = '%'.$filters['search'].'%';
            $query->where(function ($q) use ($s) {
                $q->where('title', 'like', $s)->orWhere('content', 'like', $s);
            });
        }

        $total = (clone $query)->count();

        $items = $query
            ->with(['latestAiQualityCheck', 'task:id,ai_quality_enabled'])
            ->orderByDesc('created_at')
            ->forPage($page, $perPage)
            ->get([
                'id', 'title', 'slug', 'status', 'review_status',
                'task_id', 'author_id', 'category_id', 'published_at',
                'ai_quality_required_at_creation',
                'created_at', 'updated_at',
            ])
            ->map(fn (Article $a) => array_replace($a->getAttributes(), [
                'ai_quality' => $this->aiQualitySummary($a),
            ]))
            ->all();

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function createArticle(array $data, int $auditAdminId): array
    {
        $normalized = $this->normalizeCreateInput($data);
        $workflowState = ArticleWorkflow::normalizeState(
            $normalized['status'],
            $normalized['review_status']
        );
        $slug = $normalized['slug'] ?: ArticleWorkflow::generateUniqueSlug($normalized['title']);
        $excerpt = $normalized['excerpt'] !== '' ? $normalized['excerpt'] : mb_substr(strip_tags($normalized['content']), 0, 200);

        $fallbackWorkflowState = ArticleWorkflow::normalizeState('draft', 'pending');
        $creation = DB::transaction(function () use (
            $normalized,
            $slug,
            $excerpt,
            $fallbackWorkflowState,
            $auditAdminId,
            $workflowState,
        ): array {
            $this->lockActiveTaskReference($normalized['task_id']);
            $article = Article::query()->create([
                'title' => $normalized['title'],
                'slug' => $slug,
                'content' => $normalized['content'],
                'excerpt' => $excerpt,
                'keywords' => $normalized['keywords'],
                'meta_description' => $normalized['meta_description'],
                'category_id' => $normalized['category_id'],
                'author_id' => $normalized['author_id'],
                'task_id' => $normalized['task_id'],
                'status' => $fallbackWorkflowState['status'],
                'review_status' => $fallbackWorkflowState['review_status'],
                'is_ai_generated' => $normalized['is_ai_generated'],
                'published_at' => $fallbackWorkflowState['published_at'],
            ]);
            $qualityPolicy = $this->articleAiQualityPolicyResolver->resolve($article);
            $article->forceFill([
                'ai_quality_required_at_creation' => (bool) ($qualityPolicy['required'] ?? false),
                'ai_quality_policy_snapshot' => $this->articleAiQualityPolicyResolver->snapshot($qualityPolicy),
            ])->save();

            $this->articleRiskScanner->record($article, 'api_save', $auditAdminId);

            $gateRejection = null;
            if (
                $workflowState['status'] === 'published'
                || in_array($workflowState['review_status'], ['approved', 'auto_approved'], true)
            ) {
                $isAutomaticApproval = $workflowState['review_status'] === 'auto_approved';

                try {
                    $this->articleWorkflowTransitionService->transition(
                        $article,
                        $workflowState,
                        'api_create',
                        $auditAdminId,
                        $isAutomaticApproval ? null : $normalized['risk_override_reason'],
                        ! $isAutomaticApproval,
                        $fallbackWorkflowState,
                    );
                } catch (ArticleRiskGateException|ArticleAiQualityGateException $exception) {
                    $gateRejection = $exception;
                }
            }

            return ['article' => $article, 'gate_rejection' => $gateRejection];
        });

        $article = $creation['article'];
        if ($creation['gate_rejection'] instanceof ArticleRiskGateException) {
            throw $this->riskBlockedException($article, $creation['gate_rejection']);
        }
        if ($creation['gate_rejection'] instanceof ArticleAiQualityGateException) {
            throw $this->qualityBlockedException($article, $creation['gate_rejection']);
        }
        if ($article->ai_quality_required_at_creation) {
            $this->articleAiQualityInspectionService->createOrReuse($article, trigger: 'api_create');
        }

        return $this->getArticle((int) $article->id);
    }

    public function getArticle(int $articleId): array
    {
        $article = Article::query()
            ->with([
                'task:id,name,ai_quality_enabled',
                'author:id,name',
                'category:id,name',
                'latestAiQualityCheck.prompt:id,name',
                'latestAiQualityCheck.aiModel:id,name',
            ])
            ->find($articleId);
        if (! $article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        $images = ArticleImage::query()
            ->where('article_id', $articleId)
            ->with('image:id,file_path,original_name')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn (ArticleImage $ai) => [
                'id' => $ai->id,
                'image_id' => $ai->image_id,
                'position' => $ai->position,
                'file_path' => $ai->image->file_path ?? null,
                'original_name' => $ai->image->original_name ?? null,
            ])
            ->all();

        return [
            'id' => (int) $article->id,
            'title' => $article->title,
            'slug' => $article->slug,
            'content' => $article->content,
            'excerpt' => $article->excerpt,
            'keywords' => $article->keywords,
            'meta_description' => $article->meta_description,
            'status' => $article->status,
            'review_status' => $article->review_status,
            'task_id' => $this->nullableInt($article->task_id),
            'task_name' => $article->task->name ?? null,
            'author_id' => $this->nullableInt($article->author_id),
            'author_name' => $article->author->name ?? null,
            'category_id' => $this->nullableInt($article->category_id),
            'category_name' => $article->category->name ?? null,
            'published_at' => $article->published_at?->format('Y-m-d H:i:s'),
            'created_at' => $article->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $article->updated_at?->format('Y-m-d H:i:s'),
            'images' => $images,
            'ai_quality' => $this->aiQualityDetail($article),
        ];
    }

    /** @return array<string, mixed> */
    public function getAiQualityStatus(int $articleId): array
    {
        $article = Article::query()->with('latestAiQualityCheck')->find($articleId);
        if (! $article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        $snapshot = $this->articleAiQualityProgressPresenter->snapshot($article->latestAiQualityCheck);
        $snapshot['optimization'] = $this->articleAiOptimizationCoordinator->statusForArticle($article);

        return $snapshot;
    }

    public function recheckAiQuality(int $articleId, int $auditAdminId, int $apiTokenId): array
    {
        $article = Article::query()->with('task')->whereKey($articleId)->first();
        if (! $article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        try {
            $this->articleAiQualityInspectionService->requestManualInspection(
                $article,
                trigger: 'api_manual',
                auditAdminId: $auditAdminId,
                apiTokenId: $apiTokenId,
                rejectWhenOptimizationActive: true,
            );
        } catch (ArticleAiOptimizationException $exception) {
            throw new ApiException($exception->errorCode(), 'AI 内容优化正在进行，请先取消优化再重新质检', 409, [
                'article_id' => $articleId,
                'can_cancel_optimization' => true,
            ]);
        } catch (\Throwable $exception) {
            report($exception);

            throw new ApiException('article_ai_quality_failed', 'AI 质检无法重新排队', 409, [
                'article_id' => $articleId,
            ]);
        }

        return $this->getArticle($articleId);
    }

    public function overrideAiQuality(int $articleId, string $reason, int $auditAdminId): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 4 || mb_strlen($reason, 'UTF-8') > 1000) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => ['reason' => '人工放行依据需要填写 4 至 1000 个字符'],
            ]);
        }

        $article = Article::query()->whereKey($articleId)->first();
        if (! $article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        try {
            $this->articleAiQualityGate->check(
                $article,
                'api_ai_quality_override',
                $auditAdminId,
                $reason,
                true,
            );
        } catch (ArticleAiQualityGateException $exception) {
            throw $this->qualityBlockedException($article, $exception);
        }

        return $this->getArticle($articleId);
    }

    public function updateArticle(int $articleId, array $data, int $auditAdminId): array
    {
        $existing = $this->getArticleRecord($articleId);
        $normalized = $this->normalizeUpdateInput($data, $existing);
        if (empty($normalized)) {
            throw new ApiException('validation_failed', '没有可更新的字段', 422);
        }

        foreach ($normalized as $field => $value) {
            if ((string) ($existing[$field] ?? '') === (string) ($value ?? '')) {
                unset($normalized[$field]);
            }
        }

        if ($normalized === []) {
            return $this->getArticle($articleId);
        }

        $riskRelevantFields = ['title', 'excerpt', 'content', 'keywords', 'meta_description'];
        $qualityRelevantFields = [...$riskRelevantFields, 'task_id'];
        $hasQualityRelevantChanges = array_intersect($qualityRelevantFields, array_keys($normalized)) !== [];
        $hasRiskRelevantChanges = array_intersect($riskRelevantFields, array_keys($normalized)) !== [];

        if ($hasRiskRelevantChanges && (string) ($existing['status'] ?? '') === 'published') {
            $article = Article::query()->whereKey($articleId)->firstOrFail();
            try {
                $this->articleAiQualityGate->check($article, 'api_published_content_update');
            } catch (ArticleAiQualityGateException $exception) {
                throw $this->qualityBlockedException($article, $exception);
            }
        }

        if ($hasRiskRelevantChanges) {
            $fallbackWorkflowState = ArticleWorkflow::normalizeState('draft', 'pending');
            $normalized = array_merge($normalized, $fallbackWorkflowState);
        }

        $normalized['updated_at'] = now();

        DB::transaction(function () use ($articleId, $normalized, $auditAdminId, $hasRiskRelevantChanges): void {
            Article::query()
                ->whereKey($articleId)
                ->lockForUpdate()
                ->firstOrFail(['id']);
            if (array_key_exists('task_id', $normalized)) {
                $this->lockActiveTaskReference($normalized['task_id']);
            }
            Article::query()->whereKey($articleId)->update($normalized);
            if (array_key_exists('task_id', $normalized)) {
                $article = Article::query()->findOrFail($articleId);
                $qualityPolicy = $this->articleAiQualityPolicyResolver->resolve($article);
                $article->forceFill([
                    'ai_quality_required_at_creation' => (bool) ($qualityPolicy['required'] ?? false),
                    'ai_quality_policy_snapshot' => $this->articleAiQualityPolicyResolver->snapshot($qualityPolicy),
                ])->save();
            }
            if ($hasRiskRelevantChanges) {
                $article = Article::query()->findOrFail($articleId);
                $this->articleRiskScanner->record($article, 'api_save', $auditAdminId);
            }
        });

        if ($hasQualityRelevantChanges) {
            $this->articleAiQualityInvalidationService->invalidateArticle($articleId, '文章内容或任务关联已更新');
        }

        return $this->getArticle($articleId);
    }

    public function reviewArticle(
        int $articleId,
        string $reviewStatus,
        string $reviewNote,
        string $riskOverrideReason,
        int $auditAdminId,
    ): array {
        $article = $this->getArticleRecord($articleId);
        $reviewStatus = trim($reviewStatus);
        $riskOverrideReason = trim($riskOverrideReason);
        if (! in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            throw new ApiException('validation_failed', '审核状态无效', 422, [
                'field_errors' => ['review_status' => '审核状态无效'],
            ]);
        }
        if (mb_strlen($riskOverrideReason, 'UTF-8') > 1000) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => ['risk_override_reason' => '风险放行原因不能超过 1000 个字符'],
            ]);
        }

        $desiredStatus = $article['status'] ?? 'draft';
        if (in_array($reviewStatus, ['approved', 'auto_approved'], true)) {
            $taskNeedReview = 1;
            if (! empty($article['task_id'])) {
                $taskNeedReview = (int) (Task::query()
                    ->whereKey((int) $article['task_id'])
                    ->value('need_review') ?? 1);
            }

            if ($reviewStatus === 'auto_approved' || $taskNeedReview === 0) {
                $desiredStatus = 'published';
            }
        }

        $workflowState = ArticleWorkflow::normalizeState($desiredStatus, $reviewStatus, $article['published_at'] ?? null);

        if (in_array($reviewStatus, ['approved', 'auto_approved'], true)) {
            $fallbackWorkflowState = ArticleWorkflow::normalizeState('draft', 'pending');
            $isAutomaticApproval = $reviewStatus === 'auto_approved';

            $gateRejection = DB::transaction(function () use (
                $articleId,
                $workflowState,
                $auditAdminId,
                $isAutomaticApproval,
                $reviewNote,
                $riskOverrideReason,
                $fallbackWorkflowState,
                $reviewStatus,
            ): ArticleRiskGateException|ArticleAiQualityGateException|null {
                try {
                    $this->articleWorkflowTransitionService->transition(
                        Article::query()->findOrFail($articleId),
                        $workflowState,
                        'api_review',
                        $auditAdminId,
                        $isAutomaticApproval ? null : $riskOverrideReason,
                        ! $isAutomaticApproval,
                        $fallbackWorkflowState,
                    );
                } catch (ArticleRiskGateException|ArticleAiQualityGateException $exception) {
                    return $exception;
                }

                ArticleReview::query()->create([
                    'article_id' => $articleId,
                    'admin_id' => $auditAdminId,
                    'review_status' => $reviewStatus,
                    'review_note' => trim($reviewNote),
                ]);

                return null;
            });

            if ($gateRejection instanceof ArticleRiskGateException) {
                throw $this->riskBlockedException(Article::query()->findOrFail($articleId), $gateRejection);
            }
            if ($gateRejection instanceof ArticleAiQualityGateException) {
                throw $this->qualityBlockedException(Article::query()->findOrFail($articleId), $gateRejection);
            }
        } else {
            DB::transaction(function () use ($articleId, $workflowState, $reviewStatus, $reviewNote, $auditAdminId) {
                Article::query()->whereKey($articleId)->update([
                    'status' => $workflowState['status'],
                    'review_status' => $workflowState['review_status'],
                    'published_at' => $workflowState['published_at'],
                    'updated_at' => now(),
                ]);

                ArticleReview::query()->create([
                    'article_id' => $articleId,
                    'admin_id' => $auditAdminId,
                    'review_status' => $reviewStatus,
                    'review_note' => trim($reviewNote),
                ]);
            });
        }

        return $this->getArticle($articleId);
    }

    public function publishArticle(int $articleId, int $auditAdminId): array
    {
        $article = Article::query()->whereKey($articleId)->first();
        if ($article === null) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        $reviewStatus = (string) ($article->review_status ?? 'pending');
        if (! in_array($reviewStatus, ['approved', 'auto_approved'], true)) {
            throw new ApiException('article_not_publishable', '当前文章状态不允许直接发布', 409);
        }

        try {
            $this->articleWorkflowTransitionService->transition(
                $article,
                ArticleWorkflow::normalizeState('published', $reviewStatus, $article->published_at),
                'api_publish',
                $auditAdminId,
                null,
                $reviewStatus === 'approved',
                ArticleWorkflow::normalizeState('draft', 'pending'),
                static function (Article $lockedArticle) use ($reviewStatus): void {
                    if ((string) $lockedArticle->review_status !== $reviewStatus) {
                        throw new ApiException('article_not_publishable', '当前文章状态不允许直接发布', 409);
                    }
                },
            );
        } catch (ArticleRiskGateException $exception) {
            throw $this->riskBlockedException(Article::query()->findOrFail($articleId), $exception);
        } catch (ArticleAiQualityGateException $exception) {
            throw $this->qualityBlockedException(Article::query()->findOrFail($articleId), $exception);
        }

        return $this->getArticle($articleId);
    }

    public function trashArticle(int $articleId): array
    {
        $article = Article::query()->whereKey($articleId)->first();
        if (! $article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        $article->delete();
        $this->articleAiQualityInvalidationService->cancelArticle($article);

        return [
            'id' => $articleId,
            'trashed' => true,
        ];
    }

    private function normalizeCreateInput(array $data): array
    {
        $title = trim((string) ($data['title'] ?? ''));
        $content = trim((string) ($data['content'] ?? ''));
        $excerpt = trim((string) ($data['excerpt'] ?? ''));
        $keywords = trim((string) ($data['keywords'] ?? ''));
        $metaDescription = trim((string) ($data['meta_description'] ?? ''));
        $riskOverrideReason = trim((string) ($data['risk_override_reason'] ?? ''));
        $errors = [];
        if ($title === '') {
            $errors['title'] = '文章标题不能为空';
        } elseif (mb_strlen($title, 'UTF-8') > 255) {
            $errors['title'] = '文章标题不能超过 255 个字符';
        }
        if ($content === '') {
            $errors['content'] = '文章内容不能为空';
        } elseif (mb_strlen($content, 'UTF-8') > ArticleRiskScanner::MAX_CONTENT_CHARACTERS) {
            $errors['content'] = '文章内容超过扫描长度上限';
        }
        if (mb_strlen($excerpt, 'UTF-8') > ArticleRiskScanner::MAX_EXCERPT_CHARACTERS) {
            $errors['excerpt'] = '文章摘要超过扫描长度上限';
        }
        if (mb_strlen($keywords, 'UTF-8') > 500) {
            $errors['keywords'] = '关键词不能超过 500 个字符';
        }
        if (mb_strlen($metaDescription, 'UTF-8') > 500) {
            $errors['meta_description'] = 'Meta 描述不能超过 500 个字符';
        }
        if (mb_strlen($riskOverrideReason, 'UTF-8') > 1000) {
            $errors['risk_override_reason'] = '风险放行原因不能超过 1000 个字符';
        }
        if ($errors !== []) {
            throw new ApiException('validation_failed', '参数校验失败', 422, ['field_errors' => $errors]);
        }

        $normalized = [
            'title' => $title,
            'content' => $content,
            'excerpt' => $excerpt,
            'keywords' => $keywords,
            'meta_description' => $metaDescription,
            'status' => trim((string) ($data['status'] ?? 'draft')),
            'review_status' => trim((string) ($data['review_status'] ?? 'pending')),
            'is_ai_generated' => $this->toFlag($data['is_ai_generated'] ?? 0),
            'risk_override_reason' => $riskOverrideReason,
        ];

        $normalized['slug'] = null;
        if (! empty($data['slug'])) {
            $slug = trim((string) $data['slug']);
            $this->ensureSlugAvailable($slug);
            $normalized['slug'] = $slug;
        }

        $normalized['category_id'] = $this->normalizeReference(Category::class, $data['category_id'] ?? null, 'category_id', true);
        $normalized['author_id'] = $this->normalizeReference(Author::class, $data['author_id'] ?? null, 'author_id', true);
        $normalized['task_id'] = $this->normalizeNullableReference(Task::class, $data['task_id'] ?? null, 'task_id');

        if ($normalized['is_ai_generated']) {
            $normalized = $this->articleCitationMarkerCleaner->cleanArticleFields($normalized);
            if (trim((string) $normalized['content']) === '') {
                throw new ApiException('validation_failed', '参数校验失败', 422, [
                    'field_errors' => ['content' => '文章内容不能为空'],
                ]);
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $existing
     */
    private function normalizeUpdateInput(array $data, array $existing): array
    {
        $normalized = [];
        $fieldErrors = [];

        if (array_key_exists('title', $data)) {
            $title = trim((string) $data['title']);
            if ($title === '') {
                $fieldErrors['title'] = '文章标题不能为空';
            } elseif (mb_strlen($title, 'UTF-8') > 255) {
                $fieldErrors['title'] = '文章标题不能超过 255 个字符';
            } else {
                $normalized['title'] = $title;
            }
        }

        if (array_key_exists('content', $data)) {
            $content = trim((string) $data['content']);
            if ($content === '') {
                $fieldErrors['content'] = '文章内容不能为空';
            } elseif (mb_strlen($content, 'UTF-8') > ArticleRiskScanner::MAX_CONTENT_CHARACTERS) {
                $fieldErrors['content'] = '文章内容超过扫描长度上限';
            } else {
                $normalized['content'] = $content;
            }
        }

        foreach (['excerpt', 'keywords', 'meta_description'] as $field) {
            if (array_key_exists($field, $data)) {
                $normalized[$field] = trim((string) $data[$field]);
            }
        }
        if (isset($normalized['excerpt']) && mb_strlen($normalized['excerpt'], 'UTF-8') > ArticleRiskScanner::MAX_EXCERPT_CHARACTERS) {
            $fieldErrors['excerpt'] = '文章摘要超过扫描长度上限';
        }
        foreach (['keywords', 'meta_description'] as $field) {
            if (isset($normalized[$field]) && mb_strlen($normalized[$field], 'UTF-8') > 500) {
                $fieldErrors[$field] = "{$field} 不能超过 500 个字符";
            }
        }

        if (array_key_exists('category_id', $data)) {
            $normalized['category_id'] = $this->normalizeReference(Category::class, $data['category_id'], 'category_id', true);
        }

        if (array_key_exists('author_id', $data)) {
            $normalized['author_id'] = $this->normalizeReference(Author::class, $data['author_id'], 'author_id', true);
        }

        if (array_key_exists('task_id', $data)) {
            $normalized['task_id'] = $this->normalizeNullableReference(Task::class, $data['task_id'], 'task_id');
        }

        if (array_key_exists('slug', $data)) {
            $slug = trim((string) $data['slug']);
            if ($slug === '') {
                $fieldErrors['slug'] = 'slug 不能为空';
            } else {
                $this->ensureSlugAvailable($slug, (int) $existing['id']);
                $normalized['slug'] = $slug;
            }
        } elseif (isset($normalized['title']) && $normalized['title'] !== $existing['title']) {
            $normalized['slug'] = ArticleWorkflow::generateUniqueSlug($normalized['title'], (int) $existing['id']);
        }

        if ((bool) ($existing['is_ai_generated'] ?? false)) {
            $normalized = $this->articleCitationMarkerCleaner->cleanArticleFields($normalized);
            if (array_key_exists('content', $normalized) && trim((string) $normalized['content']) === '') {
                $fieldErrors['content'] = '文章内容不能为空';
            }
        }

        if (! empty($fieldErrors)) {
            throw new ApiException('validation_failed', '参数校验失败', 422, ['field_errors' => $fieldErrors]);
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function getArticleRecord(int $articleId): array
    {
        $article = Article::query()->whereKey($articleId)->first();
        if (! $article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }

        return $article->getAttributes();
    }

    private function normalizeNullableReference(string $modelClass, mixed $value, string $field): ?int
    {
        return $this->normalizeReference($modelClass, $value, $field, false);
    }

    private function lockActiveTaskReference(?int $taskId): void
    {
        if ($taskId === null) {
            return;
        }

        $task = Task::query()
            ->whereKey($taskId)
            ->lockForUpdate()
            ->first(['id']);
        if (! $task) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => ['task_id' => 'task_id 对应资源不存在或任务已删除'],
            ]);
        }
    }

    private function normalizeReference(string $modelClass, mixed $value, string $field, bool $required = false): ?int
    {
        if ($value === null || $value === '' || (int) $value <= 0) {
            if ($required) {
                throw new ApiException('validation_failed', '参数校验失败', 422, [
                    'field_errors' => [$field => $this->requiredReferenceMessage($field)],
                ]);
            }

            return null;
        }

        $id = (int) $value;
        if (! $modelClass::query()->whereKey($id)->exists()) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => [$field => "{$field} 对应资源不存在"],
            ]);
        }

        return $id;
    }

    private function requiredReferenceMessage(string $field): string
    {
        return match ($field) {
            'category_id' => '请选择文章分类',
            'author_id' => '请选择文章作者',
            default => "{$field} 不能为空"
        };
    }

    private function ensureSlugAvailable(string $slug, ?int $excludeId = null): void
    {
        if (! $this->isSlugAvailable($slug, $excludeId)) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => ['slug' => 'slug 已存在'],
            ]);
        }
    }

    private function isSlugAvailable(string $slug, ?int $excludeId = null): bool
    {
        $q = Article::withTrashed()->where('slug', $slug);
        if ($excludeId !== null) {
            $q->where('id', '!=', $excludeId);
        }

        return ! $q->exists();
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }

    private function toFlag(mixed $value): int
    {
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if (is_numeric($value)) {
            return (int) $value > 0 ? 1 : 0;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }

    private function riskBlockedException(Article $article, ArticleRiskGateException $exception): ApiException
    {
        return new ApiException('article_risk_blocked', '文章风险检查未通过', 409, [
            'article_id' => (int) $article->getKey(),
            'risk_status' => $exception->riskStatus,
            'match_count' => (int) $exception->scan->match_count,
            'matches' => $exception->scan->matches ?? [],
        ]);
    }

    private function qualityBlockedException(Article $article, ArticleAiQualityGateException $exception): ApiException
    {
        return new ApiException($exception->getErrorCode(), $exception->getMessage(), 409, [
            'article_id' => (int) $article->getKey(),
            'ai_quality' => $this->aiQualitySummary($article->fresh('latestAiQualityCheck')),
        ]);
    }

    /** @return array<string, mixed> */
    private function aiQualitySummary(Article $article): array
    {
        $check = $article->latestAiQualityCheck;
        $progress = $this->articleAiQualityProgressPresenter->snapshot($check);
        $enabled = (bool) $article->ai_quality_required_at_creation
            || (bool) ($article->task?->ai_quality_enabled ?? false);

        return [
            'enabled' => $enabled,
            'status' => $check?->status,
            'decision' => $check?->decision,
            'score' => $check?->score,
            'score_label' => $progress['score_label'] ?? null,
            'result_label' => $progress['result_label'] ?? null,
            'inspection_scope' => $progress['inspection_scope'] ?? 'full',
            'degraded' => (bool) ($progress['degraded'] ?? false),
            'primary_deadline_at' => $progress['primary_deadline_at'] ?? null,
            'sampled_deadline_at' => $progress['sampled_deadline_at'] ?? null,
            'deadline_at' => $progress['deadline_at'] ?? null,
            'coverage' => $progress['coverage'] ?? [],
            'fallback' => $progress['fallback'] ?? [],
            'pass_score' => $check?->pass_score,
            'manual_override_min_score' => $check?->manual_override_min_score,
            'knowledge_coverage' => $check?->knowledge_coverage,
            'evidence_coverage' => $check?->knowledge_coverage,
            'confidence' => $check?->confidence,
            'gate_reasons' => $check?->gate_reasons ?? [],
            'scoring_version' => $check?->scoring_version,
            'summary' => $check?->summary,
            'issues_count' => is_array($check?->issues) ? count($check->issues) : 0,
            'critical_issues_count' => collect($check?->issues ?? [])->where('severity', 'critical')->count(),
            'is_stale' => $check?->status === 'stale',
            'is_overridden' => (bool) ($check?->is_overridden ?? false),
            'checked_at' => $check?->finished_at?->toAtomString(),
        ];
    }

    /** @return array<string, mixed> */
    private function aiQualityDetail(Article $article): array
    {
        $check = $article->latestAiQualityCheck;

        return array_replace($this->aiQualitySummary($article), [
            'check_id' => $check?->id,
            'prompt_id' => $check?->prompt_id,
            'prompt_name' => $check?->prompt?->name,
            'ai_model_id' => $check?->ai_model_id,
            'ai_model_name' => $check?->aiModel?->name,
            'dimension_scores' => $check?->dimension_scores ?? [],
            'issues' => $check?->issues ?? [],
            'uncertainties' => $check?->uncertainties ?? [],
            'is_overridden' => (bool) ($check?->is_overridden ?? false),
            'override_reason' => $check?->override_reason,
            'overridden_by_name' => $check?->overridden_by_name,
            'overridden_at' => $check?->overridden_at?->toAtomString(),
            'error_code' => $check?->error_code,
            'error_message' => $check?->error_message,
            'input_fingerprint' => $check?->input_fingerprint,
        ]);
    }

    private function applyAiQualityFilter($query, string $filter): void
    {
        match ($filter) {
            'passed' => $query->whereHas('latestAiQualityCheck', fn ($check) => $check->where('status', 'completed')->where('decision', 'passed')),
            'needs_review', 'blocked' => $query->whereHas(
                'latestAiQualityCheck',
                fn ($check) => $check->where('status', 'completed')->where('decision', $filter),
            ),
            'pending' => $query
                ->where(function ($enabled): void {
                    $enabled->where('ai_quality_required_at_creation', true)
                        ->orWhereHas('task', fn ($task) => $task->where('ai_quality_enabled', true));
                })
                ->where(function ($pending): void {
                    $pending->whereDoesntHave('latestAiQualityCheck')
                        ->orWhereHas('latestAiQualityCheck', fn ($check) => $check->whereIn('status', ['queued', 'running']));
                }),
            'failed', 'error' => $query->whereHas(
                'latestAiQualityCheck',
                fn ($check) => $check->where('status', 'failed')->orWhere('decision', 'error'),
            ),
            'stale' => $query->whereHas('latestAiQualityCheck', fn ($check) => $check->where('status', 'stale')),
            'disabled' => $query->where('ai_quality_required_at_creation', false)
                ->whereDoesntHave('task', fn ($task) => $task->where('ai_quality_enabled', true)),
            default => null,
        };
    }
}
