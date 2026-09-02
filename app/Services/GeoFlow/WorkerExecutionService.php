<?php

namespace App\Services\GeoFlow;

use App\Data\Ai\AiExecutionContext;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\ArticleAiQualityGateException;
use App\Exceptions\ArticleRiskGateException;
use App\Exceptions\PermanentAiProviderException;
use App\Exceptions\TaskTitleReadinessException;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\Category;
use App\Models\Image;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use App\Support\GeoFlow\AiModelFailoverDecider;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Ai\Responses\Data\FinishReason;
use RuntimeException;
use Throwable;

/**
 * Worker 任务执行器：将队列任务落地为文章记录（占位实现，先打通 worker/队列链路）。
 */
class WorkerExecutionService
{
    /**
     * 复用正文提示词和模型调用服务，确保任务生成与单篇生成规则一致。
     */
    public function __construct(
        private readonly KnowledgeRetrievalService $knowledgeRetrievalService,
        private readonly DistributionOrchestrator $distributionOrchestrator,
        private readonly ArticleRiskScanner $articleRiskScanner,
        private readonly ArticleWorkflowTransitionService $articleWorkflowTransitionService,
        private readonly ArticleContentPromptRenderer $articleContentPromptRenderer,
        private readonly WorkerAiModelInvocationGateway $aiModelInvocationGateway,
        private readonly ArticleCitationMarkerCleaner $articleCitationMarkerCleaner,
        private readonly TaskTitleReadinessService $taskTitleReadinessService,
        private readonly ArticleAiQualityGate $articleAiQualityGate,
        private readonly ArticleAiQualityPolicyResolver $articleAiQualityPolicyResolver,
        private readonly ArticleAiQualityInspectionService $articleAiQualityInspectionService,
        private readonly AiExecutionAccessGuard $aiExecutionAccessGuard,
        private readonly AiExecutionErrorSanitizer $aiExecutionErrorSanitizer,
        private readonly AiModelFailoverDecider $aiModelFailoverDecider,
        private readonly JobQueueService $jobQueueService,
    ) {}

    /**
     * @return array{article_id:int|null, title:string, message:string, meta:array<string,mixed>}
     */
    public function executeTask(int $taskId, ?AiExecutionContext $executionContext = null): array
    {
        $executionStartedAt = microtime(true);
        /** @var Task|null $task */
        $task = Task::query()->find($taskId);
        if (! $task) {
            throw new RuntimeException('任务不存在');
        }

        if (($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
            throw new RuntimeException('任务未激活');
        }

        $publishResult = $this->publishDueDraftArticle($task, $executionContext, $executionStartedAt);
        if ($publishResult !== null) {
            if ($publishResult['article_id'] !== null
                && (string) ($publishResult['meta']['action'] ?? '') === 'publish_draft') {
                $this->distributionOrchestrator->enqueueForArticle((int) $publishResult['article_id']);
            }

            return $publishResult;
        }

        if (! $executionContext instanceof AiExecutionContext || $executionContext->requestedModelId === null) {
            throw AiModelAccessException::configAccessRevokedForAdminId(
                (int) ($task->model_access_admin_id ?? 0),
            );
        }
        $this->aiExecutionAccessGuard->assertCurrent($executionContext);

        $generationBlockReason = $this->getGenerationBlockReason($task);
        if ($generationBlockReason !== null) {
            return [
                'article_id' => null,
                'title' => '',
                'message' => $generationBlockReason,
                'meta' => [
                    'task_id' => (int) $task->id,
                    'action' => 'noop',
                    'reason' => $generationBlockReason,
                ],
            ];
        }

        $titleRow = $this->pickTitle($task);
        $author = $this->pickAuthor($task);
        $category = $this->pickCategory($task);
        $prompt = $task->prompt_id ? Prompt::query()->find((int) $task->prompt_id) : null;

        $keyword = (string) ($titleRow->keyword ?? '');
        $knowledgeBundle = $this->resolveKnowledgeContext(
            $task,
            (string) $titleRow->title,
            $keyword,
            $executionContext,
        );
        $knowledgeContext = $knowledgeBundle['context'];
        $generationEvidenceSnapshot = $this->generationEvidenceSnapshot($knowledgeBundle['evidence']);
        $contentPrompt = $this->buildContentPrompt((string) $titleRow->title, $keyword, $prompt?->content, $knowledgeContext);

        return $this->generateContentWithModelSelection(
            $task,
            $contentPrompt,
            $executionContext,
            function (array $generation) use ($task, $titleRow, $author, $category, $keyword, $generationEvidenceSnapshot, $executionContext, $knowledgeContext, $knowledgeBundle, $executionStartedAt): array {
                $aiModel = $generation['model'];
                $imageResult = $this->insertTaskImagesIntoContent($task, $generation['content']);
                $content = $imageResult['content'];
                $selectedImages = $imageResult['images'];
                $excerpt = $this->buildExcerpt($content);

                return DB::transaction(function () use ($task, $titleRow, $author, $category, $keyword, $content, $excerpt, $selectedImages, $generationEvidenceSnapshot, $executionContext, $aiModel, $knowledgeContext, $knowledgeBundle, $generation, $executionStartedAt): array {
                    $freshTask = Task::query()
                        ->whereKey((int) $task->id)
                        ->lockForUpdate()
                        ->first();
                    if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                        throw new RuntimeException('任务未激活');
                    }

                    $this->jobQueueService->lockRunningJobForWorker(
                        $executionContext,
                        (int) $freshTask->getKey(),
                    );
                    $this->aiExecutionAccessGuard->assertCurrent($executionContext);
                    $aiModel = $this->aiModelInvocationGateway->assertReceiptCurrent(
                        $executionContext,
                        $generation['receipt'],
                    );

                    $generationBlockReason = $this->getGenerationBlockReason($freshTask, true);
                    if ($generationBlockReason !== null) {
                        throw new RuntimeException($generationBlockReason);
                    }
                    $freshTask->loadMissing(['qualityPrompt', 'knowledgeBases']);
                    $qualityPolicy = $this->articleAiQualityPolicyResolver->fromTask($freshTask);
                    $qualityPolicySnapshot = $this->articleAiQualityPolicyResolver->snapshot($qualityPolicy);
                    $workflow = [
                        'status' => 'draft',
                        'review_status' => (int) ($freshTask->need_review ?? 1) === 1 ? 'pending' : 'approved',
                        'published_at' => null,
                    ];

                    $pendingWorkflow = ArticleWorkflow::normalizeState('draft', 'pending');
                    $article = Article::query()->create([
                        'title' => (string) $titleRow->title,
                        'slug' => ArticleWorkflow::generateUniqueSlug((string) $titleRow->title),
                        'excerpt' => $excerpt,
                        'content' => $content,
                        'category_id' => $category?->id,
                        'author_id' => $author?->id,
                        'task_id' => (int) $task->id,
                        'source_title_id' => (int) $titleRow->id,
                        'original_keyword' => $keyword,
                        'keywords' => $keyword,
                        'meta_description' => mb_substr($excerpt, 0, 120),
                        'status' => $pendingWorkflow['status'],
                        'review_status' => $pendingWorkflow['review_status'],
                        'is_ai_generated' => 1,
                        'published_at' => $pendingWorkflow['published_at'],
                        'view_count' => 0,
                        'ai_quality_required_at_creation' => (bool) ($qualityPolicy['required'] ?? false),
                        'ai_quality_policy_snapshot' => $qualityPolicySnapshot,
                        'generation_evidence_snapshot' => $generationEvidenceSnapshot,
                    ]);

                    $this->articleRiskScanner->record($article, 'worker_generation');

                    if ($workflow['review_status'] === 'approved') {
                        try {
                            $this->articleWorkflowTransitionService->transition(
                                $article,
                                $workflow,
                                'worker_generation',
                                null,
                                null,
                                false,
                                $pendingWorkflow,
                            );
                        } catch (ArticleRiskGateException|ArticleAiQualityGateException) {
                            // 风险扫描和待审状态随当前生成事务一并保留。
                        }
                    }
                    if ($selectedImages !== []) {
                        foreach ($selectedImages as $position => $image) {
                            ArticleImage::query()->create([
                                'article_id' => (int) $article->id,
                                'image_id' => (int) $image->id,
                                'position' => $position,
                            ]);
                            Image::query()->whereKey((int) $image->id)->update([
                                'used_count' => DB::raw('COALESCE(used_count,0)+1'),
                                'usage_count' => DB::raw('COALESCE(usage_count,0)+1'),
                            ]);
                        }
                    }

                    // 保持与旧逻辑一致：每次任务执行会消耗标题并累加任务计数。
                    Title::query()->whereKey($titleRow->id)->increment('used_count');
                    Title::query()->whereKey($titleRow->id)->increment('usage_count');

                    $taskUpdate = [
                        'created_count' => DB::raw('COALESCE(created_count,0)+1'),
                        'loop_count' => DB::raw('COALESCE(loop_count,0)+1'),
                        'updated_at' => now(),
                    ];
                    if ($freshTask->next_publish_at === null || ! $freshTask->next_publish_at->greaterThan(now())) {
                        $taskUpdate['next_publish_at'] = now()->addSeconds($this->normalizePublishInterval($freshTask));
                    }
                    Task::query()->whereKey($task->id)->update($taskUpdate);

                    $qualityCheck = null;
                    if ($qualityPolicy['required'] ?? false) {
                        $qualityCheck = $this->articleAiQualityInspectionService->createOrReuse(
                            $article,
                            trigger: 'worker_generation',
                        );
                    }

                    $result = [
                        'article_id' => (int) $article->id,
                        'title' => (string) $titleRow->title,
                        'message' => '草稿生成成功',
                        'meta' => [
                            'task_id' => (int) $task->id,
                            'action' => 'generate_draft',
                            'title_id' => (int) $titleRow->id,
                            'author_id' => $author?->id,
                            'category_id' => $category?->id,
                            'knowledge_length' => mb_strlen($knowledgeContext, 'UTF-8'),
                            'knowledge_retrieval' => $knowledgeBundle['retrieval_meta'] ?? null,
                            'image_count' => count($selectedImages),
                            'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                            'used_model_id' => (int) $aiModel->id,
                            'used_model_name' => (string) $aiModel->name,
                            'model_attempts' => $generation['attempts'],
                            'ai_quality' => [
                                'required' => (bool) ($qualityPolicy['required'] ?? false),
                                'check_id' => $qualityCheck?->id,
                                'status' => $qualityCheck?->status,
                            ],
                        ],
                    ];

                    $this->completePersistedExecution($freshTask, $result, $executionContext, $executionStartedAt);

                    return $result;
                });
            },
        );
    }

    /**
     * 发布一个已审核草稿。生成与发布解耦后，Worker 每次执行优先释放到期草稿。
     *
     * @return array{article_id:int|null, title:string, message:string, meta:array<string,mixed>}|null
     */
    private function publishDueDraftArticle(
        Task $task,
        ?AiExecutionContext $executionContext = null,
        ?float $executionStartedAt = null,
    ): ?array {
        if ($task->next_publish_at !== null && $task->next_publish_at->greaterThan(now())) {
            return null;
        }

        $candidateArticleId = Article::query()
            ->where('task_id', (int) $task->id)
            ->where('status', 'draft')
            ->whereIn('review_status', ['approved', 'auto_approved'])
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->value('id');
        if (! $candidateArticleId) {
            return null;
        }

        return DB::transaction(function () use ($task, $candidateArticleId, $executionContext, $executionStartedAt): ?array {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'status', 'schedule_enabled', 'publish_interval', 'next_publish_at', 'publish_scope']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('任务未激活');
            }

            if ($freshTask->next_publish_at !== null && $freshTask->next_publish_at->greaterThan(now())) {
                return null;
            }

            if ($executionContext instanceof AiExecutionContext) {
                $this->jobQueueService->lockRunningJobForWorker(
                    $executionContext,
                    (int) $freshTask->getKey(),
                );
            }

            /** @var Article|null $article */
            $article = Article::query()
                ->whereKey((int) $candidateArticleId)
                ->where('task_id', (int) $task->id)
                ->where('status', 'draft')
                ->whereIn('review_status', ['approved', 'auto_approved'])
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first(['id', 'task_id', 'title', 'review_status']);
            if (! $article) {
                return null;
            }

            $qualityModelId = $this->articleAiQualityGate->modelIdThatWouldBeDispatched($article);
            if ($qualityModelId !== null) {
                if (! $executionContext instanceof AiExecutionContext) {
                    throw AiModelAccessException::configAccessRevokedForAdminId(
                        (int) ($freshTask->model_access_admin_id ?? 0),
                    );
                }
                $executionAdmin = $this->aiExecutionAccessGuard->assertCurrent($executionContext);
                $this->aiExecutionAccessGuard->assertModelCurrent(
                    $executionContext,
                    $qualityModelId,
                    $executionAdmin,
                );
            }

            $publishScope = (string) ($freshTask->publish_scope ?? 'local_and_distribution');
            $targetStatus = $publishScope === 'distribution_only' ? 'private' : 'published';
            $reviewStatus = (string) ($article->review_status ?: 'approved');
            $workflow = ArticleWorkflow::normalizeState($targetStatus, $reviewStatus);
            $fallbackWorkflow = ArticleWorkflow::normalizeState('draft', 'pending');

            try {
                $this->articleWorkflowTransitionService->transition(
                    $article,
                    $workflow,
                    'worker_publish',
                    null,
                    null,
                    $reviewStatus !== 'auto_approved',
                    $fallbackWorkflow,
                );
            } catch (ArticleRiskGateException) {
                return null;
            } catch (ArticleAiQualityGateException $exception) {
                if ($qualityModelId === null || $exception->getCheck() === null) {
                    return null;
                }

                $result = [
                    'article_id' => null,
                    'title' => (string) $article->title,
                    'message' => '草稿等待 AI 质检',
                    'meta' => [
                        'task_id' => (int) $freshTask->id,
                        'action' => 'await_ai_quality',
                        'ai_quality_check_id' => (int) $exception->getCheck()->id,
                        'ai_quality_error_code' => $exception->getErrorCode(),
                    ],
                ];
                $this->completePersistedExecution(
                    $freshTask,
                    $result,
                    $executionContext,
                    $executionStartedAt ?? microtime(true),
                );

                return $result;
            }

            $publishInterval = $this->normalizePublishInterval($freshTask);
            Task::query()->whereKey((int) $freshTask->id)->update([
                'published_count' => DB::raw('COALESCE(published_count,0)+1'),
                'next_publish_at' => now()->addSeconds($publishInterval),
                'updated_at' => now(),
            ]);

            $result = [
                'article_id' => (int) $article->id,
                'title' => (string) $article->title,
                'message' => '草稿发布成功',
                'meta' => [
                    'task_id' => (int) $freshTask->id,
                    'action' => 'publish_draft',
                    'publish_interval' => $publishInterval,
                ],
            ];

            $this->completePersistedExecution(
                $freshTask,
                $result,
                $executionContext,
                $executionStartedAt ?? microtime(true),
            );

            return $result;
        });
    }

    /**
     * 判断是否允许继续生成草稿。
     */
    private function getGenerationBlockReason(Task $task, bool $lock = false): ?string
    {
        $articleLimit = max(1, (int) ($task->article_limit ?? $task->draft_limit ?? 10));
        if ((int) ($task->created_count ?? 0) >= $articleLimit) {
            return '已达到文章总数上限';
        }

        $draftLimit = max(1, (int) ($task->draft_limit ?? 10));
        $draftQuery = Article::query()
            ->where('task_id', (int) $task->id)
            ->where('status', 'draft')
            ->whereNull('deleted_at');
        // PostgreSQL 不允许在 count(*) 聚合查询上追加 FOR UPDATE。
        // 这里的并发保护由任务行锁和 task_runs 的单任务串行队列保证，草稿计数不需要再单独加锁。

        if ($draftQuery->count() >= $draftLimit) {
            return '草稿池已满，等待审核或按间隔发布';
        }

        return null;
    }

    private function normalizePublishInterval(Task $task): int
    {
        return max(60, (int) ($task->publish_interval ?? 3600));
    }

    /**
     * 解析并校验任务绑定的 AI 模型（必须是 active + chat）。
     */
    private function resolveAiModel(Task $task, AiExecutionContext $executionContext): AiModel
    {
        $aiModel = $this->resolveConfiguredAiModel($task, $executionContext);
        if (($aiModel->status ?? 'inactive') !== 'active') {
            throw new RuntimeException('任务 AI 模型不可用');
        }

        return $aiModel;
    }

    /**
     * 读取任务绑定的聊天模型；智能切换会保留停用主模型的尝试记录并继续备用模型。
     */
    private function resolveConfiguredAiModel(Task $task, AiExecutionContext $executionContext): AiModel
    {
        $aiModelId = (int) ($executionContext->requestedModelId ?? 0);
        if ($aiModelId <= 0) {
            throw new RuntimeException('任务未配置 AI 模型');
        }

        $aiModel = $this->aiExecutionAccessGuard->assertModelCurrent($executionContext, $aiModelId);
        if (! in_array((string) ($aiModel->model_type ?? ''), ['', 'chat'], true)) {
            throw AiModelAccessException::modelUnavailable(
                $this->aiExecutionAccessGuard->assertCurrent($executionContext),
                $aiModel,
            );
        }

        return $aiModel;
    }

    /**
     * 固定模型只尝试主模型；智能切换按 failover_priority 依次尝试其它 active chat 模型。
     *
     * @template TResult
     *
     * @param  Closure(array{content:string,model:AiModel,receipt:array{model_id:int,request_id:string,configuration_digest:string},attempts:list<array{model_id:int,model_name:string,status:string,reason:?string}>}): TResult  $persistGeneratedContent
     * @return TResult
     */
    private function generateContentWithModelSelection(
        Task $task,
        string $contentPrompt,
        AiExecutionContext $executionContext,
        Closure $persistGeneratedContent,
    ): mixed {
        $mode = (string) ($task->model_selection_mode ?? 'fixed');
        $attempts = [];
        $lastMessage = '';

        foreach ($this->resolveAiModelCandidates($task, $executionContext) as $candidate) {
            $candidate = $this->aiExecutionAccessGuard->assertModelCurrent($executionContext, $candidate);

            $unavailableReason = $this->getAiModelUnavailableReason($candidate);
            if ($unavailableReason !== null) {
                $attempts[] = $this->buildModelAttempt($candidate, 'skipped', $unavailableReason);
                $lastMessage = $unavailableReason;
                if ($mode !== 'smart_failover') {
                    throw new RuntimeException($unavailableReason);
                }

                continue;
            }

            $persistenceStarted = false;
            try {
                return $this->generateContent(
                    $executionContext,
                    $candidate,
                    $contentPrompt,
                    function (array $generated) use ($executionContext, &$attempts, &$persistenceStarted, $persistGeneratedContent) {
                        $candidate = $generated['model'];
                        $this->aiExecutionAccessGuard->recordResolvedModel($executionContext, $candidate);
                        $attempts[] = $this->buildModelAttempt($candidate, 'success', null);
                        $persistenceStarted = true;

                        return $persistGeneratedContent([
                            'content' => $generated['content'],
                            'model' => $candidate,
                            'receipt' => $generated['receipt'],
                            'attempts' => $attempts,
                        ]);
                    },
                );
            } catch (AiModelAccessException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                if ($persistenceStarted) {
                    throw $exception;
                }

                $lastMessage = $this->aiExecutionErrorSanitizer->sanitize($exception);
                $attempts[] = $this->buildModelAttempt($candidate, 'failed', $lastMessage);

                if ($this->aiModelFailoverDecider->isPermanentProviderFailure($exception)) {
                    throw PermanentAiProviderException::fromProviderFailure($exception);
                }

                if ($mode !== 'smart_failover' || ! $this->aiModelFailoverDecider->shouldFailover($exception)) {
                    throw $exception;
                }
            }
        }

        if ($mode === 'smart_failover' && $attempts !== []) {
            throw new RuntimeException($this->buildFailoverErrorMessage($attempts, $lastMessage));
        }

        throw new RuntimeException('AI模型不可用或已达每日限制');
    }

    /**
     * @return list<AiModel>
     */
    private function resolveAiModelCandidates(Task $task, AiExecutionContext $executionContext): array
    {
        $primaryModel = $this->resolveConfiguredAiModel($task, $executionContext);
        if (($task->model_selection_mode ?? 'fixed') !== 'smart_failover') {
            return [$this->resolveAiModel($task, $executionContext)];
        }

        $scopedCandidates = $this->aiExecutionAccessGuard->resolveModelCandidates($executionContext, 'chat');
        $fallbackModels = array_values(array_filter(
            $scopedCandidates,
            static fn (AiModel $model): bool => (int) $model->getKey() !== (int) $primaryModel->getKey(),
        ));

        return array_values(array_merge([$primaryModel], $fallbackModels));
    }

    /**
     * Finish the persisted run inside the same transaction as its business result.
     *
     * @param  array{article_id:int|null,title:string,message:string,meta:array<string,mixed>}  $result
     */
    private function completePersistedExecution(
        Task $task,
        array $result,
        ?AiExecutionContext $executionContext,
        float $executionStartedAt,
    ): void {
        if (! $executionContext instanceof AiExecutionContext || $executionContext->taskRunId === null) {
            return;
        }

        $this->jobQueueService->completeJob(
            jobId: $executionContext->taskRunId,
            taskId: (int) $task->getKey(),
            articleId: $result['article_id'],
            durationMs: (int) round((microtime(true) - $executionStartedAt) * 1000),
            meta: $result['meta'],
            executionContext: $executionContext,
            executionLeaseToken: $executionContext->executionLeaseToken(),
            rejectStaleExecution: true,
        );
    }

    private function getAiModelUnavailableReason(AiModel $aiModel): ?string
    {
        if (($aiModel->status ?? 'inactive') !== 'active') {
            return 'AI模型不可用或已达每日限制';
        }

        return null;
    }

    /**
     * @return array{model_id:int,model_name:string,status:string,reason:?string}
     */
    private function buildModelAttempt(AiModel $aiModel, string $status, ?string $reason): array
    {
        return [
            'model_id' => (int) $aiModel->id,
            'model_name' => (string) $aiModel->name,
            'status' => $status,
            'reason' => $reason === null ? null : $this->aiExecutionErrorSanitizer->sanitize($reason, ''),
        ];
    }

    /**
     * @param  list<array{model_id:int,model_name:string,status:string,reason:?string}>  $attempts
     */
    private function buildFailoverErrorMessage(array $attempts, string $lastMessage): string
    {
        $summaries = [];
        foreach ($attempts as $attempt) {
            $reason = trim((string) ($attempt['reason'] ?? ''));
            $summaries[] = (string) $attempt['model_name'].($reason !== '' ? '（'.$reason.'）' : '');
        }

        return '智能模型切换已尝试：'.implode('；', $summaries).'。最终失败：'.$lastMessage;
    }

    private function pickTitle(Task $task): Title
    {
        $libraryId = (int) ($task->title_library_id ?? 0);
        if ($libraryId <= 0) {
            throw new TaskTitleReadinessException(
                $this->taskTitleReadinessService->inspectTask($task),
                409,
            );
        }

        $query = Title::query()->where('library_id', $libraryId);
        if ((int) ($task->is_loop ?? 0) !== 1) {
            $query->where(function ($builder): void {
                $builder->whereNull('used_count')->orWhere('used_count', '<=', 0);
            });
        }

        /** @var Title|null $title */
        $title = $query
            ->orderBy('used_count')
            ->orderBy('id')
            ->first();

        if (! $title) {
            throw new TaskTitleReadinessException(
                $this->taskTitleReadinessService->inspectTask($task),
                409,
            );
        }

        return $title;
    }

    private function pickAuthor(Task $task): Author
    {
        $authorId = (int) ($task->custom_author_id ?: $task->author_id);
        if ($authorId > 0) {
            $author = Author::query()->find($authorId);
            if ($author) {
                return $author;
            }
        }

        $author = Author::query()->orderBy('id')->first();
        if ($author) {
            return $author;
        }

        return Author::query()->firstOrCreate(
            ['name' => 'GEOFlow'],
            ['bio' => 'Default GEOFlow author for automated content generation.']
        );
    }

    private function pickCategory(Task $task): ?Category
    {
        if (($task->category_mode ?? 'smart') === 'fixed' && (int) ($task->fixed_category_id ?? 0) > 0) {
            return Category::query()->find((int) $task->fixed_category_id);
        }

        return Category::query()->orderBy('sort_order')->orderBy('id')->first();
    }

    /**
     * 构造正文提示词：优先精确替换变量；无变量的自定义提示词自动补齐任务上下文。
     */
    private function buildContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext): string
    {
        return $this->articleContentPromptRenderer->renderForWorker($title, $keyword, $promptContent, $knowledgeContext);
    }

    /**
     * 按任务配置检索知识库上下文并回填到 {{Knowledge}}。
     */
    private function resolveKnowledgeContext(
        Task $task,
        string $title,
        string $keyword,
        ?AiExecutionContext $executionContext,
    ): array {
        $knowledgeBaseIds = $this->resolveTaskKnowledgeBaseIds($task);
        if ($knowledgeBaseIds === []) {
            return ['context' => '', 'evidence' => []];
        }

        $knowledgeBases = KnowledgeBase::query()
            ->whereIn('id', $knowledgeBaseIds)
            ->select(['id'])
            ->selectRaw('SUBSTR(content, 1, ?) AS content_excerpt', [2400])
            ->get()
            ->keyBy('id');
        if ($knowledgeBases->isEmpty()) {
            return ['context' => '', 'evidence' => []];
        }

        $fallbackContents = [];
        foreach ($knowledgeBaseIds as $knowledgeBaseId) {
            /** @var KnowledgeBase|null $knowledgeBase */
            $knowledgeBase = $knowledgeBases->get($knowledgeBaseId);
            if (! $knowledgeBase) {
                continue;
            }

            $content = trim((string) ($knowledgeBase->content_excerpt ?? ''));
            if ($content === '') {
                continue;
            }

            $fallbackContents[$knowledgeBaseId] = $content;
        }

        if ($fallbackContents === []) {
            return ['context' => '', 'evidence' => []];
        }

        $query = trim($title."\n".$keyword);
        $bundle = $this->knowledgeRetrievalService->retrieveContextBundleFromMany(
            $knowledgeBaseIds,
            $query,
            5,
            3200,
            $executionContext,
        );
        if ($bundle['context'] !== '') {
            return $bundle;
        }

        $chunkCount = KnowledgeChunk::query()->whereIn('knowledge_base_id', $knowledgeBaseIds)->count();
        if ($chunkCount > 0) {
            return ['context' => '', 'evidence' => []];
        }

        return ['context' => $this->fallbackKnowledgeContext($fallbackContents, 2400), 'evidence' => []];
    }

    /** @param list<array<string,mixed>> $evidence @return list<array<string,mixed>> */
    private function generationEvidenceSnapshot(array $evidence): array
    {
        return array_values(array_map(static function (array $item): array {
            $content = trim((string) ($item['content'] ?? ''));
            $contentHash = (string) ($item['content_hash'] ?? hash('sha256', $content));

            return [
                'stable_key' => (int) ($item['knowledge_base_id'] ?? 0).':'.(int) ($item['chunk_id'] ?? 0).':'.$contentHash,
                'knowledge_base_id' => (int) ($item['knowledge_base_id'] ?? 0),
                'chunk_id' => (int) ($item['chunk_id'] ?? 0),
                'chunk_index' => (int) ($item['chunk_index'] ?? 0),
                'content_hash' => $contentHash,
                'source_hash' => (string) ($item['source_hash'] ?? ''),
                'snippet' => mb_substr($content, 0, 500, 'UTF-8'),
            ];
        }, $evidence));
    }

    /**
     * @return list<int>
     */
    private function resolveTaskKnowledgeBaseIds(Task $task): array
    {
        $taskId = (int) ($task->id ?? 0);
        if ($taskId > 0 && Schema::hasTable('task_knowledge_bases')) {
            $ids = DB::table('task_knowledge_bases')
                ->where('task_id', $taskId)
                ->orderBy('sort_order')
                ->orderBy('knowledge_base_id')
                ->pluck('knowledge_base_id')
                ->map(static fn ($id): int => (int) $id)
                ->filter(static fn (int $id): bool => $id > 0)
                ->unique()
                ->take(5)
                ->values()
                ->all();

            if ($ids !== []) {
                return $ids;
            }
        }

        $legacyKnowledgeBaseId = (int) ($task->knowledge_base_id ?? 0);

        return $legacyKnowledgeBaseId > 0 ? [$legacyKnowledgeBaseId] : [];
    }

    /**
     * @param  array<int,string>  $contents
     */
    private function fallbackKnowledgeContext(array $contents, int $maxChars): string
    {
        $parts = [];
        $charCount = 0;

        foreach ($contents as $knowledgeBaseId => $content) {
            $content = trim($content);
            if ($content === '') {
                continue;
            }

            $header = '【知识库 '.$knowledgeBaseId.'】';
            $remaining = max(0, $maxChars - $charCount - mb_strlen($header, 'UTF-8') - 2);
            if ($remaining <= 0) {
                break;
            }

            $snippet = mb_strlen($content, 'UTF-8') > $remaining
                ? mb_substr($content, 0, $remaining, 'UTF-8')
                : $content;
            $parts[] = $header."\n".$snippet;
            $charCount += mb_strlen($header."\n".$snippet, 'UTF-8');
        }

        return $parts === [] ? '' : implode("\n\n", $parts);
    }

    /**
     * 按任务图片配置插入 Markdown 配图并返回被选中的图片列表。
     *
     * @return array{content:string,images:list<Image>}
     */
    private function insertTaskImagesIntoContent(Task $task, string $content): array
    {
        $libraryId = (int) ($task->image_library_id ?? 0);
        $imageCount = max(0, (int) ($task->image_count ?? 0));
        if ($libraryId <= 0 || $imageCount <= 0) {
            return ['content' => $content, 'images' => []];
        }

        /** @var list<Image> $images */
        $images = Image::query()
            ->where('library_id', $libraryId)
            ->inRandomOrder()
            ->limit($imageCount)
            ->get(['id', 'file_path', 'original_name'])
            ->all();
        if ($images === []) {
            return ['content' => $content, 'images' => []];
        }

        $markdownBlocks = [];
        foreach ($images as $image) {
            $path = trim((string) ($image->file_path ?? ''));
            if ($path === '') {
                continue;
            }
            $path = ImageUrlNormalizer::toPublicUrl($path);
            $alt = ImageUrlNormalizer::readableAlt((string) ($image->original_name ?? ''));
            $markdownBlocks[] = '!['.($alt !== '' ? $alt : 'image').']('.$path.')';
        }

        if ($markdownBlocks !== []) {
            $content = $this->insertImagesByParagraphInterval($content, $markdownBlocks);
        }

        return ['content' => $content, 'images' => $images];
    }

    /**
     * 按段落间隔插入图片，避免全部堆在文末。
     *
     * @param  list<string>  $markdownBlocks
     */
    private function insertImagesByParagraphInterval(string $content, array $markdownBlocks): string
    {
        $trimmed = trim($content);
        if ($trimmed === '' || $markdownBlocks === []) {
            return $content;
        }

        $paragraphs = preg_split("/\n{2,}/u", $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($paragraphs === []) {
            return $trimmed."\n\n".implode("\n\n", $markdownBlocks);
        }

        $paragraphCount = count($paragraphs);
        $imageCount = count($markdownBlocks);
        $interval = max(1, (int) floor($paragraphCount / ($imageCount + 1)));

        $parts = [];
        $imageIndex = 0;
        foreach ($paragraphs as $index => $paragraph) {
            $parts[] = trim((string) $paragraph);
            $nextParagraphPosition = $index + 1;

            if (
                $imageIndex < $imageCount
                && $nextParagraphPosition % $interval === 0
                && $nextParagraphPosition < $paragraphCount
            ) {
                $parts[] = $markdownBlocks[$imageIndex];
                $imageIndex++;
            }
        }

        while ($imageIndex < $imageCount) {
            $parts[] = $markdownBlocks[$imageIndex];
            $imageIndex++;
        }

        return implode("\n\n", array_values(array_filter($parts, static fn (string $part): bool => trim($part) !== '')));
    }

    /**
     * 调用任务配置模型生成正文。
     *
     * @template TResult
     *
     * @param  Closure(array{content:string,model:AiModel,receipt:array{model_id:int,request_id:string,configuration_digest:string}}): TResult  $persistGeneratedContent
     * @return TResult
     */
    private function generateContent(
        AiExecutionContext $executionContext,
        AiModel $aiModel,
        string $contentPrompt,
        Closure $persistGeneratedContent,
    ): mixed {
        return $this->aiModelInvocationGateway->generate(
            $executionContext,
            $aiModel,
            $contentPrompt,
            function (array $invocation) use ($persistGeneratedContent) {
                $aiModel = $invocation['model'];
                $response = $invocation['response'];

                $rawContent = (string) ($response->text ?? '');
                $content = $this->articleCitationMarkerCleaner->cleanContent(
                    OpenAiRuntimeProvider::normalizeGeneratedText($rawContent),
                );
                if ($content === '') {
                    if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                        throw new RuntimeException('AI 返回空流式响应，未生成正文内容，请重试或检查模型流式输出兼容性');
                    }

                    throw new RuntimeException('AI返回空正文');
                }

                $this->warnIfContentLooksTruncated($content, $aiModel, $response);

                return $persistGeneratedContent([
                    'content' => $content,
                    'model' => $aiModel,
                    'receipt' => $invocation['receipt'],
                ]);
            }
        );
    }

    /**
     * 解析模型的最大输出 token 数：优先用模型自身配置，未配置时回退全局默认值。
     */
    private function resolveMaxTokens(AiModel $aiModel): int
    {
        return $this->aiModelInvocationGateway->maxTokens($aiModel);
    }

    /**
     * 检测生成正文是否疑似被模型截断（输出 token 用尽）。
     *
     * 仅记录告警便于排查，不阻断流程：典型信号是未闭合的代码围栏（``` 数量为奇数），
     * 或正文结尾未落在正常的句末标点上。命中后提示调大该模型的 max_tokens。
     */
    private function warnIfContentLooksTruncated(string $content, AiModel $aiModel, object $response): void
    {
        $trimmed = rtrim($content);
        if ($trimmed === '') {
            return;
        }

        $maxTokens = $this->resolveMaxTokens($aiModel);
        $completionTokens = (int) ($response->usage->completionTokens ?? 0);
        $nearTokenLimit = $completionTokens > 0 && $completionTokens >= (int) floor($maxTokens * 0.92);
        $lengthFinishReason = collect($response->steps ?? [])->contains(function (mixed $step): bool {
            $finishReason = is_object($step) ? ($step->finishReason ?? null) : null;

            return $finishReason === FinishReason::Length
                || (is_string($finishReason) && $finishReason === FinishReason::Length->value)
                || (is_object($finishReason) && property_exists($finishReason, 'value') && $finishReason->value === FinishReason::Length->value);
        });

        $fenceCount = substr_count($trimmed, '```');
        $unclosedFence = ($fenceCount % 2) === 1;

        $lastChar = mb_substr($trimmed, -1);
        $allowedEndings = ['。', '！', '？', '.', '!', '?', '”', '"', '）', ')', '》', '`', '】', ']', '…', ':', '：', ';', '；', '-', '—'];
        $hasAbruptTrailingText = $nearTokenLimit
            && ! in_array($lastChar, $allowedEndings, true)
            && preg_match('/[\p{L}\p{N}]$/u', $trimmed) === 1;

        if (! $lengthFinishReason && ! $unclosedFence && ! $hasAbruptTrailingText) {
            return;
        }

        Log::warning('GeoFlow 正文疑似被截断，建议调大该模型的 max_tokens', [
            'ai_model_id' => (int) $aiModel->id,
            'model_id' => (string) ($aiModel->model_id ?? ''),
            'max_tokens' => $maxTokens,
            'completion_tokens' => $completionTokens,
            'content_length' => mb_strlen($trimmed),
            'finish_reason_length' => $lengthFinishReason,
            'unclosed_code_fence' => $unclosedFence,
            'has_abrupt_trailing_text' => $hasAbruptTrailingText,
        ]);
    }

    /**
     * 从正文提取摘要，避免把完整提示词原文当摘要。
     */
    private function buildExcerpt(string $content): string
    {
        $plain = preg_replace('/[`#>*_\-\[\]\(\)]/u', ' ', $content) ?: $content;
        $plain = preg_replace('/\s+/u', ' ', $plain) ?: $plain;
        $plain = trim($plain);
        if ($plain === '') {
            return 'AI 生成内容摘要';
        }

        return mb_substr($plain, 0, 180);
    }
}
