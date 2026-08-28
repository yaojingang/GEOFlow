<?php

namespace App\Services\GeoFlow;

use Illuminate\Support\Str;
use UnexpectedValueException;

class ArticleAiQualityResultValidator
{
    private const CODES = [
        'knowledge_contradiction', 'data_mismatch', 'unsupported_claim', 'citation_missing',
        'citation_scope_mismatch', 'ad_absolute_claim', 'ad_false_or_misleading',
        'ad_industry_specific', 'ad_identifiability', 'ai_generated_disclosure', 'content_integrity',
    ];

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $article
     * @param  list<array<string, mixed>>  $facts
     * @param  list<array<string, mixed>>  $evidence
     * @param  array<string, mixed>  $rules
     * @param  array{start_offset?:int,end_offset?:int}|null  $segment
     * @return array<string, mixed>
     */
    public function validate(
        array $result,
        array $article,
        array $facts,
        array $evidence,
        array $rules,
        ?array $segment = null,
    ): array {
        $this->assertExactFields(
            $result,
            ['summary', 'promotion_context', 'knowledge_coverage', 'issues', 'uncertainties'],
            'ai_quality_result',
        );
        $promotion = (string) ($result['promotion_context'] ?? '');
        $coverage = (string) ($result['knowledge_coverage'] ?? '');
        if (! in_array($promotion, ['informational', 'promotional', 'mixed', 'uncertain'], true)
            || ! in_array($coverage, ['sufficient', 'partial', 'insufficient'], true)
            || ! is_array($result['issues'] ?? null)
            || ! is_array($result['uncertainties'] ?? null)) {
            throw new UnexpectedValueException('ai_quality_result_structure_invalid');
        }

        $factIds = array_fill_keys(array_map('strval', array_column($facts, 'id')), true);
        $factsById = [];
        foreach ($facts as $fact) {
            if (is_array($fact) && trim((string) ($fact['id'] ?? '')) !== '') {
                $factsById[(string) $fact['id']] = $fact;
            }
        }
        $evidenceIds = array_fill_keys(array_map('strval', array_column($evidence, 'id')), true);
        $legalRefs = [];
        foreach ((array) ($rules['rules'] ?? []) as $rule) {
            if (is_array($rule)) {
                $legalRefs[(string) ($rule['id'] ?? '')] = true;
                $legalRefs[(string) ($rule['source'] ?? '')] = true;
                $legalRefs[(string) ($rule['title'] ?? '')] = true;
            }
        }

        $issues = [];
        foreach ($result['issues'] as $rawIssue) {
            if (! is_array($rawIssue)) {
                throw new UnexpectedValueException('ai_quality_issue_structure_invalid');
            }
            $this->assertExactFields($rawIssue, [
                'code', 'severity', 'field', 'quote', 'paragraph_index', 'heading',
                'fact_candidate_id', 'article_claim', 'evidence_value', 'knowledge_refs',
                'legal_refs', 'reason', 'suggestion',
            ], 'ai_quality_issue');

            $code = (string) ($rawIssue['code'] ?? '');
            $severity = (string) ($rawIssue['severity'] ?? '');
            $field = (string) ($rawIssue['field'] ?? '');
            $quote = Str::limit(trim((string) ($rawIssue['quote'] ?? '')), 200, '');
            if (! in_array($code, self::CODES, true)
                || ! in_array($severity, ['critical', 'high', 'medium', 'low'], true)
                || ! in_array($field, ['title', 'excerpt', 'content', 'keywords', 'meta_description'], true)
                || $quote === '') {
                throw new UnexpectedValueException('ai_quality_issue_value_invalid');
            }

            $factId = trim((string) ($rawIssue['fact_candidate_id'] ?? ''));
            $knowledgeRefs = array_values(array_unique(array_map('strval', is_array($rawIssue['knowledge_refs'] ?? null) ? $rawIssue['knowledge_refs'] : [])));
            $issueLegalRefs = array_values(array_unique(array_map('strval', is_array($rawIssue['legal_refs'] ?? null) ? $rawIssue['legal_refs'] : [])));
            $referencesValid = ($factId === '' || isset($factIds[$factId]))
                && collect($knowledgeRefs)->every(fn (string $ref): bool => isset($evidenceIds[$ref]))
                && collect($issueLegalRefs)->every(fn (string $ref): bool => isset($legalRefs[$ref]));

            $location = $this->locate(
                (string) ($article[$field] ?? ''),
                $quote,
                $field === 'content' ? $segment : null,
            );
            if (in_array($code, ['knowledge_contradiction', 'data_mismatch'], true) && $knowledgeRefs !== []) {
                $severity = $this->atLeast($severity, 'high');
            }
            $fact = $factsById[$factId] ?? null;
            if ($referencesValid
                && in_array($code, ['knowledge_contradiction', 'data_mismatch'], true)
                && is_array($fact)
                && ($fact['materiality'] ?? null) === 'high'
                && in_array((string) ($fact['type'] ?? ''), ['amount', 'percentage', 'guarantee'], true)) {
                $severity = 'critical';
            }
            if ($code === 'ad_false_or_misleading' && $severity === 'low') {
                $severity = 'medium';
            }

            $issues[] = [
                'code' => $code,
                'severity' => $severity,
                'field' => $field,
                'quote' => $quote,
                'paragraph_index' => $field === 'content' ? $this->paragraphIndex((string) ($article[$field] ?? ''), $location['start_offset']) : 0,
                'heading' => Str::limit(trim((string) ($rawIssue['heading'] ?? '')), 200, ''),
                'fact_candidate_id' => $factId,
                'article_claim' => Str::limit(trim((string) ($rawIssue['article_claim'] ?? '')), 500, ''),
                'evidence_value' => Str::limit(trim((string) ($rawIssue['evidence_value'] ?? '')), 1000, ''),
                'knowledge_refs' => $knowledgeRefs,
                'legal_refs' => $issueLegalRefs,
                'reason' => Str::limit(trim((string) ($rawIssue['reason'] ?? '')), 2000, ''),
                'suggestion' => Str::limit(trim((string) ($rawIssue['suggestion'] ?? '')), 2000, ''),
                'location_status' => $location['status'],
                'start_offset' => $location['start_offset'],
                'end_offset' => $location['end_offset'],
                'references_valid' => $referencesValid,
            ];
        }

        return [
            'summary' => Str::limit(trim((string) ($result['summary'] ?? '')), 2000, ''),
            'promotion_context' => $promotion,
            'knowledge_coverage' => $coverage,
            'issues' => $issues,
            'uncertainties' => $this->uncertainties($result['uncertainties']),
        ];
    }

    /** @return array{status:string,start_offset:?int,end_offset:?int} */
    private function locate(string $text, string $quote, ?array $segment = null): array
    {
        $textLength = mb_strlen($text, 'UTF-8');
        $rangeStart = max(0, min($textLength, (int) ($segment['start_offset'] ?? 0)));
        $rangeEnd = max($rangeStart, min($textLength, (int) ($segment['end_offset'] ?? $textLength)));
        $searchText = mb_substr($text, $rangeStart, $rangeEnd - $rangeStart, 'UTF-8');
        $relativeOffset = mb_strpos($searchText, $quote, 0, 'UTF-8');
        if ($relativeOffset === false) {
            return ['status' => 'unresolved', 'start_offset' => null, 'end_offset' => null];
        }

        $offset = $rangeStart + $relativeOffset;
        $next = mb_strpos(
            $searchText,
            $quote,
            $relativeOffset + max(1, mb_strlen($quote, 'UTF-8')),
            'UTF-8',
        );
        if ($next !== false) {
            return ['status' => 'unresolved', 'start_offset' => null, 'end_offset' => null];
        }

        return ['status' => 'resolved', 'start_offset' => $offset, 'end_offset' => $offset + mb_strlen($quote, 'UTF-8')];
    }

    private function paragraphIndex(string $text, ?int $offset): int
    {
        if ($offset === null) {
            return 0;
        }

        $prefix = mb_substr($text, 0, $offset, 'UTF-8');
        $paragraphs = preg_split('/\n\s*\n/u', $prefix) ?: [];

        return max(1, count($paragraphs));
    }

    private function atLeast(string $severity, string $minimum): string
    {
        $rank = ['low' => 1, 'medium' => 2, 'high' => 3, 'critical' => 4];

        return ($rank[$severity] ?? 0) >= $rank[$minimum] ? $severity : $minimum;
    }

    /** @param array<int, mixed> $items */
    private function uncertainties(array $items): array
    {
        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $this->assertExactFields(
                $item,
                ['claim', 'materiality', 'reason', 'needed_evidence'],
                'ai_quality_uncertainty',
            );

            $materiality = (string) ($item['materiality'] ?? 'medium');
            $result[] = [
                'claim' => Str::limit(trim((string) ($item['claim'] ?? $item['subject'] ?? '')), 500, ''),
                'materiality' => in_array($materiality, ['high', 'medium', 'low'], true) ? $materiality : 'medium',
                'reason' => Str::limit(trim((string) ($item['reason'] ?? '')), 1000, ''),
                'needed_evidence' => Str::limit(trim((string) ($item['needed_evidence'] ?? '')), 1000, ''),
            ];
        }

        return $result;
    }

    /** @param array<string, mixed> $value @param list<string> $allowed */
    private function assertExactFields(array $value, array $allowed, string $prefix): void
    {
        $keys = array_keys($value);
        if (array_diff($keys, $allowed) !== []) {
            throw new UnexpectedValueException($prefix.'_unknown_field');
        }
        if (array_diff($allowed, $keys) !== []) {
            throw new UnexpectedValueException($prefix.'_missing_field');
        }
    }
}
