<?php

namespace App\Services\GeoFlow;

use App\Exceptions\ApiException;
use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleAiQualityRollout;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\KnowledgeBase;
use App\Models\Task;
use App\Services\Site\UrlChangeGuard;
use App\Services\Site\UrlChangeInspector;
use App\Support\Admin\ArticleAiQualityProgressPresenter;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
        private readonly ArticleAiQualityConfigurationService $articleAiQualityConfigurationService,
        private readonly AiQualityAuditService $aiQualityAuditService,
        private readonly DistributionOrchestrator $distributionOrchestrator,
        private readonly ArticleSlugRegistry $articleSlugRegistry,
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
            ->with(['latestAiQualityCheck', 'latestRiskScan', 'latestPublicationHandoff', 'latestAiOptimizationRun', 'task'])
            ->orderByDesc('created_at')
            ->forPage($page, $perPage)
            ->get()
            ->map(fn (Article $a) => array_replace($a->only([
                'id', 'title', 'slug', 'status', 'review_status',
                'task_id', 'author_id', 'category_id', 'published_at',
                'ai_quality_required_at_creation',
                'ai_quality_retrieval_mode_override', 'ai_quality_policy_version',
                'publication_intent', 'workflow_version', 'created_at', 'updated_at',
            ]), [
                'ai_quality' => $this->aiQualitySummary($a),
                'workflow' => app(ArticlePublicationEligibilityService::class)->evaluate($a),
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
        $slug = $normalized['slug'] ?: ArticleWorkflow::generateUniqueSlug($normalized['title']);
        $excerpt = $normalized['excerpt'] !== '' ? $normalized['excerpt'] : mb_substr(strip_tags($normalized['content']), 0, 200);

        $fallbackWorkflowState = ArticleWorkflow::normalizeState('draft', 'pending');
        $creation = DB::transaction(function () use (
            $normalized,
            $slug,
            $excerpt,
            $fallbackWorkflowState,
            $auditAdminId,
        ): array {
            $this->lockActiveTaskReference($normalized['task_id']);
            $this->ensureSlugAvailable($slug);
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
            app(UrlChangeInspector::class)->assertArticleCompatible($article);
            $qualityPolicy = $this->articleAiQualityPolicyResolver->resolve($article);
            $article->forceFill([
                'ai_quality_required_at_creation' => (bool) ($qualityPolicy['required'] ?? false),
                'ai_quality_policy_snapshot' => $this->articleAiQualityPolicyResolver->snapshot($qualityPolicy),
            ])->save();

            $this->articleRiskScanner->record($article, 'api_save', $auditAdminId);

            $gateRejection = null;
            try {
                $reviewAction = match ($normalized['review_status']) {
                    'approved', 'auto_approved' => 'approve',
                    'rejected' => 'reject',
                    default => 'revoke',
                };
                $article = $this->articleWorkflowTransitionService->humanAction($article, $reviewAction, $auditAdminId);
                $publicationAction = match ($normalized['status']) {
                    'published' => 'publish',
                    'private' => 'private',
                    default => 'hold',
                };
                $article = $this->articleWorkflowTransitionService->humanAction(
                    $article, $publicationAction, $auditAdminId, $normalized['risk_override_reason'], (int) $article->workflow_version,
                );
            } catch (ArticleRiskGateException|ArticleAiQualityGateException $exception) {
                $gateRejection = $exception;
            } catch (\RuntimeException $exception) {
                if ($exception->getMessage() !== 'article_not_publishable') {
                    throw $exception;
                }

                throw new ApiException('article_not_publishable', __('article_workflow.reason.article_not_publishable'), 409);
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

        $result = $this->getArticle((int) $article->id);
        if ($normalized['review_status'] === 'auto_approved') {
            $result['compatibility_notice'] = 'auto_approved 已作为人工审核通过处理；发布安排保持不变。';
        }

        return $result;
    }

    public function getArticle(int $articleId): array
    {
        $article = Article::query()
            ->with([
                'task',
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
            'publication_intent' => $article->publication_intent,
            'workflow_version' => (int) $article->workflow_version,
            'workflow' => app(ArticlePublicationEligibilityService::class)->evaluate($article),
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

    public function recheckAiQuality(
        int $articleId,
        int $auditAdminId,
        int $apiTokenId,
        ?int $expectedPolicyVersion = null,
    ): array {
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
                expectedPolicyVersion: $expectedPolicyVersion,
            );
        } catch (ApiException $exception) {
            throw $exception;
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

    /** @return array<string,mixed> */
    public function aiQualityIdempotencyContext(int $articleId, int $auditAdminId): array
    {
        $article = Article::withTrashed()
            ->with(['task' => fn ($query) => $query->withTrashed()->select([
                'id', 'ai_quality_policy_version', 'ai_quality_retrieval_mode', 'ai_quality_enabled', 'deleted_at',
            ])])
            ->whereKey($articleId)
            ->first([
                'id', 'task_id', 'ai_quality_policy_version', 'ai_quality_retrieval_mode_override', 'deleted_at',
            ]);
        $admin = Admin::query()->whereKey($auditAdminId)->first(['id', 'role', 'status', 'auth_version']);
        if (! $article) {
            return ['article_id' => $articleId, 'resource_state' => 'missing', 'admin' => $this->adminAuthorizationContext($admin)];
        }

        $knowledgeBaseIds = $article->trashed()
            ? []
            : $this->articleAiQualityConfigurationService->effectiveKnowledgeBaseIds($article);
        $knowledgeSources = KnowledgeBase::query()
            ->whereIn('id', $knowledgeBaseIds)
            ->with('factLibrary:id,knowledge_base_id,serving_status,active_revision_id,active_hash,source_hash,updated_at')
            ->get([
                'id', 'chunk_sync_status', 'chunk_source_hash', 'chunk_serving_generation',
                'chunk_serving_source_hash', 'chunk_manifest_hash', 'ai_quality_content_hash',
                'review_status',
            ])
            ->sortBy(static fn (KnowledgeBase $base): int => array_search((int) $base->id, $knowledgeBaseIds, true))
            ->values()
            ->map(static fn (KnowledgeBase $base): array => [
                'id' => (int) $base->id,
                'content_hash' => (string) $base->ai_quality_content_hash,
                'review_status' => (string) $base->review_status,
                'chunk_sync_status' => (string) $base->chunk_sync_status,
                'chunk_source_hash' => (string) $base->chunk_source_hash,
                'chunk_serving_generation' => (string) $base->chunk_serving_generation,
                'chunk_serving_source_hash' => (string) $base->chunk_serving_source_hash,
                'chunk_manifest_hash' => (string) $base->chunk_manifest_hash,
                'fact_library' => $base->factLibrary ? [
                    'serving_status' => (string) $base->factLibrary->serving_status,
                    'active_revision_id' => (int) $base->factLibrary->active_revision_id,
                    'active_hash' => (string) $base->factLibrary->active_hash,
                    'source_hash' => (string) $base->factLibrary->source_hash,
                ] : null,
            ])->all();

        return [
            'article_id' => (int) $article->id,
            'resource_state' => $article->trashed() ? 'deleted' : 'active',
            'task_id' => $article->task_id ? (int) $article->task_id : null,
            'article_policy_version' => max(1, (int) $article->ai_quality_policy_version),
            'article_retrieval_mode' => (string) ($article->ai_quality_retrieval_mode_override ?? ''),
            'rollout_epoch' => max(1, (int) (ArticleAiQualityRollout::query()->whereKey(1)->value('epoch') ?? 1)),
            'task' => $article->task ? [
                'id' => (int) $article->task->id,
                'state' => $article->task->trashed() ? 'deleted' : 'active',
                'policy_version' => max(1, (int) $article->task->ai_quality_policy_version),
                'retrieval_mode' => (string) $article->task->ai_quality_retrieval_mode,
                'quality_enabled' => (bool) $article->task->ai_quality_enabled,
            ] : null,
            'knowledge_sources' => $knowledgeSources,
            'admin' => $this->adminAuthorizationContext($admin),
        ];
    }

    /** @return array<string,mixed> */
    private function adminAuthorizationContext(?Admin $admin): array
    {
        return [
            'id' => $admin?->id,
            'role' => (string) ($admin?->role ?? ''),
            'status' => (string) ($admin?->status ?? ''),
            'auth_version' => (int) ($admin?->auth_version ?? 0),
            'protected_workflows' => $admin?->canManageProtectedWorkflows() === true,
        ];
    }

    public function overrideAiQuality(
        int $articleId,
        string $reason,
        int $auditAdminId,
        int $apiTokenId = 0,
    ): array {
        $reason = trim($reason);
        if (mb_strlen($reason, 'UTF-8') < 4 || mb_strlen($reason, 'UTF-8') > 1000) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => ['reason' => '人工放行依据需要填写 4 至 1000 个字符'],
            ]);
        }

        DB::transaction(function () use ($articleId, $reason, $auditAdminId, $apiTokenId): void {
            $taskId = Article::query()->whereKey($articleId)->value('task_id');
            if ($taskId) {
                Task::withTrashed()->whereKey($taskId)->lockForUpdate()->first();
            }
            $article = Article::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article) {
                throw new ApiException('article_not_found', '文章不存在', 404);
            }
            $admin = Admin::query()->whereKey($auditAdminId)->first();
            if (! $admin) {
                throw new ApiException('forbidden', '当前账号无权执行人工质检放行', 403, [
                    'reason_code' => 'quality_decision_permission_required',
                ]);
            }
            $hostedTask = (int) $article->task_id > 0 && Task::query()
                ->whereKey((int) $article->task_id)
                ->whereHas('distributionChannels', static fn ($query) => $query->where(
                    'channel_type',
                    DistributionChannel::TYPE_HOSTED_SITE,
                ))
                ->exists();
            if ($hostedTask && ! $admin->canManageProtectedWorkflows()) {
                throw new ApiException('forbidden', '当前账号无权放行托管任务文章', 403, [
                    'reason_code' => 'hosted_quality_decision_permission_required',
                ]);
            }

            $beforeCheck = $article->latestAiQualityCheck()->first();
            try {
                $check = $this->articleAiQualityGate->check(
                    $article,
                    'api_ai_quality_override',
                    $auditAdminId,
                    $reason,
                    true,
                );
            } catch (ArticleAiQualityGateException $exception) {
                throw $this->qualityBlockedException($article, $exception);
            }
            if ($check === null) {
                throw new ApiException('article_ai_quality_not_required', '当前文章未启用 AI 质检，无需人工放行。', 409);
            }
            $this->aiQualityAuditService->record('article_quality_decision_overridden', [
                'article_id' => $articleId,
                'task_id' => $article->task_id ? (int) $article->task_id : null,
                'article_ai_quality_check_id' => (int) $check->id,
                'admin_id' => $auditAdminId,
                'api_token_id' => $apiTokenId > 0 ? $apiTokenId : null,
                'authorization_result' => 'allowed',
                'policy_version' => max(1, (int) $article->ai_quality_policy_version),
                'before_hash' => hash('sha256', json_encode([
                    'check_id' => $beforeCheck?->id,
                    'is_overridden' => (bool) ($beforeCheck?->is_overridden ?? false),
                ], JSON_THROW_ON_ERROR)),
                'after_hash' => hash('sha256', json_encode([
                    'check_id' => $check->id,
                    'is_overridden' => (bool) $check->is_overridden,
                ], JSON_THROW_ON_ERROR)),
                'basis_hash' => (string) ($check->retrieval_basis_hash ?? ''),
                'reason_code' => 'manual_quality_decision_override',
            ]);
        });

        return $this->getArticle($articleId);
    }

    public function updateArticle(int $articleId, array $data, int $auditAdminId, bool $canUpdatePublished = true): array
    {
        app(UrlChangeGuard::class)->article(Article::query()->findOrFail($articleId), $data, Admin::query()->find($auditAdminId));
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

        $nothingChanged = $normalized === [];

        $riskRelevantFields = ['title', 'excerpt', 'content', 'keywords', 'meta_description'];
        $qualityRelevantFields = [...$riskRelevantFields, 'task_id'];
        $hasQualityRelevantChanges = array_intersect($qualityRelevantFields, array_keys($normalized)) !== [];
        $hasRiskRelevantChanges = array_intersect($riskRelevantFields, array_keys($normalized)) !== [];

        $normalized['updated_at'] = now();
        $preserveWorkflowDuringQualityInvalidation = false;

        DB::transaction(function () use ($articleId, $normalized, $auditAdminId, $hasRiskRelevantChanges, $hasQualityRelevantChanges, $existing, $data, $nothingChanged, $canUpdatePublished, &$preserveWorkflowDuringQualityInvalidation): void {
            Task::withTrashed()->whereKey(array_filter([$existing['task_id'] ?? null, $normalized['task_id'] ?? null]))
                ->orderBy('id')->lockForUpdate()->get(['id']);
            $lockedArticle = Article::query()
                ->whereKey($articleId)
                ->lockForUpdate()
                ->firstOrFail();
            if ((int) ($data['workflow_version'] ?? $existing['workflow_version'] ?? 0) !== (int) $lockedArticle->workflow_version) {
                throw new ApiException('workflow_version_conflict', __('article_workflow.reason.workflow_version_conflict'), 409);
            }
            if ($nothingChanged) {
                return;
            }
            if ((int) ($existing['task_id'] ?? 0) !== (int) $lockedArticle->task_id) {
                throw new ApiException('workflow_version_conflict', __('article_workflow.reason.workflow_version_conflict'), 409);
            }
            if ($lockedArticle->status === 'published' && ! $canUpdatePublished) {
                foreach ($normalized as $field => $value) {
                    if ($field !== 'updated_at' && (string) ($lockedArticle->getAttribute($field) ?? '') !== (string) ($value ?? '')) {
                        throw new ApiException('forbidden', '修改已发布文章需要发布权限', 403, [
                            'required_scope' => 'articles:publish',
                        ]);
                    }
                }
            }
            app(UrlChangeGuard::class)->article($lockedArticle, $normalized, Admin::query()->find($auditAdminId));
            $newSlug = array_key_exists('slug', $normalized) ? (string) $normalized['slug'] : null;
            unset($normalized['slug']);
            if ($newSlug !== null) {
                $lockedArticle = $this->articleSlugRegistry->change($lockedArticle, $newSlug);
            }
            $nextPolicyVersion = max(1, (int) $lockedArticle->ai_quality_policy_version);
            if ($hasQualityRelevantChanges) {
                $nextPolicyVersion++;
                $normalized['ai_quality_policy_version'] = $nextPolicyVersion;
            }
            if (array_key_exists('task_id', $normalized)) {
                $preserveWorkflowDuringQualityInvalidation = $this->rebindArticleTask(
                    $lockedArticle,
                    $normalized['task_id'],
                    $nextPolicyVersion,
                );
                if ($hasRiskRelevantChanges) {
                    $preserveWorkflowDuringQualityInvalidation = false;
                }
                unset($normalized['task_id'], $normalized['ai_quality_policy_version']);
            }
            if ($normalized !== []) {
                Article::query()->whereKey($articleId)->update($normalized);
            }
            if ($hasRiskRelevantChanges) {
                $article = $this->articleWorkflowTransitionService->contentChanged(Article::query()->findOrFail($articleId), $auditAdminId);
                $this->articleRiskScanner->record($article, 'api_save', $auditAdminId);
                if ($article->status === 'published') {
                    try {
                        app(ArticlePublicationQualityGate::class)->check($article, 'api_published_content_update', $auditAdminId);
                        $eligibility = app(ArticlePublicationEligibilityService::class);
                        if ($eligibility->manualReviewRequired($article) && ! $eligibility->hasCurrentApproval($article)) {
                            throw new ApiException('article_review_required', '新正文需要重新审核；请先保留为草稿再编辑。', 422);
                        }
                    } catch (ArticleRiskGateException $exception) {
                        throw $this->riskBlockedException($article, $exception);
                    } catch (ArticleAiQualityGateException $exception) {
                        throw $this->qualityBlockedException($article, $exception);
                    }
                }
            }
        });

        if ($hasQualityRelevantChanges) {
            $this->articleAiQualityInvalidationService->invalidateArticle(
                $articleId,
                '文章内容或任务关联已更新',
                preserveWorkflow: $preserveWorkflowDuringQualityInvalidation,
            );
        }

        return $this->getArticle($articleId);
    }

    private function rebindArticleTask(Article $article, ?int $targetTaskId, int $nextPolicyVersion): bool
    {
        $currentTaskId = $article->task_id ? (int) $article->task_id : null;
        if ($currentTaskId === $targetTaskId) {
            return false;
        }

        $currentTask = $currentTaskId === null
            ? null
            : Task::withTrashed()->whereKey($currentTaskId)->lockForUpdate()->first();
        if ($currentTask instanceof Task) {
            $currentTask->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
            $article->setRelation('task', $currentTask);
        }

        if ($targetTaskId === null) {
            $policy = $currentTask instanceof Task
                ? $this->articleAiQualityPolicyResolver->fromTaskForDetachment($currentTask, $article)
                : $this->articleAiQualityPolicyResolver->resolve($article);
            $policy['policy_version'] = $nextPolicyVersion;
            $knowledgeBaseIds = array_values(array_unique(array_map(
                'intval',
                (array) ($policy['knowledge_base_ids'] ?? []),
            )));
            $article->aiQualityKnowledgeBases()->sync(collect($knowledgeBaseIds)->mapWithKeys(
                static fn (int $id, int $index): array => [$id => ['sort_order' => $index]],
            )->all());
            $article->forceFill([
                'task_id' => null,
                'workflow_version' => (int) $article->workflow_version + 1,
                'publication_intent' => $article->publication_intent === 'scheduled' ? 'hold' : $article->publication_intent,
                'ai_quality_retrieval_mode_override' => (string) (
                    $policy['retrieval_mode'] ?? AiQualityRetrievalMode::legacyDefault()
                ),
                'ai_quality_required_at_creation' => (bool) ($policy['required'] ?? false),
                'ai_quality_policy_version' => $nextPolicyVersion,
                'ai_quality_policy_snapshot' => $this->articleAiQualityPolicyResolver->snapshot($policy),
            ])->save();

            return false;
        }

        $targetTask = Task::query()
            ->whereKey($targetTaskId)
            ->lockForUpdate()
            ->first();
        if (! $targetTask) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => ['task_id' => 'task_id 对应资源不存在或任务已删除'],
            ]);
        }
        $targetTask->load(['qualityPrompt', 'qualityModel', 'aiModel', 'knowledgeBases']);
        $article->aiQualityKnowledgeBases()->detach();
        $article->forceFill([
            'task_id' => $targetTaskId,
            'workflow_version' => (int) $article->workflow_version + 1,
            'ai_quality_retrieval_mode_override' => null,
            'ai_quality_policy_version' => $nextPolicyVersion,
        ]);
        $article->setRelation('task', $targetTask);
        $policy = $this->articleAiQualityPolicyResolver->fromTask($targetTask, $article);
        $policy['policy_version'] = $nextPolicyVersion;
        try {
            $this->articleAiQualityPolicyResolver->assertExecutable($policy);
        } catch (\RuntimeException $exception) {
            throw new ApiException('validation_failed', '目标任务的 AI 质检配置当前不可用', 422, [
                'field_errors' => ['task_id' => '目标任务的 AI 质检配置当前不可用'],
                'reason_code' => $exception->getMessage(),
            ]);
        }
        $currentWorkflowState = [
            'status' => (string) $article->status,
            'review_status' => (string) $article->review_status,
            'published_at' => $article->published_at,
        ];
        $publishScopeWorkflowState = ArticleWorkflow::normalizeForPublishScope(
            $currentWorkflowState,
            $targetTask->publish_scope,
        );
        $article->forceFill([
            'ai_quality_required_at_creation' => (bool) ($policy['required'] ?? false),
            'ai_quality_policy_snapshot' => $this->articleAiQualityPolicyResolver->snapshot($policy),
            ...$publishScopeWorkflowState,
        ])->save();

        return $publishScopeWorkflowState !== $currentWorkflowState;
    }

    /**
     * @param  list<int>|null  $knowledgeBaseIds
     * @return array<string,mixed>
     */
    public function updateAiQualityConfiguration(
        int $articleId,
        mixed $requestedMode,
        bool $modeProvided,
        ?array $knowledgeBaseIds,
        int $expectedVersion,
        int $auditAdminId,
        int $apiTokenId,
    ): array {
        DB::transaction(function () use (
            $articleId,
            $requestedMode,
            $modeProvided,
            $knowledgeBaseIds,
            $expectedVersion,
            $auditAdminId,
            $apiTokenId,
        ): void {
            $article = Article::query()
                ->with('task')
                ->whereKey($articleId)
                ->lockForUpdate()
                ->first();
            if (! $article) {
                throw new ApiException('article_not_found', '文章不存在', 404);
            }
            $this->assertAiQualityConfigurationVersion($article, $expectedVersion);
            $this->assertCanManageArticleQualityPolicy($article, $auditAdminId);

            $beforeHash = $this->qualityConfigurationHash($article);
            try {
                $changed = $this->articleAiQualityConfigurationService->apply(
                    $article,
                    $modeProvided ? $requestedMode : $article->ai_quality_retrieval_mode_override,
                    $knowledgeBaseIds,
                );
            } catch (ValidationException $exception) {
                throw new ApiException('validation_failed', '参数校验失败', 422, [
                    'field_errors' => collect($exception->errors())
                        ->map(static fn (array $messages): string => (string) ($messages[0] ?? 'Invalid value.'))
                        ->all(),
                ]);
            }
            if (! $changed) {
                return;
            }

            $article->refresh();
            $this->aiQualityAuditService->record('article_quality_configuration_changed', [
                'article_id' => $articleId,
                'task_id' => $article->task_id ? (int) $article->task_id : null,
                'admin_id' => $auditAdminId,
                'api_token_id' => $apiTokenId > 0 ? $apiTokenId : null,
                'policy_version' => (int) $article->ai_quality_policy_version,
                'before_hash' => $beforeHash,
                'after_hash' => $this->qualityConfigurationHash($article),
                'metadata' => [
                    'trigger' => 'api_patch',
                    'retrieval_mode' => (string) ($article->ai_quality_retrieval_mode_override ?? ''),
                ],
            ]);
            $this->articleAiQualityInvalidationService->invalidateArticle(
                $article,
                'article_quality_configuration_changed',
            );
        });

        return $this->getArticle($articleId);
    }

    public function assertCanManageArticleQualityPolicy(
        Article|int $article,
        int $adminId,
        mixed $targetTaskId = null,
        bool $targetTaskProvided = false,
    ): void {
        $model = $article instanceof Article
            ? $article
            : Article::query()->whereKey($article)->first();
        if (! $model) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }
        $admin = Admin::query()->whereKey($adminId)->first();
        if (! $admin) {
            throw new ApiException('forbidden', '当前账号无权修改文章质检策略', 403, [
                'reason_code' => 'quality_policy_permission_required',
            ]);
        }

        $taskIds = array_values(array_unique(array_filter([
            (int) $model->task_id,
            $targetTaskProvided ? (int) $targetTaskId : 0,
        ])));
        $hasHostedTask = $taskIds !== [] && Task::withTrashed()
            ->whereIn('id', $taskIds)
            ->whereHas('distributionChannels', static fn ($query) => $query->where(
                'channel_type',
                DistributionChannel::TYPE_HOSTED_SITE,
            ))
            ->exists();
        if ($hasHostedTask && ! $admin->canManageProtectedWorkflows()) {
            throw new ApiException('forbidden', '当前账号无权修改托管任务文章的质检策略', 403, [
                'reason_code' => 'hosted_task_permission_required',
            ]);
        }
    }

    public function assertAiQualityConfigurationVersion(Article|int $article, int $expectedVersion): void
    {
        $model = $article instanceof Article
            ? $article
            : Article::query()->whereKey($article)->first();
        if (! $model) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }
        $currentVersion = max(1, (int) $model->ai_quality_policy_version);
        if ($currentVersion !== $expectedVersion) {
            throw new ApiException(
                'article_ai_quality_config_version_conflict',
                'AI 质检配置已更新，请刷新后重试',
                409,
                [
                    'expected_config_version' => $expectedVersion,
                    'current_config_version' => $currentVersion,
                ],
            );
        }
    }

    public function reviewArticle(
        int $articleId,
        string $reviewStatus,
        string $reviewNote,
        string $riskOverrideReason,
        int $auditAdminId,
        ?int $expectedVersion = null,
    ): array {
        $reviewStatus = trim($reviewStatus);
        if (! in_array($reviewStatus, ['pending', 'approved', 'rejected', 'auto_approved'], true)) {
            throw new ApiException('validation_failed', '审核状态无效', 422, ['field_errors' => ['review_status' => '审核状态无效']]);
        }
        if (mb_strlen($riskOverrideReason, 'UTF-8') > 1000 || mb_strlen($reviewNote, 'UTF-8') > 1000) {
            throw new ApiException('validation_failed', '审核说明不能超过 1000 个字符', 422);
        }
        $action = match ($reviewStatus) {
            'pending' => 'revoke',
            'rejected' => 'reject',
            default => 'approve',
        };
        $this->performHumanAction($articleId, $action, $auditAdminId, trim($reviewNote), $expectedVersion);

        $result = $this->getArticle($articleId);
        if ($reviewStatus === 'auto_approved') {
            $result['compatibility_notice'] = 'auto_approved 已作为人工审核通过处理；发布安排保持不变。';
        }

        return $result;
    }

    public function publishArticle(int $articleId, int $auditAdminId, ?int $expectedVersion = null, ?string $riskOverrideReason = null): array
    {
        $this->performHumanAction($articleId, 'publish', $auditAdminId, $riskOverrideReason, $expectedVersion);

        return $this->getArticle($articleId);
    }

    public function scheduleArticle(int $articleId, int $auditAdminId, ?int $expectedVersion = null): array
    {
        $this->performHumanAction($articleId, 'schedule', $auditAdminId, null, $expectedVersion);

        return $this->getArticle($articleId);
    }

    public function holdArticle(int $articleId, string $status, int $auditAdminId, ?int $expectedVersion = null): array
    {
        if (! in_array($status, ['draft', 'private'], true)) {
            throw new ApiException('validation_failed', '保留状态无效', 422);
        }
        $this->performHumanAction($articleId, $status === 'private' ? 'private' : 'hold', $auditAdminId, null, $expectedVersion);

        return $this->getArticle($articleId);
    }

    private function performHumanAction(int $articleId, string $action, int $adminId, ?string $note = null, ?int $expectedVersion = null): Article
    {
        $article = Article::query()->find($articleId);
        if (! $article instanceof Article) {
            throw new ApiException('article_not_found', '文章不存在', 404);
        }
        try {
            return $this->articleWorkflowTransitionService->humanAction(
                $article, $action, $adminId, $note, $expectedVersion ?? (int) $article->workflow_version,
            );
        } catch (ArticleRiskGateException $exception) {
            throw $this->riskBlockedException($article->fresh(), $exception);
        } catch (ArticleAiQualityGateException $exception) {
            throw $this->qualityBlockedException($article->fresh(), $exception);
        } catch (\RuntimeException $exception) {
            if (in_array($exception->getMessage(), ['workflow_version_conflict', 'article_not_publishable', 'task_unavailable'], true)) {
                throw new ApiException($exception->getMessage(), __('article_workflow.reason.'.$exception->getMessage()), 409);
            }
            throw $exception;
        }
    }

    public function trashArticle(int $articleId, ?int $auditAdminId = null, ?int $apiTokenId = null): array
    {
        DB::transaction(function () use ($articleId, $auditAdminId, $apiTokenId): void {
            $article = Article::query()->whereKey($articleId)->lockForUpdate()->first();
            if (! $article) {
                throw new ApiException('article_not_found', '文章不存在', 404);
            }
            $article->forceFill([
                'ai_quality_policy_version' => max(1, (int) $article->ai_quality_policy_version) + 1,
            ])->save();
            $this->aiQualityAuditService->record('article_deleted', [
                'article_id' => $articleId,
                'task_id' => $article->task_id ? (int) $article->task_id : null,
                'admin_id' => $auditAdminId,
                'api_token_id' => $apiTokenId !== null && $apiTokenId > 0 ? $apiTokenId : null,
                'policy_version' => (int) $article->ai_quality_policy_version,
                'reason_code' => 'article_soft_deleted',
            ]);
            $article->delete();
            $this->articleAiQualityInvalidationService->cancelArticle($article);
        });

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
        try {
            $this->articleSlugRegistry->assertAvailable($slug, $excludeId);
        } catch (ValidationException) {
            throw new ApiException('validation_failed', '参数校验失败', 422, [
                'field_errors' => ['slug' => 'slug 已存在'],
            ]);
        }
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
        $enabled = (bool) ($article->task?->ai_quality_enabled ?? $article->ai_quality_required_at_creation);
        $configuredMode = $article->ai_quality_retrieval_mode_override
            ?: $article->task?->ai_quality_retrieval_mode
            ?: AiQualityRetrievalMode::legacyDefault();

        return [
            'enabled' => $enabled,
            'config_version' => max(1, (int) $article->ai_quality_policy_version),
            'requested_retrieval_mode' => $configuredMode,
            'last_check_requested_retrieval_mode' => $check?->requested_retrieval_mode,
            'effective_retrieval_mode' => $check?->effective_retrieval_mode,
            'retrieval_strategy_version' => $check?->retrieval_strategy_version,
            'retrieval_failure_code' => $check?->retrieval_failure_code,
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
            'score_adjustments' => data_get($check?->execution_meta, 'score_policy.adjustments', []),
            'decision_policy_version' => data_get($check?->execution_meta, 'score_policy.version'),
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

    private function qualityConfigurationHash(Article $article): string
    {
        return hash('sha256', json_encode([
            'mode' => $article->ai_quality_retrieval_mode_override,
            'knowledge_base_ids' => $this->articleAiQualityConfigurationService
                ->effectiveKnowledgeBaseIds($article),
            'policy_version' => max(1, (int) $article->ai_quality_policy_version),
        ], JSON_THROW_ON_ERROR));
    }

    private function applyAiQualityFilter($query, string $filter): void
    {
        if ($filter !== 'disabled') {
            $query->where(fn ($enabled) => $enabled->whereHas('task', fn ($task) => $task->where('ai_quality_enabled', true))
                ->orWhere(fn ($independent) => $independent->whereDoesntHave('task')->where('ai_quality_required_at_creation', true)));
        }
        match ($filter) {
            'passed' => $query->whereHas('latestAiQualityCheck', fn ($check) => $check->where('status', 'completed')->where('decision', 'passed')),
            'needs_review', 'blocked' => $query->whereHas(
                'latestAiQualityCheck',
                fn ($check) => $check->where('status', 'completed')->where('decision', $filter),
            ),
            'pending' => $query
                ->where(function ($enabled): void {
                    $enabled->where(fn ($independent) => $independent->whereDoesntHave('task')->where('ai_quality_required_at_creation', true))
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
            'disabled' => $query->where(fn ($disabled) => $disabled->whereHas('task', fn ($task) => $task->where('ai_quality_enabled', false))
                ->orWhere(fn ($independent) => $independent->whereDoesntHave('task')->where('ai_quality_required_at_creation', false))),
            default => null,
        };
    }
}
