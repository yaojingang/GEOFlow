<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Data\Ai\AiExecutionContext;
use App\Data\Ai\KnowledgeEmbeddingProviderResult;
use App\Data\Ai\KnowledgeQueryEmbeddingResult;
use App\Data\Ai\SystemAiIdentity;
use App\Exceptions\AiModelAccessException;
use App\Exceptions\PermanentAiProviderException;
use App\Jobs\ReconcileKnowledgeFactEvidenceJob;
use App\Models\Admin;
use App\Models\AiModel;
use App\Models\AiModelUsageEvent;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use App\Models\SiteSetting;
use App\Services\Admin\AdminAiModelAccessResolver;
use App\Services\Admin\AiModelUsageAttempt;
use App\Services\Admin\AiModelUsageAttemptFactory;
use App\Services\AiWorkspace\AiModelInvocationLock;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\GeoFlow\AiExecutionErrorSanitizer;
use App\Support\GeoFlow\AiModelFailoverDecider;
use App\Support\GeoFlow\ApiKeyCrypto;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
    private const MAX_CONTENT_BYTES = 8 * 1024 * 1024;

    private const MAX_STRUCTURED_LINES = 2000;

    private const MIN_STRUCTURED_LINE_BUDGET = 100;

    private const STRUCTURED_LINE_AMPLIFICATION = 4;

    private const SEMANTIC_CHUNKING_MAX_BLOCKS = 120;

    private const SEMANTIC_CHUNKING_MAX_PROMPT_CHARS = 20000;

    /**
     * 复用统一 API Key 解密组件，保证 embedding 调用与模型配置页完全一致。
     */
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly Factory $http,
        private readonly AiUsageQuotaService $usageQuota,
        private readonly ArticleAiQualityInvalidationService $qualityInvalidationService,
        private readonly SystemAiModelAccessResolver $systemModelAccessResolver,
        private readonly AdminAiModelAccessResolver $adminModelAccessResolver,
        private readonly AiExecutionAccessGuard $executionAccessGuard,
        private readonly KnowledgeEmbeddingModelFingerprint $embeddingFingerprint,
        private readonly AiExecutionErrorSanitizer $errorSanitizer,
        private readonly AiModelFailoverDecider $failoverDecider,
        private readonly AiModelUsageAttemptFactory $usageAttempts,
        private readonly AiModelInvocationLock $invocationLocks,
    ) {}

    /**
     * 将知识库正文重建为 chunks，并同步向量相关字段。
     *
     * 默认仍允许 fallback 向量，避免上传/编辑知识库时被 embedding 服务阻断。
     * 管理后台“更新切片”会启用强制真实 embedding 模式，失败时抛错并保留原切片。
     */
    public function sync(
        int $knowledgeBaseId,
        string $content,
        SystemAiIdentity $identity,
        bool $requireRealEmbedding = false,
    ): int {
        $identity->assertCanBuildKnowledgeIndex();
        if ($knowledgeBaseId <= 0 || ! KnowledgeBase::query()->whereKey($knowledgeBaseId)->exists()) {
            return 0;
        }

        $syncToken = (string) Str::uuid();
        $pipelineFields = $this->systemEmbeddingPipelineFields($identity);
        KnowledgeBase::query()->whereKey($knowledgeBaseId)->update([
            'chunk_sync_status' => 'processing',
            'chunk_sync_token' => $syncToken,
            'chunk_source_hash' => hash('sha256', $content),
            'chunk_sync_error' => null,
            ...$pipelineFields,
            'updated_at' => now(),
        ]);

        try {
            $chunkCount = $this->prepareStagingSync(
                $knowledgeBaseId,
                $content,
                $syncToken,
                $identity,
                $this->usageAttempts->requestId(),
            );
            $afterRowId = 0;
            $dispatchOrdinal = 1;
            while (true) {
                $batch = $this->embedStagingBatch(
                    $knowledgeBaseId,
                    $syncToken,
                    $afterRowId,
                    $identity,
                    $requireRealEmbedding,
                    $this->usageAttempts->requestId(),
                    1,
                    $dispatchOrdinal++,
                );
                if ($batch === null || $batch['done']) {
                    break;
                }

                $afterRowId = $batch['last_id'];
            }

            $this->finalizeStagingSync($knowledgeBaseId, $syncToken, $identity);

            return $chunkCount;
        } catch (Throwable $exception) {
            DB::table('knowledge_chunk_sync_rows')
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->where('sync_token', $syncToken)
                ->delete();
            KnowledgeBase::query()
                ->whereKey($knowledgeBaseId)
                ->where('chunk_sync_token', $syncToken)
                ->update([
                    'chunk_sync_status' => 'failed',
                    'chunk_sync_error' => $this->errorSanitizer->sanitize($exception, 'knowledge_sync_failed'),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }

    public function prepareStagingSync(
        int $knowledgeBaseId,
        string $content,
        string $syncToken,
        SystemAiIdentity $identity,
        ?string $executionToken = null,
        int $executionAttempt = 1,
        int $dispatchOrdinal = 1,
    ): int {
        $identity->assertCanBuildKnowledgeIndex();
        $expectedSourceHash = hash('sha256', $content);
        KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->where('chunk_sync_token', $syncToken)
            ->whereIn('chunk_sync_status', ['pending', 'processing'])
            ->where('content', $content)
            ->where(static function ($query): void {
                $query->whereNull('chunk_source_hash')->orWhere('chunk_source_hash', '');
            })
            ->update(['chunk_source_hash' => $expectedSourceHash]);
        if (! $this->knowledgeSyncSourceIsCurrent($knowledgeBaseId, $syncToken, $expectedSourceHash)) {
            return 0;
        }
        $this->ensurePipelineProfile($knowledgeBaseId, $syncToken, $identity);
        if (strlen($content) > self::MAX_CONTENT_BYTES) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.content_too_large'));
        }

        $executionToken = $this->normalizeKnowledgeIndexExecutionToken($executionToken);
        $semanticUsage = null;
        $semanticStrategy = null;
        $plannedChunks = $this->planChunks(
            $knowledgeBaseId,
            $content,
            $identity,
            $syncToken,
            $executionToken,
            $executionAttempt,
            $dispatchOrdinal,
            $semanticUsage,
            $semanticStrategy,
        );
        $knowledgeMetadata = $this->resolveKnowledgeBaseMetadata($knowledgeBaseId);
        $now = now();

        try {
            $persisted = DB::transaction(function () use (
                $knowledgeBaseId,
                $syncToken,
                $plannedChunks,
                $knowledgeMetadata,
                $now,
                $identity,
                $semanticUsage,
                $semanticStrategy,
                $expectedSourceHash,
            ): bool {
                $knowledgeBase = KnowledgeBase::query()
                    ->whereKey($knowledgeBaseId)
                    ->lockForUpdate()
                    ->first(['id', 'content', 'chunk_sync_token', 'chunk_sync_status', 'chunk_source_hash']);
                if (! $knowledgeBase
                    || ! hash_equals((string) $knowledgeBase->chunk_sync_token, $syncToken)
                    || ! in_array((string) $knowledgeBase->chunk_sync_status, ['pending', 'processing'], true)
                    || ! $this->knowledgeBaseMatchesSyncSource($knowledgeBase, $expectedSourceHash)
                    || ($semanticStrategy !== null && ! hash_equals($semanticStrategy, $this->resolveChunkStrategy()))) {
                    return false;
                }
                if ($semanticUsage instanceof KnowledgeIndexAiUsageSession) {
                    $snapshot = new AiModel;
                    $snapshot->setAttribute($snapshot->getKeyName(), $semanticUsage->modelId);
                    $currentModel = $this->systemModelAccessResolver->assertSemanticChunkingCurrent($identity, $snapshot);
                    if (! hash_equals(
                        $semanticUsage->configurationRevision,
                        $this->embeddingFingerprint->configurationRevision($currentModel),
                    )) {
                        throw AiModelAccessException::configAccessRevokedForAdminId($semanticUsage->ownerAdminId);
                    }
                }

                DB::table('knowledge_chunk_sync_rows')
                    ->where('knowledge_base_id', $knowledgeBaseId)
                    ->where('sync_token', $syncToken)
                    ->delete();

                $rows = [];
                foreach ($plannedChunks as $index => $chunk) {
                    $chunkContent = (string) ($chunk['content'] ?? '');
                    $fallbackVector = $this->buildFallbackVector($chunkContent, 256);
                    $rows[] = [
                        'knowledge_base_id' => $knowledgeBaseId,
                        'sync_token' => $syncToken,
                        'chunk_index' => $index,
                        'content' => $chunkContent,
                        'content_hash' => hash('sha256', $chunkContent),
                        'chunk_title' => mb_substr((string) ($chunk['title'] ?? ''), 0, 255, 'UTF-8'),
                        'section_path' => mb_substr((string) ($chunk['section_path'] ?? ''), 0, 500, 'UTF-8'),
                        'chunk_strategy' => mb_substr((string) ($chunk['strategy'] ?? 'structured_rule'), 0, 50, 'UTF-8'),
                        'metadata_json' => json_encode(
                            $this->mergeChunkMetadata($chunk['metadata'] ?? [], $knowledgeMetadata),
                            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
                        ),
                        'source_hash' => hash('sha256', (string) ($chunk['section_path'] ?? '').'|'.$chunkContent),
                        'token_count' => $this->estimateTokenCount($chunkContent),
                        'embedding_json' => json_encode(
                            $fallbackVector,
                            JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
                        ) ?: '[]',
                        'embedding_model_id' => null,
                        'embedding_dimensions' => 0,
                        'embedding_provider' => '',
                        'embedding_fingerprint' => '',
                        'embedding_profile_version' => null,
                        'embedding_profile_digest' => null,
                        'embedding_config_revision' => null,
                        'embedding_vector' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    if (count($rows) >= 50) {
                        DB::table('knowledge_chunk_sync_rows')->insert($rows);
                        $rows = [];
                    }
                }

                if ($rows !== []) {
                    DB::table('knowledge_chunk_sync_rows')->insert($rows);
                }

                KnowledgeBase::query()
                    ->whereKey($knowledgeBaseId)
                    ->where('chunk_sync_token', $syncToken)
                    ->update([
                        'chunk_sync_status' => 'processing',
                        'updated_at' => $now,
                    ]);

                return true;
            });
        } catch (AiModelAccessException $exception) {
            $semanticUsage?->revoked($exception->getErrorCode());

            throw $exception;
        } catch (Throwable $exception) {
            $semanticUsage?->discarded('knowledge_semantic_staging_failed');

            throw $exception;
        }

        if (! $persisted) {
            $semanticUsage?->discarded('knowledge_semantic_staging_stale');

            return 0;
        }

        $semanticUsage?->succeeded();

        return count($plannedChunks);
    }

    /**
     * @return array{last_id:int,done:bool}|null
     */
    public function embedStagingBatch(
        int $knowledgeBaseId,
        string $syncToken,
        int $afterRowId,
        SystemAiIdentity $identity,
        bool $requireRealEmbedding = false,
        ?string $executionToken = null,
        int $executionAttempt = 1,
        int $dispatchOrdinal = 1,
    ): ?array {
        $identity->assertCanBuildKnowledgeIndex();
        $batchLimit = max(1, min(32, (int) config('geoflow.knowledge_embedding_job_size', 32)));
        $rows = DB::table('knowledge_chunk_sync_rows')
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->where('sync_token', $syncToken)
            ->where('id', '>', $afterRowId)
            ->orderBy('id')
            ->limit($batchLimit)
            ->get(['id', 'content']);

        if ($rows->isEmpty()) {
            return null;
        }

        $chunks = [];
        foreach ($rows as $row) {
            $chunks[(int) $row->id] = (string) $row->content;
        }

        $embeddingMetadata = $this->resolveFrozenSystemEmbeddingMetadata(
            $knowledgeBaseId,
            $syncToken,
            $identity,
        );
        $executionToken = $this->normalizeKnowledgeIndexExecutionToken($executionToken);
        $embeddingUsage = null;
        $generatedEmbeddings = $this->generateEmbeddingsForChunks(
            $chunks,
            $embeddingMetadata,
            $identity,
            $requireRealEmbedding,
            $this->resolveEmbeddingDocumentTitle($knowledgeBaseId),
            $knowledgeBaseId,
            $syncToken,
            $executionToken,
            $executionAttempt,
            $dispatchOrdinal,
            $embeddingUsage,
        );

        if ($requireRealEmbedding && count($generatedEmbeddings) !== count($chunks)) {
            throw new \RuntimeException(__('admin.knowledge_bases.error.embedding_sync_failed'));
        }

        if ($generatedEmbeddings === []) {
            $hasRealEmbeddings = DB::table('knowledge_chunk_sync_rows')
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->where('sync_token', $syncToken)
                ->whereNotNull('embedding_model_id')
                ->exists();
            if ($hasRealEmbeddings) {
                $this->resetStagingEmbeddingsToFallback($knowledgeBaseId, $syncToken);
            }

            return [
                'last_id' => (int) $rows->last()->id,
                'done' => true,
            ];
        }

        $generatedProfile = $generatedEmbeddings[array_key_first($generatedEmbeddings)] ?? [];
        $existingProfile = DB::table('knowledge_chunk_sync_rows')
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->where('sync_token', $syncToken)
            ->whereNotNull('embedding_model_id')
            ->first(['embedding_model_id', 'embedding_profile_digest', 'embedding_config_revision']);
        if ($existingProfile !== null
            && ((int) $existingProfile->embedding_model_id !== (int) ($generatedProfile['model_id'] ?? 0)
                || ! hash_equals((string) $existingProfile->embedding_profile_digest, (string) ($generatedProfile['profile_digest'] ?? ''))
                || ! hash_equals((string) $existingProfile->embedding_config_revision, (string) ($generatedProfile['config_revision'] ?? '')))) {
            throw PermanentAiProviderException::fromProviderFailure(
                new \RuntimeException('knowledge_embedding_profile_mixed'),
            );
        }
        foreach ($generatedEmbeddings as $embedding) {
            if ((int) ($embedding['model_id'] ?? 0) !== (int) ($generatedProfile['model_id'] ?? 0)
                || (int) ($embedding['dimensions'] ?? 0) !== (int) ($generatedProfile['dimensions'] ?? 0)
                || ! hash_equals((string) ($embedding['provider'] ?? ''), (string) ($generatedProfile['provider'] ?? ''))
                || ! hash_equals((string) ($embedding['profile_digest'] ?? ''), (string) ($generatedProfile['profile_digest'] ?? ''))
                || ! hash_equals((string) ($embedding['config_revision'] ?? ''), (string) ($generatedProfile['config_revision'] ?? ''))) {
                throw PermanentAiProviderException::fromProviderFailure(
                    new \RuntimeException('knowledge_embedding_batch_profile_mixed'),
                );
            }
        }

        try {
            $persisted = DB::transaction(function () use ($knowledgeBaseId, $syncToken, $identity, $generatedEmbeddings): bool {
                $knowledgeBase = KnowledgeBase::query()
                    ->whereKey($knowledgeBaseId)
                    ->lockForUpdate()
                    ->first(['id', 'content', 'chunk_sync_token', 'chunk_sync_status', 'chunk_source_hash']);
                if (! $knowledgeBase
                    || ! hash_equals((string) $knowledgeBase->chunk_sync_token, $syncToken)
                    || ! in_array((string) $knowledgeBase->chunk_sync_status, ['pending', 'processing'], true)
                    || ! $this->knowledgeBaseMatchesSyncSource($knowledgeBase)) {
                    return false;
                }
                $this->assertFrozenSystemEmbeddingPipelineCurrent($knowledgeBaseId, $syncToken, $identity);
                foreach ($generatedEmbeddings as $rowId => $embedding) {
                    $updated = DB::table('knowledge_chunk_sync_rows')
                        ->where('id', (int) $rowId)
                        ->where('knowledge_base_id', $knowledgeBaseId)
                        ->where('sync_token', $syncToken)
                        ->update([
                            'embedding_json' => json_encode(
                                $embedding['vector'] ?? [],
                                JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
                            ) ?: '[]',
                            'embedding_model_id' => (int) ($embedding['model_id'] ?? 0),
                            'embedding_dimensions' => (int) ($embedding['dimensions'] ?? 0),
                            'embedding_provider' => (string) ($embedding['provider'] ?? ''),
                            'embedding_fingerprint' => (string) ($embedding['fingerprint'] ?? ''),
                            'embedding_profile_version' => (int) ($embedding['profile_version'] ?? 0),
                            'embedding_profile_digest' => (string) ($embedding['profile_digest'] ?? ''),
                            'embedding_config_revision' => (string) ($embedding['config_revision'] ?? ''),
                            'embedding_vector' => $embedding['vector_literal'] ?? null,
                            'updated_at' => now(),
                        ]);
                    if ($updated !== 1) {
                        return false;
                    }
                }

                return true;
            });
        } catch (AiModelAccessException|PermanentAiProviderException $exception) {
            $errorCode = $exception instanceof AiModelAccessException
                ? $exception->getErrorCode()
                : 'knowledge_embedding_configuration_revoked';
            $embeddingUsage?->revoked($errorCode);

            throw $exception;
        } catch (Throwable $exception) {
            $embeddingUsage?->discarded('knowledge_embedding_staging_failed');

            throw $exception;
        }
        if (! $persisted) {
            $embeddingUsage?->discarded('knowledge_embedding_staging_stale');

            return null;
        }
        $embeddingUsage?->succeeded();
        KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->where('chunk_sync_token', $syncToken)
            ->where('chunk_sync_status', 'processing')
            ->update(['updated_at' => now()]);

        $lastId = (int) $rows->last()->id;
        $hasMoreRows = DB::table('knowledge_chunk_sync_rows')
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->where('sync_token', $syncToken)
            ->where('id', '>', $lastId)
            ->exists();

        return [
            'last_id' => $lastId,
            'done' => ! $hasMoreRows,
        ];
    }

    public function finalizeStagingSync(
        int $knowledgeBaseId,
        string $syncToken,
        SystemAiIdentity $identity,
    ): bool {
        $identity->assertCanBuildKnowledgeIndex();
        $finalized = DB::transaction(function () use ($knowledgeBaseId, $syncToken, $identity): bool {
            $knowledgeBase = KnowledgeBase::query()
                ->whereKey($knowledgeBaseId)
                ->lockForUpdate()
                ->first();
            if (! $knowledgeBase
                || ! hash_equals((string) $knowledgeBase->chunk_sync_token, $syncToken)
                || ! in_array((string) $knowledgeBase->chunk_sync_status, ['pending', 'processing'], true)
                || ! $this->knowledgeBaseMatchesSyncSource($knowledgeBase)) {
                DB::table('knowledge_chunk_sync_rows')
                    ->where('knowledge_base_id', $knowledgeBaseId)
                    ->where('sync_token', $syncToken)
                    ->delete();

                return false;
            }

            $stagedQuery = DB::table('knowledge_chunk_sync_rows')
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->where('sync_token', $syncToken);
            if (! (clone $stagedQuery)->exists()) {
                throw new \RuntimeException('No staged knowledge chunks are available.');
            }

            $currentMetadata = $this->assertFrozenSystemEmbeddingPipelineCurrent(
                $knowledgeBaseId,
                $syncToken,
                $identity,
                true,
            );
            $embeddingProfile = $this->assertSingleStagedEmbeddingProfile($stagedQuery, $currentMetadata);

            (clone $stagedQuery)
                ->orderBy('id')
                ->chunkById(100, function ($rows) use ($syncToken): void {
                    $inserts = [];
                    foreach ($rows as $row) {
                        $inserts[] = [
                            'knowledge_base_id' => (int) $row->knowledge_base_id,
                            'generation_key' => $syncToken,
                            'chunk_index' => (int) $row->chunk_index,
                            'content' => (string) $row->content,
                            'content_hash' => (string) $row->content_hash,
                            'chunk_title' => (string) $row->chunk_title,
                            'section_path' => (string) $row->section_path,
                            'chunk_strategy' => (string) $row->chunk_strategy,
                            'metadata_json' => $row->metadata_json,
                            'source_hash' => (string) $row->source_hash,
                            'token_count' => (int) $row->token_count,
                            'embedding_json' => $row->embedding_json,
                            'embedding_model_id' => $row->embedding_model_id,
                            'embedding_dimensions' => (int) $row->embedding_dimensions,
                            'embedding_provider' => (string) $row->embedding_provider,
                            'embedding_fingerprint' => (string) ($row->embedding_fingerprint ?? ''),
                            'embedding_profile_version' => $row->embedding_profile_version,
                            'embedding_profile_digest' => $row->embedding_profile_digest,
                            'embedding_config_revision' => $row->embedding_config_revision,
                            'embedding_vector' => $row->embedding_vector,
                            'created_at' => $row->created_at,
                            'updated_at' => $row->updated_at,
                        ];
                    }

                    if ($inserts !== []) {
                        KnowledgeChunk::query()->insert($inserts);
                    }
                });

            $manifestHash = $this->stagedManifestHash($knowledgeBaseId, $syncToken);
            $servingSourceHash = (string) $knowledgeBase->chunk_source_hash;
            $knowledgeBase->forceFill([
                'chunk_sync_status' => 'ready',
                'chunk_sync_token' => null,
                'chunk_serving_generation' => $syncToken,
                'chunk_serving_source_hash' => $servingSourceHash,
                'chunk_manifest_hash' => $manifestHash,
                'chunk_embedding_fingerprint' => $embeddingProfile?->embedding_fingerprint ?: null,
                'chunk_embedding_dimensions' => $embeddingProfile?->embedding_dimensions ?: null,
                'chunk_embedding_provider' => $embeddingProfile?->embedding_provider ?: null,
                'chunk_embedding_model_id' => $embeddingProfile?->embedding_model_id ?: null,
                'chunk_embedding_profile_version' => $embeddingProfile?->embedding_profile_version ?: null,
                'chunk_embedding_profile_digest' => $embeddingProfile?->embedding_profile_digest ?: null,
                'chunk_sync_embedding_profile_version' => null,
                'chunk_sync_embedding_model_id' => null,
                'chunk_sync_embedding_config_revision' => null,
                'chunk_sync_error' => null,
                'chunk_sync_require_real_embedding' => false,
                'chunk_synced_at' => now(),
            ])->save();
            KnowledgeChunk::query()
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->where(function ($query) use ($syncToken): void {
                    $query->whereNull('generation_key')
                        ->orWhere('generation_key', '!=', $syncToken);
                })
                ->delete();
            DB::table('knowledge_chunk_sync_rows')
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->delete();

            return true;
        });

        if ($finalized) {
            $sourceHash = (string) KnowledgeBase::query()->whereKey($knowledgeBaseId)->value('chunk_source_hash');
            ReconcileKnowledgeFactEvidenceJob::dispatch($knowledgeBaseId, $sourceHash)
                ->onQueue('knowledge')
                ->afterCommit();
            $this->qualityInvalidationService->invalidateKnowledgeBase(
                $knowledgeBaseId,
                '知识库切片与证据索引已更新',
                ['chunk', 'atomic'],
                'chunk_generation_changed',
            );
        }

        return $finalized;
    }

    private function stagedManifestHash(int $knowledgeBaseId, string $syncToken): string
    {
        $hashContext = hash_init('sha256');
        DB::table('knowledge_chunk_sync_rows')
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->where('sync_token', $syncToken)
            ->orderBy('chunk_index')
            ->orderBy('id')
            ->cursor()
            ->each(function (object $row) use ($hashContext): void {
                hash_update($hashContext, json_encode([
                    'chunk_index' => (int) $row->chunk_index,
                    'content_hash' => (string) $row->content_hash,
                    'source_hash' => (string) $row->source_hash,
                    'chunk_title' => (string) $row->chunk_title,
                    'section_path' => (string) $row->section_path,
                    'chunk_strategy' => (string) $row->chunk_strategy,
                    'embedding_model_id' => (int) ($row->embedding_model_id ?? 0),
                    'embedding_provider' => (string) ($row->embedding_provider ?? ''),
                    'embedding_fingerprint' => (string) ($row->embedding_fingerprint ?? ''),
                    'embedding_profile_version' => (int) ($row->embedding_profile_version ?? 0),
                    'embedding_profile_digest' => (string) ($row->embedding_profile_digest ?? ''),
                    'embedding_config_revision' => (string) ($row->embedding_config_revision ?? ''),
                    'embedding_hash' => hash('sha256', (string) ($row->embedding_json ?? '')),
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
            });

        return hash_final($hashContext);
    }

    /**
     * @param  mixed  $stagedQuery
     * @param  array{model_id:int,model_name:string,provider:string,config_revision:string,api_url:string,api_key:string,driver:string}|null  $currentMetadata
     */
    private function assertSingleStagedEmbeddingProfile($stagedQuery, ?array $currentMetadata): ?object
    {
        $profile = null;
        $hasFallback = false;
        foreach ((clone $stagedQuery)->orderBy('id')->cursor([
            'embedding_model_id',
            'embedding_dimensions',
            'embedding_provider',
            'embedding_fingerprint',
            'embedding_profile_version',
            'embedding_profile_digest',
            'embedding_config_revision',
        ]) as $row) {
            $isReal = (int) ($row->embedding_model_id ?? 0) > 0;
            if (! $isReal) {
                $hasFallback = true;
                if ((int) ($row->embedding_dimensions ?? 0) !== 0
                    || trim((string) ($row->embedding_provider ?? '')) !== ''
                    || trim((string) ($row->embedding_fingerprint ?? '')) !== ''
                    || (int) ($row->embedding_profile_version ?? 0) !== 0
                    || trim((string) ($row->embedding_profile_digest ?? '')) !== ''
                    || trim((string) ($row->embedding_config_revision ?? '')) !== '') {
                    $this->throwMixedEmbeddingProfile();
                }

                continue;
            }

            if ($hasFallback
                || (int) $row->embedding_dimensions <= 0
                || (int) $row->embedding_profile_version !== $this->embeddingFingerprint->profileVersion()
                || trim((string) $row->embedding_profile_digest) === ''
                || ! hash_equals((string) $row->embedding_fingerprint, (string) $row->embedding_profile_digest)) {
                $this->throwMixedEmbeddingProfile();
            }
            if ($profile === null) {
                $profile = $row;

                continue;
            }
            foreach ([
                'embedding_model_id',
                'embedding_dimensions',
                'embedding_provider',
                'embedding_fingerprint',
                'embedding_profile_version',
                'embedding_profile_digest',
                'embedding_config_revision',
            ] as $field) {
                if ((string) $profile->{$field} !== (string) $row->{$field}) {
                    $this->throwMixedEmbeddingProfile();
                }
            }
        }

        if ($profile === null) {
            if ($currentMetadata !== null) {
                return null;
            }

            return null;
        }
        if ($hasFallback || $currentMetadata === null
            || (int) $profile->embedding_model_id !== (int) $currentMetadata['model_id']
            || ! hash_equals((string) $profile->embedding_provider, (string) $currentMetadata['provider'])
            || ! hash_equals((string) $profile->embedding_config_revision, (string) $currentMetadata['config_revision'])) {
            $this->throwMixedEmbeddingProfile();
        }

        $expectedDigest = $this->embeddingFingerprint->forRuntimeProfile(
            (string) $currentMetadata['api_url'],
            (string) $currentMetadata['model_name'],
            (int) $profile->embedding_dimensions,
        );
        if ($expectedDigest === '' || ! hash_equals($expectedDigest, (string) $profile->embedding_profile_digest)) {
            $this->throwMixedEmbeddingProfile();
        }

        return $profile;
    }

    private function throwMixedEmbeddingProfile(): never
    {
        throw PermanentAiProviderException::fromProviderFailure(
            new \RuntimeException('knowledge_embedding_profile_mixed'),
        );
    }

    public function discardStagingSync(int $knowledgeBaseId, string $syncToken): void
    {
        DB::table('knowledge_chunk_sync_rows')
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->where('sync_token', $syncToken)
            ->delete();
    }

    private function resetStagingEmbeddingsToFallback(int $knowledgeBaseId, string $syncToken): void
    {
        DB::table('knowledge_chunk_sync_rows')
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->where('sync_token', $syncToken)
            ->orderBy('id')
            ->chunkById(50, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('knowledge_chunk_sync_rows')
                        ->where('id', (int) $row->id)
                        ->update([
                            'embedding_json' => json_encode(
                                $this->buildFallbackVector((string) $row->content, 256),
                                JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
                            ) ?: '[]',
                            'embedding_model_id' => null,
                            'embedding_dimensions' => 0,
                            'embedding_provider' => '',
                            'embedding_fingerprint' => '',
                            'embedding_profile_version' => null,
                            'embedding_profile_digest' => null,
                            'embedding_config_revision' => null,
                            'embedding_vector' => null,
                            'updated_at' => now(),
                        ]);
                }
            }, 'id');
    }

    /**
     * @return array<string,mixed>
     */
    private function resolveKnowledgeBaseMetadata(int $knowledgeBaseId): array
    {
        /** @var KnowledgeBase|null $knowledgeBase */
        $knowledgeBase = KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->first($this->knowledgeBaseMetadataSelectColumns());

        if (! $knowledgeBase) {
            return [];
        }

        return array_filter([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'knowledge_base_name' => (string) $knowledgeBase->name,
            'knowledge_base_description' => trim((string) ($knowledgeBase->description ?? '')),
            'file_type' => (string) ($knowledgeBase->file_type ?? 'markdown'),
            'source_name' => trim((string) ($knowledgeBase->source_name ?? '')),
            'source_url' => trim((string) ($knowledgeBase->source_url ?? '')),
            'source_type' => trim((string) ($knowledgeBase->source_type ?? 'document')),
            'business_line' => trim((string) ($knowledgeBase->business_line ?? '')),
            'effective_date' => $knowledgeBase->effective_date?->toDateString(),
            'risk_level' => trim((string) ($knowledgeBase->risk_level ?? 'medium')),
            'review_status' => trim((string) ($knowledgeBase->review_status ?? 'unreviewed')),
        ], static fn ($value): bool => $value !== null && $value !== '');
    }

    /**
     * @return list<string>
     */
    private function knowledgeBaseMetadataSelectColumns(): array
    {
        $columns = ['id', 'name', 'description', 'file_type'];
        foreach (['source_name', 'source_url', 'source_type', 'business_line', 'effective_date', 'risk_level', 'review_status'] as $column) {
            if (Schema::hasColumn('knowledge_bases', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @param  array<string,mixed>  $chunkMetadata
     * @param  array<string,mixed>  $knowledgeMetadata
     * @return array<string,mixed>
     */
    private function mergeChunkMetadata(array $chunkMetadata, array $knowledgeMetadata): array
    {
        return array_replace($knowledgeMetadata, $chunkMetadata);
    }

    /**
     * 构建知识库切片：默认使用结构化规则切片；配置语义模型时仅让 LLM 规划 block 边界，
     * 最终 chunk 文本仍由本地原文重组，避免模型改写知识内容。
     *
     * @return list<array{content:string,title:string,section_path:string,strategy:string,metadata:array<string,mixed>}>
     */
    private function planChunks(
        int $knowledgeBaseId,
        string $content,
        SystemAiIdentity $identity,
        string $syncToken,
        string $executionToken,
        int $executionAttempt,
        int $dispatchOrdinal,
        ?KnowledgeIndexAiUsageSession &$usageSession,
        ?string &$semanticStrategy,
    ): array {
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

        $semanticStrategy = $strategy;
        $semanticChunks = $this->buildSemanticChunks(
            $knowledgeBaseId,
            $blocks,
            hash('sha256', $content),
            $identity,
            $syncToken,
            $executionToken,
            $executionAttempt,
            $dispatchOrdinal,
            $usageSession,
        );

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

        $expectedRuleChunks = max(
            1,
            (int) ceil(mb_strlen($normalized, 'UTF-8') / $this->chunkMaxChars())
        );
        $structuredLineBudget = min(
            self::MAX_STRUCTURED_LINES,
            max(
                self::MIN_STRUCTURED_LINE_BUDGET,
                $expectedRuleChunks * self::STRUCTURED_LINE_AMPLIFICATION,
            ),
        );
        if ((substr_count($normalized, "\n") + 1) > $structuredLineBudget) {
            $parts = $this->splitTextByCharacters($normalized, $this->chunkMaxChars());

            return array_map(
                static fn (string $text, int $index): array => [
                    'index' => $index,
                    'type' => 'paragraph',
                    'text' => $text,
                    'section_path' => '',
                    'heading_level' => null,
                    'heading_text' => null,
                    'skip_semantic_planning' => true,
                ],
                $parts,
                array_keys($parts),
            );
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
        return array_values(array_filter(
            array_map(
                static fn (string $part): string => trim($part),
                mb_str_split($text, $maxChars, 'UTF-8')
            ),
            static fn (string $part): bool => $part !== ''
        ));
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
    private function buildSemanticChunks(
        int $knowledgeBaseId,
        array $blocks,
        string $expectedSourceHash,
        SystemAiIdentity $identity,
        string $syncToken,
        string $executionToken,
        int $executionAttempt,
        int $dispatchOrdinal,
        ?KnowledgeIndexAiUsageSession &$usageSession,
    ): array {
        $models = $this->resolveSemanticChunkingModels($identity);
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

            $session = null;
            $lock = null;
            $reservation = null;
            $providerAttempt = null;
            $providerReturned = false;
            try {
                $lock = $this->invocationLocks->acquireForInvocation(
                    (int) $model->getKey(),
                    MarkdownContentWriterAgent::PROVIDER_TIMEOUT_SECONDS + 60,
                );
                $currentModel = $this->systemModelAccessResolver->assertSemanticChunkingCurrent($identity, $model);
                $this->assertKnowledgeSyncSourceCurrent(
                    $knowledgeBaseId,
                    $syncToken,
                    $expectedSourceHash,
                );
                $currentProviderUrl = OpenAiRuntimeProvider::resolveChatBaseUrl((string) ($currentModel->api_url ?? ''));
                $currentApiKey = $this->decryptApiKey((string) ($currentModel->getRawOriginal('api_key') ?? ''));
                $currentModelId = trim((string) ($currentModel->model_id ?? ''));
                if ($currentProviderUrl === '' || $currentApiKey === '' || $currentModelId === '') {
                    $this->invocationLocks->release($lock);

                    continue;
                }
                $reservation = $this->usageQuota->reserveModel($currentModel);
                if ($reservation === null) {
                    $this->invocationLocks->release($lock);

                    continue;
                }
                $currentModel = $this->systemModelAccessResolver->assertSemanticChunkingCurrent($identity, $currentModel);
                $this->assertKnowledgeSyncSourceCurrent($knowledgeBaseId, $syncToken, $expectedSourceHash);
                $session = new KnowledgeIndexAiUsageSession(
                    modelId: (int) $currentModel->getKey(),
                    ownerAdminId: (int) $currentModel->owner_admin_id,
                    configurationRevision: $this->embeddingFingerprint->configurationRevision($currentModel),
                    quota: $this->usageQuota,
                    invocationLocks: $this->invocationLocks,
                    lock: $lock,
                );
                $driver = OpenAiRuntimeProvider::resolveChatDriver($currentProviderUrl, $currentModelId);
                $providerName = OpenAiRuntimeProvider::registerProvider('knowledge_chunking', $driver, $currentProviderUrl, $currentApiKey);
                $agent = new MarkdownContentWriterAgent($this->semanticChunkingSystemPrompt());
                $prompt = $this->semanticChunkingUserPrompt($knowledgeBaseId, $blocks);
                $providerAttempt = $session->begin(
                    $this->usageAttempts->beginForSystem(
                        model: $currentModel,
                        identity: $identity,
                        requestId: $syncToken,
                        requestPayload: $prompt,
                        callKey: $this->knowledgeIndexCallKey(
                            'semantic',
                            $executionToken,
                            $executionAttempt,
                            $dispatchOrdinal,
                            1,
                        ),
                        operation: 'knowledge.semantic_chunking',
                        businessSource: 'knowledge_index',
                        sourceType: KnowledgeBase::class,
                        sourceId: $knowledgeBaseId,
                    ),
                    $reservation,
                );
                $response = $agent->prompt(
                    $prompt,
                    [],
                    $providerName,
                    $currentModelId
                );
                $providerReturned = true;
                $session->providerReturned($providerAttempt, $response->usage ?? null);
                $this->systemModelAccessResolver->assertSemanticChunkingCurrent($identity, $model);
                $this->assertKnowledgeSyncSourceCurrent($knowledgeBaseId, $syncToken, $expectedSourceHash);
                $content = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
                $plan = $this->decodeSemanticChunkPlan($content);
                $chunks = $this->chunksFromSemanticPlan($blocks, $plan);
                if ($chunks === []) {
                    $session->providerDiscarded($providerAttempt, 'knowledge_semantic_plan_invalid');
                    $session->discarded('knowledge_semantic_plan_invalid');
                    Log::info('geoflow.knowledge_semantic_chunking_invalid_response', [
                        'knowledge_base_id' => $knowledgeBaseId,
                        'semantic_model_id' => (int) $model->id,
                        'model_identifier' => $modelId,
                        'plan_count' => count($plan),
                    ]);

                    continue;
                }

                $usageSession = $session;

                return $chunks;
            } catch (AiModelAccessException $exception) {
                if ($providerAttempt === null && $reservation !== null) {
                    $this->usageQuota->releaseModel($reservation);
                }
                $session?->revoked($exception->getErrorCode());
                if (! $session instanceof KnowledgeIndexAiUsageSession) {
                    $this->invocationLocks->release($lock);
                }

                throw $exception;
            } catch (Throwable $exception) {
                if ($providerAttempt === null && $reservation !== null) {
                    $this->usageQuota->releaseModel($reservation);
                }
                if ($session instanceof KnowledgeIndexAiUsageSession) {
                    if ($providerAttempt === null) {
                        $session->discarded('knowledge_semantic_preflight_failed');
                    } elseif ($providerReturned) {
                        $session->providerDiscarded($providerAttempt, 'knowledge_semantic_result_invalid');
                    } else {
                        $session->providerFailed($providerAttempt, 'knowledge_semantic_provider_failed');
                    }
                    $session->discarded('knowledge_semantic_provider_failed');
                } else {
                    $this->invocationLocks->release($lock);
                }
                Log::info('geoflow.knowledge_semantic_chunking_failed', [
                    'knowledge_base_id' => $knowledgeBaseId,
                    'semantic_model_id' => (int) $model->id,
                    'model_identifier' => $modelId,
                    'message' => $this->errorSanitizer->sanitize($exception, 'semantic_chunking_failed'),
                ]);
            }
        }

        return [];
    }

    private function normalizeKnowledgeIndexExecutionToken(?string $executionToken): string
    {
        $executionToken = trim((string) $executionToken);

        return Str::isUuid($executionToken) || Str::isUlid($executionToken)
            ? $executionToken
            : $this->usageAttempts->requestId();
    }

    private function knowledgeIndexCallKey(
        string $phase,
        string $executionToken,
        int $executionAttempt,
        int $dispatchOrdinal,
        int $providerOrdinal,
    ): string {
        return sprintf(
            '%s.e-%s.d-%d.a-%d.p-%d',
            $phase,
            $executionToken,
            max(1, $dispatchOrdinal),
            max(1, $executionAttempt),
            max(1, $providerOrdinal),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $blocks
     */
    private function canAttemptSemanticChunking(array $blocks): bool
    {
        foreach ($blocks as $block) {
            if (($block['skip_semantic_planning'] ?? false) === true) {
                return false;
            }
        }

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
    private function resolveSemanticChunkingModels(SystemAiIdentity $identity): array
    {
        $model = $this->systemModelAccessResolver->resolveSemanticChunking($identity);

        return $model instanceof AiModel ? [$model] : [];
    }

    private function semanticChunkingMaxPromptChars(): int
    {
        return max(1, (int) config('geoflow.semantic_chunking_max_chars', self::SEMANTIC_CHUNKING_MAX_PROMPT_CHARS));
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

    public function generateCompatibleQueryEmbedding(
        string $query,
        KnowledgeBase $knowledgeBase,
        Admin|AiExecutionContext|null $identity,
        ?string $requestId = null,
        int $queryOrdinal = 1,
    ): KnowledgeQueryEmbeddingResult {
        $query = trim($query);
        if ($query === '') {
            return KnowledgeQueryEmbeddingResult::incompatible('empty_query');
        }
        if ($identity === null) {
            return KnowledgeQueryEmbeddingResult::incompatible('missing_execution_identity');
        }

        $fingerprint = trim((string) $knowledgeBase->chunk_embedding_fingerprint);
        $profileDigest = trim((string) $knowledgeBase->chunk_embedding_profile_digest);
        $profileVersion = (int) $knowledgeBase->chunk_embedding_profile_version;
        $provider = $this->embeddingFingerprint->normalizeProvider(
            (string) $knowledgeBase->chunk_embedding_provider,
        );
        $dimensions = (int) $knowledgeBase->chunk_embedding_dimensions;
        if ($profileVersion !== $this->embeddingFingerprint->profileVersion()) {
            return KnowledgeQueryEmbeddingResult::incompatible('index_embedding_profile_incompatible');
        }
        if ($fingerprint === '' || $profileDigest === '' || $provider === '' || $dimensions <= 0
            || ! hash_equals($fingerprint, $profileDigest)) {
            return KnowledgeQueryEmbeddingResult::incompatible('index_embedding_profile_missing');
        }

        [$admin, $accessVersion, $adminRole] = $this->currentQueryAdmin($identity);
        if (! is_string($requestId) || (! Str::isUuid($requestId) && ! Str::isUlid($requestId))) {
            $requestId = $this->usageAttempts->requestId();
        }
        try {
            $candidates = $identity instanceof AiExecutionContext
                ? $this->executionAccessGuard->resolveModelCandidates($identity, 'embedding')
                : $this->adminModelAccessResolver->resolveCandidates($admin, 'embedding')->all();
        } catch (AiModelAccessException $exception) {
            if ($exception->getErrorCode() !== AiModelAccessException::AI_MODEL_UNAVAILABLE) {
                throw $exception;
            }

            return KnowledgeQueryEmbeddingResult::incompatible('no_accessible_embedding_model');
        }

        $compatibleCandidates = array_values(array_filter(
            $candidates,
            fn (AiModel $model): bool => hash_equals($fingerprint, $this->embeddingFingerprint->forModel($model, $dimensions))
                && hash_equals($provider, $this->embeddingFingerprint->provider($model)),
        ));
        if ($compatibleCandidates === []) {
            return KnowledgeQueryEmbeddingResult::incompatible('no_compatible_embedding_model');
        }

        foreach ($compatibleCandidates as $candidateIndex => $model) {
            $currentModel = $this->assertQueryModelCurrent($identity, $admin, $accessVersion, $adminRole, $model);
            $metadata = $this->modelToEmbeddingMetadata($currentModel);
            if ($metadata === null) {
                return KnowledgeQueryEmbeddingResult::incompatible('embedding_model_configuration_invalid');
            }
            $configurationRevision = $this->embeddingFingerprint->configurationRevision($currentModel);

            $reservation = $this->usageQuota->reserveModel($currentModel);
            if ($reservation === null) {
                continue;
            }

            $usageAttempt = null;
            $providerResult = null;
            try {
                $providerName = OpenAiRuntimeProvider::registerProvider(
                    'embedding_query',
                    $metadata['driver'],
                    $metadata['api_url'],
                    $metadata['api_key'],
                );
                $usageAttempt = $this->usageAttempts->beginForAdmin(
                    model: $currentModel,
                    executionAdminId: (int) $admin->getKey(),
                    accessVersion: $accessVersion,
                    executionScope: $identity instanceof AiExecutionContext
                        ? AiModelUsageEvent::EXECUTION_SCOPE_PERSISTED_ADMIN
                        : AiModelUsageEvent::EXECUTION_SCOPE_INTERACTIVE_ADMIN,
                    modelSource: $this->usageAttempts->sourceFor($currentModel, (int) $admin->getKey()),
                    requestId: $requestId,
                    requestPayload: $query,
                    callKey: sprintf(
                        'query-%d.kb-%d.candidate-%d.provider-1',
                        max(1, $queryOrdinal),
                        (int) $knowledgeBase->getKey(),
                        $candidateIndex + 1,
                    ),
                    operation: 'knowledge.query_embedding',
                    businessSource: 'knowledge_retrieval',
                    sourceType: KnowledgeBase::class,
                    sourceId: (int) $knowledgeBase->getKey(),
                );
                $providerResult = $this->requestEmbeddingVectors(
                    [$this->formatEmbeddingQueryInput($query, $metadata)],
                    $metadata,
                    $providerName,
                );
                $postCallModel = $this->assertQueryModelCurrent(
                    $identity,
                    $admin,
                    $accessVersion,
                    $adminRole,
                    $currentModel,
                );
                if (! hash_equals(
                    $configurationRevision,
                    $this->embeddingFingerprint->configurationRevision($postCallModel),
                )) {
                    $usageAttempt->discarded('embedding_model_configuration_changed', $providerResult->usage);
                    $this->usageQuota->releaseModel($reservation);

                    return KnowledgeQueryEmbeddingResult::incompatible('embedding_model_configuration_changed');
                }
                $rawVector = $this->normalizeEmbeddingVector($providerResult->embeddings[0] ?? null);
                if ($rawVector === null) {
                    $usageAttempt->discarded('embedding_response_invalid', $providerResult->usage);
                    $this->usageQuota->releaseModel($reservation);

                    return KnowledgeQueryEmbeddingResult::incompatible('embedding_response_invalid');
                }
                if (count($rawVector) !== $dimensions) {
                    $usageAttempt->discarded('embedding_dimensions_mismatch', $providerResult->usage);
                    $this->usageQuota->releaseModel($reservation);

                    return KnowledgeQueryEmbeddingResult::incompatible('embedding_dimensions_mismatch');
                }

                $this->usageQuota->recordModelSuccess($reservation);
                $usageAttempt->succeeded($providerResult->usage);

                return KnowledgeQueryEmbeddingResult::success(
                    $rawVector,
                    (int) $currentModel->getKey(),
                    (int) $currentModel->owner_admin_id === (int) $admin->getKey() ? 'personal' : 'shared',
                );
            } catch (AiModelAccessException $exception) {
                $usageAttempt?->revoked($exception->getErrorCode(), $providerResult?->usage);
                $this->usageQuota->releaseModel($reservation);

                throw $exception;
            } catch (Throwable $exception) {
                if ($usageAttempt instanceof AiModelUsageAttempt) {
                    $providerResult instanceof KnowledgeEmbeddingProviderResult
                        ? $usageAttempt->discarded('embedding_result_processing_failed', $providerResult->usage)
                        : $usageAttempt->failed('embedding_provider_failed');
                }
                $this->usageQuota->releaseModel($reservation);
                Log::info('geoflow.knowledge_query_embedding_failed', [
                    'embedding_model_id' => (int) $currentModel->getKey(),
                    'message' => $this->errorSanitizer->sanitize($exception, 'embedding_provider_failed'),
                ]);
                if (! $this->failoverDecider->shouldFailover($exception)) {
                    break;
                }
            } finally {
                $usageAttempt?->discarded('embedding_result_not_delivered', $providerResult?->usage);
            }
        }

        return KnowledgeQueryEmbeddingResult::incompatible('compatible_embedding_unavailable');
    }

    /** @param list<float> $vector */
    public function queryVectorLiteral(array $vector): string
    {
        if ($vector === [] || ! $this->canStoreEmbeddingVector()) {
            return '';
        }

        return $this->vectorLiteral($this->padVector($vector, $this->embeddingStorageDimensions()));
    }

    /** @return array{0:Admin,1:int,2:string} */
    private function currentQueryAdmin(Admin|AiExecutionContext $identity): array
    {
        if ($identity instanceof AiExecutionContext) {
            $admin = $this->executionAccessGuard->assertCurrent($identity);

            return [$admin, $identity->aiConfigAccessVersion, $identity->modelAccessAdminRole];
        }

        $admin = Admin::query()->whereKey($identity->getKey())->active()->first();
        if (! $admin instanceof Admin) {
            throw AiModelAccessException::executionAdminInactive($identity);
        }

        return [
            $admin,
            max(1, (int) $admin->ai_config_access_version),
            $admin->isSuperAdmin() ? 'super_admin' : 'admin',
        ];
    }

    private function assertQueryModelCurrent(
        Admin|AiExecutionContext $identity,
        Admin $admin,
        int $accessVersion,
        string $adminRole,
        AiModel $model,
    ): AiModel {
        if ($identity instanceof AiExecutionContext) {
            return $this->executionAccessGuard->assertModelCurrent($identity, $model);
        }

        $current = Admin::query()->whereKey($admin->getKey())->active()->first();
        if (! $current instanceof Admin
            || max(1, (int) $current->ai_config_access_version) !== $accessVersion
            || ($current->isSuperAdmin() ? 'super_admin' : 'admin') !== $adminRole) {
            throw AiModelAccessException::configAccessRevoked($admin);
        }

        return $this->adminModelAccessResolver->assertUsable($current, $model);
    }

    /** @return array{model_id:int,model_name:string,provider:string,config_revision:string,api_url:string,api_key:string,driver:string}|null */
    private function resolveSystemEmbeddingMetadata(SystemAiIdentity $identity): ?array
    {
        $model = $this->systemModelAccessResolver->resolveEmbedding($identity);

        return $model instanceof AiModel ? $this->modelToEmbeddingMetadata($model) : null;
    }

    /** @return array<string,int|string|null> */
    private function systemEmbeddingPipelineFields(SystemAiIdentity $identity): array
    {
        $model = $this->systemModelAccessResolver->resolveEmbedding($identity);

        return [
            'chunk_sync_embedding_profile_version' => $this->embeddingFingerprint->profileVersion(),
            'chunk_sync_embedding_model_id' => $model?->getKey(),
            'chunk_sync_embedding_config_revision' => $model instanceof AiModel
                ? $this->embeddingFingerprint->configurationRevision($model)
                : $this->embeddingFingerprint->emptyConfigurationRevision(),
        ];
    }

    private function ensurePipelineProfile(
        int $knowledgeBaseId,
        string $syncToken,
        SystemAiIdentity $identity,
    ): void {
        $currentVersion = KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->where('chunk_sync_token', $syncToken)
            ->value('chunk_sync_embedding_profile_version');
        if ((int) $currentVersion === $this->embeddingFingerprint->profileVersion()) {
            return;
        }

        KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->where('chunk_sync_token', $syncToken)
            ->whereNull('chunk_sync_embedding_profile_version')
            ->update($this->systemEmbeddingPipelineFields($identity));
    }

    /**
     * @return array{model_id:int,model_name:string,provider:string,config_revision:string,api_url:string,api_key:string,driver:string}|null
     */
    private function resolveFrozenSystemEmbeddingMetadata(
        int $knowledgeBaseId,
        string $syncToken,
        SystemAiIdentity $identity,
    ): ?array {
        return $this->assertFrozenSystemEmbeddingPipelineCurrent(
            $knowledgeBaseId,
            $syncToken,
            $identity,
        );
    }

    /**
     * @return array{model_id:int,model_name:string,provider:string,config_revision:string,api_url:string,api_key:string,driver:string}|null
     */
    private function assertFrozenSystemEmbeddingPipelineCurrent(
        int $knowledgeBaseId,
        string $syncToken,
        SystemAiIdentity $identity,
        bool $lock = false,
    ): ?array {
        $query = KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->where('chunk_sync_token', $syncToken)
            ->whereIn('chunk_sync_status', ['pending', 'processing']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $knowledgeBase = $query->first([
            'id',
            'content',
            'chunk_source_hash',
            'chunk_sync_embedding_profile_version',
            'chunk_sync_embedding_model_id',
            'chunk_sync_embedding_config_revision',
        ]);
        if (! $knowledgeBase || ! $this->knowledgeBaseMatchesSyncSource($knowledgeBase)) {
            throw new \RuntimeException('knowledge_sync_source_stale');
        }

        if ((int) $knowledgeBase->chunk_sync_embedding_profile_version !== $this->embeddingFingerprint->profileVersion()
            || trim((string) $knowledgeBase->chunk_sync_embedding_config_revision) === '') {
            throw PermanentAiProviderException::fromProviderFailure(
                new \RuntimeException('knowledge_embedding_pipeline_profile_missing'),
            );
        }

        $modelId = (int) $knowledgeBase->chunk_sync_embedding_model_id;
        if ($modelId <= 0) {
            if ($this->systemModelAccessResolver->resolveEmbedding($identity) instanceof AiModel
                || ! hash_equals(
                    $this->embeddingFingerprint->emptyConfigurationRevision(),
                    (string) $knowledgeBase->chunk_sync_embedding_config_revision,
                )) {
                throw PermanentAiProviderException::fromProviderFailure(
                    new \RuntimeException('knowledge_embedding_pipeline_revision_changed'),
                );
            }

            return null;
        }

        $snapshot = new AiModel;
        $snapshot->setAttribute($snapshot->getKeyName(), $modelId);
        $model = $this->systemModelAccessResolver->assertEmbeddingCurrent($identity, $snapshot);
        $currentRevision = $this->embeddingFingerprint->configurationRevision($model);
        if (! hash_equals((string) $knowledgeBase->chunk_sync_embedding_config_revision, $currentRevision)) {
            throw PermanentAiProviderException::fromProviderFailure(
                new \RuntimeException('knowledge_embedding_pipeline_revision_changed'),
            );
        }

        return $this->modelToEmbeddingMetadata($model);
    }

    /**
     * @return array{model_id:int,model_name:string,provider:string,config_revision:string,api_url:string,api_key:string,driver:string}|null
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
            'provider' => $this->embeddingFingerprint->provider($model),
            'config_revision' => $this->embeddingFingerprint->configurationRevision($model),
            'api_url' => $providerUrl,
            'api_key' => $apiKey,
            'driver' => OpenAiRuntimeProvider::resolveEmbeddingDriver($providerUrl, $modelName),
        ];
    }

    /**
     * 批量生成真实向量；任一异常则整体回退到 fallback 向量。
     *
     * @param  list<string>  $chunks
     * @param  array{model_id:int,model_name:string,provider:string,config_revision:string,api_url:string,api_key:string,driver:string}|null  $embeddingMetadata
     * @return array<int, array{model_id:int,dimensions:int,provider:string,fingerprint:string,profile_version:int,profile_digest:string,config_revision:string,vector:list<float>,vector_literal:?string}>
     */
    private function generateEmbeddingsForChunks(
        array $chunks,
        ?array $embeddingMetadata,
        SystemAiIdentity $identity,
        bool $requireRealEmbedding = false,
        ?string $documentTitle = null,
        ?int $knowledgeBaseId = null,
        ?string $syncToken = null,
        ?string $executionToken = null,
        int $executionAttempt = 1,
        int $dispatchOrdinal = 1,
        ?KnowledgeIndexAiUsageSession &$usageSession = null,
    ): array {
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
        $session = null;
        try {
            $modelId = (int) ($embeddingMetadata['model_id'] ?? 0);
            $lock = $this->invocationLocks->acquireForInvocation($modelId, 240);
            try {
                $snapshot = new AiModel;
                $snapshot->setAttribute($snapshot->getKeyName(), $modelId);
                $currentModel = $this->systemModelAccessResolver->assertEmbeddingCurrent($identity, $snapshot);
                if (! hash_equals(
                    (string) ($embeddingMetadata['config_revision'] ?? ''),
                    $this->embeddingFingerprint->configurationRevision($currentModel),
                )) {
                    throw AiModelAccessException::configAccessRevokedForAdminId((int) $currentModel->owner_admin_id);
                }
                $session = new KnowledgeIndexAiUsageSession(
                    modelId: $modelId,
                    ownerAdminId: (int) $currentModel->owner_admin_id,
                    configurationRevision: (string) $embeddingMetadata['config_revision'],
                    quota: $this->usageQuota,
                    invocationLocks: $this->invocationLocks,
                    lock: $lock,
                );
            } catch (Throwable $exception) {
                $this->invocationLocks->release($lock);

                throw $exception;
            }
            $results = [];
            $pendingChunks = $chunks;
            $batchSize = $this->embeddingBatchSize();
            $providerOrdinal = 0;
            $currentMetadata = $embeddingMetadata;
            while ($pendingChunks !== []) {
                $batch = array_slice($pendingChunks, 0, $batchSize, true);

                try {
                    if ($knowledgeBaseId !== null && $syncToken !== null) {
                        $this->assertKnowledgeSyncSourceCurrent($knowledgeBaseId, $syncToken);
                        try {
                            $currentMetadata = $this->resolveFrozenSystemEmbeddingMetadata(
                                $knowledgeBaseId,
                                $syncToken,
                                $identity,
                            );
                        } catch (AiModelAccessException|PermanentAiProviderException $exception) {
                            $errorCode = $exception instanceof AiModelAccessException
                                ? $exception->getErrorCode()
                                : 'knowledge_embedding_configuration_revoked';
                            $session->revoked($errorCode);

                            throw $exception;
                        }
                    } else {
                        $currentMetadata = $embeddingMetadata;
                    }
                    if ($currentMetadata === null) {
                        throw PermanentAiProviderException::fromProviderFailure(
                            new \RuntimeException('knowledge_embedding_model_unavailable'),
                        );
                    }
                    $providerName = OpenAiRuntimeProvider::registerProvider(
                        'embedding',
                        (string) ($currentMetadata['driver'] ?? 'openai'),
                        (string) $currentMetadata['api_url'],
                        (string) $currentMetadata['api_key'],
                    );
                    foreach ($this->generateEmbeddingBatch(
                        $batch,
                        $currentMetadata,
                        $providerName,
                        $canStoreEmbeddingVector,
                        $identity,
                        $session,
                        $syncToken ?? $this->usageAttempts->requestId(),
                        $executionToken ?? $this->usageAttempts->requestId(),
                        $executionAttempt,
                        $dispatchOrdinal,
                        ++$providerOrdinal,
                        $documentTitle,
                        $knowledgeBaseId,
                        $syncToken,
                    ) as $chunkIndex => $embeddingResult) {
                        $results[$chunkIndex] = $embeddingResult;
                    }

                    foreach (array_keys($batch) as $chunkIndex) {
                        unset($pendingChunks[$chunkIndex]);
                    }
                } catch (Throwable $batchException) {
                    if ($batchException instanceof AiModelAccessException
                        || $batchException instanceof PermanentAiProviderException
                        || $this->failoverDecider->isPermanentProviderFailure($batchException)) {
                        if ($batchException instanceof AiModelAccessException
                            || $batchException instanceof PermanentAiProviderException) {
                            throw $batchException;
                        }

                        throw PermanentAiProviderException::fromProviderFailure($batchException);
                    }
                    $message = $this->errorSanitizer->sanitize(
                        OpenAiRuntimeProvider::normalizeApiException(
                            $batchException,
                            (string) ($currentMetadata['api_url'] ?? ''),
                        ),
                        'embedding_batch_failed',
                    );
                    if ($batchSize > 1 && count($batch) > 1 && $this->isEmbeddingBatchSizeError($message)) {
                        Log::info('geoflow.knowledge_embedding_batch_fallback', [
                            'embedding_model_id' => (int) ($currentMetadata['model_id'] ?? 0),
                            'model_identifier' => (string) ($currentMetadata['model_name'] ?? ''),
                            'batch_size' => count($batch),
                            'message' => $message,
                        ]);

                        $batchSize = 1;

                        continue;
                    }

                    throw $batchException;
                }
            }

            if (count($results) !== count($chunks)) {
                $session->discarded('knowledge_embedding_result_incomplete');

                return [];
            }

            $usageSession = $session;

            return $results;
        } catch (AiModelAccessException|PermanentAiProviderException $exception) {
            $exception instanceof AiModelAccessException
                ? $session?->revoked($exception->getErrorCode())
                : $session?->discarded('knowledge_embedding_pipeline_failed');
            throw $exception;
        } catch (Throwable $exception) {
            $session?->discarded('knowledge_embedding_result_not_committed');
            $message = $this->errorSanitizer->sanitize(
                OpenAiRuntimeProvider::normalizeApiException(
                    $exception,
                    (string) ($embeddingMetadata['api_url'] ?? ''),
                ),
                'embedding_provider_failed',
            );
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

    /**
     * @param  array<int, string>  $batch
     * @param  array{model_id:int,model_name:string,provider:string,config_revision:string,api_url:string,api_key:string,driver:string}  $embeddingMetadata
     * @return array<int, array{model_id:int,dimensions:int,provider:string,fingerprint:string,profile_version:int,profile_digest:string,config_revision:string,vector:list<float>,vector_literal:?string}>
     */
    private function generateEmbeddingBatch(
        array $batch,
        array $embeddingMetadata,
        string $providerName,
        bool $canStoreEmbeddingVector,
        SystemAiIdentity $identity,
        KnowledgeIndexAiUsageSession $usageSession,
        string $requestId,
        string $executionToken,
        int $executionAttempt,
        int $dispatchOrdinal,
        int $providerOrdinal,
        ?string $documentTitle = null,
        ?int $knowledgeBaseId = null,
        ?string $syncToken = null,
    ): array {
        $modelSnapshot = new AiModel;
        $modelSnapshot->setAttribute($modelSnapshot->getKeyName(), (int) $embeddingMetadata['model_id']);
        $model = $this->systemModelAccessResolver->assertEmbeddingCurrent($identity, $modelSnapshot);
        $reservation = $this->usageQuota->reserveModel($model);
        if ($reservation === null) {
            throw new \RuntimeException('Embedding model has reached its daily usage limit.');
        }

        $batchKeys = array_keys($batch);
        $batchInputs = $this->formatEmbeddingDocumentInputs(array_values($batch), $embeddingMetadata, $documentTitle);
        $providerAttempt = null;
        $providerResult = null;
        try {
            $currentModel = $this->systemModelAccessResolver->assertEmbeddingCurrent($identity, $model);
            if (! hash_equals(
                (string) ($embeddingMetadata['config_revision'] ?? ''),
                $this->embeddingFingerprint->configurationRevision($currentModel),
            )) {
                throw AiModelAccessException::configAccessRevokedForAdminId((int) $currentModel->owner_admin_id);
            }
            if ($knowledgeBaseId !== null && $syncToken !== null) {
                $this->assertKnowledgeSyncSourceCurrent($knowledgeBaseId, $syncToken);
                $this->assertFrozenSystemEmbeddingPipelineCurrent($knowledgeBaseId, $syncToken, $identity);
            }
            $providerAttempt = $usageSession->begin(
                $this->usageAttempts->beginForSystem(
                    model: $model,
                    identity: $identity,
                    requestId: $requestId,
                    requestPayload: implode("\n", $batchInputs),
                    callKey: $this->knowledgeIndexCallKey(
                        'embedding',
                        $executionToken,
                        $executionAttempt,
                        $dispatchOrdinal,
                        $providerOrdinal,
                    ),
                    operation: 'knowledge.embedding_batch',
                    businessSource: 'knowledge_index',
                    sourceType: KnowledgeBase::class,
                    sourceId: $knowledgeBaseId,
                ),
                $reservation,
            );
            $providerResult = $this->requestEmbeddingVectors(
                $batchInputs,
                $embeddingMetadata,
                $providerName,
            );
            $usageSession->providerReturned($providerAttempt, $providerResult->usage);
            $embeddings = $providerResult->embeddings;
            $this->systemModelAccessResolver->assertEmbeddingCurrent($identity, $model);
            if ($knowledgeBaseId !== null && $syncToken !== null) {
                $this->assertKnowledgeSyncSourceCurrent($knowledgeBaseId, $syncToken);
                $this->assertFrozenSystemEmbeddingPipelineCurrent($knowledgeBaseId, $syncToken, $identity);
            }

            $results = [];
            foreach (array_values($batch) as $position => $_chunkContent) {
                $rawVector = $this->normalizeEmbeddingVector($embeddings[$position] ?? null);
                if ($rawVector === null) {
                    throw new \RuntimeException('invalid_embedding_vector');
                }

                $actualDimensions = count($rawVector);
                $profileDigest = $this->embeddingFingerprint->forModel($model, $actualDimensions);
                $results[$batchKeys[$position]] = [
                    'model_id' => (int) $embeddingMetadata['model_id'],
                    'dimensions' => $actualDimensions,
                    'provider' => (string) $embeddingMetadata['provider'],
                    'fingerprint' => $profileDigest,
                    'profile_version' => $this->embeddingFingerprint->profileVersion(),
                    'profile_digest' => $profileDigest,
                    'config_revision' => (string) $embeddingMetadata['config_revision'],
                    'vector' => $rawVector,
                    'vector_literal' => $canStoreEmbeddingVector
                        ? $this->vectorLiteral($this->padVector($rawVector, $this->embeddingStorageDimensions()))
                        : null,
                ];
            }
        } catch (AiModelAccessException|PermanentAiProviderException $exception) {
            if ($providerAttempt === null) {
                $this->usageQuota->releaseModel($reservation);
            }
            $errorCode = $exception instanceof AiModelAccessException
                ? $exception->getErrorCode()
                : 'knowledge_embedding_configuration_revoked';
            $usageSession->revoked($errorCode);

            throw $exception;
        } catch (Throwable $exception) {
            if ($providerAttempt === null) {
                $this->usageQuota->releaseModel($reservation);
            } elseif ($providerResult instanceof KnowledgeEmbeddingProviderResult) {
                $usageSession->providerDiscarded($providerAttempt, 'knowledge_embedding_result_invalid');
            } else {
                $usageSession->providerFailed($providerAttempt, 'knowledge_embedding_provider_failed');
            }

            throw $exception;
        }

        return $results;
    }

    private function knowledgeSyncSourceIsCurrent(
        int $knowledgeBaseId,
        string $syncToken,
        ?string $expectedSourceHash = null,
    ): bool {
        $knowledgeBase = KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->where('chunk_sync_token', $syncToken)
            ->whereIn('chunk_sync_status', ['pending', 'processing'])
            ->first(['content', 'chunk_source_hash']);

        return $knowledgeBase instanceof KnowledgeBase
            && $this->knowledgeBaseMatchesSyncSource($knowledgeBase, $expectedSourceHash);
    }

    private function assertKnowledgeSyncSourceCurrent(
        int $knowledgeBaseId,
        string $syncToken,
        ?string $expectedSourceHash = null,
    ): void {
        if (! $this->knowledgeSyncSourceIsCurrent($knowledgeBaseId, $syncToken, $expectedSourceHash)) {
            throw new \RuntimeException('knowledge_sync_source_stale');
        }
    }

    private function knowledgeBaseMatchesSyncSource(
        KnowledgeBase $knowledgeBase,
        ?string $expectedSourceHash = null,
    ): bool {
        $frozenSourceHash = trim((string) $knowledgeBase->chunk_source_hash);
        if ($frozenSourceHash === ''
            || ($expectedSourceHash !== null && ! hash_equals($frozenSourceHash, $expectedSourceHash))) {
            return false;
        }

        return hash_equals($frozenSourceHash, hash('sha256', (string) $knowledgeBase->content));
    }

    private function isEmbeddingBatchSizeError(string $message): bool
    {
        $normalized = strtolower($message);

        return str_contains($normalized, 'batch size')
            || str_contains($normalized, 'batch_size');
    }

    /**
     * 生成一批文本对应的真实 embedding 向量。
     *
     * OpenAI 兼容服务商（OpenAI / 火山方舟 Doubao / MiniMax / 智谱 等）统一走直连 /embeddings 请求，
     * 仅发送 model + input；不再附带 Laravel AI 默认注入的 dimensions 参数，避免部分服务商
     * （如 doubao-embedding-text）将其判定为 InvalidParameter 而导致整批向量化失败。
     * Gemini 原生接口形态不同，继续复用 SDK。
     *
     * @param  list<string>  $inputs
     * @param  array{model_id:int,model_name:string,provider:string,fingerprint:string,api_url:string,api_key:string,driver:string}  $embeddingMetadata
     * @return KnowledgeEmbeddingProviderResult 与 $inputs 顺序对应的原始向量和用量
     */
    private function requestEmbeddingVectors(
        array $inputs,
        array $embeddingMetadata,
        string $providerName,
    ): KnowledgeEmbeddingProviderResult {
        if ($this->isGeminiEmbeddingMetadata($embeddingMetadata)) {
            $response = Embeddings::for($inputs)
                ->timeout(45)
                ->generate($providerName, (string) $embeddingMetadata['model_name']);

            return new KnowledgeEmbeddingProviderResult(
                array_values((array) $response->embeddings),
                ['input_tokens' => $response->tokens, 'total_tokens' => $response->tokens],
            );
        }

        return $this->requestOpenAiCompatibleEmbeddings($inputs, $embeddingMetadata);
    }

    /**
     * 直连 OpenAI 兼容 /embeddings 接口，仅发送 model + input。
     *
     * 请求通过统一安全出站网关校验并固定目标地址。
     *
     * @param  list<string>  $inputs
     * @param  array{model_id:int,model_name:string,provider:string,api_url:string,api_key:string,driver:string}  $embeddingMetadata
     */
    private function requestOpenAiCompatibleEmbeddings(
        array $inputs,
        array $embeddingMetadata,
    ): KnowledgeEmbeddingProviderResult {
        $endpoint = rtrim((string) $embeddingMetadata['api_url'], '/').'/embeddings';

        $request = $this->http->acceptJson()
            ->asJson()
            ->withToken((string) $embeddingMetadata['api_key'])
            ->connectTimeout(8)
            ->timeout(45);
        $response = $this->safeHttp->post($request, $endpoint, [
            'model' => (string) $embeddingMetadata['model_name'],
            'input' => $inputs,
        ], (int) config('geoflow.outbound_ai_max_bytes', 8 * 1024 * 1024));

        if (! $response->successful()) {
            $error = data_get($response->json(), 'error.message');
            $message = is_string($error) && $this->isEmbeddingBatchSizeError($error)
                ? 'Embedding provider rejected batch size.'
                : 'Embedding provider request failed.';

            throw new \RuntimeException(sprintf(
                'HTTP request returned status code %d: %s',
                $response->status(),
                $message,
            ), 0, $response->toException());
        }

        $data = $response->json();
        $rows = is_array($data) ? ($data['data'] ?? []) : [];
        if (! is_array($rows)) {
            return new KnowledgeEmbeddingProviderResult([], is_array($data) ? ($data['usage'] ?? null) : null);
        }

        $embeddings = [];
        foreach ($rows as $position => $row) {
            if (! is_array($row)) {
                continue;
            }

            $index = $position;
            if (array_key_exists('index', $row) && is_numeric($row['index'])) {
                $index = max(0, (int) $row['index']);
            }

            $embeddings[$index] = $row['embedding'] ?? null;
        }
        ksort($embeddings);

        return new KnowledgeEmbeddingProviderResult(
            $embeddings,
            is_array($data) ? ($data['usage'] ?? null) : null,
        );
    }

    private function embeddingBatchSize(): int
    {
        return max(1, min(64, (int) config('geoflow.embedding_batch_size', 1)));
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
