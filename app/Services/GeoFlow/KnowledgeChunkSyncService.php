<?php

namespace App\Services\GeoFlow;

use App\Models\AiModel;
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
    /**
     * 复用统一 API Key 解密组件，保证 embedding 调用与模型配置页完全一致。
     */
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    /**
     * 将知识库正文重建为 chunks，并同步向量相关字段。
     */
    public function sync(int $knowledgeBaseId, string $content): int
    {
        if ($knowledgeBaseId <= 0) {
            return 0;
        }

        $chunks = $this->chunkText($content);
        $embeddingMetadata = $this->resolveEmbeddingMetadata();
        $generatedEmbeddings = $this->generateEmbeddingsForChunks($chunks, $embeddingMetadata);

        DB::transaction(function () use ($knowledgeBaseId, $chunks, $generatedEmbeddings): void {
            KnowledgeChunk::query()->where('knowledge_base_id', $knowledgeBaseId)->delete();

            foreach ($chunks as $index => $chunkContent) {
                $fallbackVector = $this->buildFallbackVector($chunkContent, 256);
                $realEmbedding = $generatedEmbeddings[$index] ?? null;
                $isRealEmbedding = is_array($realEmbedding);

                KnowledgeChunk::query()->create([
                    'knowledge_base_id' => $knowledgeBaseId,
                    'chunk_index' => $index,
                    'content' => $chunkContent,
                    'content_hash' => hash('sha256', $chunkContent),
                    'token_count' => $this->estimateTokenCount($chunkContent),
                    'embedding_json' => json_encode($fallbackVector, JSON_UNESCAPED_UNICODE),
                    'embedding_model_id' => $isRealEmbedding ? (int) ($realEmbedding['model_id'] ?? 0) : null,
                    'embedding_dimensions' => $isRealEmbedding ? (int) ($realEmbedding['dimensions'] ?? 0) : 0,
                    'embedding_provider' => $isRealEmbedding ? (string) ($realEmbedding['provider'] ?? '') : '',
                    'embedding_vector' => $isRealEmbedding ? (string) ($realEmbedding['vector_literal'] ?? '') : null,
                ]);
            }
        });

        return count($chunks);
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

        $embeddingMetadata = $this->resolveEmbeddingMetadata();
        if ($embeddingMetadata === null) {
            if ($debug) {
                Log::info('geoflow.knowledge_query_embedding', ['outcome' => 'skip_no_embedding_model']);
            }

            return '';
        }

        $providerName = OpenAiRuntimeProvider::registerProvider(
            'embedding_query',
            'openai',
            (string) $embeddingMetadata['api_url'],
            (string) $embeddingMetadata['api_key']
        );

        try {
            $response = Embeddings::for([$query])
                ->timeout(45)
                ->generate($providerName, (string) $embeddingMetadata['model_name']);
            $rawVector = $this->normalizeEmbeddingVector($response->embeddings[0] ?? null);
            if ($rawVector === null) {
                if ($debug) {
                    Log::info('geoflow.knowledge_query_embedding', [
                        'outcome' => 'embedding_response_invalid',
                        'embedding_model_id' => (int) ($embeddingMetadata['model_id'] ?? 0),
                        'model_identifier' => (string) ($embeddingMetadata['model_name'] ?? ''),
                        'query_length' => mb_strlen($query, 'UTF-8'),
                    ]);
                }

                return '';
            }

            $paddedVector = $this->padVector($rawVector, $this->embeddingStorageDimensions());

            if ($debug) {
                Log::info('geoflow.knowledge_query_embedding', [
                    'outcome' => 'embedding_api_ok',
                    'embedding_model_id' => (int) ($embeddingMetadata['model_id'] ?? 0),
                    'model_identifier' => (string) ($embeddingMetadata['model_name'] ?? ''),
                    'provider_host' => (string) ($embeddingMetadata['provider'] ?? ''),
                    'raw_dimensions' => count($rawVector),
                    'storage_dimensions' => count($paddedVector),
                    'query_length' => mb_strlen($query, 'UTF-8'),
                ]);
            }

            return $this->vectorLiteral($paddedVector);
        } catch (Throwable $exception) {
            if ($debug) {
                Log::info('geoflow.knowledge_query_embedding', [
                    'outcome' => 'embedding_api_exception',
                    'embedding_model_id' => (int) ($embeddingMetadata['model_id'] ?? 0),
                    'model_identifier' => (string) ($embeddingMetadata['model_name'] ?? ''),
                    'query_length' => mb_strlen($query, 'UTF-8'),
                    'message' => OpenAiRuntimeProvider::normalizeApiException($exception, (string) ($embeddingMetadata['api_url'] ?? '')),
                ]);
            }

            return '';
        }
    }

    /**
     * 读取可用的默认 embedding 模型元数据。
     *
     * @return array{model_id:int,model_name:string,provider:string,api_url:string,api_key:string}|null
     */
    private function resolveEmbeddingMetadata(): ?array
    {
        $defaultEmbeddingModelId = (int) (SiteSetting::query()
            ->where('setting_key', 'default_embedding_model_id')
            ->value('setting_value') ?? 0);
        if ($defaultEmbeddingModelId <= 0) {
            return null;
        }

        $model = AiModel::query()
            ->whereKey($defaultEmbeddingModelId)
            ->where('status', 'active')
            ->whereRaw("COALESCE(NULLIF(model_type, ''), 'chat') = 'embedding'")
            ->first();
        if (! $model) {
            return null;
        }

        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($model->api_url ?? ''));
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
        ];
    }

    /**
     * 批量生成真实向量；任一异常则整体回退到 fallback 向量。
     *
     * @param  list<string>  $chunks
     * @param  array{model_id:int,model_name:string,provider:string,api_url:string,api_key:string}|null  $embeddingMetadata
     * @return array<int, array{model_id:int,dimensions:int,provider:string,vector_literal:string}>
     */
    private function generateEmbeddingsForChunks(array $chunks, ?array $embeddingMetadata): array
    {
        if ($chunks === [] || $embeddingMetadata === null || ! $this->canStoreEmbeddingVector()) {
            return [];
        }

        $providerName = OpenAiRuntimeProvider::registerProvider(
            'embedding',
            'openai',
            (string) $embeddingMetadata['api_url'],
            (string) $embeddingMetadata['api_key']
        );

        try {
            $results = [];
            foreach (array_chunk($chunks, 12, true) as $batch) {
                $batchKeys = array_keys($batch);
                $response = Embeddings::for(array_values($batch))
                    ->timeout(45)
                    ->generate($providerName, (string) $embeddingMetadata['model_name']);

                $embeddings = $response->embeddings;
                foreach (array_values($batch) as $position => $_chunkContent) {
                    $rawVector = $this->normalizeEmbeddingVector($embeddings[$position] ?? null);
                    if ($rawVector === null) {
                        throw new \RuntimeException('invalid_embedding_vector');
                    }

                    $actualDimensions = count($rawVector);
                    $paddedVector = $this->padVector($rawVector, $this->embeddingStorageDimensions());
                    $results[$batchKeys[$position]] = [
                        'model_id' => (int) $embeddingMetadata['model_id'],
                        'dimensions' => $actualDimensions,
                        'provider' => (string) $embeddingMetadata['provider'],
                        'vector_literal' => $this->vectorLiteral($paddedVector),
                    ];
                }
            }

            return count($results) === count($chunks) ? $results : [];
        } catch (Throwable) {
            // 关键兜底：向量 API 不可用时，不中断知识库同步主流程。
            return [];
        }
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
     * 解密 ai_models 中的 API Key（兼容旧系统 enc:v1 格式）。
     */
    private function decryptApiKey(string $storedApiKey): string
    {
        return $this->apiKeyCrypto->decrypt($storedApiKey);
    }

    /**
     * 按段落切块，超长段落会按字符数切分。
     *
     * @return list<string>
     */
    private function chunkText(string $content, int $maxChars = 900): array
    {
        $normalized = $this->normalizeText($content);
        if ($normalized === '') {
            return [];
        }

        $paragraphs = preg_split("/\n{2,}/u", $normalized, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (empty($paragraphs)) {
            $paragraphs = [$normalized];
        }

        $chunks = [];
        $buffer = '';

        foreach ($paragraphs as $paragraph) {
            $paragraph = $this->normalizeText($paragraph);
            if ($paragraph === '') {
                continue;
            }

            if (mb_strlen($paragraph, 'UTF-8') > $maxChars) {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                    $buffer = '';
                }

                $length = mb_strlen($paragraph, 'UTF-8');
                for ($offset = 0; $offset < $length; $offset += $maxChars) {
                    $piece = $this->normalizeText(mb_substr($paragraph, $offset, $maxChars, 'UTF-8'));
                    if ($piece !== '') {
                        $chunks[] = $piece;
                    }
                }

                continue;
            }

            $candidate = $buffer === '' ? $paragraph : $buffer."\n\n".$paragraph;
            if (mb_strlen($candidate, 'UTF-8') <= $maxChars) {
                $buffer = $candidate;
            } else {
                if ($buffer !== '') {
                    $chunks[] = $buffer;
                }
                $buffer = $paragraph;
            }
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return array_values(array_filter(array_map(fn (string $item): string => $this->normalizeText($item), $chunks)));
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
