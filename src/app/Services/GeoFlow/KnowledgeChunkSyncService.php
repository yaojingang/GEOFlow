<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\SiteSetting;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Throwable;

/**
 * 知识库分块与向量字段同步服务。
 *
 * 说明：
 * - 优先使用 AI 配置中的默认 embedding 模型生成真实向量；
 * - 若模型未配置或调用失败，自动回退为 fallback_hash 向量，保证流程稳定。
 */
class KnowledgeChunkSyncService
{
    private const SEMANTIC_CHUNKING_MAX_BLOCKS = 120;

    private const SEMANTIC_CHUNKING_MAX_PROMPT_CHARS = 20000;

    /**
     * 复用统一 API Key 解密组件，保证 embedding 调用与模型配置页完全一致。
     */
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * 将知识库正文重建为 chunks，并同步向量相关字段。
     *
     * 默认仍允许 fallback 向量，避免上传/编辑知识库时被 embedding 服务阻断。
     * 管理后台“更新切片”会启用强制真实 embedding 模式，失败时抛错并保留原切片。
     */
    public function sync(int $knowledgeBaseId, string $content, bool $requireRealEmbedding = false): int
    {
        if ($knowledgeBaseId <= 0) {
            return 0;
        }

        $plannedChunks = $this->planChunks($knowledgeBaseId, $content);
        $chunks = array_values(array_map(
            static fn (array $chunk): string => (string) ($chunk['content'] ?? ''),
            $plannedChunks
        ));
        $embeddingMetadata = $this->resolveEmbeddingMetadata();
        $embeddingDocumentTitle = $this->resolveEmbeddingDocumentTitle($knowledgeBaseId);
        $generatedEmbeddings = $this->generateEmbeddingsForChunks($chunks, $embeddingMetadata, $requireRealEmbedding, $embeddingDocumentTitle);

        if ($requireRealEmbedding && count($generatedEmbeddings) !== count($chunks)) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.embedding_sync_failed'));
        }

        DB::transaction(function () use ($knowledgeBaseId, $plannedChunks, $generatedEmbeddings): void {
            KnowledgeChunk::query()->where('knowledge_base_id', $knowledgeBaseId)->delete();

            foreach ($plannedChunks as $index => $chunk) {
                $chunkContent = (string) ($chunk['content'] ?? '');
                $fallbackVector = $this->buildFallbackVector($chunkContent, 256);
                $realEmbedding = $generatedEmbeddings[$index] ?? null;
                $isRealEmbedding = is_array($realEmbedding);
                $embeddingJson = $isRealEmbedding
                    ? json_encode($realEmbedding['vector'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)
                    : json_encode($fallbackVector, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

                KnowledgeChunk::query()->create([
                    'knowledge_base_id' => $knowledgeBaseId,
                    'chunk_index' => $index,
                    'content' => $chunkContent,
                    'content_hash' => hash('sha256', $chunkContent),
                    'chunk_title' => mb_substr((string) ($chunk['title'] ?? ''), 0, 255, 'UTF-8'),
                    'section_path' => mb_substr((string) ($chunk['section_path'] ?? ''), 0, 500, 'UTF-8'),
                    'chunk_strategy' => mb_substr((string) ($chunk['strategy'] ?? 'structured_rule'), 0, 50, 'UTF-8'),
                    'metadata_json' => json_encode($chunk['metadata'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION),
                    'source_hash' => hash('sha256', (string) ($chunk['section_path'] ?? '').'|'.$chunkContent),
                    'token_count' => $this->estimateTokenCount($chunkContent),
                    'embedding_json' => $embeddingJson ?: '[]',
                    'embedding_model_id' => $isRealEmbedding ? (int) ($realEmbedding['model_id'] ?? 0) : null,
                    'embedding_dimensions' => $isRealEmbedding ? (int) ($realEmbedding['dimensions'] ?? 0) : 0,
                    'embedding_provider' => $isRealEmbedding ? (string) ($realEmbedding['provider'] ?? '') : '',
                    'embedding_vector' => $isRealEmbedding ? ($realEmbedding['vector_literal'] ?? null) : null,
                ]);
            }
        });

        return count($chunks);
    }

    /**
     * 构建知识库切片：默认使用结构化规则切片；配置语义模型时仅让 LLM 规划 block 边界，
     * 最终 chunk 文本仍由本地原文重组，避免模型改写知识内容。
     *
     * @return list<array{content:string,title:string,section_path:string,strategy:string,metadata:array<string,mixed>}>
     */
    private function planChunks(int $knowledgeBaseId, string $content): array
    {
        $blocks = $this->expandOversizedBlocks($this->splitStructuredBlocks($content));
        if ($blocks === []) {
            return [];
        }

        $ruleChunks = $this->buildStructuredRuleChunks($blocks, 'structured_rule');
        $strategy = $this->resolveChunkStrategy();
        if ($strategy === 'rule') {
            return $ruleChunks;
        }

        if (! $this->canAttemptSemanticChunking($blocks)) {
            Log::info('geoflow.knowledge_semantic_chunking_skipped', [
                'knowledge_base_id' => $knowledgeBaseId,
                'block_count' => count($blocks),
                'prompt_chars' => $this->estimateSemanticPlanningPromptChars($blocks),
            ]);

            return $strategy === 'auto'
                ? $ruleChunks
                : $this->buildStructuredRuleChunks($blocks, 'semantic_fallback');
        }

        $semanticChunks = $this->buildSemanticChunks($knowledgeBaseId, $blocks);

        if ($semanticChunks !== []) {
            return $semanticChunks;
        }

        return $strategy === 'auto'
            ? $ruleChunks
            : $this->buildStructuredRuleChunks($blocks, 'semantic_fallback');
    }

    private function resolveChunkStrategy(): string
    {
        $strategy = trim((string) (SiteSetting::query()
            ->where('setting_key', 'knowledge_chunk_strategy')
            ->value('setting_value') ?? 'rule'));

        return in_array($strategy, ['rule', 'semantic_llm', 'auto'], true) ? $strategy : 'rule';
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     * @return list<array{content:string,title:string,section_path:string,strategy:string,metadata:array<string,mixed>}>
     */
    private function buildStructuredRuleChunks(array $blocks, string $strategy): array
    {
        $chunks = [];
        $buffer = [];
        $maxChars = $this->chunkMaxChars();

        foreach ($blocks as $block) {
            $blockText = (string) ($block['text'] ?? '');
            if ($blockText === '') {
                continue;
            }

            if (($block['type'] ?? '') === 'heading' && $buffer !== []) {
                $chunks[] = $this->chunkFromBlocks($buffer, $strategy);
                $buffer = [];
            }

            $candidate = $buffer === [] ? $blockText : $this->joinBlockTexts([...$buffer, $block]);
            if ($buffer !== [] && mb_strlen($candidate, 'UTF-8') > $maxChars) {
                $chunks[] = $this->chunkFromBlocks($buffer, $strategy);
                $buffer = [];
            }

            $buffer[] = $block;
        }

        if ($buffer !== []) {
            $chunks[] = $this->chunkFromBlocks($buffer, $strategy);
        }

        return array_values(array_filter(
            $chunks,
            static fn (array $chunk): bool => trim((string) ($chunk['content'] ?? '')) !== ''
        ));
    }

    /**
     * @return list<array{index:int,type:string,text:string,section_path:string,heading_level:int|null,heading_text:string|null}>
     */
    private function splitStructuredBlocks(string $content): array
    {
        $normalized = $this->normalizeText($content);
        if ($normalized === '') {
            return [];
        }

        $lines = preg_split('/\R/u', $normalized) ?: [];
        $rawBlocks = [];
        $buffer = [];
        $bufferType = 'paragraph';
        $inFence = false;
        $fenceMarker = '';

        $flushBuffer = function () use (&$rawBlocks, &$buffer, &$bufferType): void {
            $text = trim(implode("\n", $buffer));
            if ($text !== '') {
                $rawBlocks[] = ['type' => $bufferType, 'text' => $text];
            }
            $buffer = [];
            $bufferType = 'paragraph';
        };

        foreach ($lines as $line) {
            $trimmed = trim((string) $line);

            if ($inFence) {
                $buffer[] = (string) $line;
                if ($fenceMarker !== '' && preg_match('/^'.preg_quote($fenceMarker, '/').'/u', $trimmed) === 1) {
                    $flushBuffer();
                    $inFence = false;
                    $fenceMarker = '';
                }
                continue;
            }

            if (preg_match('/^(```+|~~~+)/u', $trimmed, $fenceMatch) === 1) {
                $flushBuffer();
                $inFence = true;
                $fenceMarker = (string) $fenceMatch[1];
                $bufferType = 'code';
                $buffer[] = (string) $line;
                continue;
            }

            if ($trimmed === '') {
                $flushBuffer();
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/u', $trimmed, $headingMatch) === 1) {
                $flushBuffer();
                $rawBlocks[] = [
                    'type' => 'heading',
                    'text' => $trimmed,
                    'heading_level' => strlen((string) $headingMatch[1]),
                    'heading_text' => trim((string) $headingMatch[2]),
                ];
                continue;
            }

            $lineType = $this->detectStructuredLineType($trimmed);
            if ($buffer !== [] && $lineType !== $bufferType) {
                $flushBuffer();
            }
            $bufferType = $lineType;
            $buffer[] = (string) $line;
        }

        $flushBuffer();

        $blocks = [];
        $sectionPath = [];
        foreach ($rawBlocks as $rawBlock) {
            if (($rawBlock['type'] ?? '') === 'heading') {
                $level = max(1, min(6, (int) ($rawBlock['heading_level'] ?? 1)));
                foreach (array_keys($sectionPath) as $existingLevel) {
                    if ((int) $existingLevel >= $level) {
                        unset($sectionPath[$existingLevel]);
                    }
                }
                $sectionPath[$level] = (string) ($rawBlock['heading_text'] ?? '');
                ksort($sectionPath);
            }

            $blocks[] = [
                'index' => count($blocks),
                'type' => (string) ($rawBlock['type'] ?? 'paragraph'),
                'text' => (string) ($rawBlock['text'] ?? ''),
                'section_path' => trim(implode(' > ', array_filter($sectionPath))),
                'heading_level' => isset($rawBlock['heading_level']) ? (int) $rawBlock['heading_level'] : null,
                'heading_text' => isset($rawBlock['heading_text']) ? (string) $rawBlock['heading_text'] : null,
            ];
        }

        return $blocks;
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     * @return list<array<string,mixed>>
     */
    private function expandOversizedBlocks(array $blocks): array
    {
        $maxChars = $this->chunkMaxChars();
        $expanded = [];

        foreach ($blocks as $block) {
            $parts = $this->splitOversizedBlockText((string) ($block['text'] ?? ''), $maxChars);
            foreach ($parts as $partIndex => $partText) {
                $part = $block;
                $part['index'] = count($expanded);
                $part['text'] = $partText;
                $part['source_block_index'] = (int) ($block['index'] ?? count($expanded));
                $part['source_part_index'] = $partIndex;

                if ($partIndex > 0 && ($part['type'] ?? '') === 'heading') {
                    $part['type'] = 'paragraph';
                    $part['heading_level'] = null;
                    $part['heading_text'] = null;
                }

                $expanded[] = $part;
            }
        }

        return $expanded;
    }

    /**
     * @return list<string>
     */
    private function splitOversizedBlockText(string $text, int $maxChars): array
    {
        $text = trim($text);
        if ($text === '') {
            return [];
        }

        if (mb_strlen($text, 'UTF-8') <= $maxChars) {
            return [$text];
        }

        $lines = preg_split('/\n/u', $text) ?: [];
        if (count($lines) <= 1) {
            return $this->splitTextByCharacters($text, $maxChars);
        }

        $parts = [];
        $buffer = '';
        foreach ($lines as $line) {
            $line = (string) $line;
            $candidate = $buffer === '' ? $line : $buffer."\n".$line;
            if (mb_strlen($candidate, 'UTF-8') <= $maxChars) {
                $buffer = $candidate;
                continue;
            }

            if (trim($buffer) !== '') {
                $parts[] = trim($buffer);
                $buffer = '';
            }

            if (mb_strlen($line, 'UTF-8') > $maxChars) {
                array_push($parts, ...$this->splitTextByCharacters($line, $maxChars));
            } else {
                $buffer = $line;
            }
        }

        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @return list<string>
     */
    private function splitTextByCharacters(string $text, int $maxChars): array
    {
        $parts = [];
        $length = mb_strlen($text, 'UTF-8');
        for ($offset = 0; $offset < $length; $offset += $maxChars) {
            $part = trim(mb_substr($text, $offset, $maxChars, 'UTF-8'));
            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    private function detectStructuredLineType(string $line): string
    {
        if (preg_match('/^(\-|\*|\+|\d+\.)\s+/u', $line) === 1) {
            return 'list';
        }
        if (str_starts_with($line, '|')) {
            return 'table';
        }
        if (str_starts_with($line, '>')) {
            return 'quote';
        }

        return 'paragraph';
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     * @return array{content:string,title:string,section_path:string,strategy:string,metadata:array<string,mixed>}
     */
    private function chunkFromBlocks(array $blocks, string $strategy, string $title = ''): array
    {
        $content = $this->joinBlockTexts($blocks);
        $first = $blocks[0] ?? [];
        $title = trim($title) !== '' ? trim($title) : $this->inferChunkTitle($blocks);

        return [
            'content' => $content,
            'title' => $title,
            'section_path' => (string) ($first['section_path'] ?? ''),
            'strategy' => $strategy,
            'metadata' => [
                'block_indexes' => array_values(array_map(
                    static fn (array $block): int => (int) ($block['index'] ?? 0),
                    $blocks
                )),
                'source_block_indexes' => array_values(array_unique(array_map(
                    static fn (array $block): int => (int) ($block['source_block_index'] ?? ($block['index'] ?? 0)),
                    $blocks
                ))),
            ],
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     */
    private function joinBlockTexts(array $blocks): string
    {
        return trim(implode("\n\n", array_values(array_filter(
            array_map(static fn (array $block): string => trim((string) ($block['text'] ?? '')), $blocks),
            static fn (string $text): bool => $text !== ''
        ))));
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     */
    private function inferChunkTitle(array $blocks): string
    {
        foreach ($blocks as $block) {
            if (($block['type'] ?? '') === 'heading' && trim((string) ($block['heading_text'] ?? '')) !== '') {
                return trim((string) $block['heading_text']);
            }
        }

        return trim((string) ($blocks[0]['section_path'] ?? ''));
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     * @return list<array{content:string,title:string,section_path:string,strategy:string,metadata:array<string,mixed>}>
     */
    private function buildSemanticChunks(int $knowledgeBaseId, array $blocks): array
    {
        $models = $this->resolveSemanticChunkingModels();
        if ($models === []) {
            return [];
        }

        foreach ($models as $model) {
            $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
            $apiKey = $this->decryptApiKey((string) ($model->getRawOriginal('api_key') ?? ''));
            $modelId = trim((string) ($model->model_id ?? ''));
            if ($providerUrl === '' || $apiKey === '' || $modelId === '') {
                Log::info('geoflow.knowledge_semantic_chunking_model_skipped', [
                    'knowledge_base_id' => $knowledgeBaseId,
                    'semantic_model_id' => (int) $model->id,
                    'model_identifier' => $modelId,
                    'reason' => 'incomplete_model_config',
                ]);

                continue;
            }

            try {
                $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, $modelId);
                $providerName = OpenAiRuntimeProvider::registerProvider('knowledge_chunking', $driver, $providerUrl, $apiKey);
                $agent = new MarkdownContentWriterAgent($this->semanticChunkingSystemPrompt());
                $response = $agent->prompt(
                    $this->semanticChunkingUserPrompt($knowledgeBaseId, $blocks),
                    [],
                    $providerName,
                    $modelId
                );
                $content = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
                $plan = $this->decodeSemanticChunkPlan($content);
                $chunks = $this->chunksFromSemanticPlan($blocks, $plan);
                if ($chunks === []) {
                    Log::info('geoflow.knowledge_semantic_chunking_invalid_response', [
                        'knowledge_base_id' => $knowledgeBaseId,
                        'semantic_model_id' => (int) $model->id,
                        'model_identifier' => $modelId,
                        'provider_url' => $providerUrl,
                        'plan_count' => count($plan),
                    ]);

                    continue;
                }

                $this->recordSemanticChunkingUsage((int) $model->id);

                return $chunks;
            } catch (Throwable $exception) {
                Log::info('geoflow.knowledge_semantic_chunking_failed', [
                    'knowledge_base_id' => $knowledgeBaseId,
                    'semantic_model_id' => (int) $model->id,
                    'model_identifier' => $modelId,
                    'provider_url' => $providerUrl,
                    'message' => OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl),
                ]);
            }
        }

        return [];
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     */
    private function canAttemptSemanticChunking(array $blocks): bool
    {
        return count($blocks) <= self::SEMANTIC_CHUNKING_MAX_BLOCKS
            && $this->estimateSemanticPlanningPromptChars($blocks) <= $this->semanticChunkingMaxPromptChars();
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     */
    private function estimateSemanticPlanningPromptChars(array $blocks): int
    {
        $total = 600;
        foreach ($blocks as $block) {
            $total += mb_strlen((string) ($block['type'] ?? ''), 'UTF-8')
                + mb_strlen((string) ($block['section_path'] ?? ''), 'UTF-8')
                + min(260, mb_strlen($this->normalizeText((string) ($block['text'] ?? '')), 'UTF-8'))
                + 80;
        }

        return $total;
    }

    /**
     * @return list<AiModel>
     */
    private function resolveSemanticChunkingModels(): array
    {
        $modelId = (int) (SiteSetting::query()
            ->where('setting_key', 'knowledge_chunking_model_id')
            ->value('setting_value') ?? 0);
        if ($modelId <= 0) {
            return [];
        }

        $models = [];
        $primaryModel = $this->semanticChunkingModelQuery()
            ->whereKey($modelId)
            ->first();
        if ($primaryModel) {
            $models[(int) $primaryModel->id] = $primaryModel;
        }

        $fallbackModels = $this->semanticChunkingModelQuery()
            ->when($models !== [], function ($query) use ($models): void {
                $query->whereNotIn('id', array_keys($models));
            })
            ->orderByRaw('COALESCE(failover_priority, 1000000) asc')
            ->orderBy('id')
            ->get();

        foreach ($fallbackModels as $fallbackModel) {
            $models[(int) $fallbackModel->id] = $fallbackModel;
        }

        return array_values($models);
    }

    private function semanticChunkingModelQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return AiModel::query()
            ->where('status', 'active')
            ->where(function ($query): void {
                $query->whereNull('model_type')
                    ->orWhere('model_type', '')
                    ->orWhere('model_type', 'chat');
            })
            ->where(function ($query): void {
                $query->whereNull('daily_limit')
                    ->orWhere('daily_limit', '<=', 0)
                    ->orWhereRaw('COALESCE(used_today, 0) < daily_limit');
            });
    }

    private function semanticChunkingMaxPromptChars(): int
    {
        return max(1, (int) config('geoflow.semantic_chunking_max_chars', self::SEMANTIC_CHUNKING_MAX_PROMPT_CHARS));
    }

    private function recordSemanticChunkingUsage(int $modelId): void
    {
        AiModel::query()->whereKey($modelId)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);
    }

    private function semanticChunkingSystemPrompt(): string
    {
        return 'You are GEOFlow\'s knowledge-base semantic chunk planner. You only group original block indexes into chunks. Do not rewrite, summarize, translate, add facts, or return source text. Output strict JSON only.';
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     */
    private function semanticChunkingUserPrompt(int $knowledgeBaseId, array $blocks): string
    {
        $blockPayload = array_map(function (array $block): array {
            return [
                'index' => (int) ($block['index'] ?? 0),
                'type' => (string) ($block['type'] ?? 'paragraph'),
                'section_path' => (string) ($block['section_path'] ?? ''),
                'text' => mb_substr($this->normalizeText((string) ($block['text'] ?? '')), 0, 260, 'UTF-8'),
            ];
        }, $blocks);

        return "Plan semantic chunks for knowledge base {$knowledgeBaseId}.\n"
            ."Requirements:\n"
            ."1. Every block index must appear exactly once.\n"
            ."2. Keep block indexes in original ascending order; never reorder, skip, or duplicate blocks.\n"
            ."3. Merge adjacent blocks when they are semantically continuous; split at heading, topic, list, or table boundaries when useful.\n"
            ."4. Return only a concise chunk title and block_indexes. Do not include source text, summaries, explanations, Markdown fences, or comments.\n"
            ."5. Output strict JSON only with this schema: {\"chunks\":[{\"title\":\"...\",\"block_indexes\":[0,1]}]}.\n\n"
            ."blocks:\n".json_encode($blockPayload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @return list<array{title:string,block_indexes:list<int>}>
     */
    private function decodeSemanticChunkPlan(string $content): array
    {
        $content = trim($content);
        if ($content === '') {
            return [];
        }

        if (preg_match('/```(?:json)?\s*(.*?)```/su', $content, $matches) === 1) {
            $content = trim((string) $matches[1]);
        } else {
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            if ($start !== false && $end !== false && $end >= $start) {
                $content = substr($content, $start, $end - $start + 1);
            }
        }

        $decoded = json_decode($content, true);
        if (! is_array($decoded) || ! isset($decoded['chunks']) || ! is_array($decoded['chunks'])) {
            return [];
        }

        $plan = [];
        foreach ($decoded['chunks'] as $item) {
            if (! is_array($item) || ! isset($item['block_indexes']) || ! is_array($item['block_indexes'])) {
                return [];
            }

            $indexes = [];
            foreach ($item['block_indexes'] as $index) {
                $normalizedIndex = $this->normalizeSemanticPlanIndex($index);
                if ($normalizedIndex === null) {
                    return [];
                }
                $indexes[] = $normalizedIndex;
            }
            if ($indexes === []) {
                return [];
            }

            $plan[] = [
                'title' => trim((string) ($item['title'] ?? '')),
                'block_indexes' => $indexes,
            ];
        }

        return $plan;
    }

    private function normalizeSemanticPlanIndex(mixed $index): ?int
    {
        if (is_int($index)) {
            return $index >= 0 ? $index : null;
        }

        if (is_string($index) && preg_match('/^\d+$/u', $index) === 1) {
            return (int) $index;
        }

        return null;
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     * @param  list<array{title:string,block_indexes:list<int>}>  $plan
     * @return list<array{content:string,title:string,section_path:string,strategy:string,metadata:array<string,mixed>}>
     */
    private function chunksFromSemanticPlan(array $blocks, array $plan): array
    {
        if ($plan === []) {
            return [];
        }

        $blocksByIndex = [];
        foreach ($blocks as $block) {
            $blocksByIndex[(int) ($block['index'] ?? 0)] = $block;
        }

        $seen = [];
        $chunks = [];
        $lastIndex = -1;
        foreach ($plan as $plannedChunk) {
            $chunkBlocks = [];
            foreach ($plannedChunk['block_indexes'] as $index) {
                if ($index <= $lastIndex || ! isset($blocksByIndex[$index]) || isset($seen[$index])) {
                    return [];
                }
                $seen[$index] = true;
                $lastIndex = $index;
                $chunkBlocks[] = $blocksByIndex[$index];
            }
            $chunks[] = $this->chunkFromBlocks($chunkBlocks, 'semantic_llm', $plannedChunk['title']);
        }

        if (count($seen) !== count($blocks)) {
            return [];
        }

        return $chunks;
    }

    private function chunkMaxChars(): int
    {
        $configured = (int) (SiteSetting::query()
            ->where('setting_key', 'knowledge_chunk_max_chars')
            ->value('setting_value') ?? 900);

        return max(300, min(3000, $configured));
    }

    /**
     * 生成检索查询文本对应的 pgvector 字面量。
     *
     * 对齐 bak 逻辑：优先使用默认 embedding 模型生成真实查询向量；
     * 当模型不可用、调用失败或当前环境不支持 pgvector 时返回空字符串，调用方走回退检索。
     *
     * 观测：开启 {@see config('geoflow.debug_knowledge_query_embedding')} 时写入 `geoflow.knowledge_query_embedding` 日志。
     */
    public function generateQueryVectorLiteral(string $query): string
    {
        $debug = (bool) config('geoflow.debug_knowledge_query_embedding', false);
        $query = trim($query);
        if ($query === '') {
            if ($debug) {
                Log::info('geoflow.knowledge_query_embedding', ['outcome' => 'skip_empty_query']);
            }

            return '';
        }

        if (! $this->canStoreEmbeddingVector()) {
            if ($debug) {
                Log::info('geoflow.knowledge_query_embedding', ['outcome' => 'skip_no_pgvector_storage']);
            }

            return '';
        }

        $rawVector = $this->generateQueryEmbeddingVector($query);
        if ($rawVector === []) {
            return '';
        }

        $paddedVector = $this->padVector($rawVector, $this->embeddingStorageDimensions());
        if ($debug) {
            Log::info('geoflow.knowledge_query_embedding', [
                'outcome' => 'embedding_api_ok',
                'raw_dimensions' => count($rawVector),
                'storage_dimensions' => count($paddedVector),
            ]);
        }

        return $this->vectorLiteral($paddedVector);
    }

    /**
     * 生成检索查询文本对应的真实 embedding 数组。
     *
     * 当没有可用 embedding 模型或 API 调用失败时返回空数组，调用方可继续走 fallback 检索。
     *
     * @return list<float>
     */
    public function generateQueryEmbeddingVector(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $embeddingMetadata = $this->resolveEmbeddingMetadata();
        if ($embeddingMetadata === null) {
            return [];
        }

        $providerName = OpenAiRuntimeProvider::registerProvider(
            'embedding_query',
            (string) ($embeddingMetadata['driver'] ?? 'openai'),
            (string) $embeddingMetadata['api_url'],
            (string) $embeddingMetadata['api_key']
        );

        try {
            $response = Embeddings::for([$this->formatEmbeddingQueryInput($query, $embeddingMetadata)])
                ->timeout(45)
                ->generate($providerName, (string) $embeddingMetadata['model_name']);
            $rawVector = $this->normalizeEmbeddingVector($response->embeddings[0] ?? null);
            if ($rawVector === null) {
                return [];
            }

            $this->recordEmbeddingUsage((int) $embeddingMetadata['model_id']);

            return $rawVector;
        } catch (Throwable $exception) {
            Log::info('geoflow.knowledge_query_embedding_failed', [
                'embedding_model_id' => (int) ($embeddingMetadata['model_id'] ?? 0),
                'model_identifier' => (string) ($embeddingMetadata['model_name'] ?? ''),
                'message' => OpenAiRuntimeProvider::normalizeApiException($exception, (string) ($embeddingMetadata['api_url'] ?? '')),
            ]);

            return [];
        }
    }

    /**
     * 读取可用的默认 embedding 模型元数据。
     *
     * @return array{model_id:int,model_name:string,provider:string,api_url:string,api_key:string,driver:string}|null
     */
    private function resolveEmbeddingMetadata(): ?array
    {
        $defaultEmbeddingModelId = (int) (SiteSetting::query()
            ->where('setting_key', 'default_embedding_model_id')
            ->value('setting_value') ?? 0);

        $query = AiModel::query()
            ->where('status', 'active')
            ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'");

        $candidates = [];
        if ($defaultEmbeddingModelId > 0) {
            $defaultModel = (clone $query)->whereKey($defaultEmbeddingModelId)->first();
            if ($defaultModel) {
                $candidates[] = $defaultModel;
            }
        }

        foreach (
            (clone $query)
                ->when($defaultEmbeddingModelId > 0, fn ($builder) => $builder->whereKeyNot($defaultEmbeddingModelId))
                ->orderBy('failover_priority')
                ->orderByDesc('id')
                ->get() as $fallbackModel
        ) {
            $candidates[] = $fallbackModel;
        }

        foreach ($candidates as $model) {
            $metadata = $this->modelToEmbeddingMetadata($model);
            if ($metadata !== null) {
                return $metadata;
            }
        }

        return null;
    }

    /**
     * @return array{model_id:int,model_name:string,provider:string,api_url:string,api_key:string,driver:string}|null
     */
    private function modelToEmbeddingMetadata(AiModel $model): ?array
    {
        $providerUrl = OpenAiRuntimeProvider::resolveEmbeddingBaseUrl((string) ($model->api_url ?? ''));
        $apiKey = $this->decryptApiKey((string) ($model->getRawOriginal('api_key') ?? ''));
        $modelName = trim((string) ($model->model_id ?? ''));
        if ($providerUrl === '' || $apiKey === '' || $modelName === '') {
            return null;
        }

        return [
            'model_id' => (int) $model->id,
            'model_name' => $modelName,
            'provider' => (string) (parse_url($providerUrl, PHP_URL_HOST) ?: ''),
            'api_url' => $providerUrl,
            'api_key' => $apiKey,
            'driver' => OpenAiRuntimeProvider::resolveEmbeddingDriver($providerUrl, $modelName),
        ];
    }

    /**
     * 批量生成真实向量；任一异常则整体回退到 fallback 向量。
     *
     * @param  list<string>  $chunks
     * @param  array{model_id:int,model_name:string,provider:string,api_url:string,api_key:string,driver:string}|null  $embeddingMetadata
     * @return array<int, array{model_id:int,dimensions:int,provider:string,vector:list<float>,vector_literal:?string}>
     */
    private function generateEmbeddingsForChunks(
        array $chunks,
        ?array $embeddingMetadata,
        bool $requireRealEmbedding = false,
        ?string $documentTitle = null
    ): array
    {
        if ($chunks === []) {
            return [];
        }
        if ($embeddingMetadata === null) {
            if ($requireRealEmbedding) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.embedding_required'));
            }

            return [];
        }

        $canStoreEmbeddingVector = $this->canStoreEmbeddingVector();
        $providerName = OpenAiRuntimeProvider::registerProvider(
            'embedding',
            (string) ($embeddingMetadata['driver'] ?? 'openai'),
            (string) $embeddingMetadata['api_url'],
            (string) $embeddingMetadata['api_key']
        );

        try {
            $results = [];
            foreach (array_chunk($chunks, 12, true) as $batch) {
                $batchKeys = array_keys($batch);
                $batchInputs = $this->formatEmbeddingDocumentInputs(array_values($batch), $embeddingMetadata, $documentTitle);
                $response = Embeddings::for($batchInputs)
                    ->timeout(45)
                    ->generate($providerName, (string) $embeddingMetadata['model_name']);

                $embeddings = $response->embeddings;
                foreach (array_values($batch) as $position => $_chunkContent) {
                    $rawVector = $this->normalizeEmbeddingVector($embeddings[$position] ?? null);
                    if ($rawVector === null) {
                        throw new \RuntimeException('invalid_embedding_vector');
                    }

                    $actualDimensions = count($rawVector);
                    $results[$batchKeys[$position]] = [
                        'model_id' => (int) $embeddingMetadata['model_id'],
                        'dimensions' => $actualDimensions,
                        'provider' => (string) $embeddingMetadata['provider'],
                        'vector' => $rawVector,
                        'vector_literal' => $canStoreEmbeddingVector
                            ? $this->vectorLiteral($this->padVector($rawVector, $this->embeddingStorageDimensions()))
                            : null,
                    ];
                }

                $this->recordEmbeddingUsage((int) $embeddingMetadata['model_id']);
            }

            return count($results) === count($chunks) ? $results : [];
        } catch (Throwable $exception) {
            $message = OpenAiRuntimeProvider::normalizeApiException($exception, (string) ($embeddingMetadata['api_url'] ?? ''));
            Log::info('geoflow.knowledge_embedding_failed', [
                'embedding_model_id' => (int) ($embeddingMetadata['model_id'] ?? 0),
                'model_identifier' => (string) ($embeddingMetadata['model_name'] ?? ''),
                'message' => $message,
            ]);

            if ($requireRealEmbedding) {
                throw new \RuntimeException(__('admin.knowledge_bases.error.embedding_api_failed', ['message' => $message]));
            }

            // 关键兜底：向量 API 不可用时，不中断知识库同步主流程。
            return [];
        }
    }

    private function resolveEmbeddingDocumentTitle(int $knowledgeBaseId): string
    {
        $title = trim((string) (KnowledgeBase::query()->whereKey($knowledgeBaseId)->value('name') ?? ''));

        return $title !== '' ? $this->normalizeGeminiEmbeddingSegment($title) : 'none';
    }

    /**
     * @param  array{model_id:int,model_name:string,provider:string,api_url:string,api_key:string,driver:string}  $embeddingMetadata
     */
    private function formatEmbeddingQueryInput(string $query, array $embeddingMetadata): string
    {
        $query = trim($query);
        if (! $this->isGeminiEmbeddingMetadata($embeddingMetadata)) {
            return $query;
        }

        return 'task: search result | query: '.$this->normalizeGeminiEmbeddingSegment($query);
    }

    /**
     * @param  list<string>  $chunks
     * @param  array{model_id:int,model_name:string,provider:string,api_url:string,api_key:string,driver:string}  $embeddingMetadata
     * @return list<string>
     */
    private function formatEmbeddingDocumentInputs(array $chunks, array $embeddingMetadata, ?string $documentTitle): array
    {
        if (! $this->isGeminiEmbeddingMetadata($embeddingMetadata)) {
            return $chunks;
        }

        $title = trim((string) $documentTitle);
        $title = $title !== '' ? $this->normalizeGeminiEmbeddingSegment($title) : 'none';

        return array_map(
            fn (string $chunk): string => 'title: '.$title.' | text: '.$this->normalizeGeminiEmbeddingSegment($chunk),
            $chunks
        );
    }

    /**
     * @param  array<string, mixed>  $embeddingMetadata
     */
    private function isGeminiEmbeddingMetadata(array $embeddingMetadata): bool
    {
        return (string) ($embeddingMetadata['driver'] ?? '') === 'gemini'
            || OpenAiRuntimeProvider::isGeminiProviderUrl((string) ($embeddingMetadata['api_url'] ?? ''));
    }

    private function normalizeGeminiEmbeddingSegment(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?: $value);
    }

    /**
     * 对齐 bak：仅在 PostgreSQL + pgvector 可用时写入 embedding_vector。
     */
    private function canStoreEmbeddingVector(): bool
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

            return $typeRow !== null && (bool) ($typeRow->ok ?? false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * 对齐 bak：向量列固定存储 3072 维。
     */
    private function embeddingStorageDimensions(): int
    {
        return 3072;
    }

    /**
     * 对齐 bak：不足补 0，超长截断，保证可写入 vector(3072)。
     *
     * @param  list<float>  $vector
     * @return list<float>
     */
    private function padVector(array $vector, int $storageDimensions): array
    {
        $storageDimensions = max(1, $storageDimensions);
        $normalized = [];
        foreach ($vector as $value) {
            $normalized[] = (float) $value;
        }

        if (count($normalized) > $storageDimensions) {
            $normalized = array_slice($normalized, 0, $storageDimensions);
        }

        while (count($normalized) < $storageDimensions) {
            $normalized[] = 0.0;
        }

        return $normalized;
    }

    /**
     * 转为 pgvector 可识别的文本字面量。
     *
     * @param  list<float>  $vector
     */
    private function vectorLiteral(array $vector): string
    {
        return json_encode($vector, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) ?: '[]';
    }

    /**
     * 清洗并校验 Embedding 返回值。
     *
     * @return list<float>|null
     */
    private function normalizeEmbeddingVector(mixed $rawVector): ?array
    {
        if (! is_array($rawVector) || $rawVector === []) {
            return null;
        }

        $vector = [];
        foreach ($rawVector as $value) {
            if (! is_numeric($value)) {
                return null;
            }
            $vector[] = (float) $value;
        }

        return $vector === [] ? null : $vector;
    }

    /**
     * 记录 embedding API 成功调用次数。
     */
    private function recordEmbeddingUsage(int $modelId): void
    {
        if ($modelId <= 0) {
            return;
        }

        AiModel::query()->whereKey($modelId)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);
    }

    /**
     * 解密 ai_models 中的 API Key（兼容旧系统 enc:v1 格式）。
     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
    }

    /**
     * 构建 fallback 哈希向量，维度固定，便于后续检索回退。
     *
     * @return list<float>
     */
    private function buildFallbackVector(string $text, int $dimensions): array
    {
        $vector = array_fill(0, $dimensions, 0.0);
        $tokens = $this->extractTokens($text);

        if (empty($tokens)) {
            return $vector;
        }

        foreach ($tokens as $token) {
            $indexSeed = abs((int) crc32('i:'.$token));
            $signSeed = abs((int) crc32('s:'.$token));
            $index = $indexSeed % $dimensions;
            $sign = ($signSeed % 2 === 0) ? 1.0 : -1.0;
            $weight = 1.0 + log(1 + mb_strlen($token, 'UTF-8'));
            $vector[$index] += $sign * $weight;
        }

        $norm = 0.0;
        foreach ($vector as $value) {
            $norm += $value * $value;
        }
        if ($norm <= 0.0) {
            return $vector;
        }

        $norm = sqrt($norm);
        foreach ($vector as $index => $value) {
            $vector[$index] = $value / $norm;
        }

        return $vector;
    }

    /**
     * 提取中英混合 token，用于 token 数估算与 fallback 向量。
     *
     * @return list<string>
     */
    private function extractTokens(string $text): array
    {
        $normalized = mb_strtolower($this->normalizeText($text), 'UTF-8');
        if ($normalized === '') {
            return [];
        }

        $tokens = [];
        if (preg_match_all('/[a-z0-9][a-z0-9._+#-]{1,}/u', $normalized, $latinMatches)) {
            foreach ($latinMatches[0] as $token) {
                $token = trim((string) $token);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }
        if (preg_match_all('/[\p{Han}]{2,32}/u', $normalized, $hanMatches)) {
            foreach ($hanMatches[0] as $sequence) {
                $sequence = trim((string) $sequence);
                if ($sequence !== '') {
                    $tokens[] = $sequence;
                }
            }
        }

        return $tokens;
    }

    /**
     * 估算 token 数，用于展示与后续检索排序。
     */
    private function estimateTokenCount(string $content): int
    {
        return count($this->extractTokens($content));
    }

    /**
     * 标准化文本，减少分块抖动。
     */
    private function normalizeText(string $text): string
    {
        $text = str_replace(["\xEF\xBB\xBF", "\xC2\xA0", "\xE3\x80\x80"], ['', ' ', ' '], $text);
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace("/\r\n|\r/u", "\n", $text) ?? $text;
        $text = preg_replace("/[ \t]+\n/u", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;

        return trim($text);
    }
}
