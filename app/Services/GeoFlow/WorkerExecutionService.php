<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
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
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\ArticleWorkflow;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Worker 任务执行器：将队列任务落地为文章记录（占位实现，先打通 worker/队列链路）。
 */
class WorkerExecutionService
{
    /**
     * 复用统一 API Key 解密组件，确保 worker 与后台配置端解密行为一致。
     */
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly KnowledgeChunkSyncService $knowledgeChunkSyncService
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

        $titleRow = $this->pickTitle($task);
        $author = $this->pickAuthor($task);
        $category = $this->pickCategory($task);
        $prompt = $task->prompt_id ? Prompt::query()->find((int) $task->prompt_id) : null;

        $keyword = (string) ($titleRow->keyword ?? '');
        $knowledgeContext = $this->resolveKnowledgeContext($task, (string) $titleRow->title, $keyword);
        $contentPrompt = $this->buildContentPrompt((string) $titleRow->title, $keyword, $prompt?->content, $knowledgeContext);
        $generation = $this->generateContentWithModelSelection($task, $contentPrompt);
        $aiModel = $generation['model'];
        $generatedContent = $generation['content'];
        $imageResult = $this->insertTaskImagesIntoContent($task, $generatedContent);
        $content = $imageResult['content'];
        $selectedImages = $imageResult['images'];
        $excerpt = $this->buildExcerpt($content);
        $workflow = [
            'status' => 'draft',
            'review_status' => (int) ($task->need_review ?? 1) === 1 ? 'pending' : 'approved',
            'published_at' => null,
        ];

        $articleId = DB::transaction(function () use ($task, $titleRow, $author, $category, $keyword, $content, $excerpt, $workflow, $selectedImages): int {
            $freshTask = Task::query()
                ->whereKey((int) $task->id)
                ->lockForUpdate()
                ->first(['id', 'status', 'schedule_enabled', 'created_count', 'draft_limit', 'article_limit', 'publish_interval', 'next_publish_at']);
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
                'knowledge_length' => mb_strlen($knowledgeContext, 'UTF-8'),
                'image_count' => count($selectedImages),
                'model_selection_mode' => (string) ($task->model_selection_mode ?? 'fixed'),
                'used_model_id' => (int) $aiModel->id,
                'used_model_name' => (string) $aiModel->name,
                'model_attempts' => $generation['attempts'],
            ],
        ];
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
                ->first(['id', 'status', 'schedule_enabled', 'publish_interval', 'next_publish_at']);
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

            $workflow = ArticleWorkflow::normalizeState('published', (string) ($article->review_status ?: 'approved'));
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

    private function pickAuthor(Task $task): ?Author
    {
        $authorId = (int) ($task->custom_author_id ?: $task->author_id);
        if ($authorId > 0) {
            return Author::query()->find($authorId);
        }

        return Author::query()->orderBy('id')->first();
    }

    private function pickCategory(Task $task): ?Category
    {
        if (($task->category_mode ?? 'smart') === 'fixed' && (int) ($task->fixed_category_id ?? 0) > 0) {
            return Category::query()->find((int) $task->fixed_category_id);
        }

        return Category::query()->orderBy('sort_order')->orderBy('id')->first();
    }

    /**
     * 构造正文提示词：替换旧占位符并剔除未提供知识时的条件块。
     */
    private function buildContentPrompt(string $title, string $keyword, ?string $promptContent, string $knowledgeContext): string
    {
        $prompt = trim((string) $promptContent);
        if ($prompt === '') {
            $prompt = "请围绕标题“{$title}”和关键词“{$keyword}”生成一篇结构清晰、语言自然的中文文章。";
        }

        $renderedPrompt = str_replace(
            ['{{title}}', '{{keyword}}', '{{Knowledge}}'],
            [$title, $keyword, $knowledgeContext],
            $prompt
        );
        $renderedPrompt = preg_replace_callback('/\{\{#if\s+Knowledge\}\}(.*?)\{\{\/if\}\}/su', static function (array $matches) use ($knowledgeContext): string {
            return $knowledgeContext !== '' ? (string) ($matches[1] ?? '') : '';
        }, $renderedPrompt) ?: $renderedPrompt;

        return trim($renderedPrompt)."\n\n请直接输出最终文章正文（Markdown），不要重复提示词、不要输出占位符。";
    }

    /**
     * 按任务配置检索知识库上下文并回填到 {{Knowledge}}。
     */
    private function resolveKnowledgeContext(Task $task, string $title, string $keyword): string
    {
        $knowledgeBaseId = (int) ($task->knowledge_base_id ?? 0);
        if ($knowledgeBaseId <= 0) {
            return '';
        }

        $knowledgeBase = KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->first(['id', 'content']);
        if (! $knowledgeBase) {
            return '';
        }

        $content = trim((string) ($knowledgeBase->content ?? ''));
        if ($content === '') {
            return '';
        }

        $chunkCount = KnowledgeChunk::query()->where('knowledge_base_id', $knowledgeBaseId)->count();
        if ($chunkCount <= 0) {
            $this->knowledgeChunkSyncService->sync($knowledgeBaseId, $content);
        }

        $query = trim($title."\n".$keyword);
        $context = $this->fetchKnowledgeContextFromChunks($knowledgeBaseId, $query, 4, 2400);
        if ($context !== '') {
            return $context;
        }

        return mb_strlen($content, 'UTF-8') > 2400 ? mb_substr($content, 0, 2400, 'UTF-8') : $content;
    }

    /**
     * 从 knowledge_chunks 中检索相关片段。
     */
    private function fetchKnowledgeContextFromChunks(int $knowledgeBaseId, string $query, int $limit, int $maxChars): string
    {
        if (trim($query) !== '') {
            $vectorRows = $this->fetchKnowledgeChunksByPgvector($knowledgeBaseId, $query, max($limit * 3, 8));
            if ($vectorRows !== []) {
                return $this->composeKnowledgeContext($vectorRows, $limit, $maxChars);
            }
        }

        $rows = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->orderBy('chunk_index')
            ->get(['chunk_index', 'content', 'embedding_json'])
            ->all();
        if ($rows === []) {
            return '';
        }

        $queryTerms = $this->termFrequencies($query);
        $queryVector = $this->decodeVector(json_encode($this->buildFallbackVector($query, 256)));

        $scored = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }

            $vector = $this->decodeVector((string) ($row->embedding_json ?? ''));
            $chunkTerms = $this->termFrequencies($content);
            $lexicalScore = $this->lexicalScore($queryTerms, $chunkTerms);
            $vectorScore = $this->dotProduct($queryVector, $vector);
            $score = ($vectorScore * 0.75) + ($lexicalScore * 0.25);

            $scored[] = [
                'chunk_index' => (int) ($row->chunk_index ?? 0),
                'content' => $content,
                'score' => $score,
            ];
        }

        usort($scored, static function (array $a, array $b): int {
            $diff = ($b['score'] <=> $a['score']);

            return $diff !== 0 ? $diff : ($a['chunk_index'] <=> $b['chunk_index']);
        });

        return $this->composeKnowledgeContext($scored, $limit, $maxChars);
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
        $agent = new MarkdownContentWriterAgent;

        try {
            $response = $agent->prompt($contentPrompt, [], $providerName, (string) ($aiModel->model_id ?? ''));
        } catch (Throwable $exception) {
            throw new RuntimeException('AI 生成失败: '.$exception->getMessage(), 0, $exception);
        }

        $content = trim((string) ($response->text ?? ''));
        if ($content === '') {
            throw new RuntimeException('AI返回空正文');
        }

        AiModel::query()->whereKey((int) $aiModel->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        return $content;
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
     * @return list<array{chunk_index:int,content:string,score:float}>
     */
    private function fetchKnowledgeChunksByPgvector(int $knowledgeBaseId, string $query, int $candidateLimit): array
    {
        if (! $this->canUsePgvectorSearch()) {
            return [];
        }

        $vectorLiteral = $this->knowledgeChunkSyncService->generateQueryVectorLiteral($query);
        if ($vectorLiteral === '') {
            return [];
        }

        $rows = DB::select(
            '
                SELECT chunk_index, content,
                       (embedding_vector <=> CAST(? AS vector)) AS vector_distance
                FROM knowledge_chunks
                WHERE knowledge_base_id = ?
                  AND embedding_vector IS NOT NULL
                ORDER BY embedding_vector <=> CAST(? AS vector), chunk_index ASC
                LIMIT ?
            ',
            [$vectorLiteral, $knowledgeBaseId, $vectorLiteral, max(1, $candidateLimit)]
        );

        $results = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }
            $distance = (float) ($row->vector_distance ?? 1.0);
            $results[] = [
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
     * @param  list<array{chunk_index:int,content:string,score:float}>  $scored
     */
    private function composeKnowledgeContext(array $scored, int $limit, int $maxChars): string
    {
        if ($scored === []) {
            return '';
        }

        $selected = array_slice($scored, 0, max(1, $limit));
        usort($selected, static fn (array $a, array $b): int => $a['chunk_index'] <=> $b['chunk_index']);

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
            $parts[] = '【知识片段'.($index + 1)."】\n".$content;
            $charCount = $nextLength;
        }

        return trim(implode("\n\n", $parts));
    }
}
