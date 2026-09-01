<?php

namespace App\Services\GeoFlow;

use RuntimeException;

class ArticleAiQualityPrincipleCompiler
{
    public const VERSION = 'article-quality-principles-2.1.0';

    private const COMPATIBLE_VERSIONS = [
        self::VERSION,
        'article-quality-principles-2.0.0',
    ];

    private const DEPRECATED_RULE_IDS = [
        'CN-AIGC-LABEL-04-06',
        'CN-AIGC-LABEL-09-10',
    ];

    private const UNIVERSAL_RULE_IDS = [
        'CN-AD-LAW-04',
        'CN-AD-LAW-08',
        'CN-AD-LAW-09',
        'CN-AD-ABS-GUIDE',
        'CN-AD-LAW-11',
        'CN-AD-CITATION-GUIDE',
        'CN-AD-LAW-28',
        'CN-AD-LAW-34',
        'CN-INTERNET-AD-07-09',
        'CN-INTERNET-AD-13-14',
    ];

    private const SPECIALIZED_RULE_KEYWORDS = [
        'CN-AD-LAW-12' => ['专利', 'patent'],
        'CN-AD-LAW-16-18' => ['医疗', '药品', '医药', '器械', '保健', '治疗', '治愈', '健康'],
        'CN-AD-LAW-24' => ['教育', '培训', '课程', '升学', '考试', '证书'],
        'CN-AD-LAW-25' => ['招商', '投资', '收益', '回报', '理财', '融资'],
        'CN-AD-LAW-26' => ['房地产', '房产', '楼盘', '住宅', '商铺', '公寓'],
    ];

    /** @param array<string,mixed> $article @param array<string,mixed> $rules @param list<mixed> $knowledgeSources @param array<string,mixed> $publicationContext @return array<string,mixed> */
    public function compile(
        array $article,
        array $rules,
        array $knowledgeSources,
        array $publicationContext = [],
    ): array {
        $surface = mb_strtolower(implode("\n", array_map(
            static fn (string $field): string => (string) ($article[$field] ?? ''),
            ['title', 'excerpt', 'content', 'keywords', 'meta_description'],
        )), 'UTF-8');
        $selectedIds = array_fill_keys(self::UNIVERSAL_RULE_IDS, true);
        foreach (self::SPECIALIZED_RULE_KEYWORDS as $ruleId => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($surface, mb_strtolower($keyword, 'UTF-8'))) {
                    $selectedIds[$ruleId] = true;
                    break;
                }
            }
        }
        $selectedRules = array_values(array_filter(
            is_array($rules['rules'] ?? null) ? $rules['rules'] : [],
            static fn (mixed $rule): bool => is_array($rule) && isset($selectedIds[(string) ($rule['id'] ?? '')]),
        ));
        $rulesDocument = array_replace($rules, ['rules' => $selectedRules]);
        $rulesHash = hash('sha256', json_encode($rulesDocument, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'version' => self::VERSION,
            'knowledge_sources' => array_values($knowledgeSources),
            'advertising_rules_version' => (string) ($rules['version'] ?? 'unknown'),
            'advertising_rules_hash' => $rulesHash,
            'advertising_rules' => $rulesDocument,
            'selected_rule_ids' => array_values(array_map(
                static fn (array $rule): string => (string) ($rule['id'] ?? ''),
                $selectedRules,
            )),
            'compiled_at' => now()->toIso8601String(),
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    public function rules(array $snapshot): array
    {
        $rules = is_array($snapshot['advertising_rules'] ?? null) ? $snapshot['advertising_rules'] : [];
        $expectedHash = (string) ($snapshot['advertising_rules_hash'] ?? '');
        $actualHash = hash('sha256', json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (! in_array((string) ($snapshot['version'] ?? ''), self::COMPATIBLE_VERSIONS, true)
            || $expectedHash === ''
            || ! hash_equals($expectedHash, $actualHash)
            || ! is_array($rules['rules'] ?? null)) {
            throw new RuntimeException('principle_snapshot_invalid');
        }

        return array_replace($rules, [
            'rules' => array_values(array_filter(
                $rules['rules'],
                static fn (mixed $rule): bool => is_array($rule)
                    && ! in_array((string) ($rule['id'] ?? ''), self::DEPRECATED_RULE_IDS, true),
            )),
        ]);
    }
}
