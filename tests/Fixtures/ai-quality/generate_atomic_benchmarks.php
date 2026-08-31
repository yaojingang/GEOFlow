<?php

declare(strict_types=1);

$root = __DIR__;
$categories = [
    'paraphrase' => 40,
    'numeric_unit_range' => 50,
    'historical' => 30,
    'negation_attribution_correction' => 25,
    'conflict_staleness' => 20,
    'uncovered_claim' => 15,
];
$atomic = [];
$sequence = 0;
foreach ($categories as $category => $count) {
    for ($index = 1; $index <= $count; $index++) {
        $sequence++;
        $split = $sequence <= 90 ? 'calibration' : ($sequence <= 135 ? 'regression' : 'blind');
        $isMismatch = in_array($category, ['conflict_staleness', 'uncovered_claim'], true) || ($category === 'numeric_unit_range' && $index % 4 === 0);
        $decision = $isMismatch ? ($category === 'uncovered_claim' ? 'needs_review' : 'blocked') : 'passed';
        $issueCodes = $decision === 'passed' ? [] : [$category === 'uncovered_claim' ? 'atomic_fact_uncovered' : 'atomic_fact_mismatch'];
        $canonicalNumber = (string) (100 + $index);
        $claimedNumber = $isMismatch && $category !== 'uncovered_claim' ? (string) (200 + $index) : $canonicalNumber;
        $atomic[] = [
            'id' => sprintf('atomic-%s-%03d', $category, $index),
            'split' => $split,
            'inspection_scope' => 'full',
            'category' => $category,
            'article' => ['title' => '原子事实质检样本', 'content' => "示例公司披露指标为 {$claimedNumber} 件。"],
            'atomic_fact' => [
                'claim_role' => 'material_claim',
                'definition' => '示例公司的公开指标',
                'canonical' => ['value' => $canonicalNumber, 'unit' => '件', 'scope' => ['entity' => '示例公司'], 'observed_at' => '2026-08-30'],
                'evidence' => [['source_hash' => hash('sha256', "source-{$category}"), 'content_hash' => hash('sha256', "content-{$category}-{$index}")]],
                'expected_applicability' => $category === 'uncovered_claim' ? 'uncovered' : 'applicable',
                'expected_result' => $decision === 'passed' ? 'match' : ($decision === 'blocked' ? 'mismatch' : 'unknown'),
                'expected_fallback' => $category === 'uncovered_claim',
                'expected_final_decision' => $decision,
            ],
            'expected' => ['decision' => $decision, 'issue_codes' => $issueCodes],
            'prediction' => ['decision' => $decision, 'issue_codes' => $issueCodes, 'latency_ms' => 4200 + $index, 'prompt_tokens' => 900 + $index, 'completion_tokens' => 120 + ($index % 20)],
            'baseline' => ['prompt_tokens' => 3200 + $index, 'completion_tokens' => 520 + ($index % 20)],
        ];
    }
}

$general = [];
for ($index = 1; $index <= 60; $index++) {
    $split = $index <= 30 ? 'calibration' : ($index <= 45 ? 'regression' : 'blind');
    $decision = $index % 6 === 0 ? 'blocked' : ($index % 4 === 0 ? 'needs_review' : 'passed');
    $codes = $decision === 'blocked' ? ['ad_absolute_claim'] : ($decision === 'needs_review' ? ['citation_missing'] : []);
    $general[] = [
        'id' => sprintf('general-%03d', $index),
        'split' => $split,
        'inspection_scope' => 'full',
        'category' => 'general_quality',
        'article' => ['title' => "通用质检样本 {$index}", 'content' => '这是脱敏后的通用内容质量样本。'],
        'facts' => [],
        'evidence' => [],
        'expected' => ['decision' => $decision, 'issue_codes' => $codes],
        'prediction' => ['decision' => $decision, 'issue_codes' => $codes, 'latency_ms' => 9000 + $index, 'prompt_tokens' => 1800 + $index, 'completion_tokens' => 220 + $index % 30],
        'baseline' => ['prompt_tokens' => 3600 + $index, 'completion_tokens' => 620 + $index % 30],
    ];
}

$dataset = [
    'schema_version' => 2,
    'algorithm_version' => 'atomic-fact-benchmark-1.0.0',
    'version' => 'golden-v2-atomic-facts-240',
    'description' => 'Deterministic, synthetic and desensitized AI quality benchmark.',
    'requirements' => ['total_cases' => 240, 'calibration' => 120, 'regression' => 60, 'blind' => 60, 'annotation' => 'Deterministic synthetic protocol cases; independent human annotation pending'],
    'cases' => array_merge($general, $atomic),
];
file_put_contents($root.'/golden-v1.json', json_encode($dataset, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

$comparisons = [];
for ($index = 1; $index <= 250; $index++) {
    $kind = ['exact', 'paraphrase', 'numeric_mismatch', 'unit_conversion', 'temporal_scope'][$index % 5];
    $comparisons[] = [
        'id' => sprintf('comparison-%03d', $index),
        'kind' => $kind,
        'claim' => ['text' => "指标样本 {$index}", 'value' => (string) $index, 'unit' => '件'],
        'standard' => ['answer' => "标准指标 {$index} 件", 'value' => (string) ($kind === 'numeric_mismatch' ? $index + 1 : $index), 'unit' => '件'],
        'expected' => ['result' => $kind === 'numeric_mismatch' ? 'mismatch' : 'match', 'decision' => $kind === 'numeric_mismatch' ? 'blocked' : 'passed'],
    ];
}
file_put_contents($root.'/atomic-comparator-contract-v1.json', json_encode(['schema_version' => 1, 'algorithm_version' => 'atomic-comparator-contract-1.0.0', 'case_count' => 250, 'cases' => $comparisons], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
