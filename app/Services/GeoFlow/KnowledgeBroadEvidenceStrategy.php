<?php

namespace App\Services\GeoFlow;

use App\Contracts\ArticleAiQualityEvidenceStrategy;
use App\Models\KnowledgeBase;
use App\Support\GeoFlow\AiQualityRetrievalMode;
use App\Support\GeoFlow\AiQualityRetrievalResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class KnowledgeBroadEvidenceStrategy implements ArticleAiQualityEvidenceStrategy
{
    public function __construct(
        private readonly KnowledgeEvidenceSecurityInspector $securityInspector = new KnowledgeEvidenceSecurityInspector,
    ) {}

    public function build(
        array $knowledgeBaseIds,
        array $articleSnapshot,
        array $factCandidates,
        array $options = [],
    ): AiQualityRetrievalResult {
        $ids = collect($knowledgeBaseIds)->map('intval')->filter()->unique()->take(5)->values()->all();
        $maxEvidence = max(1, min(30, (int) ($options['max_evidence'] ?? config('geoflow.ai_quality_max_evidence', 12))));
        $maxCharacters = max(100, min(12000, (int) ($options['max_characters'] ?? config('geoflow.ai_quality_max_evidence_characters', 6000))));
        $knowledgeBases = KnowledgeBase::query()
            ->whereIn('id', $ids)
            ->get([
                'id', 'name', 'ai_quality_content_hash', 'ai_quality_content_length',
                'source_name', 'source_url', 'source_type', 'business_line',
                'effective_date', 'risk_level', 'review_status',
            ])
            ->sortBy(static fn (KnowledgeBase $base): int => array_search((int) $base->id, $ids, true))
            ->values();
        $evidence = [];
        $baseCount = max(1, $knowledgeBases->count());
        $remainingTotal = $maxCharacters;
        $promptInjectionRiskCount = 0;
        $sourceKnowledgeBaseIds = [];

        foreach ($knowledgeBases as $baseIndex => $knowledgeBase) {
            if (count($evidence) >= $maxEvidence || $remainingTotal <= 0) {
                break;
            }
            $remainingBases = max(1, $baseCount - $baseIndex);
            $baseBudget = (int) floor($remainingTotal / $remainingBases);
            $baseEvidenceLimit = min(
                $maxEvidence - count($evidence),
                max(1, (int) ceil(($maxEvidence - count($evidence)) / $remainingBases)),
            );
            $sourceKnowledgeBaseIds[] = (int) $knowledgeBase->id;
            $selected = $this->selectWindows($knowledgeBase, $baseEvidenceLimit, $baseBudget);
            foreach ($selected as $section) {
                if (count($evidence) >= $maxEvidence || $remainingTotal <= 0) {
                    break;
                }
                $content = (string) $section['content'];
                $content = mb_substr($content, 0, $remainingTotal, 'UTF-8');
                if ($content === '') {
                    break;
                }
                $id = 'K'.(count($evidence) + 1);
                $item = AiQualityRetrievalResult::normalizeEvidence([
                    'id' => $id,
                    'knowledge_base_id' => (int) $knowledgeBase->id,
                    'chunk_id' => 0,
                    'chunk_index' => (int) $section['index'],
                    'stable_key' => (int) $knowledgeBase->id.':broad:'.(int) $section['start'],
                    'content' => $content,
                    'content_hash' => hash('sha256', $content),
                    'source_hash' => (string) $knowledgeBase->ai_quality_content_hash,
                    'chunk_title' => (string) $section['title'],
                    'section_path' => (string) $section['title'],
                    'source_offset_start' => (int) $section['start'],
                    'source_offset_end' => (int) $section['end'],
                    'retrieval_strategy' => AiQualityRetrievalMode::KNOWLEDGE_BROAD,
                    'retrieval_strategy_version' => $this->version(),
                    'governance_status' => (string) ($knowledgeBase->review_status ?? 'unreviewed'),
                    'coverage_meta' => [
                        'region' => (string) $section['region'],
                        'paragraph_truncated' => (bool) $section['paragraph_truncated'],
                        'truncation_reason' => $section['paragraph_truncated']
                            ? 'paragraph_exceeds_window_budget'
                            : null,
                    ],
                    'metadata' => array_filter([
                        'knowledge_base_id' => (int) $knowledgeBase->id,
                        'knowledge_base_name' => (string) $knowledgeBase->name,
                        'source_name' => (string) ($knowledgeBase->source_name ?? ''),
                        'source_url' => (string) ($knowledgeBase->source_url ?? ''),
                        'source_type' => (string) ($knowledgeBase->source_type ?? 'document'),
                        'business_line' => (string) ($knowledgeBase->business_line ?? ''),
                        'effective_date' => $knowledgeBase->effective_date?->toDateString(),
                        'risk_level' => (string) ($knowledgeBase->risk_level ?? 'medium'),
                        'review_status' => (string) ($knowledgeBase->review_status ?? 'unreviewed'),
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                ], AiQualityRetrievalMode::KNOWLEDGE_BROAD, $this->version(), [
                    'provider' => 'knowledge_broad',
                    'region' => (string) $section['region'],
                ]);
                if ($this->securityInspector->hasPromptInjectionRisk($item)) {
                    $promptInjectionRiskCount++;

                    continue;
                }
                $item['prompt_injection_risk'] = false;
                $evidence[] = $item;
                $remainingTotal = max(0, $remainingTotal - mb_strlen($content, 'UTF-8'));
            }
        }

        $coveredFacts = [];
        foreach ($factCandidates as $candidate) {
            $references = collect($evidence)
                ->filter(fn (array $item): bool => $this->matches(
                    (string) ($candidate['normalized_claim'] ?? $candidate['quote'] ?? ''),
                    (string) $item['content'],
                ))
                ->pluck('id')
                ->values()
                ->all();
            $reviewed = collect($evidence)
                ->whereIn('id', $references)
                ->every(static fn (array $item): bool => in_array(
                    strtolower((string) data_get($item, 'metadata.review_status', 'unreviewed')),
                    ['reviewed', 'approved', 'verified'],
                    true,
                ));
            $candidate['knowledge_refs'] = $references;
            $candidate['coverage_status'] = $references === [] ? 'insufficient' : ($reviewed ? 'sufficient' : 'partial');
            $candidate['retrieval_status'] = $references === [] ? 'no_evidence' : 'evidence_found';
            $coveredFacts[] = $candidate;
        }
        $materialFacts = collect($coveredFacts)->filter(static fn (array $fact): bool => in_array(
            (string) ($fact['materiality'] ?? 'high'),
            ['high', 'medium'],
            true,
        ));
        $coverage = $materialFacts->isEmpty() || $materialFacts->every(
            static fn (array $fact): bool => (string) ($fact['coverage_status'] ?? '') === 'sufficient',
        ) ? 'sufficient' : 'insufficient';

        return new AiQualityRetrievalResult([
            'evidence' => $evidence,
            'fact_candidates' => $coveredFacts,
            'knowledge_coverage' => $coverage,
            'generation_evidence_reused_count' => 0,
            'effective_retrieval_mode' => AiQualityRetrievalMode::KNOWLEDGE_BROAD,
            'retrieval_strategy_version' => $this->version(),
            'retrieval_meta' => [
                'path' => ['knowledge_broad'],
                'source_character_count' => $knowledgeBases->sum(static fn (KnowledgeBase $base): int => (int) $base->ai_quality_content_length),
                'evidence_character_count' => array_sum(array_map(static fn (array $item): int => mb_strlen((string) $item['content']), $evidence)),
                'prompt_injection_risk_count' => $promptInjectionRiskCount,
                'truncated_window_count' => collect($evidence)->filter(
                    static fn (array $item): bool => (bool) data_get($item, 'coverage_meta.paragraph_truncated', false),
                )->count(),
                'source_knowledge_base_ids' => [
                    'knowledge_broad' => array_values(array_unique($sourceKnowledgeBaseIds)),
                ],
            ],
        ]);
    }

    public function version(): string
    {
        return 'knowledge-broad-1.1.0';
    }

    /** @return list<array{index:int,content:string,title:string,start:int,end:int,region:string,paragraph_truncated:bool}> */
    private function selectWindows(KnowledgeBase $knowledgeBase, int $limit, int $budget): array
    {
        $contentLength = max(0, (int) $knowledgeBase->ai_quality_content_length);
        $limit = max(1, min(3, $limit));
        $budget = max(1, $budget);
        if ($contentLength < 1) {
            return [];
        }

        $windowLength = max(1, (int) floor($budget / $limit));
        $positions = collect([
            ['position' => 1, 'region' => 'front'],
            ['position' => max(1, (int) floor(($contentLength - $windowLength) / 2) + 1), 'region' => 'middle'],
            ['position' => max(1, $contentLength - $windowLength + 1), 'region' => 'back'],
        ])->unique('position')->take($limit)->values();
        $selected = [];
        foreach ($positions as $index => $window) {
            $position = (int) $window['position'];
            $lookaround = min(1000, max(128, $windowLength));
            $queryStart = max(1, $position - $lookaround);
            $queryLength = min(
                $contentLength - $queryStart + 1,
                $windowLength + ($lookaround * 2),
            );
            $row = DB::table('knowledge_bases')
                ->where('id', (int) $knowledgeBase->id)
                ->selectRaw('SUBSTR(content, ?, ?) AS content_window', [$queryStart, $queryLength])
                ->first();
            $contentWindow = (string) ($row->content_window ?? '');
            $targetOffset = max(0, $position - $queryStart);
            $region = (string) $window['region'];
            $startInWindow = match ($region) {
                'front' => 0,
                'back' => $this->paragraphStartAtOrAfterOffset($contentWindow, $targetOffset),
                default => $this->paragraphStartOffset($contentWindow, $targetOffset),
            };
            $bounded = mb_substr($contentWindow, $startInWindow, $windowLength, 'UTF-8');
            if ($region !== 'back'
                && ($queryStart - 1 + $startInWindow + mb_strlen($bounded, 'UTF-8')) < $contentLength) {
                $bounded = $this->withoutPartialTrailingParagraph($bounded);
            }
            preg_match('/\A\s+/u', $bounded, $leadingWhitespace);
            $leadingLength = mb_strlen((string) ($leadingWhitespace[0] ?? ''), 'UTF-8');
            $startInWindow += $leadingLength;
            $bounded = rtrim(mb_substr($bounded, $leadingLength, null, 'UTF-8'));
            if ($bounded === '') {
                continue;
            }
            $start = $queryStart - 1 + $startInWindow;
            $length = mb_strlen($bounded, 'UTF-8');
            $end = $start + $length;
            $selected[$start] = [
                'index' => (int) $index,
                'content' => $bounded,
                'title' => $this->sectionTitle($bounded),
                'start' => $start,
                'end' => $end,
                'region' => $region,
                'paragraph_truncated' => ! $this->isParagraphStart($contentWindow, $startInWindow, $start)
                    || ! $this->isParagraphEnd($contentWindow, $startInWindow + $length, $end, $contentLength),
            ];
        }

        return array_values($selected);
    }

    private function paragraphStartOffset(string $content, int $targetOffset): int
    {
        $prefix = mb_substr($content, 0, min(mb_strlen($content, 'UTF-8'), $targetOffset + 1), 'UTF-8');
        $start = null;
        foreach (["\n\n", "\r\n\r\n", "\r\r"] as $separator) {
            $boundary = mb_strrpos($prefix, $separator, 0, 'UTF-8');
            if ($boundary !== false) {
                $candidate = $boundary + mb_strlen($separator, 'UTF-8');
                $start = $start === null ? $candidate : max($start, $candidate);
            }
        }

        return $start ?? $targetOffset;
    }

    private function paragraphStartAtOrAfterOffset(string $content, int $targetOffset): int
    {
        foreach (["\n\n", "\r\n\r\n", "\r\r"] as $separator) {
            $separatorLength = mb_strlen($separator, 'UTF-8');
            $searchStart = max(0, $targetOffset - $separatorLength + 1);
            $boundary = mb_strpos($content, $separator, $searchStart, 'UTF-8');
            if ($boundary !== false
                && $boundary <= $targetOffset
                && $targetOffset < ($boundary + $separatorLength)) {
                return $boundary + $separatorLength;
            }
        }
        $prefix = mb_substr($content, 0, $targetOffset, 'UTF-8');
        foreach (["\n\n", "\r\n\r\n", "\r\r"] as $separator) {
            if (str_ends_with($prefix, $separator)) {
                return $targetOffset;
            }
        }
        $start = null;
        foreach (["\n\n", "\r\n\r\n", "\r\r"] as $separator) {
            $boundary = mb_strpos($content, $separator, $targetOffset, 'UTF-8');
            if ($boundary !== false) {
                $candidate = $boundary + mb_strlen($separator, 'UTF-8');
                $start = $start === null ? $candidate : min($start, $candidate);
            }
        }

        return $start ?? $targetOffset;
    }

    private function withoutPartialTrailingParagraph(string $content): string
    {
        $boundary = null;
        foreach (["\n\n", "\r\n\r\n", "\r\r"] as $separator) {
            $candidate = mb_strrpos($content, $separator, 0, 'UTF-8');
            if ($candidate !== false) {
                $boundary = $boundary === null ? $candidate : max($boundary, $candidate);
            }
        }
        if ($boundary === null || $boundary === 0) {
            return $content;
        }

        return mb_substr($content, 0, $boundary, 'UTF-8');
    }

    private function isParagraphStart(string $contentWindow, int $startInWindow, int $absoluteStart): bool
    {
        if ($absoluteStart === 0) {
            return true;
        }
        $prefix = mb_substr($contentWindow, 0, $startInWindow, 'UTF-8');

        return collect(["\n\n", "\r\n\r\n", "\r\r"])->contains(
            static fn (string $separator): bool => str_ends_with($prefix, $separator),
        );
    }

    private function isParagraphEnd(string $contentWindow, int $endInWindow, int $absoluteEnd, int $contentLength): bool
    {
        if ($absoluteEnd >= $contentLength) {
            return true;
        }
        $suffix = mb_substr($contentWindow, $endInWindow, null, 'UTF-8');

        return collect(["\n\n", "\r\n\r\n", "\r\r"])->contains(
            static fn (string $separator): bool => str_starts_with($suffix, $separator),
        );
    }

    private function sectionTitle(string $block): string
    {
        $firstLine = trim((string) Str::of($block)->before("\n"));

        return Str::limit(ltrim($firstLine, "# \t"), 120, '');
    }

    private function matches(string $claim, string $evidence): bool
    {
        $claim = Str::of($claim)->lower()->replaceMatches('/[^\pL\pN]+/u', '')->toString();
        $evidence = Str::of($evidence)->lower()->replaceMatches('/[^\pL\pN]+/u', '')->toString();
        if ($claim === '' || $evidence === '') {
            return false;
        }
        if (str_contains($evidence, $claim)) {
            return true;
        }
        preg_match_all('/\d+(?:\.\d+)?/u', $claim, $claimNumbers);
        preg_match_all('/\d+(?:\.\d+)?/u', $evidence, $evidenceNumbers);
        if (array_intersect($claimNumbers[0] ?? [], $evidenceNumbers[0] ?? []) === []) {
            return false;
        }

        return array_intersect($this->semanticTokens($claim), $this->semanticTokens($evidence)) !== [];
    }

    /** @return list<string> */
    private function semanticTokens(string $text): array
    {
        preg_match_all('/[\p{Han}]{2,}|[\p{L}]{3,}/u', $text, $matches);
        $tokens = [];
        foreach ($matches[0] ?? [] as $term) {
            $term = mb_strtolower((string) $term, 'UTF-8');
            if (preg_match('/\p{Han}/u', $term) !== 1) {
                $tokens[$term] = true;

                continue;
            }
            for ($index = 0; $index < mb_strlen($term, 'UTF-8') - 1; $index++) {
                $tokens[mb_substr($term, $index, 2, 'UTF-8')] = true;
            }
        }

        return array_keys($tokens);
    }
}
