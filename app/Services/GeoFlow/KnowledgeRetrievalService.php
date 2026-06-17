<?php

namespace App\Services\GeoFlow;

use App\Models\KnowledgeBase;
use App\Models\KnowledgeChunk;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * 知识库混合召回与证据上下文拼装。
 *
 * 召回不只依赖向量分数：同时考虑关键词、标题/章节、来源元数据、审核状态和资料时效。
 */
class KnowledgeRetrievalService
{
    private const FULL_SCAN_CHUNK_LIMIT = 500;

    private const MAX_PREFILTER_ROWS = 300;

    private const MAX_PREFILTER_TERMS = 12;

    public function __construct(private readonly KnowledgeChunkSyncService $knowledgeChunkSyncService) {}

    public function retrieveContext(int $knowledgeBaseId, string $query, int $limit = 5, int $maxChars = 3200): string
    {
        return $this->composeEvidenceContext(
            $this->retrieveEvidence($knowledgeBaseId, $query, max($limit * 4, 16)),
            $limit,
            $maxChars
        );
    }

    /**
     * @param  list<int>  $knowledgeBaseIds
     */
    public function retrieveContextFromMany(array $knowledgeBaseIds, string $query, int $limit = 5, int $maxChars = 3200): string
    {
        return $this->composeEvidenceContext(
            $this->retrieveEvidenceFromMany($knowledgeBaseIds, $query, max($limit * 4, 16)),
            $limit,
            $maxChars
        );
    }

    /**
     * @param  list<int>  $knowledgeBaseIds
     * @return list<array<string,mixed>>
     */
    public function retrieveEvidenceFromMany(array $knowledgeBaseIds, string $query, int $candidateLimit = 16): array
    {
        $knowledgeBaseIds = collect($knowledgeBaseIds)
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(5)
            ->values()
            ->all();

        if ($knowledgeBaseIds === []) {
            return [];
        }

        if (count($knowledgeBaseIds) === 1) {
            return $this->retrieveEvidence($knowledgeBaseIds[0], $query, $candidateLimit);
        }

        $perBaseLimit = max(6, (int) ceil(max(1, $candidateLimit) / count($knowledgeBaseIds)) + 4);
        $merged = [];

        foreach ($knowledgeBaseIds as $order => $knowledgeBaseId) {
            foreach ($this->retrieveEvidence($knowledgeBaseId, $query, $perBaseLimit) as $candidate) {
                $candidate['knowledge_base_rank'] = $order;
                $merged[] = $candidate;
            }
        }

        if ($merged === []) {
            return [];
        }

        $merged = $this->resolveEvidenceConflicts($merged);
        usort($merged, static function (array $a, array $b): int {
            $diff = ((float) $b['score']) <=> ((float) $a['score']);
            if ($diff !== 0) {
                return $diff;
            }

            $rankDiff = ((int) ($a['knowledge_base_rank'] ?? 0)) <=> ((int) ($b['knowledge_base_rank'] ?? 0));

            return $rankDiff !== 0 ? $rankDiff : ((int) $a['chunk_index'] <=> (int) $b['chunk_index']);
        });

        return array_slice($merged, 0, max(1, $candidateLimit));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function retrieveEvidence(int $knowledgeBaseId, string $query, int $candidateLimit = 16): array
    {
        /** @var KnowledgeBase|null $knowledgeBase */
        $knowledgeBase = KnowledgeBase::query()
            ->whereKey($knowledgeBaseId)
            ->first($this->knowledgeBaseSelectColumns());

        if (! $knowledgeBase) {
            return [];
        }

        $queryTerms = $this->termFrequencies($query);
        $pgvectorScores = trim($query) !== ''
            ? $this->fetchPgvectorScores($knowledgeBaseId, $query, max($candidateLimit, 16))
            : [];

        $rows = $this->loadCandidateRows($knowledgeBaseId, $queryTerms, $pgvectorScores, $candidateLimit);
        if ($rows === []) {
            return [];
        }

        $hasRealEmbeddingRows = $pgvectorScores === [] && $this->knowledgeBaseHasRealEmbeddingRows($knowledgeBaseId);
        $queryVector = [];
        $useRealEmbeddingScore = false;
        if ($pgvectorScores === [] && $hasRealEmbeddingRows && trim($query) !== '') {
            $queryVector = $this->knowledgeChunkSyncService->generateQueryEmbeddingVector($query);
            $useRealEmbeddingScore = $queryVector !== [];
        }
        if ($queryVector === []) {
            $queryVector = $this->buildFallbackVector($query, 256);
        }

        $scored = [];
        foreach ($rows as $row) {
            $content = trim((string) ($row->content ?? ''));
            if ($content === '') {
                continue;
            }

            $metadata = $this->mergeMetadata($this->baseMetadata($knowledgeBase), $this->decodeMetadata((string) ($row->metadata_json ?? '')));
            if ($this->shouldExcludeByGovernance($metadata)) {
                continue;
            }

            $chunkIndex = (int) ($row->chunk_index ?? 0);
            $title = trim((string) ($row->chunk_title ?? ''));
            $sectionPath = trim((string) ($row->section_path ?? ''));
            $searchText = implode("\n", array_filter([
                $title,
                $sectionPath,
                (string) ($metadata['source_name'] ?? ''),
                (string) ($metadata['business_line'] ?? ''),
                $content,
            ]));

            $lexicalScore = $this->lexicalScore($queryTerms, $this->termFrequencies($searchText));
            $titleScore = $this->lexicalScore($queryTerms, $this->termFrequencies($title."\n".$sectionPath));
            $metadataScore = $this->metadataScore($metadata);
            $vectorScore = $pgvectorScores[$chunkIndex] ?? $this->localVectorScore($row, $queryVector, $useRealEmbeddingScore);
            $score = ($vectorScore * 0.45) + ($lexicalScore * 0.35) + ($titleScore * 0.12) + ($metadataScore * 0.08);

            $scored[] = [
                'chunk_index' => $chunkIndex,
                'content' => $content,
                'chunk_title' => $title,
                'section_path' => $sectionPath,
                'metadata' => $metadata,
                'score' => $score,
                'vector_score' => $vectorScore,
                'keyword_score' => $lexicalScore,
                'title_score' => $titleScore,
                'metadata_score' => $metadataScore,
            ];
        }

        $scored = $this->resolveEvidenceConflicts($scored);
        usort($scored, static function (array $a, array $b): int {
            $diff = ((float) $b['score']) <=> ((float) $a['score']);

            return $diff !== 0 ? $diff : ((int) $a['chunk_index'] <=> (int) $b['chunk_index']);
        });

        return array_slice($scored, 0, max(1, $candidateLimit));
    }

    /**
     * @param  list<array<string,mixed>>  $evidence
     */
    private function composeEvidenceContext(array $evidence, int $limit, int $maxChars): string
    {
        if ($evidence === []) {
            return '';
        }

        $parts = [];
        $charCount = 0;
        foreach ($evidence as $candidate) {
            if (count($parts) >= max(1, $limit)) {
                break;
            }

            $content = trim((string) ($candidate['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            $nextLength = $charCount + mb_strlen($content, 'UTF-8');
            if ($parts !== [] && $nextLength > $maxChars) {
                continue;
            }

            $citation = 'K'.(count($parts) + 1);
            $metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];
            $lines = ['【证据 '.$citation.'】'];

            $knowledgeBaseName = trim((string) ($metadata['knowledge_base_name'] ?? ''));
            if ($knowledgeBaseName !== '') {
                $lines[] = '知识库：'.$knowledgeBaseName;
            }

            $title = trim((string) ($candidate['chunk_title'] ?? ''));
            if ($title !== '') {
                $lines[] = '标题：'.$title;
            }

            $sectionPath = trim((string) ($candidate['section_path'] ?? ''));
            if ($sectionPath !== '') {
                $lines[] = '章节：'.$sectionPath;
            }

            $sourceName = trim((string) ($metadata['source_name'] ?? $metadata['knowledge_base_name'] ?? ''));
            if ($sourceName !== '') {
                $lines[] = '来源：'.$sourceName;
            }

            $sourceUrl = trim((string) ($metadata['source_url'] ?? ''));
            if ($sourceUrl !== '') {
                $lines[] = '链接：'.$sourceUrl;
            }

            $effectiveDate = trim((string) ($metadata['effective_date'] ?? ''));
            if ($effectiveDate !== '') {
                $lines[] = '时间：'.$effectiveDate;
            }

            $businessLine = trim((string) ($metadata['business_line'] ?? ''));
            if ($businessLine !== '') {
                $lines[] = '业务线：'.$businessLine;
            }

            $riskLevel = trim((string) ($metadata['risk_level'] ?? ''));
            $reviewStatus = trim((string) ($metadata['review_status'] ?? ''));
            if ($riskLevel !== '' || $reviewStatus !== '') {
                $lines[] = '治理：'.trim(($riskLevel !== '' ? '风险='.$riskLevel : '').($reviewStatus !== '' ? ' 审核='.$reviewStatus : ''));
            }

            $conflictMergedCount = (int) ($candidate['conflict_merged_count'] ?? 0);
            if ($conflictMergedCount > 0) {
                $lines[] = '冲突处理：已优先采用较新或已审核资料，忽略 '.$conflictMergedCount.' 条同主题旧资料。';
            }

            $lines[] = '内容：';
            $lines[] = $content;
            $parts[] = implode("\n", $lines);
            $charCount = $nextLength;
        }

        if ($parts === []) {
            return '';
        }

        return "【知识库证据】\n".implode("\n\n", $parts);
    }

    /**
     * @return array<string,mixed>
     */
    private function baseMetadata(KnowledgeBase $knowledgeBase): array
    {
        return array_filter([
            'knowledge_base_id' => (int) $knowledgeBase->id,
            'knowledge_base_name' => (string) $knowledgeBase->name,
            'knowledge_base_description' => trim((string) ($knowledgeBase->description ?? '')),
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
    private function knowledgeBaseSelectColumns(): array
    {
        $columns = ['id', 'name', 'description'];
        foreach (['source_name', 'source_url', 'source_type', 'business_line', 'effective_date', 'risk_level', 'review_status'] as $column) {
            if (Schema::hasColumn('knowledge_bases', $column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeMetadata(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $chunk
     * @return array<string,mixed>
     */
    private function mergeMetadata(array $base, array $chunk): array
    {
        return array_replace($base, array_filter($chunk, static fn ($value): bool => $value !== null && $value !== ''));
    }

    /**
     * 大知识库先做候选集预筛，避免每次召回都把单个知识库全部切片加载到 PHP。
     *
     * @param  array<string,int>  $queryTerms
     * @param  array<int,float>  $pgvectorScores
     * @return list<KnowledgeChunk>
     */
    private function loadCandidateRows(int $knowledgeBaseId, array $queryTerms, array $pgvectorScores, int $candidateLimit): array
    {
        $chunkCount = KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->count();

        if ($chunkCount <= self::FULL_SCAN_CHUNK_LIMIT) {
            return KnowledgeChunk::query()
                ->where('knowledge_base_id', $knowledgeBaseId)
                ->orderBy('chunk_index')
                ->get($this->knowledgeChunkSelectColumns())
                ->all();
        }

        $rowsByIndex = [];
        foreach ($this->fetchRowsByChunkIndexes($knowledgeBaseId, array_keys($pgvectorScores)) as $row) {
            $rowsByIndex[(int) ($row->chunk_index ?? 0)] = $row;
        }

        foreach ($this->fetchKeywordCandidateRows($knowledgeBaseId, $queryTerms, $candidateLimit) as $row) {
            $rowsByIndex[(int) ($row->chunk_index ?? 0)] = $row;
        }

        ksort($rowsByIndex);

        return array_values($rowsByIndex);
    }

    /**
     * @return list<string>
     */
    private function knowledgeChunkSelectColumns(): array
    {
        return [
            'chunk_index',
            'content',
            'chunk_title',
            'section_path',
            'metadata_json',
            'embedding_json',
            'embedding_model_id',
            'embedding_dimensions',
        ];
    }

    /**
     * @param  list<int>  $chunkIndexes
     * @return list<KnowledgeChunk>
     */
    private function fetchRowsByChunkIndexes(int $knowledgeBaseId, array $chunkIndexes): array
    {
        $chunkIndexes = array_values(array_unique(array_map('intval', $chunkIndexes)));
        if ($chunkIndexes === []) {
            return [];
        }

        return KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->whereIn('chunk_index', $chunkIndexes)
            ->orderBy('chunk_index')
            ->get($this->knowledgeChunkSelectColumns())
            ->all();
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @return list<KnowledgeChunk>
     */
    private function fetchKeywordCandidateRows(int $knowledgeBaseId, array $queryTerms, int $candidateLimit): array
    {
        $terms = $this->candidateQueryTerms($queryTerms);
        if ($terms === []) {
            return [];
        }

        return KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->where(function ($query) use ($terms): void {
                foreach ($terms as $term) {
                    $like = '%'.mb_strtolower($term, 'UTF-8').'%';
                    $query->orWhere(function ($termQuery) use ($like): void {
                        $termQuery
                            ->whereRaw("LOWER(COALESCE(content, '')) LIKE ?", [$like])
                            ->orWhereRaw("LOWER(COALESCE(chunk_title, '')) LIKE ?", [$like])
                            ->orWhereRaw("LOWER(COALESCE(section_path, '')) LIKE ?", [$like]);
                    });
                }
            })
            ->orderBy('chunk_index')
            ->limit($this->prefilterRowLimit($candidateLimit))
            ->get($this->knowledgeChunkSelectColumns())
            ->all();
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @return list<string>
     */
    private function candidateQueryTerms(array $queryTerms): array
    {
        $terms = array_filter(
            array_keys($queryTerms),
            static fn (string $term): bool => mb_strlen($term, 'UTF-8') > 1
        );

        usort($terms, static function (string $left, string $right): int {
            $lengthDiff = mb_strlen($right, 'UTF-8') <=> mb_strlen($left, 'UTF-8');

            return $lengthDiff !== 0 ? $lengthDiff : strcmp($left, $right);
        });

        return array_slice(array_values($terms), 0, self::MAX_PREFILTER_TERMS);
    }

    private function prefilterRowLimit(int $candidateLimit): int
    {
        return min(self::MAX_PREFILTER_ROWS, max(80, max(1, $candidateLimit) * 12));
    }

    private function knowledgeBaseHasRealEmbeddingRows(int $knowledgeBaseId): bool
    {
        return KnowledgeChunk::query()
            ->where('knowledge_base_id', $knowledgeBaseId)
            ->whereNotNull('embedding_model_id')
            ->where('embedding_model_id', '>', 0)
            ->where('embedding_dimensions', '>', 0)
            ->exists();
    }

    /**
     * 同主题证据只保留最可信版本，避免新旧资料同时进入提示词造成生成冲突。
     *
     * @param  list<array<string,mixed>>  $scored
     * @return list<array<string,mixed>>
     */
    private function resolveEvidenceConflicts(array $scored): array
    {
        $groups = [];
        foreach ($scored as $candidate) {
            $groups[$this->conflictTopicKey($candidate)][] = $candidate;
        }

        $resolved = [];
        foreach ($groups as $group) {
            if (count($group) === 1) {
                $candidate = $group[0];
                $candidate['conflict_merged_count'] = 0;
                $resolved[] = $candidate;
                continue;
            }

            if (! $this->hasVersionConflictSignal($group)) {
                foreach ($group as $candidate) {
                    $candidate['conflict_merged_count'] = 0;
                    $resolved[] = $candidate;
                }
                continue;
            }

            $winner = $group[0];
            foreach (array_slice($group, 1) as $candidate) {
                if ($this->compareEvidenceAuthority($candidate, $winner) > 0) {
                    $winner = $candidate;
                }
            }

            $winner['conflict_merged_count'] = count($group) - 1;
            $resolved[] = $winner;
        }

        return $resolved;
    }

    /**
     * 同标题/章节不一定冲突，可能只是同一章节被切成多段。
     * 只有出现资料时间差异时，才把它当作同主题新旧版本来归并。
     *
     * @param  list<array<string,mixed>>  $group
     */
    private function hasVersionConflictSignal(array $group): bool
    {
        $dates = [];
        foreach ($group as $candidate) {
            $metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];
            $dates[] = trim((string) ($metadata['effective_date'] ?? ''));
        }

        $dates = array_values(array_unique($dates));

        return count($dates) > 1 && count(array_filter($dates, static fn (string $date): bool => $date !== '')) > 0;
    }

    /**
     * @param  array<string,mixed>  $candidate
     */
    private function conflictTopicKey(array $candidate): string
    {
        $title = $this->normalizeTopicKey((string) ($candidate['chunk_title'] ?? ''));
        $sectionPath = $this->normalizeTopicKey((string) ($candidate['section_path'] ?? ''));

        if ($title !== '' || $sectionPath !== '') {
            return 'topic:'.sha1($sectionPath.'|'.$title);
        }

        return 'chunk:'.(string) ($candidate['chunk_index'] ?? spl_object_id((object) $candidate));
    }

    private function normalizeTopicKey(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/[\s\p{P}\p{S}]+/u', '', $value);

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<string,mixed>  $left
     * @param  array<string,mixed>  $right
     */
    private function compareEvidenceAuthority(array $left, array $right): int
    {
        $leftValues = $this->evidenceAuthorityValues($left);
        $rightValues = $this->evidenceAuthorityValues($right);

        foreach ($leftValues as $index => $leftValue) {
            $diff = $leftValue <=> $rightValues[$index];
            if ($diff !== 0) {
                return $diff;
            }
        }

        return 0;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array{int,int,int,float,int}
     */
    private function evidenceAuthorityValues(array $candidate): array
    {
        $metadata = is_array($candidate['metadata'] ?? null) ? $candidate['metadata'] : [];

        return [
            $this->reviewStatusRank((string) ($metadata['review_status'] ?? '')),
            $this->effectiveDateTimestamp((string) ($metadata['effective_date'] ?? '')),
            $this->riskLevelRank((string) ($metadata['risk_level'] ?? '')),
            (float) ($candidate['score'] ?? 0.0),
            -1 * (int) ($candidate['chunk_index'] ?? 0),
        ];
    }

    private function reviewStatusRank(string $reviewStatus): int
    {
        $reviewStatus = strtolower(trim($reviewStatus));

        return in_array($reviewStatus, ['reviewed', 'approved', 'verified'], true) ? 2 : 1;
    }

    private function riskLevelRank(string $riskLevel): int
    {
        return match (strtolower(trim($riskLevel))) {
            'low' => 3,
            'high' => 1,
            default => 2,
        };
    }

    private function effectiveDateTimestamp(string $effectiveDate): int
    {
        $effectiveDate = trim($effectiveDate);
        if ($effectiveDate === '') {
            return 0;
        }

        try {
            return Carbon::parse($effectiveDate)->timestamp;
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function shouldExcludeByGovernance(array $metadata): bool
    {
        $riskLevel = strtolower(trim((string) ($metadata['risk_level'] ?? '')));
        $reviewStatus = strtolower(trim((string) ($metadata['review_status'] ?? '')));

        return $riskLevel === 'high' && ! in_array($reviewStatus, ['reviewed', 'approved', 'verified'], true);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function metadataScore(array $metadata): float
    {
        $score = 0.0;
        $reviewStatus = strtolower(trim((string) ($metadata['review_status'] ?? '')));
        if (in_array($reviewStatus, ['reviewed', 'approved', 'verified'], true)) {
            $score += 0.42;
        }

        if (trim((string) ($metadata['source_name'] ?? '')) !== '' || trim((string) ($metadata['source_url'] ?? '')) !== '') {
            $score += 0.18;
        }

        if (trim((string) ($metadata['business_line'] ?? '')) !== '') {
            $score += 0.12;
        }

        $effectiveDate = trim((string) ($metadata['effective_date'] ?? ''));
        if ($effectiveDate !== '') {
            try {
                $ageDays = max(0, Carbon::parse($effectiveDate)->diffInDays(now()));
                $score += $ageDays <= 365 ? 0.2 : ($ageDays <= 1095 ? 0.1 : 0.02);
            } catch (Throwable) {
                $score += 0.02;
            }
        }

        $riskLevel = strtolower(trim((string) ($metadata['risk_level'] ?? '')));
        if ($riskLevel === 'low') {
            $score += 0.08;
        } elseif ($riskLevel === 'high') {
            $score -= 0.12;
        }

        return max(0.0, min(1.0, $score));
    }

    private function localVectorScore(object $row, array $queryVector, bool $useRealEmbeddingScore): float
    {
        $vector = $this->decodeVector((string) ($row->embedding_json ?? ''));
        if ($vector === []) {
            return 0.0;
        }

        $chunkUsesRealEmbedding = $this->chunkHasRealEmbedding($row);
        if ($useRealEmbeddingScore !== $chunkUsesRealEmbedding) {
            return 0.0;
        }

        return $this->dotProduct($queryVector, $vector);
    }

    private function chunkHasRealEmbedding(object $row): bool
    {
        return (int) ($row->embedding_model_id ?? 0) > 0
            && (int) ($row->embedding_dimensions ?? 0) > 0;
    }

    /**
     * @return array<int,float>
     */
    private function fetchPgvectorScores(int $knowledgeBaseId, string $query, int $candidateLimit): array
    {
        if (! $this->canUsePgvectorSearch()) {
            return [];
        }

        $vectorLiteral = $this->knowledgeChunkSyncService->generateQueryVectorLiteral($query);
        if ($vectorLiteral === '') {
            return [];
        }

        try {
            $rows = DB::select(
                '
                    SELECT chunk_index,
                           (embedding_vector <=> CAST(? AS vector)) AS vector_distance
                    FROM knowledge_chunks
                    WHERE knowledge_base_id = ?
                      AND embedding_vector IS NOT NULL
                    ORDER BY embedding_vector <=> CAST(? AS vector), chunk_index ASC
                    LIMIT ?
                ',
                [$vectorLiteral, $knowledgeBaseId, $vectorLiteral, max(1, $candidateLimit)]
            );
        } catch (Throwable) {
            return [];
        }

        $scores = [];
        foreach ($rows as $row) {
            $distance = (float) ($row->vector_distance ?? 1.0);
            $scores[(int) ($row->chunk_index ?? 0)] = max(0.0, min(1.0, 1.0 - $distance));
        }

        return $scores;
    }

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
     * @return array<string,int>
     */
    private function termFrequencies(string $text): array
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        if ($text === '') {
            return [];
        }

        preg_match_all('/[a-z0-9_]+|\p{Han}+/u', $text, $matches);
        $frequencies = [];
        foreach ($matches[0] ?? [] as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }

            if (preg_match('/^\p{Han}+$/u', $token) === 1) {
                foreach ($this->cjkTokens($token) as $cjkToken) {
                    $frequencies[$cjkToken] = (int) ($frequencies[$cjkToken] ?? 0) + 1;
                }
                continue;
            }

            if (mb_strlen($token, 'UTF-8') > 1) {
                $frequencies[$token] = (int) ($frequencies[$token] ?? 0) + 1;
            }
        }

        return $frequencies;
    }

    /**
     * @return list<string>
     */
    private function cjkTokens(string $text): array
    {
        $length = mb_strlen($text, 'UTF-8');
        if ($length <= 1) {
            return [];
        }

        $tokens = [];
        if ($length <= 8) {
            $tokens[] = $text;
        }
        foreach ([2, 3, 4] as $size) {
            if ($length < $size) {
                continue;
            }
            for ($offset = 0; $offset <= $length - $size; $offset++) {
                $tokens[] = mb_substr($text, $offset, $size, 'UTF-8');
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param  array<string,int>  $queryTerms
     * @param  array<string,int>  $documentTerms
     */
    private function lexicalScore(array $queryTerms, array $documentTerms): float
    {
        if ($queryTerms === [] || $documentTerms === []) {
            return 0.0;
        }

        $matched = 0;
        $total = 0;
        foreach ($queryTerms as $term => $count) {
            $total += $count;
            if (isset($documentTerms[$term])) {
                $matched += min($count, (int) $documentTerms[$term]);
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
}
