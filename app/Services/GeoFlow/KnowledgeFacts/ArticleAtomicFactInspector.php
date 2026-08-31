<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

use App\Models\KnowledgeFactLibrary;
use Illuminate\Support\Collection;

class ArticleAtomicFactInspector
{
    public const ALGORITHM_VERSION = 'atomic-facts-2.3.0';

    public function __construct(private readonly AtomicFactComparator $comparator) {}

    /** @param list<int> $knowledgeBaseIds @return array<string,mixed> */
    public function inspect(string $article, array $knowledgeBaseIds): array
    {
        $startedAt = hrtime(true);
        $libraries = KnowledgeFactLibrary::query()
            ->with(['knowledgeBase:id,chunk_sync_status,chunk_source_hash,chunk_serving_source_hash', 'activeRevision:id,library_id,version,library_hash,source_hash,manifest_json'])
            ->whereIn('knowledge_base_id', array_values(array_unique(array_map('intval', $knowledgeBaseIds))))
            ->get(['id', 'knowledge_base_id', 'serving_status', 'active_revision_id', 'active_hash', 'source_hash']);
        $ready = $libraries->filter(fn ($library): bool => $library->serving_status === 'ready'
            && $library->activeRevision !== null
            && $library->knowledgeBase?->chunk_sync_status === 'ready'
            && trim($library->knowledgeBase?->servingChunkSourceHash() ?? '') !== ''
            && hash_equals($library->knowledgeBase?->servingChunkSourceHash() ?? '', (string) $library->source_hash)
            && hash_equals((string) $library->activeRevision->source_hash, (string) $library->source_hash));
        $facts = $this->compile($ready);
        $claims = $this->claims($article);
        $atomicClaimLimit = max(1, (int) config('geoflow.ai_quality_max_atomic_claims', 24));
        $atomicClaims = array_slice($claims, 0, $atomicClaimLimit, true);
        $results = collect();
        $coveredClaimIndexes = [];

        foreach ($atomicClaims as $claimIndex => $claim) {
            foreach ($facts->filter(fn (array $fact): bool => $this->recalls($claim, $fact))->take(8) as $fact) {
                $result = $this->evaluateClaim($claim, $fact);
                $results->push($result + ['claim_index' => $claimIndex]);
                if (in_array($result['status'], ['supported', 'contradicted'], true)) {
                    $coveredClaimIndexes[$claimIndex] = true;
                }
            }
        }

        $results = $results->unique(fn (array $row): string => $row['claim_index'].'|'.$row['stable_key'].'|'.$row['status'])->values();
        $issues = $results->where('status', 'contradicted')->map(function (array $result): array {
            return [
                'code' => 'knowledge_contradiction',
                'severity' => $result['importance'] === 'critical' ? 'critical' : 'high',
                'field' => 'content',
                'reason' => sprintf('文章中的“%s”与原子事实标准答案不一致。', $result['label']),
                'quote' => $result['article_claim'],
                'suggestion' => '请依据标准答案修正：'.$result['standard_answer'],
                'location_status' => 'resolved',
                'references_valid' => true,
                'hard_blocker' => $result['importance'] === 'critical',
                'claim_hash' => hash('sha256', $result['article_claim']),
                'knowledge_refs' => array_values(array_filter(array_map(static fn (array $evidence): string => (string) ($evidence['knowledge_chunk_id'] ?? ''), $result['evidence']))),
                'atomic_fact' => [
                    'stable_key' => $result['stable_key'],
                    'standard_answer' => $result['standard_answer'],
                    'comparison_method' => $result['comparison_method'],
                    'revision_id' => $result['revision_id'],
                    'revision_version' => $result['revision_version'],
                    'evidence' => $result['evidence'],
                ],
            ];
        })->values()->all();
        $counts = $results->countBy('status');
        $fallbackClaims = collect($claims)->reject(fn (string $_claim, int $index): bool => isset($coveredClaimIndexes[$index]))->values();
        $overflowFallbackCount = max(0, count($claims) - count($atomicClaims));

        return [
            'mode' => $ready->isEmpty() ? 'knowledge_fallback' : 'hybrid',
            'algorithm_version' => self::ALGORITHM_VERSION,
            'revision_ids' => $ready->pluck('active_revision_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'knowledge_base_ids' => $ready->pluck('knowledge_base_id')->map(fn ($id): int => (int) $id)->values()->all(),
            'library_hashes' => $ready->pluck('active_hash')->filter()->values()->all(),
            'source_hashes' => $ready->pluck('source_hash')->filter()->values()->all(),
            'fact_count' => $facts->count(),
            'claims_processed' => count($atomicClaims),
            'detected_claim_count' => count($claims),
            'atomic_processed_count' => count($atomicClaims),
            'overflow_fallback_count' => $overflowFallbackCount,
            'uninspected_claim_count' => 0,
            'supported_count' => (int) $counts->get('supported', 0),
            'contradicted_count' => (int) $counts->get('contradicted', 0),
            'not_covered_count' => $fallbackClaims->count(),
            'ambiguous_count' => (int) $counts->get('ambiguous', 0),
            'stale_count' => $libraries->count() - $ready->count(),
            'conflict_count' => (int) $counts->get('conflict', 0),
            'fallback_count' => $fallbackClaims->count(),
            'coverage_rate' => count($claims) === 0 ? 0.0 : round(count($coveredClaimIndexes) / count($claims), 4),
            'fallback_content' => $fallbackClaims->implode("\n"),
            'elapsed_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0],
            'results' => $results->all(),
            'issues' => $issues,
        ];
    }

    /** @param Collection<int,KnowledgeFactLibrary> $libraries @return Collection<int,array<string,mixed>> */
    private function compile(Collection $libraries): Collection
    {
        $compiled = collect();
        foreach ($libraries as $library) {
            foreach ((array) data_get($library->activeRevision?->manifest_json, 'facts', []) as $fact) {
                foreach ((array) ($fact['values'] ?? []) as $value) {
                    $compiled->push(array_replace($fact, [
                        'revision_id' => (int) $library->activeRevision->id,
                        'revision_version' => (int) $library->activeRevision->version,
                        'library_id' => (int) $library->id,
                        'knowledge_base_id' => (int) $library->knowledge_base_id,
                        'source_hash' => (string) $library->source_hash,
                        'value' => $value,
                        'evidence' => (array) ($value['evidence'] ?? []),
                    ]));
                }
            }
        }

        return $compiled->groupBy('stable_key')->map(function (Collection $group): array {
            $first = $group->first();
            $answers = $group->map(fn (array $fact): string => $this->normalized((string) data_get($fact, 'value.canonical_answer')))->filter()->unique();
            if ($answers->count() > 1 && $group->pluck('library_id')->unique()->count() > 1) {
                $first['compiled_conflict'] = true;
            }
            $first['candidate_values'] = $group->values()->all();

            return $first;
        })->values();
    }

    /** @return list<string> */
    private function claims(string $article): array
    {
        return collect(preg_split('/(?<=[。！？!?；;])|\n+/u', strip_tags($article)) ?: [])
            ->map(fn ($claim): string => trim((string) $claim))
            ->filter(fn (string $claim): bool => mb_strlen($claim) >= 4)
            ->values()->all();
    }

    /** @param array<string,mixed> $fact */
    private function recalls(string $claim, array $fact): bool
    {
        $haystack = $this->normalized($claim);
        $identityAnchors = array_filter(array_merge(
            [$this->scalarString($fact['label'] ?? '')],
            array_map(fn (mixed $alias): string => $this->scalarString($alias), (array) ($fact['aliases'] ?? [])),
        ), fn ($value): bool => mb_strlen($this->normalized((string) $value)) >= 2);
        if (collect($identityAnchors)->contains(fn ($anchor): bool => str_contains($haystack, $this->normalized((string) $anchor)))) {
            return true;
        }

        $subject = $this->normalized((string) ($fact['subject'] ?? ''));
        $subjectIsSpecific = $subject !== '' && ! in_array($subject, ['geoflow'], true);
        $predicateAnchors = $this->predicateAnchors((string) ($fact['predicate'] ?? ''));
        if ($subjectIsSpecific
            && str_contains($haystack, $subject)
            && collect($predicateAnchors)->contains(fn (string $anchor): bool => str_contains($haystack, $anchor))) {
            return true;
        }

        return (string) ($fact['value_type'] ?? '') === 'version'
            && $subject !== ''
            && str_contains($haystack, $subject)
            && preg_match('/\bv?\d+\.\d+(?:\.\d+){0,2}\b/i', $claim) === 1;
    }

    /** @return list<string> */
    private function predicateAnchors(string $predicate): array
    {
        preg_match_all('/[\p{Han}]{3,}|[a-z0-9][a-z0-9.+#_-]{3,}/iu', $predicate, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $anchor): string => $this->normalized($anchor))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $fact @return array<string,mixed> */
    private function evaluateClaim(string $claim, array $fact): array
    {
        if ((bool) ($fact['compiled_conflict'] ?? false)) {
            return $this->factResult($fact, 'conflict', $claim);
        }
        $applicable = $this->applicableValues($claim, collect((array) ($fact['candidate_values'] ?? [$fact])));
        if ($applicable->count() !== 1) {
            return $this->factResult($fact, 'ambiguous', $claim);
        }
        $fact = $applicable->first();
        $type = match ((string) ($fact['value_type'] ?? 'string')) {
            'integer', 'decimal', 'number', 'percentage' => 'numeric', default => (string) ($fact['value_type'] ?? 'string')
        };
        $standardAnswer = (string) data_get($fact, 'value.canonical_answer', '');
        $standardValue = (array) data_get($fact, 'value.canonical_value', []);
        $comparisonAnswer = in_array($type, ['string', 'text'], true)
            ? $standardAnswer
            : (string) ($standardValue['value'] ?? $standardAnswer);
        $numeric = $this->numericValueForFact($claim, $fact);
        $comparison = $this->comparator->compare(
            ['text' => $claim, 'value' => $numeric['value'], 'unit' => $numeric['unit'], 'type' => $type],
            ['answer' => $comparisonAnswer, 'value' => isset($standardValue['value']) ? (string) $standardValue['value'] : null, 'unit' => (string) ($standardValue['unit'] ?? ''), 'type' => $type] + (array) data_get($fact, 'value.comparison_policy', []),
        );

        return $this->factResult($fact, match ($comparison['result']) {
            'match' => 'supported', 'mismatch' => 'contradicted', 'ambiguous' => 'ambiguous', default => 'not_covered'
        }, $claim, $standardAnswer, (string) $comparison['method']);
    }

    /** @param array<string,mixed> $fact @return array{value:?string,unit:string} */
    private function numericValueForFact(string $claim, array $fact): array
    {
        preg_match_all(
            '/(?<value>-?\d+(?:\.\d+)?)\s*(?<unit>百万|万|亿|千|%|million|percent|percentage)?/iu',
            $claim,
            $matches,
            PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
        );
        if ($matches === []) {
            return ['value' => null, 'unit' => ''];
        }

        $anchors = array_values(array_filter(array_map(
            fn (mixed $value): string => $this->scalarString($value),
            array_merge(
                [$fact['label'] ?? '', $fact['predicate'] ?? ''],
                (array) ($fact['aliases'] ?? []),
            ),
        )));
        $anchorOffsets = [];
        foreach ($anchors as $anchor) {
            $offset = mb_strpos($claim, $anchor, 0, 'UTF-8');
            if ($offset !== false) {
                $anchorOffsets[] = strlen(mb_substr($claim, 0, $offset, 'UTF-8'));
            }
        }
        $standardUnit = mb_strtolower(trim((string) data_get($fact, 'value.canonical_value.unit', '')));
        $candidates = collect($matches)->map(static function (array $match) use ($claim, $anchorOffsets, $standardUnit): array {
            $value = (string) $match['value'][0];
            $unit = mb_strtolower((string) ($match['unit'][0] ?? ''));
            $offset = (int) $match['value'][1];
            $tail = substr($claim, $offset + strlen($value), 4);
            $isYear = preg_match('/\A\s*年/u', $tail) === 1 && preg_match('/\A20\d{2}\z/', $value) === 1;
            $distance = $anchorOffsets === []
                ? $offset
                : min(array_map(static fn (int $anchor): int => abs($anchor - $offset), $anchorOffsets));

            return [
                'value' => $value,
                'unit' => $unit,
                'rank' => [
                    $standardUnit !== '' && $unit === $standardUnit ? 0 : 1,
                    $isYear ? 1 : 0,
                    $distance,
                    $offset,
                ],
            ];
        })->sortBy('rank')->first();

        return [
            'value' => is_array($candidates) ? (string) $candidates['value'] : null,
            'unit' => is_array($candidates) ? (string) $candidates['unit'] : '',
        ];
    }

    /** @param Collection<int,array<string,mixed>> $values @return Collection<int,array<string,mixed>> */
    private function applicableValues(string $claim, Collection $values): Collection
    {
        $date = preg_match('/(?<year>20\d{2})[-年\/](?<month>\d{1,2})(?:[-月\/](?<day>\d{1,2}))?/u', $claim, $match)
            ? sprintf('%04d-%02d-%02d', $match['year'], $match['month'], ($match['day'] ?? '') !== '' ? $match['day'] : 1) : null;
        $scoped = $values->filter(function (array $fact) use ($claim, $date): bool {
            $value = (array) ($fact['value'] ?? []);
            if ($date !== null && (($value['valid_from'] ?? null) > $date || (($value['valid_to'] ?? null) !== null && $value['valid_to'] < $date))) {
                return false;
            }
            $scope = array_values(array_filter(array_map(
                fn (mixed $term): string => $this->scalarString($term),
                (array) ($value['scope'] ?? []),
            )));

            return $scope === [] || collect($scope)->contains(fn (string $term): bool => str_contains($this->normalized($claim), $this->normalized($term)));
        });
        if ($scoped->isEmpty() && $values->count() === 1) {
            return $values;
        }
        if ($scoped->count() > 1 && $scoped->map(fn (array $fact): string => $this->normalized((string) data_get($fact, 'value.canonical_answer')))->unique()->count() === 1) {
            return collect([$scoped->first()]);
        }

        return $scoped;
    }

    /** @param array<string,mixed> $fact @return array<string,mixed> */
    private function factResult(array $fact, string $status, string $claim, string $standardAnswer = '', string $method = 'applicability'): array
    {
        return [
            'stable_key' => (string) ($fact['stable_key'] ?? ''), 'label' => (string) ($fact['label'] ?? ''), 'status' => $status,
            'claim_count' => 1, 'article_claim' => $claim, 'standard_answer' => $standardAnswer ?: (string) data_get($fact, 'value.canonical_answer', ''),
            'comparison_method' => $method, 'importance' => (string) ($fact['importance'] ?? 'normal'),
            'revision_id' => (int) ($fact['revision_id'] ?? 0), 'revision_version' => (int) ($fact['revision_version'] ?? 0),
            'knowledge_base_id' => (int) ($fact['knowledge_base_id'] ?? 0), 'source_hash' => (string) ($fact['source_hash'] ?? ''),
            'evidence' => (array) ($fact['evidence'] ?? []),
        ];
    }

    private function normalized(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', $text));
    }

    private function scalarString(mixed $value): string
    {
        return is_scalar($value) || $value instanceof \Stringable ? trim((string) $value) : '';
    }
}
