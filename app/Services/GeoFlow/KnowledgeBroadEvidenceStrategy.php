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
                $item = [
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
                    'coverage_meta' => ['region' => (string) $section['region']],
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
                ];
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
            ],
        ]);
    }

    public function version(): string
    {
        return 'knowledge-broad-1.0.0';
    }

    /** @return list<array{index:int,content:string,title:string,start:int,end:int,region:string}> */
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
            $row = DB::table('knowledge_bases')
                ->where('id', (int) $knowledgeBase->id)
                ->selectRaw('SUBSTR(content, ?, ?) AS content_window', [$position, $windowLength])
                ->first();
            $bounded = trim((string) ($row->content_window ?? ''));
            if ($bounded === '') {
                continue;
            }
            $start = $position - 1;
            $length = mb_strlen($bounded, 'UTF-8');
            $selected[] = [
                'index' => (int) $index,
                'content' => $bounded,
                'title' => $this->sectionTitle($bounded),
                'start' => $start,
                'end' => $start + $length,
                'region' => (string) $window['region'],
            ];
        }

        return $selected;
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
