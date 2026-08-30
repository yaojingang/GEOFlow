<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleAiQualityCheck;
use Illuminate\Support\Str;

final class ArticleAiOptimizationPatchValidator
{
    public const VERSION = '1.2.2';

    private const FIELDS = ['title', 'excerpt', 'content', 'keywords', 'meta_description'];

    private const FIELD_LIMITS = [
        'title' => 255,
        'excerpt' => 10000,
        'content' => ArticleRiskScanner::MAX_CONTENT_CHARACTERS,
        'keywords' => 500,
        'meta_description' => 500,
    ];

    private const AUTO_FIXABLE_CODES = [
        'knowledge_contradiction',
        'data_mismatch',
        'citation_scope_mismatch',
        'ad_absolute_claim',
        'ad_false_or_misleading',
        'content_integrity',
        'citation_missing',
        'unsupported_claim',
    ];

    private const MAX_ISSUE_CLUSTER_GAP = 32;

    public function __construct(
        private readonly ArticleFactCandidateExtractor $factExtractor,
        private readonly ArticleRiskScanner $riskScanner,
    ) {}

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  list<array<string,mixed>>  $operations
     * @param  array{edit_budget_percent?:int,max_edit_characters?:int}  $policy
     * @return array{candidate:array<string,string>,operations:list<array<string,mixed>>,changed_characters:int,changed_percent:float,changed_operation_count:int,validation:array<string,mixed>}
     */
    public function validateAndApply(
        array $snapshot,
        ArticleAiQualityCheck $sourceCheck,
        array $operations,
        array $policy,
    ): array {
        if ($operations === [] || count($operations) > 50) {
            throw new ArticleAiOptimizationException('article_ai_optimization_operation_count', httpStatus: 422);
        }

        $article = $this->normalizeSnapshot($snapshot);
        $issues = $this->issues($sourceCheck);
        $evidence = $this->evidence($sourceCheck);
        $validatedOperations = [];
        $rangesByField = [];
        $changedCharacters = 0;

        foreach ($operations as $operation) {
            if (! is_array($operation)) {
                throw new ArticleAiOptimizationException('article_ai_optimization_patch_structure', httpStatus: 422);
            }
            $validated = $this->validateOperation($article, $operation, $issues, $evidence);
            $field = $validated['field'];
            foreach ($rangesByField[$field] ?? [] as $range) {
                if ($validated['replace_start'] < $range['end'] && $validated['replace_end'] > $range['start']) {
                    throw new ArticleAiOptimizationException('article_ai_optimization_overlapping_patch', httpStatus: 422);
                }
            }
            $rangesByField[$field][] = [
                'start' => $validated['replace_start'],
                'end' => $validated['replace_end'],
            ];
            $changedCharacters += max(
                $validated['replace_end'] - $validated['replace_start'],
                Str::length($validated['replacement']),
            );
            $validatedOperations[] = $validated;
        }

        $totalCharacters = max(1, array_sum(array_map(
            static fn (string $value): int => Str::length($value),
            $article,
        )));
        $changedPercent = round(($changedCharacters / $totalCharacters) * 100, 2);
        $budgetPercent = max(1, min(100, (int) ($policy['edit_budget_percent'] ?? 15)));
        $maxCharacters = max(1, (int) ($policy['max_edit_characters'] ?? 8000));
        if ($changedCharacters > $maxCharacters || $changedPercent > $budgetPercent) {
            throw new ArticleAiOptimizationException('article_ai_optimization_edit_budget_exceeded', httpStatus: 422);
        }

        $candidate = $article;
        $operationsByField = collect($validatedOperations)
            ->groupBy('field');
        foreach ($operationsByField as $field => $fieldOperations) {
            $value = $candidate[(string) $field];
            foreach ($fieldOperations->sortByDesc('replace_start') as $operation) {
                $value = mb_substr($value, 0, (int) $operation['replace_start'], 'UTF-8')
                    .(string) $operation['replacement']
                    .mb_substr($value, (int) $operation['replace_end'], null, 'UTF-8');
            }
            $candidate[(string) $field] = $value;
        }

        $this->validateCandidate($article, $candidate);

        return [
            'candidate' => $candidate,
            'operations' => $validatedOperations,
            'changed_characters' => $changedCharacters,
            'changed_percent' => $changedPercent,
            'changed_operation_count' => count($validatedOperations),
            'validation' => [
                'version' => self::VERSION,
                'edit_budget_percent' => $budgetPercent,
                'max_edit_characters' => $maxCharacters,
                'risk_status_before' => $this->riskScanner->scan($article)['status'],
                'risk_status_after' => $this->riskScanner->scan($candidate)['status'],
            ],
        ];
    }

    /** @param array<string,string> $article @param array<string,mixed> $operation @param array<string,array<string,mixed>> $issues @param array<string,array<string,mixed>> $evidence @return array<string,mixed> */
    private function validateOperation(array $article, array $operation, array $issues, array $evidence): array
    {
        $field = (string) ($operation['field'] ?? '');
        if (! in_array($field, self::FIELDS, true)) {
            throw new ArticleAiOptimizationException('article_ai_optimization_field_forbidden', httpStatus: 422);
        }

        $issueCodes = $this->stringList($operation['issue_codes'] ?? []);
        $rootCauseKeys = $this->stringList($operation['root_cause_keys'] ?? []);
        if ($issueCodes === [] || $rootCauseKeys === []) {
            throw new ArticleAiOptimizationException('article_ai_optimization_issue_reference_missing', httpStatus: 422);
        }
        $matchingIssues = collect($issues)->filter(static function (array $issue) use ($field, $issueCodes, $rootCauseKeys): bool {
            return (string) ($issue['field'] ?? '') === $field
                && (string) ($issue['location_status'] ?? '') === 'resolved'
                && in_array((string) ($issue['code'] ?? ''), $issueCodes, true)
                && in_array((string) ($issue['root_cause_key'] ?? ''), $rootCauseKeys, true);
        });
        if ($matchingIssues->isEmpty()
            || collect($issueCodes)->contains(static fn (string $code): bool => ! in_array($code, self::AUTO_FIXABLE_CODES, true))
            || $matchingIssues->pluck('code')->map('strval')->unique()->sort()->values()->all() !== collect($issueCodes)->unique()->sort()->values()->all()
            || $matchingIssues->pluck('root_cause_key')->map('strval')->unique()->sort()->values()->all() !== collect($rootCauseKeys)->unique()->sort()->values()->all()) {
            throw new ArticleAiOptimizationException('article_ai_optimization_issue_not_auto_fixable', httpStatus: 422);
        }
        $anchors = $matchingIssues
            ->map(static fn (array $issue): array => [
                'start' => (int) ($issue['start_offset'] ?? -1),
                'end' => (int) ($issue['end_offset'] ?? -1),
            ])
            ->unique(static fn (array $anchor): string => $anchor['start'].':'.$anchor['end'])
            ->sortBy('start')
            ->values();
        if ($anchors->contains(static fn (array $anchor): bool => $anchor['start'] < 0 || $anchor['end'] <= $anchor['start'])) {
            throw new ArticleAiOptimizationException('article_ai_optimization_offset_invalid', httpStatus: 422);
        }
        $anchorStart = (int) $anchors[0]['start'];
        $anchorEnd = (int) $anchors[0]['end'];
        foreach ($anchors->slice(1) as $anchor) {
            if ((int) $anchor['start'] - $anchorEnd > self::MAX_ISSUE_CLUSTER_GAP) {
                throw new ArticleAiOptimizationException('article_ai_optimization_issue_anchor_ambiguous', httpStatus: 422);
            }
            $anchorEnd = max($anchorEnd, (int) $anchor['end']);
        }
        $fieldLength = Str::length($article[$field]);
        if ($anchorStart < 0 || $anchorEnd <= $anchorStart || $anchorEnd > $fieldLength) {
            throw new ArticleAiOptimizationException('article_ai_optimization_offset_invalid', httpStatus: 422);
        }
        $replaceStart = $anchorStart;
        $replaceEnd = $anchorEnd;
        if (! $this->sameParagraph($article[$field], $replaceStart, $replaceEnd)) {
            throw new ArticleAiOptimizationException('article_ai_optimization_cross_paragraph_patch', httpStatus: 422);
        }

        $evidenceKeys = $this->stringList($operation['evidence_keys'] ?? []);
        $allowedEvidenceKeys = $matchingIssues
            ->pluck('evidence_keys')
            ->flatten()
            ->map('strval')
            ->filter()
            ->unique();
        if (collect($evidenceKeys)->contains(static fn (string $key): bool => ! isset($evidence[$key]))
            || collect($evidenceKeys)->contains(static fn (string $key): bool => ! $allowedEvidenceKeys->contains($key))) {
            throw new ArticleAiOptimizationException('article_ai_optimization_evidence_invalid', httpStatus: 422);
        }

        $oldText = mb_substr($article[$field], $replaceStart, $replaceEnd - $replaceStart, 'UTF-8');
        $oldTextHash = hash('sha256', $oldText);

        $replacement = (string) ($operation['replacement'] ?? '');
        if (preg_match('/<\s*(script|iframe|object|embed)|\bon\w+\s*=|javascript\s*:/iu', $replacement) === 1) {
            throw new ArticleAiOptimizationException('article_ai_optimization_dangerous_markup', httpStatus: 422);
        }

        return [
            'field' => $field,
            'anchor_start' => $anchorStart,
            'anchor_end' => $anchorEnd,
            'replace_start' => $replaceStart,
            'replace_end' => $replaceEnd,
            'old_text_hash' => $oldTextHash,
            'replacement' => $replacement,
            'issue_codes' => $issueCodes,
            'root_cause_keys' => $rootCauseKeys,
            'evidence_keys' => $evidenceKeys,
            'reason' => $this->safeReason($matchingIssues->pluck('code')->map('strval')->all()),
        ];
    }

    /** @param list<string> $issueCodes */
    private function safeReason(array $issueCodes): string
    {
        $labels = [
            'knowledge_contradiction' => '修正知识依据冲突',
            'data_mismatch' => '修正数据不一致',
            'citation_scope_mismatch' => '修正引用范围不一致',
            'ad_absolute_claim' => '收敛绝对化承诺',
            'ad_false_or_misleading' => '修正误导性表达',
            'content_integrity' => '修正内容完整性问题',
            'citation_missing' => '收敛缺少引用的表述',
            'unsupported_claim' => '收敛缺少依据的主张',
        ];

        return collect($issueCodes)
            ->unique()
            ->map(static fn (string $code): string => $labels[$code] ?? '修正质检定位问题')
            ->unique()
            ->implode('；');
    }

    /** @param array<string,string> $before @param array<string,string> $after */
    private function validateCandidate(array $before, array $after): void
    {
        foreach (self::FIELDS as $field) {
            if (Str::length($after[$field]) > self::FIELD_LIMITS[$field]) {
                throw new ArticleAiOptimizationException('article_ai_optimization_field_too_long', httpStatus: 422);
            }
        }
        if (trim($after['title']) === '' || trim($after['content']) === '') {
            throw new ArticleAiOptimizationException('article_ai_optimization_required_content_missing', httpStatus: 422);
        }
        if ($this->urls($after) !== $this->urls($before)) {
            throw new ArticleAiOptimizationException('article_ai_optimization_new_link', httpStatus: 422);
        }
        if ($this->structureSignature($after['content']) !== $this->structureSignature($before['content'])) {
            throw new ArticleAiOptimizationException('article_ai_optimization_structure_changed', httpStatus: 422);
        }
        if (Str::length($after['content']) < (int) floor(Str::length($before['content']) * 0.75)) {
            throw new ArticleAiOptimizationException('article_ai_optimization_content_too_short', httpStatus: 422);
        }

        $beforeFacts = collect($this->factExtractor->extract($before, 1000))->keyBy('claim_hash');
        $newHighFacts = collect($this->factExtractor->extract($after, 1000))
            ->filter(static fn (array $fact): bool => (string) ($fact['materiality'] ?? '') === 'high')
            ->reject(static fn (array $fact): bool => $beforeFacts->has((string) ($fact['claim_hash'] ?? '')));
        if ($newHighFacts->isNotEmpty()) {
            throw new ArticleAiOptimizationException('article_ai_optimization_new_material_fact', httpStatus: 422);
        }

        $riskBefore = $this->riskScanner->scan($before);
        $riskAfter = $this->riskScanner->scan($after);
        $riskRanks = ['clean' => 0, 'warning' => 1, 'blocked' => 2];
        if (($riskRanks[(string) $riskAfter['status']] ?? 3) > ($riskRanks[(string) $riskBefore['status']] ?? 3)) {
            throw new ArticleAiOptimizationException('article_ai_optimization_risk_increased', httpStatus: 422);
        }
    }

    /** @param array<string,mixed> $snapshot @return array<string,string> */
    private function normalizeSnapshot(array $snapshot): array
    {
        return collect(self::FIELDS)
            ->mapWithKeys(static fn (string $field): array => [$field => (string) ($snapshot[$field] ?? '')])
            ->all();
    }

    /** @return array<string,array<string,mixed>> */
    private function issues(ArticleAiQualityCheck $check): array
    {
        return collect(is_array($check->issues) ? $check->issues : [])
            ->filter(static fn (mixed $issue): bool => is_array($issue))
            ->flatMap(static fn (array $issue): array => self::expandIssueOccurrences($issue))
            ->mapWithKeys(static fn (array $issue): array => [(string) $issue['root_cause_key'] => $issue])
            ->all();
    }

    /**
     * Quality report v2 can attach several resolved locations to one root cause.
     * Each location becomes an independently addressable repair target.
     *
     * @param  array<string,mixed>  $issue
     * @return list<array<string,mixed>>
     */
    public static function expandIssueOccurrences(array $issue): array
    {
        $occurrences = collect(is_array($issue['occurrences'] ?? null) ? $issue['occurrences'] : [])
            ->filter(static fn (mixed $occurrence): bool => is_array($occurrence))
            ->map(static fn (array $occurrence): array => array_replace($issue, $occurrence))
            ->values();
        if ($occurrences->isEmpty()) {
            $occurrences = collect([$issue]);
        }

        $occurrences = $occurrences
            ->unique(static fn (array $occurrence): string => implode(':', [
                (string) ($occurrence['field'] ?? ''),
                (int) ($occurrence['start_offset'] ?? -1),
                (int) ($occurrence['end_offset'] ?? -1),
                (string) ($occurrence['quote'] ?? ''),
            ]))
            ->values();
        $baseRootKey = self::rootCauseKeyForIssue($issue);
        $hasMultipleOccurrences = $occurrences->count() > 1;

        return $occurrences
            ->map(static function (array $occurrence) use ($baseRootKey, $hasMultipleOccurrences): array {
                unset($occurrence['occurrences']);
                $occurrence['root_cause_key'] = $hasMultipleOccurrences
                    ? $baseRootKey.'@'.implode(':', [
                        (string) ($occurrence['field'] ?? ''),
                        (int) ($occurrence['start_offset'] ?? -1),
                        (int) ($occurrence['end_offset'] ?? -1),
                    ])
                    : $baseRootKey;

                return $occurrence;
            })
            ->all();
    }

    /** @param array<string,mixed> $issue */
    public static function rootCauseKeyForIssue(array $issue): string
    {
        $rootKey = trim((string) ($issue['root_cause_key'] ?? ''));
        if ($rootKey !== '') {
            return $rootKey;
        }

        return implode(':', [
            (string) ($issue['code'] ?? ''),
            (string) ($issue['field'] ?? ''),
            (int) ($issue['start_offset'] ?? -1),
        ]);
    }

    /** @return array<string,array<string,mixed>> */
    private function evidence(ArticleAiQualityCheck $check): array
    {
        return collect(is_array($check->evidence_snapshot) ? $check->evidence_snapshot : [])
            ->filter(static fn (mixed $item): bool => is_array($item))
            ->mapWithKeys(static function (array $item): array {
                $key = (string) ($item['stable_key'] ?? $item['id'] ?? '');

                return $key !== '' ? [$key => $item] : [];
            })
            ->all();
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return collect($value)
            ->filter(static fn (mixed $item): bool => is_scalar($item))
            ->map(static fn (mixed $item): string => trim((string) $item))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function sameParagraph(string $value, int $start, int $end): bool
    {
        return preg_match('/\R/u', mb_substr($value, $start, $end - $start, 'UTF-8')) !== 1;
    }

    /** @param array<string,string> $snapshot @return list<string> */
    private function urls(array $snapshot): array
    {
        preg_match_all('/(?:https?:\/\/|mailto:)[^\s)\]<>"\']+/iu', implode("\n", $snapshot), $matches);
        $urls = array_values(array_unique($matches[0] ?? []));
        sort($urls);

        return $urls;
    }

    /** @return array<string,mixed> */
    private function structureSignature(string $content): array
    {
        $lines = preg_split('/\R/u', $content) ?: [];
        preg_match_all('/^#{1,6}\s+.+$/mu', $content, $headings);
        preg_match_all('/!\[[^\]]*\]\([^)]+\)/u', $content, $images);
        preg_match_all('/(?<!!)\[[^\]]+\]\([^)]+\)/u', $content, $links);
        preg_match_all('/^\s*(```|~~~)[^\r\n]*\R[\s\S]*?^\s*\1\s*$/mu', $content, $codeBlocks);
        $blockLines = collect($lines)->map(static function (string $line, int $index): ?array {
            if (preg_match('/^(\s*)([-+*]|\d+[.)])(\s+)/u', $line, $list) === 1) {
                return ['line' => $index, 'type' => 'list', 'prefix' => $list[1].$list[2].$list[3]];
            }
            if (preg_match('/^(\s*>+\s*)/u', $line, $quote) === 1) {
                return ['line' => $index, 'type' => 'quote', 'prefix' => $quote[1]];
            }
            if (preg_match('/^\s{0,3}((\*\s*){3,}|(-\s*){3,}|(_\s*){3,})$/u', $line) === 1) {
                return ['line' => $index, 'type' => 'horizontal_rule', 'hash' => hash('sha256', $line)];
            }
            if (str_contains($line, '|')) {
                return [
                    'line' => $index,
                    'type' => 'table',
                    'pipes' => substr_count($line, '|'),
                    'separator' => preg_match('/^\s*\|?\s*:?-{3,}:?\s*(\|\s*:?-{3,}:?\s*)+\|?\s*$/u', $line) === 1,
                ];
            }

            return null;
        })->filter()->values()->all();

        return [
            'headings' => array_values($headings[0] ?? []),
            'images' => array_values($images[0] ?? []),
            'links' => array_values($links[0] ?? []),
            'code_blocks' => array_map(
                static fn (string $block): string => hash('sha256', str_replace(["\r\n", "\r"], "\n", $block)),
                $codeBlocks[0] ?? [],
            ),
            'block_lines' => $blockLines,
        ];
    }
}
