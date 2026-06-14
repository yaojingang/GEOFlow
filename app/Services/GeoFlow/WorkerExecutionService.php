<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\ArticleImage;
use App\Models\Author;
use App\Models\CaseRecord;
use App\Models\Category;
use App\Models\EntityRecord;
use App\Models\Image;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\Prompt;
use App\Models\Task;
use App\Models\Title;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\GeoFlow\CaseTypes;
use App\Support\GeoFlow\EntityTypes;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\Data\FinishReason;
use RuntimeException;
use Throwable;

/**
 * Worker 任务执行器：将队列任务落地为文章记录（占位实现，先打通 worker/队列链路）。
 */
class WorkerExecutionService
{
    /**
     * 最近一次生成链路的知识检索追踪，随 executeTask 写入 task_runs.meta。
     *
     * @var array<string,mixed>
     */
    private array $lastKnowledgeTrace = [];

    /**
     * @var list<array<string,mixed>>
     */
    private array $lastKnowledgeChunkTrace = [];

    /**
     * @var array{entities:list<array<string,mixed>>,cases:list<array<string,mixed>>}
     */
    private array $lastEntityCaseTrace = ['entities' => [], 'cases' => []];

    /**
     * 复用统一 API Key 解密组件，确保 worker 与后台配置端解密行为一致。
     */
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly KnowledgeChunkSyncService $knowledgeChunkSyncService,
        private readonly RagRetrievalService $ragRetrievalService,
        private readonly DistributionOrchestrator $distributionOrchestrator,
        private readonly TagService $tagService
    ) {}

    /**
     * @return array{article_id:int|null, title:string, message:string, meta:array<string,mixed>}
     */
    public function executeTask(int $taskId): array
    {
        /** @var Task|null $task */
        $task = Task::query()->find($taskId);
        if (! $task) {
            throw new RuntimeException('任务不存在');
        }

        if (($task->status ?? 'paused') !== 'active' || (int) ($task->schedule_enabled ?? 1) !== 1) {
            throw new RuntimeException('任务未激活');
        }

        $publishResult = $this->publishDueDraftArticle($task);
        if ($publishResult !== null) {
            $this->distributionOrchestrator->enqueueForArticle((int) $publishResult['article_id']);

            return $publishResult;
        }

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

        $pipeline = $this->runArticleGenerationPipeline($task);
        $articleId = $this->persistGeneratedDraft($task, $pipeline);
        /** @var Title $titleRow */
        $titleRow = $pipeline['titleRow'];
        /** @var AiModel $aiModel */
        $aiModel = $pipeline['aiModel'];
        /** @var Author|null $author */
        $author = $pipeline['author'];
        /** @var Category|null $category */
        $category = $pipeline['category'];

        return [
            'article_id' => $articleId,
            'title' => (string) $titleRow->title,
            'message' => '草稿生成成功',
            'meta' => [
                'task_id' => (int) $task->id,
                'action' => 'generate_draft',
                'title_id' => (int) $titleRow->id,
                'author_id' => $author?->id,
                'category_id' => $category?->id,
                'knowledge_length' => mb_strlen((string) $pipeline['knowledgeContext'], 'UTF-8'),
                'image_count' => count($pipeline['selectedImages']),
                'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                'used_model_id' => (int) $aiModel->id,
                'used_model_name' => (string) $aiModel->name,
                'model_attempts' => $pipeline['generationAttempts'],
                'generation_trace' => $this->buildGenerationTrace(
                    task: $task,
                    titleRow: $titleRow,
                    keyword: (string) $pipeline['keyword'],
                    author: $author,
                    category: $category,
                    prompt: $pipeline['prompt'],
                    skillPrompt: $pipeline['skillPrompt'],
                    aiModel: $aiModel,
                    generationAttempts: $pipeline['generationAttempts'],
                    knowledgeContext: (string) $pipeline['knowledgeContext'],
                    selectedImages: $pipeline['selectedImages'],
                    pipelineSteps: $pipeline['pipelineSteps']
                ),
            ],
        ];
    }

    /**
     * @return array{
     *   titleRow:Title,
     *   author:Author|null,
     *   category:Category|null,
     *   prompt:Prompt|null,
     *   skillPrompt:Prompt|null,
     *   keyword:string,
     *   knowledgeContext:string,
     *   contentPrompt:string,
     *   generatedContent:string,
     *   content:string,
     *   excerpt:string,
     *   workflow:array{status:string,review_status:string,published_at:null},
     *   aiModel:AiModel,
     *   generationAttempts:list<array<string,mixed>>,
     *   selectedImages:list<Image>,
     *   pipelineSteps:list<array<string,mixed>>
     * }
     */
    private function runArticleGenerationPipeline(Task $task): array
    {
        $pipelineSteps = [];

        $titleRow = $this->pickTitle($task);
        $author = $this->pickAuthor($task);
        $category = $this->pickCategory($task);
        $prompt = $task->prompt_id ? Prompt::query()->find((int) $task->prompt_id) : null;
        $skillPrompt = $task->skill_prompt_id ? Prompt::query()->whereKey((int) $task->skill_prompt_id)->where('type', 'skill')->first() : null;
        $keyword = (string) ($titleRow->keyword ?? '');
        $pipelineSteps[] = $this->pipelineStep('select_sources', [
            'title_id' => (int) $titleRow->id,
            'author_id' => $author?->id,
            'category_id' => $category?->id,
            'prompt_id' => $prompt?->id,
            'skill_prompt_id' => $skillPrompt?->id,
        ]);

        $knowledgeContext = $this->resolveKnowledgeContext($task, (string) $titleRow->title, $keyword);
        $pipelineSteps[] = $this->pipelineStep('retrieve_context', [
            'strategy' => (string) ($this->lastKnowledgeTrace['strategy'] ?? 'none'),
            'context_length' => mb_strlen($knowledgeContext, 'UTF-8'),
            'chunks' => count($this->lastKnowledgeTrace['chunks'] ?? []),
            'entities' => count($this->lastKnowledgeTrace['entities'] ?? []),
            'cases' => count($this->lastKnowledgeTrace['cases'] ?? []),
        ]);

        $composedPromptContent = $this->composeMasterAndSkillPrompt($prompt?->content, $skillPrompt?->content);
        $targetLanguage = $this->determineGenerationLanguage((string) $titleRow->title, $keyword, $composedPromptContent);
        $contentPrompt = $this->buildContentPrompt((string) $titleRow->title, $keyword, $composedPromptContent, $knowledgeContext, $targetLanguage);
        $pipelineSteps[] = $this->pipelineStep('compose_prompt', [
            'prompt_length' => mb_strlen($contentPrompt, 'UTF-8'),
            'has_custom_prompt' => $prompt !== null,
            'has_skill_prompt' => $skillPrompt !== null,
            'target_language' => $targetLanguage,
        ]);

        $generation = $this->generateContentWithModelSelection($task, $contentPrompt);
        /** @var AiModel $aiModel */
        $aiModel = $generation['model'];
        $generatedContent = (string) $generation['content'];
        $generationAttempts = is_array($generation['attempts'] ?? null) ? $generation['attempts'] : [];
        $pipelineSteps[] = $this->pipelineStep('generate_article', [
            'model_id' => (int) $aiModel->id,
            'model_name' => (string) $aiModel->name,
            'content_length' => mb_strlen($generatedContent, 'UTF-8'),
            'attempts' => count($generationAttempts),
        ]);

        $imageResult = $this->insertTaskImagesIntoContent($task, $generatedContent);
        $content = (string) $imageResult['content'];
        $selectedImages = $imageResult['images'];
        $pipelineSteps[] = $this->pipelineStep('attach_images', [
            'image_count' => count($selectedImages),
            'content_length' => mb_strlen($content, 'UTF-8'),
        ]);

        $excerpt = $this->buildExcerpt($content);
        $workflow = [
            'status' => 'draft',
            'review_status' => (int) ($task->need_review ?? 1) === 1 ? 'pending' : 'approved',
            'published_at' => null,
        ];
        $pipelineSteps[] = $this->pipelineStep('prepare_draft', [
            'excerpt_length' => mb_strlen($excerpt, 'UTF-8'),
            'review_status' => $workflow['review_status'],
        ]);

        return [
            'titleRow' => $titleRow,
            'author' => $author,
            'category' => $category,
            'prompt' => $prompt,
            'skillPrompt' => $skillPrompt,
            'keyword' => $keyword,
            'knowledgeContext' => $knowledgeContext,
            'contentPrompt' => $contentPrompt,
            'generatedContent' => $generatedContent,
            'content' => $content,
            'excerpt' => $excerpt,
            'workflow' => $workflow,
            'aiModel' => $aiModel,
            'generationAttempts' => $generationAttempts,
            'selectedImages' => $selectedImages,
            'pipelineSteps' => $pipelineSteps,
        ];
    }

    /**
     * @param  array<string,mixed>  $pipeline
     */
    private function persistGeneratedDraft(Task $task, array $pipeline): int
    {
        /** @var Title $titleRow */
        $titleRow = $pipeline['titleRow'];
        /** @var Author|null $author */
        $author = $pipeline['author'];
        /** @var Category|null $category */
        $category = $pipeline['category'];
        $keyword = (string) $pipeline['keyword'];
        $content = (string) $pipeline['content'];
        $excerpt = (string) $pipeline['excerpt'];
        /** @var array{status:string,review_status:string,published_at:null} $workflow */
        $workflow = $pipeline['workflow'];
        /** @var list<Image> $selectedImages */
        $selectedImages = $pipeline['selectedImages'];
        $contextMetadata = $this->articleContextMetadataFromTrace($this->lastKnowledgeTrace);

        return DB::transaction(function () use ($task, $titleRow, $author, $category, $keyword, $content, $excerpt, $workflow, $selectedImages, $contextMetadata): int {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'collection_id', 'status', 'schedule_enabled', 'created_count', 'draft_limit', 'article_limit', 'publish_interval', 'next_publish_at']);
            if (! $freshTask || ($freshTask->status ?? 'paused') !== 'active' || (int) ($freshTask->schedule_enabled ?? 1) !== 1) {
                throw new RuntimeException('任务未激活');
            }
            $generationBlockReason = $this->getGenerationBlockReason($freshTask, true);
            if ($generationBlockReason !== null) {
                throw new RuntimeException($generationBlockReason);
            }

            $article = Article::query()->create([
                'title' => (string) $titleRow->title,
                'slug' => ArticleWorkflow::generateUniqueSlug((string) $titleRow->title),
                'excerpt' => $excerpt,
                'content' => $content,
                'category_id' => $category?->id,
                'author_id' => $author?->id,
                'task_id' => (int) $task->id,
                'selected_collection_id' => $contextMetadata['selected_collection_id'] ?? ((int) ($freshTask->collection_id ?? 0) ?: null),
                'selected_entity_ids' => $contextMetadata['selected_entity_ids'],
                'selected_case_ids' => $contextMetadata['selected_case_ids'],
                'used_knowledge_base_ids' => $contextMetadata['used_knowledge_base_ids'],
                'used_tags' => $contextMetadata['used_tags'],
                'context_snapshot' => $contextMetadata['context_snapshot'],
                'original_keyword' => $keyword,
                'keywords' => $keyword,
                'meta_description' => mb_substr($excerpt, 0, 120),
                'status' => $workflow['status'],
                'review_status' => $workflow['review_status'],
                'is_ai_generated' => 1,
                'published_at' => $workflow['published_at'],
                'view_count' => 0,
            ]);
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

            return (int) $article->id;
        });
    }

    /**
     * @param  array<string,mixed>  $meta
     * @return array<string,mixed>
     */
    private function pipelineStep(string $name, array $meta = []): array
    {
        return [
            'name' => $name,
            'status' => 'completed',
            'meta' => $meta,
        ];
    }

    /**
     * @param  list<Image>  $selectedImages
     * @param  list<array<string,mixed>>  $generationAttempts
     * @param  list<array<string,mixed>>  $pipelineSteps
     * @return array<string,mixed>
     */
    private function buildGenerationTrace(
        Task $task,
        Title $titleRow,
        string $keyword,
        ?Author $author,
        ?Category $category,
        ?Prompt $prompt,
        ?Prompt $skillPrompt,
        AiModel $aiModel,
        array $generationAttempts,
        string $knowledgeContext,
        array $selectedImages,
        array $pipelineSteps = []
    ): array {
        return [
            'version' => 1,
            'generated_at' => now()->toDateTimeString(),
            'pipeline' => $pipelineSteps,
            'task' => [
                'id' => (int) $task->id,
                'name' => (string) ($task->name ?? ''),
                'collection_id' => $task->collection_id !== null ? (int) $task->collection_id : null,
                'knowledge_tag_filter' => (string) ($task->knowledge_tag_filter ?? ''),
                'entity_filter' => (string) ($task->entity_filter ?? ''),
                'image_tag_filter' => (string) ($task->image_tag_filter ?? ''),
                'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
            ],
            'title' => [
                'id' => (int) $titleRow->id,
                'text' => (string) $titleRow->title,
                'keyword' => $keyword,
            ],
            'author' => $author ? ['id' => (int) $author->id, 'name' => (string) $author->name] : null,
            'category' => $category ? ['id' => (int) $category->id, 'name' => (string) $category->name] : null,
            'prompt' => $prompt ? ['id' => (int) $prompt->id, 'name' => (string) $prompt->name, 'type' => (string) $prompt->type] : null,
            'skill_prompt' => $skillPrompt ? ['id' => (int) $skillPrompt->id, 'name' => (string) $skillPrompt->name, 'type' => (string) $skillPrompt->type] : null,
            'language' => [
                'code' => $this->determineGenerationLanguage((string) $titleRow->title, $keyword, $this->composeMasterAndSkillPrompt($prompt?->content, $skillPrompt?->content)),
            ],
            'model' => [
                'id' => (int) $aiModel->id,
                'name' => (string) $aiModel->name,
                'model_id' => (string) ($aiModel->model_id ?? ''),
                'provider' => (string) ($aiModel->provider ?? ''),
            ],
            'model_attempts' => $generationAttempts,
            'knowledge' => array_merge($this->lastKnowledgeTrace, [
                'context_length' => mb_strlen($knowledgeContext, 'UTF-8'),
            ]),
            'images' => array_map(static fn (Image $image): array => [
                'id' => (int) $image->id,
                'library_id' => (int) ($image->library_id ?? 0),
                'filename' => (string) ($image->filename ?? ''),
                'original_name' => (string) ($image->original_name ?? ''),
                'file_path' => (string) ($image->file_path ?? ''),
            ], $selectedImages),
        ];
    }

    /**
     * @param  array<string,mixed>  $trace
     * @return array{selected_collection_id:int|null,selected_entity_ids:list<int>,selected_case_ids:list<int>,used_knowledge_base_ids:list<int>,used_tags:list<string>,context_snapshot:array<string,mixed>}
     */
    private function articleContextMetadataFromTrace(array $trace): array
    {
        $package = is_array($trace['context_package'] ?? null) ? $trace['context_package'] : $trace;

        return [
            'selected_collection_id' => isset($package['selected_collection_id'])
                ? ((int) $package['selected_collection_id'] ?: null)
                : (isset($trace['collection_id']) ? ((int) $trace['collection_id'] ?: null) : null),
            'selected_entity_ids' => $this->integerList($package['selected_entity_ids'] ?? $trace['entity_filter_ids'] ?? []),
            'selected_case_ids' => $this->integerList($package['selected_case_ids'] ?? $trace['case_filter_ids'] ?? []),
            'used_knowledge_base_ids' => $this->integerList($package['used_knowledge_base_ids'] ?? $trace['knowledge_base_ids'] ?? []),
            'used_tags' => $this->stringList($package['used_tags'] ?? $trace['tag_filters'] ?? []),
            'context_snapshot' => $package,
        ];
    }

    /**
     * @return list<int>
     */
    private function integerList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(static fn (mixed $item): int => (int) $item)
            ->filter(static fn (int $item): bool => $item > 0)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter(static fn (string $item): bool => $item !== '')
            ->unique(static fn (string $item): string => mb_strtolower($item, 'UTF-8'))
            ->values()
            ->all();
    }

    private function detectPromptLanguage(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') {
            return 'unknown';
        }
        $han = preg_match_all('/\p{Han}/u', $text);
        if ($han > 10) {
            return 'zh';
        }
        preg_match_all('/[A-Za-z]/', $text, $latinMatches);
        if ($han === 0 && count($latinMatches[0] ?? []) > 20) {
            return 'en';
        }

        return preg_match('/\b(?:the|and|for|with|how|what|why|service|customer|business|company)\b/u', $text) === 1
            ? 'en'
            : 'unknown';
    }

    private function determineGenerationLanguage(string $title, string $keyword, ?string $promptContent): string
    {
        $titleKeywordLanguage = $this->detectPromptLanguage(trim($title."\n".$keyword));
        if ($titleKeywordLanguage !== 'unknown') {
            return $titleKeywordLanguage;
        }

        $promptLanguage = $this->detectPromptLanguage((string) $promptContent);

        return $promptLanguage !== 'unknown' ? $promptLanguage : 'zh';
    }

    /**
     * 发布一个已审核草稿。生成与发布解耦后，Worker 每次执行优先释放到期草稿。
     *
     * @return array{article_id:int, title:string, message:string, meta:array<string,mixed>}|null
     */
    private function publishDueDraftArticle(Task $task): ?array
    {
        if ($task->next_publish_at !== null && $task->next_publish_at->greaterThan(now())) {
            return null;
        }

        return DB::transaction(function () use ($task): ?array {
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

            /** @var Article|null $article */
            $article = Article::query()
                ->where('task_id', (int) $freshTask->id)
                ->where('status', 'draft')
                ->whereIn('review_status', ['approved', 'auto_approved'])
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first(['id', 'title', 'review_status']);
            if (! $article) {
                return null;
            }

            $publishScope = (string) ($freshTask->publish_scope ?? 'local_and_distribution');
            $targetStatus = $publishScope === 'distribution_only' ? 'private' : 'published';
            $workflow = ArticleWorkflow::normalizeState($targetStatus, (string) ($article->review_status ?: 'approved'));
            Article::query()->whereKey((int) $article->id)->update([
                'status' => $workflow['status'],
                'review_status' => $workflow['review_status'],
                'published_at' => $workflow['published_at'],
                'updated_at' => now(),
            ]);

            $publishInterval = $this->normalizePublishInterval($freshTask);
            Task::query()->whereKey((int) $freshTask->id)->update([
                'published_count' => DB::raw('COALESCE(published_count,0)+1'),
                'next_publish_at' => now()->addSeconds($publishInterval),
                'updated_at' => now(),
            ]);

            return [
                'article_id' => (int) $article->id,
                'title' => (string) $article->title,
                'message' => '草稿发布成功',
                'meta' => [
                    'task_id' => (int) $freshTask->id,
                    'action' => 'publish_draft',
                    'publish_interval' => $publishInterval,
                ],
            ];
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
    private function resolveAiModel(Task $task): AiModel
    {
        $aiModelId = (int) ($task->ai_model_id ?? 0);
        if ($aiModelId <= 0) {
            throw new RuntimeException('任务未配置 AI 模型');
        }

        $aiModel = AiModel::query()
            ->whereKey($aiModelId)
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->first();

        if (! $aiModel) {
            throw new RuntimeException('任务 AI 模型不可用');
        }

        return $aiModel;
    }

    /**
     * 固定模型只尝试主模型；智能切换按 failover_priority 依次尝试其它 active chat 模型。
     *
     * @return array{content:string,model:AiModel,attempts:list<array{model_id:int,model_name:string,status:string,reason:?string}>}
     */
    private function generateContentWithModelSelection(Task $task, string $contentPrompt): array
    {
        $mode = (string) ($task->model_selection_mode ?? 'fixed');
        $attempts = [];
        $lastMessage = '';

        foreach ($this->resolveAiModelCandidates($task) as $candidate) {
            $unavailableReason = $this->getAiModelUnavailableReason($candidate);
            if ($unavailableReason !== null) {
                $attempts[] = $this->buildModelAttempt($candidate, 'skipped', $unavailableReason);
                $lastMessage = $unavailableReason;
                if ($mode !== 'smart_failover') {
                    throw new RuntimeException($unavailableReason);
                }

                continue;
            }

            try {
                $content = $this->generateContent($candidate, $contentPrompt);
                $attempts[] = $this->buildModelAttempt($candidate, 'success', null);

                return [
                    'content' => $content,
                    'model' => $candidate,
                    'attempts' => $attempts,
                ];
            } catch (Throwable $exception) {
                $lastMessage = trim($exception->getMessage());
                $attempts[] = $this->buildModelAttempt($candidate, 'failed', $lastMessage);

                if ($mode !== 'smart_failover') {
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
    private function resolveAiModelCandidates(Task $task): array
    {
        $primaryModel = $this->resolveAiModel($task);
        if (($task->model_selection_mode ?? 'fixed') !== 'smart_failover') {
            return [$primaryModel];
        }

        $fallbackModels = AiModel::query()
            ->whereKeyNot((int) $primaryModel->id)
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->orderBy('failover_priority')
            ->orderBy('id')
            ->get()
            ->all();

        return array_values(array_merge([$primaryModel], $fallbackModels));
    }

    private function getAiModelUnavailableReason(AiModel $aiModel): ?string
    {
        if (($aiModel->status ?? 'inactive') !== 'active') {
            return 'AI模型不可用或已达每日限制';
        }

        $dailyLimit = (int) ($aiModel->daily_limit ?? 0);
        $usedToday = (int) ($aiModel->used_today ?? 0);
        if ($dailyLimit > 0 && $usedToday >= $dailyLimit) {
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
            'reason' => $reason,
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
            throw new RuntimeException('任务未配置标题库');
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
            throw new RuntimeException((int) ($task->is_loop ?? 0) === 1 ? '没有可用的标题' : '标题库已用尽');
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
    private function composeMasterAndSkillPrompt(?string $masterPromptContent, ?string $skillPromptContent): ?string
    {
        $masterPrompt = trim((string) $masterPromptContent);
        $skillPrompt = trim((string) $skillPromptContent);

        if ($masterPrompt === '') {
            return $skillPrompt !== '' ? $skillPrompt : null;
        }

        if ($skillPrompt === '') {
            return $masterPrompt;
        }

        return trim("=== Master Prompt ===\n{$masterPrompt}\n\n=== Skill Prompt ===\n{$skillPrompt}");
    }

    private function buildContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext, string $targetLanguage): string
    {
        $prompt = trim((string) $promptContent);
        $isFallbackPrompt = false;
        if ($prompt === '') {
            $prompt = $targetLanguage === 'zh'
                ? "请围绕标题“{$title}”和关键词“{$keyword}”生成一篇结构清晰、语言自然的中文文章。"
                : "Write a clear, well-structured article around the title \"{$title}\" and the keyword \"{$keyword}\".";
            $isFallbackPrompt = true;
        }

        $hasExplicitContextVariables = $isFallbackPrompt || $this->promptHasKnownContextVariables($prompt);
        $renderedPrompt = $this->renderPromptTemplate($prompt, [
            'title' => $title,
            'keyword' => $keyword,
            'knowledge' => $knowledgeContext,
        ]);

        if (! $hasExplicitContextVariables) {
            $renderedPrompt = $this->appendSmartPromptContext($renderedPrompt, $title, $keyword, $knowledgeContext, $targetLanguage);
        }

        return trim($renderedPrompt)."\n\n".$this->finalPromptInstruction($targetLanguage);
    }

    private function promptHasKnownContextVariables(string $prompt): bool
    {
        return preg_match('/\{\{\s*(title|keyword|knowledge)\s*\}\}/iu', $prompt) === 1
            || preg_match('/\{\{#if\s+(title|keyword|knowledge)\s*\}\}/iu', $prompt) === 1;
    }

    /**
     * 渲染任务上下文变量，兼容 {{Knowledge}} 与 {{knowledge}} 等大小写写法。
     *
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function renderPromptTemplate(string $prompt, array $context): string
    {
        $renderedPrompt = preg_replace_callback('/\{\{#if\s+([A-Za-z_][A-Za-z0-9_]*)\s*\}\}(.*?)\{\{\/if\}\}/su', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            if (! $this->isKnownPromptContextName($name)) {
                return (string) ($matches[0] ?? '');
            }

            $value = $this->promptContextValue($name, $context);

            return trim($value) !== '' ? (string) ($matches[2] ?? '') : '';
        }, $prompt) ?? $prompt;

        return preg_replace_callback('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/u', function (array $matches) use ($context): string {
            $name = (string) ($matches[1] ?? '');
            $value = $this->promptContextValue($name, $context);

            return $value !== '' || $this->isKnownPromptContextName($name) ? $value : (string) ($matches[0] ?? '');
        }, $renderedPrompt) ?? $renderedPrompt;
    }

    /**
     * @param  array{title:string, keyword:string, knowledge:string}  $context
     */
    private function promptContextValue(string $name, array $context): string
    {
        return match (mb_strtolower($name, 'UTF-8')) {
            'title' => $context['title'],
            'keyword' => $context['keyword'],
            'knowledge' => $context['knowledge'],
            default => '',
        };
    }

    private function isKnownPromptContextName(string $name): bool
    {
        return in_array(mb_strtolower($name, 'UTF-8'), ['title', 'keyword', 'knowledge'], true);
    }

    private function appendSmartPromptContext(string $prompt, string $title, string $keyword, string $knowledgeContext, string $targetLanguage): string
    {
        if ($targetLanguage !== 'zh') {
            $lines = [
                'Task context:',
                '- Article title: '.$title,
            ];
            if (trim($keyword) !== '') {
                $lines[] = '- Core keyword: '.$keyword;
            }
            if (trim($knowledgeContext) !== '') {
                $lines[] = '- Reference knowledge:';
                $lines[] = $knowledgeContext;
            }

            return trim($prompt)."\n\n".implode("\n", $lines);
        }

        $lines = [
            '【任务上下文】',
            '- 文章标题：'.$title,
        ];
        if (trim($keyword) !== '') {
            $lines[] = '- 核心关键词：'.$keyword;
        }
        if (trim($knowledgeContext) !== '') {
            $lines[] = '- 参考知识：';
            $lines[] = $knowledgeContext;
        }

        return trim($prompt)."\n\n".implode("\n", $lines);
    }

    private function finalPromptInstruction(string $targetLanguage): string
    {
        $instruction = match ($targetLanguage) {
            'en' => 'The final article must be written entirely in English. Output only the final article body in Markdown. Do not repeat the prompt or output placeholders.',
            default => '请直接输出最终中文文章正文（Markdown）。全文必须使用中文，不要重复提示词、不要输出占位符。',
        };

        return $instruction."\n".($targetLanguage === 'zh'
            ? '不要自行插入站内链接；草稿审核页会单独处理内链建议。'
            : 'Do not insert internal links yourself; the draft review page handles internal link suggestions separately.');
    }

    private function isLikelyEnglishPrompt(string $prompt): bool
    {
        preg_match_all('/\p{Han}/u', $prompt, $cjkMatches);
        preg_match_all('/[A-Za-z]/', $prompt, $latinMatches);

        return count($latinMatches[0] ?? []) > 20 && count($cjkMatches[0] ?? []) <= 3;
    }

    /**
     * 按任务配置检索知识库上下文并回填到 {{Knowledge}}。
     *
     * 支持两种范围：
     * - knowledge_base_id：单个固定知识库；
     * - knowledge_tag_filter：跨所有命中标签的知识库、Entity DB 和 Case DB。
     */
    private function resolveKnowledgeContext(Task $task, string $title, string $keyword): string
    {
        $result = $this->ragRetrievalService->retrieveForTask($task, $title, $keyword);
        $trace = is_array($result['trace'] ?? null) ? $result['trace'] : [];
        $this->lastKnowledgeChunkTrace = is_array($trace['chunks'] ?? null) ? $trace['chunks'] : [];
        $this->lastEntityCaseTrace = [
            'entities' => is_array($trace['entities'] ?? null) ? $trace['entities'] : [],
            'cases' => is_array($trace['cases'] ?? null) ? $trace['cases'] : [],
        ];
        $this->lastKnowledgeTrace = $trace;

        return (string) ($result['context'] ?? '');
    }

    /**
     * @return list<int>
     */
    private function resolveKnowledgeBaseIds(Task $task): array
    {
        $ids = [];
        $knowledgeBaseId = (int) ($task->knowledge_base_id ?? 0);
        if ($knowledgeBaseId > 0) {
            $ids[] = $knowledgeBaseId;
        }

        $tagFilters = $this->taskTagFilters($task);
        if ($tagFilters !== []) {
            $tagKnowledgeBaseIds = KnowledgeBase::query()
                ->whereHas('tags', fn ($query) => $this->addExactTagFilterConditions($query, $tagFilters))
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $ids = array_merge($ids, $tagKnowledgeBaseIds);
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }

    /**
     * @return list<array{group_name:string,name:string}>
     */
    private function taskTagFilters(Task $task): array
    {
        $tagFilter = trim((string) ($task->knowledge_tag_filter ?? ''));

        return $tagFilter === '' ? [] : $this->tagService->parseTagText($tagFilter);
    }

    /**
     * @param  list<array{group_name:string,name:string}>  $tagFilters
     */
    private function addExactTagFilterConditions($query, array $tagFilters): void
    {
        $query->where(function ($nested) use ($tagFilters): void {
            foreach ($tagFilters as $tagFilter) {
                $groupName = trim((string) ($tagFilter['group_name'] ?? ''));
                $name = trim((string) ($tagFilter['name'] ?? ''));
                if ($name === '') {
                    continue;
                }

                $nested->orWhere(function ($tagQuery) use ($groupName, $name): void {
                    if ($groupName !== '') {
                        $tagQuery
                            ->where('group_name', $groupName)
                            ->where('name', $name);

                        return;
                    }

                    $tagQuery->where('name', $name);
                });
            }
        });
    }

    /**
     * @param  list<array{group_name:string,name:string}>  $tagFilters
     */
    private function composeTaggedEntityCaseContext(array $tagFilters, int $maxChars): string
    {
        if ($tagFilters === []) {
            return '';
        }

        $entities = EntityRecord::query()
            ->whereHas('tags', fn ($query) => $this->addExactTagFilterConditions($query, $tagFilters))
            ->orderBy('name')
            ->limit(12)
            ->get(['id', 'name', 'entity_type', 'aliases', 'description', 'attributes_json', 'canonical_url', 'link_policy']);

        $cases = CaseRecord::query()
            ->with('entities:id,name')
            ->where(function ($query) use ($tagFilters): void {
                $query
                    ->whereHas('tags', fn ($tagQuery) => $this->addExactTagFilterConditions($tagQuery, $tagFilters))
                    ->orWhereHas('entity.tags', fn ($tagQuery) => $this->addExactTagFilterConditions($tagQuery, $tagFilters));
            })
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'entity_id', 'title', 'case_type', 'summary', 'challenge', 'solution', 'result', 'metrics']);

        if ($entities->isEmpty() && $cases->isEmpty()) {
            return '';
        }

        $this->lastEntityCaseTrace = [
            'entities' => $entities
                ->map(static fn (EntityRecord $entity): array => [
                    'id' => (int) $entity->id,
                    'name' => (string) $entity->name,
                    'type' => (string) ($entity->entity_type ?? ''),
                    'role' => EntityTypes::roleDescription((string) ($entity->entity_type ?? '')),
                    'linkable' => EntityTypes::isLinkable((string) ($entity->entity_type ?? ''))
                        && (string) ($entity->link_policy ?? '') === EntityTypes::LINK_POLICY_SUGGEST
                        && trim((string) ($entity->canonical_url ?? '')) !== '',
                ])
                ->values()
                ->all(),
            'cases' => $cases
                ->map(static fn (CaseRecord $caseRecord): array => [
                    'id' => (int) $caseRecord->id,
                    'title' => (string) $caseRecord->title,
                    'type' => (string) ($caseRecord->case_type ?? ''),
                    'role' => CaseTypes::referenceRule((string) ($caseRecord->case_type ?? '')),
                    'entity_id' => $caseRecord->entity_id !== null ? (int) $caseRecord->entity_id : null,
                    'entity_name' => (string) (($e = $caseRecord->entities->first()) ? $e->name : ''),
                ])
                ->values()
                ->all(),
        ];

        $lines = [];
        if ($entities->isNotEmpty()) {
            $lines[] = '【Entity DB 参考】';
            foreach ($entities as $entity) {
                $line = '- 实体：'.(string) $entity->name;
                if ((string) ($entity->entity_type ?? '') !== '') {
                    $line .= '（类型：'.(string) $entity->entity_type.'）';
                }
                $lines[] = $line;
                $lines[] = '  写作角色：'.EntityTypes::roleDescription((string) ($entity->entity_type ?? ''));
                if ((string) ($entity->aliases ?? '') !== '') {
                    $lines[] = '  别名：'.$this->shortContextText($entity->aliases, 180);
                }
                if ((string) ($entity->description ?? '') !== '') {
                    $lines[] = '  描述：'.$this->shortContextText($entity->description, 320);
                }
                if ((string) ($entity->attributes_json ?? '') !== '' && trim((string) $entity->attributes_json) !== '{}') {
                    $lines[] = '  属性：'.$this->shortContextText($entity->attributes_json, 260);
                }
            }
        }

        if ($cases->isNotEmpty()) {
            $lines[] = '【Case DB 参考】';
            foreach ($cases as $caseRecord) {
                $line = '- 案例：'.(string) $caseRecord->title;
                if ((string) ($caseRecord->case_type ?? '') !== '') {
                    $line .= '（类型：'.(string) $caseRecord->case_type.'）';
                }
                if (($e = $caseRecord->entities->first())) {
                    $line .= '，关联实体：'.(string) $e->name;
                }
                $lines[] = $line;
                $lines[] = '  引用规则：'.CaseTypes::referenceRule((string) ($caseRecord->case_type ?? ''));

                foreach ([
                    'summary' => '摘要',
                    'challenge' => '挑战',
                    'solution' => '方案',
                    'result' => '结果',
                    'metrics' => '指标',
                ] as $field => $label) {
                    $value = (string) ($caseRecord->{$field} ?? '');
                    if ($value !== '') {
                        $lines[] = '  '.$label.'：'.$this->shortContextText($value, 260);
                    }
                }
            }
        }

        $context = trim(implode("\n", $lines));

        return mb_strlen($context, 'UTF-8') > $maxChars
            ? mb_substr($context, 0, $maxChars, 'UTF-8').'...'
            : $context;
    }

    private function shortContextText(mixed $value, int $maxChars): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        return mb_strlen($text, 'UTF-8') > $maxChars
            ? mb_substr($text, 0, $maxChars, 'UTF-8').'...'
            : $text;
    }

    /**
     * 从 knowledge_chunks 中检索相关片段。
     *
     * @param  list<int>  $knowledgeBaseIds
     */
    private function fetchKnowledgeContextFromChunks(array $knowledgeBaseIds, string $query, int $limit, int $maxChars): string
    {
        $knowledgeBaseIds = array_values(array_unique(array_filter($knowledgeBaseIds, static fn (int $id): bool => $id > 0)));
        if ($knowledgeBaseIds === []) {
            return '';
        }

        if (trim($query) !== '') {
            $vectorRows = $this->fetchKnowledgeChunksByPgvector($knowledgeBaseIds, $query, max($limit * 3, 8));
            if ($vectorRows !== []) {
                return $this->composeKnowledgeContext($vectorRows, $limit, $maxChars);
            }
        }

        $rows = DB::table('knowledge_chunks as kc')
            ->join('knowledge_bases as kb', 'kb.id', '=', 'kc.knowledge_base_id')
            ->whereIn('kc.knowledge_base_id', $knowledgeBaseIds)
            ->orderBy('kc.knowledge_base_id')
            ->orderBy('kc.chunk_index')
            ->get([
                'kc.knowledge_base_id',
                'kb.name as knowledge_base_name',
                'kc.chunk_index',
                'kc.content',
                'kc.embedding_json',
                'kc.embedding_model_id',
                'kc.embedding_dimensions',
            ])
            ->all();
        if ($rows === []) {
            return '';
        }

        $queryTerms = $this->termFrequencies($query);
        $hasRealEmbeddingRows = collect($rows)->contains(
            fn ($row): bool => $this->chunkHasRealEmbedding($row)
        );
        $useRealEmbeddingScore = false;
        $queryVector = [];
        if ($hasRealEmbeddingRows && trim($query) !== '') {
            $queryVector = $this->knowledgeChunkSyncService->generateQueryEmbeddingVector($query);
            $useRealEmbeddingScore = $queryVector !== [];
        }
        if ($queryVector === []) {
            $queryVector = $this->decodeVector(json_encode($this->buildFallbackVector($query, 256)));
        }

        $scored = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }

            $vector = $this->decodeVector((string) ($row->embedding_json ?? ''));
            $chunkTerms = $this->termFrequencies($content);
            $lexicalScore = $this->lexicalScore($queryTerms, $chunkTerms);
            $chunkUsesRealEmbedding = $this->chunkHasRealEmbedding($row);
            $vectorScore = ($useRealEmbeddingScore === $chunkUsesRealEmbedding)
                ? $this->dotProduct($queryVector, $vector)
                : 0.0;
            $score = ($vectorScore * 0.75) + ($lexicalScore * 0.25);

            $scored[] = [
                'knowledge_base_id' => (int) ($row->knowledge_base_id ?? 0),
                'knowledge_base_name' => (string) ($row->knowledge_base_name ?? ''),
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => $score,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            $diff = ($b['score'] <=> $a['score']);

            return $diff !== 0 ? $diff : (($a['knowledge_base_id'] <=> $b['knowledge_base_id']) ?: ($a['chunk_index'] <=> $b['chunk_index']));
        });

        return $this->composeKnowledgeContext($scored, $limit, $maxChars);
    }

    /**
     * 判断 chunk 是否保存了真实 embedding，而不是 fallback hash 向量。
     */
    private function chunkHasRealEmbedding(object $row): bool
    {
        return (int) ($row->embedding_model_id ?? 0) > 0
            && (int) ($row->embedding_dimensions ?? 0) > 0;
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

        $imageQuery = Image::query()->where('library_id', $libraryId);
        $imageTagFilters = $this->taskImageTagFilters($task);
        if ($imageTagFilters !== []) {
            $imageQuery->whereHas('tags', fn ($query) => $this->addExactTagFilterConditions($query, $imageTagFilters));
        }

        /** @var list<Image> $images */
        $images = $imageQuery
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
     * @return list<array{group_name:string,name:string}>
     */
    private function taskImageTagFilters(Task $task): array
    {
        $tagFilter = trim((string) ($task->image_tag_filter ?? ''));

        return $tagFilter === '' ? [] : $this->tagService->parseTagText($tagFilter);
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
     */
    private function generateContent(AiModel $aiModel, string $contentPrompt): string
    {
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($aiModel->api_url ?? ''));
        if ($providerUrl === '') {
            throw new RuntimeException('AI 模型 API 地址为空');
        }

        $apiKey = $this->decryptApiKey((string) ($aiModel->getRawOriginal('api_key') ?? ''));
        if ($apiKey === '') {
            throw new RuntimeException('AI 模型密钥为空');
        }

        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider('worker', $driver, $providerUrl, $apiKey);
        $agent = new MarkdownContentWriterAgent(maxTokens: $this->resolveMaxTokens($aiModel));

        try {
            $response = $agent->prompt($contentPrompt, [], $providerName, (string) ($aiModel->model_id ?? ''));
        } catch (Throwable $exception) {
            throw new RuntimeException('AI 生成失败: '.OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        $rawContent = (string) ($response->text ?? '');
        $content = OpenAiRuntimeProvider::normalizeGeneratedText($rawContent);
        if ($content === '') {
            if (OpenAiRuntimeProvider::looksLikeSseCompletionPayload($rawContent)) {
                throw new RuntimeException('AI 返回空流式响应，未生成正文内容，请重试或检查模型流式输出兼容性');
            }

            throw new RuntimeException('AI返回空正文');
        }

        $this->warnIfContentLooksTruncated($content, $aiModel, $response);

        AiModel::query()->whereKey((int) $aiModel->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        return $content;
    }

    private function resolveMaxTokens(AiModel $aiModel): int
    {
        $configured = (int) ($aiModel->max_tokens ?? 0);
        if ($configured > 0) {
            return $configured;
        }

        return max(256, (int) config('geoflow.content_max_tokens', 8192));
    }

    private function warnIfContentLooksTruncated(string $content, AiModel $aiModel, mixed $response): void
    {
        $finishReason = $this->responseFinishReason($response);
        $signals = [];

        if ($finishReason === FinishReason::Length) {
            $signals[] = 'finish_reason_length';
        }

        $trimmed = rtrim($content);
        if ($trimmed !== '' && substr_count($trimmed, '```') % 2 === 1) {
            $signals[] = 'unclosed_code_fence';
        }

        if (
            mb_strlen($trimmed, 'UTF-8') > 500
            && preg_match('/[。！？.!?）\]\)"\'`》]$/u', $trimmed) !== 1
        ) {
            $signals[] = 'unfinished_sentence';
        }

        if ($signals === []) {
            return;
        }

        Log::warning('GEOFlow article generation may be truncated.', [
            'ai_model_id' => (int) $aiModel->id,
            'model_id' => (string) ($aiModel->model_id ?? ''),
            'max_tokens' => $this->resolveMaxTokens($aiModel),
            'finish_reason' => $finishReason instanceof FinishReason ? $finishReason->value : null,
            'content_length' => mb_strlen($trimmed, 'UTF-8'),
            'signals' => $signals,
        ]);
    }

    private function responseFinishReason(mixed $response): ?FinishReason
    {
        $steps = $response->steps ?? null;
        $lastStep = null;

        if ($steps instanceof Collection) {
            $lastStep = $steps->last();
        } elseif (is_array($steps) && $steps !== []) {
            $lastStep = end($steps);
        }

        $finishReason = $lastStep->finishReason ?? null;
        if ($finishReason instanceof FinishReason) {
            return $finishReason;
        }

        if (is_string($finishReason)) {
            return FinishReason::tryFrom($finishReason);
        }

        return null;
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

    /**
     * 兼容 enc:v1 历史格式解密 API Key。
     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
    }

    /**
     * @return array<string,int>
     */
    private function termFrequencies(string $text): array
    {
        $tokens = preg_split('/[^\p{L}\p{N}_]+/u', mb_strtolower(trim($text), 'UTF-8')) ?: [];
        $frequencies = [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '' || mb_strlen($token, 'UTF-8') <= 1) {
                continue;
            }
            $frequencies[$token] = (int) ($frequencies[$token] ?? 0) + 1;
        }

        return $frequencies;
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @param  array<string,int>  $chunkTerms
     */
    private function lexicalScore(array $queryTerms, array $chunkTerms): float
    {
        if ($queryTerms === [] || $chunkTerms === []) {
            return 0.0;
        }

        $matched = 0;
        $total = 0;
        foreach ($queryTerms as $term => $count) {
            $total += $count;
            if (isset($chunkTerms[$term])) {
                $matched += min($count, (int) $chunkTerms[$term]);
            }
        }

        return $total > 0 ? ($matched / $total) : 0.0;
    }

    /**
     * @return list<float>
     */
    private function decodeVector(string $json): array
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        $vector = [];
        foreach ($decoded as $value) {
            if (is_numeric($value)) {
                $vector[] = (float) $value;
            }
        }

        return $vector;
    }

    /**
     * @param  list<float>  $left
     * @param  list<float>  $right
     */
    private function dotProduct(array $left, array $right): float
    {
        if ($left === [] || $right === []) {
            return 0.0;
        }
        $sum = 0.0;
        $limit = min(count($left), count($right));
        for ($i = 0; $i < $limit; $i++) {
            $sum += ((float) $left[$i]) * ((float) $right[$i]);
        }

        return $sum;
    }

    /**
     * @return list<float>
     */
    private function buildFallbackVector(string $text, int $dimensions): array
    {
        $dimensions = max(1, $dimensions);
        $vector = array_fill(0, $dimensions, 0.0);
        foreach ($this->termFrequencies($text) as $token => $count) {
            $indexSeed = abs((int) crc32('i:'.$token));
            $signSeed = abs((int) crc32('s:'.$token));
            $index = $indexSeed % $dimensions;
            $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
            $tokenLength = max(1, mb_strlen($token, 'UTF-8'));
            $weight = (1.0 + log(1 + $count)) * min(2.0, 0.8 + ($tokenLength / 4));
            $vector[$index] += $sign * $weight;
        }

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        if ($norm > 0.0) {
            $norm = sqrt($norm);
            foreach ($vector as $index => $value) {
                $vector[$index] = $value / $norm;
            }
        }

        return $vector;
    }

    /**
     * 优先使用 pgvector 执行数据库向量检索，命中则返回候选块。
     *
     * @param  list<int>  $knowledgeBaseIds
     * @return list<array{knowledge_base_id:int,knowledge_base_name:string,chunk_index:int,content:string,score:float}>
     */
    private function fetchKnowledgeChunksByPgvector(array $knowledgeBaseIds, string $query, int $candidateLimit): array
    {
        if (! $this->canUsePgvectorSearch()) {
            return [];
        }
        $knowledgeBaseIds = array_values(array_unique(array_filter($knowledgeBaseIds, static fn (int $id): bool => $id > 0)));
        if ($knowledgeBaseIds === []) {
            return [];
        }

        $vectorLiteral = $this->knowledgeChunkSyncService->generateQueryVectorLiteral($query);
        if ($vectorLiteral === '') {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($knowledgeBaseIds), '?'));

        $rows = DB::select(
            "
                SELECT kc.knowledge_base_id, kb.name AS knowledge_base_name, kc.chunk_index, kc.content,
                       (kc.embedding_vector <=> CAST(? AS vector)) AS vector_distance
                FROM knowledge_chunks kc
                JOIN knowledge_bases kb ON kb.id = kc.knowledge_base_id
                WHERE kc.knowledge_base_id IN ({$placeholders})
                  AND kc.embedding_vector IS NOT NULL
                ORDER BY kc.embedding_vector <=> CAST(? AS vector), kc.chunk_index ASC
                LIMIT ?
            ",
            array_merge([$vectorLiteral], $knowledgeBaseIds, [$vectorLiteral, max(1, $candidateLimit)])
        );

        $results = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }
            $distance = (float) ($row->vector_distance ?? 1.0);
            $results[] = [
                'knowledge_base_id' => (int) ($row->knowledge_base_id ?? 0),
                'knowledge_base_name' => (string) ($row->knowledge_base_name ?? ''),
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => 1.0 - $distance,
            ];
        }

        return $results;
    }

    /**
     * 仅在 PostgreSQL 且 pgvector 可用时启用向量检索。
     */
    private function canUsePgvectorSearch(): bool
    {
        if (DB::getDriverName() !== 'pgsql') {
            return false;
        }

        try {
            $typeRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1 FROM pg_type WHERE typname = 'vector'
                ) AS ok
            ");
            if (! $typeRow || ! (bool) ($typeRow->ok ?? false)) {
                return false;
            }

            $columnRow = DB::selectOne("
                SELECT EXISTS (
                    SELECT 1
                    FROM information_schema.columns
                    WHERE table_name = 'knowledge_chunks'
                      AND column_name = 'embedding_vector'
                ) AS ok
            ");

            return $columnRow !== null && (bool) ($columnRow->ok ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 从候选块拼装知识上下文，按片段顺序输出。
     *
     * @param  list<array{knowledge_base_id?:int,knowledge_base_name?:string,chunk_index:int,content:string,score:float}>  $scored
     */
    private function composeKnowledgeContext(array $scored, int $limit, int $maxChars): string
    {
        if ($scored === []) {
            $this->lastKnowledgeChunkTrace = [];

            return '';
        }

        $selected = array_slice($scored, 0, max(1, $limit));
        usort($selected, static fn (array $a, array $b): int => (($a['knowledge_base_id'] ?? 0) <=> ($b['knowledge_base_id'] ?? 0)) ?: ($a['chunk_index'] <=> $b['chunk_index']));
        $this->lastKnowledgeChunkTrace = array_map(static fn (array $chunk): array => [
            'knowledge_base_id' => (int) ($chunk['knowledge_base_id'] ?? 0),
            'knowledge_base_name' => (string) ($chunk['knowledge_base_name'] ?? ''),
            'chunk_index' => (int) ($chunk['chunk_index'] ?? 0),
            'score' => round((float) ($chunk['score'] ?? 0), 6),
            'preview' => mb_substr(trim((string) ($chunk['content'] ?? '')), 0, 160, 'UTF-8'),
        ], $selected);

        $parts = [];
        $charCount = 0;
        foreach ($selected as $index => $chunk) {
            $content = trim((string) ($chunk['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $nextLength = $charCount + mb_strlen($content, 'UTF-8');
            if ($parts !== [] && $nextLength > $maxChars) {
                continue;
            }
            $source = trim((string) ($chunk['knowledge_base_name'] ?? ''));
            $heading = '【知识片段'.($index + 1).($source !== '' ? ' / 知识库：'.$source : '').'】';
            $parts[] = $heading."\n".$content;
            $charCount = $nextLength;
        }

        return trim(implode("\n\n", $parts));
    }

    /**
     * @param  list<KnowledgeBase>  $knowledgeBases
     */
    private function composeFallbackKnowledgeContent(array $knowledgeBases, int $maxChars): string
    {
        $parts = [];
        $charCount = 0;
        foreach ($knowledgeBases as $knowledgeBase) {
            $content = trim((string) ($knowledgeBase->content ?? ''));
            if ($content === '') {
                continue;
            }
            $name = trim((string) ($knowledgeBase->name ?? ''));
            $block = ($name !== '' ? "【知识库：{$name}】\n" : '').$content;
            $blockLength = mb_strlen($block, 'UTF-8');
            if ($parts !== [] && $charCount + $blockLength > $maxChars) {
                $remaining = $maxChars - $charCount;
                if ($remaining <= 120) {
                    break;
                }
                $block = mb_substr($block, 0, $remaining, 'UTF-8');
                $blockLength = mb_strlen($block, 'UTF-8');
            }
            $parts[] = $block;
            $charCount += $blockLength;
            if ($charCount >= $maxChars) {
                break;
            }
        }

        return trim(implode("\n\n", $parts));
    }
}
