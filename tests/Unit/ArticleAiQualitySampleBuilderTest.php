<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualitySampleBuilder;
use Tests\TestCase;

class ArticleAiQualitySampleBuilderTest extends TestCase
{
    public function test_it_builds_a_stable_non_overlapping_sample_covering_regions_and_mandatory_claims(): void
    {
        $content = implode("\n\n", [
            '开头介绍产品背景和使用边界。',
            str_repeat('前部普通说明。', 40),
            '本产品价格为 199 元，并于 2026 年 8 月上线。',
            str_repeat('中部普通说明。', 60),
            '公司已通过 ISO 9001 认证，证书编号 ABC-2026。',
            str_repeat('后部普通说明。', 50),
            '结论部分汇总适用范围与风险提示。',
        ]);
        $snapshot = [
            'title' => '抽样算法测试',
            'excerpt' => '摘要内容',
            'meta_description' => '页面描述',
            'keywords' => '抽样,质检',
            'content' => $content,
        ];
        $facts = [
            [
                'field' => 'content',
                'quote' => '本产品价格为 199 元，并于 2026 年 8 月上线。',
                'claim_hash' => hash('sha256', 'price'),
                'materiality' => 'high',
                'start_offset' => mb_strpos($content, '本产品价格'),
                'end_offset' => mb_strpos($content, '本产品价格') + mb_strlen('本产品价格为 199 元，并于 2026 年 8 月上线。'),
            ],
            [
                'field' => 'content',
                'quote' => '公司已通过 ISO 9001 认证，证书编号 ABC-2026。',
                'claim_hash' => hash('sha256', 'qualification'),
                'materiality' => 'high',
                'start_offset' => mb_strpos($content, '公司已通过'),
                'end_offset' => mb_strpos($content, '公司已通过') + mb_strlen('公司已通过 ISO 9001 认证，证书编号 ABC-2026。'),
            ],
        ];

        $builder = new ArticleAiQualitySampleBuilder(maxCharacters: 1200, maxRanges: 12);
        $first = $builder->build($snapshot, $facts);
        $second = $builder->build($snapshot, $facts);

        $this->assertSame($first, $second);
        $this->assertLessThanOrEqual(1200, $first['checked_chars']);
        $this->assertLessThanOrEqual(12, $first['range_count']);
        $this->assertSame(2, $first['mandatory_claims_total']);
        $this->assertSame(2, $first['mandatory_claims_covered']);
        $this->assertFalse($first['mandatory_overflow']);
        $this->assertSame(['front', 'middle', 'back'], $first['regions_covered']);
        $this->assertSame(['title', 'excerpt', 'meta_description', 'keywords'], $first['metadata_fields_included']);

        $previousEnd = -1;
        foreach ($first['sampled_ranges'] as $range) {
            $this->assertGreaterThanOrEqual($previousEnd, $range['start_offset']);
            $this->assertSame(
                mb_substr($content, $range['start_offset'], $range['end_offset'] - $range['start_offset']),
                $range['content'],
            );
            $previousEnd = $range['end_offset'];
        }
    }

    public function test_it_marks_the_sample_unsafe_when_mandatory_ranges_exceed_the_budget(): void
    {
        $parts = [];
        $facts = [];
        for ($index = 0; $index < 16; $index++) {
            $quote = "第 {$index} 项关键事实为 ".str_repeat((string) $index, 60).' 元。';
            $offset = mb_strlen(implode("\n\n", $parts).($parts === [] ? '' : "\n\n"));
            $parts[] = $quote;
            $facts[] = [
                'field' => 'content',
                'quote' => $quote,
                'claim_hash' => hash('sha256', (string) $index),
                'materiality' => 'high',
                'start_offset' => $offset,
                'end_offset' => $offset + mb_strlen($quote),
            ];
        }

        $sample = (new ArticleAiQualitySampleBuilder(maxCharacters: 500, maxRanges: 4))->build([
            'title' => '超出预算',
            'content' => implode("\n\n", $parts),
        ], $facts);

        $this->assertTrue($sample['mandatory_overflow']);
        $this->assertLessThan($sample['mandatory_claims_total'], $sample['mandatory_claims_covered']);
        $this->assertLessThanOrEqual(500, $sample['checked_chars']);
        $this->assertLessThanOrEqual(4, $sample['range_count']);
    }

    public function test_it_always_covers_exact_deterministic_risk_matches_and_metadata_claims(): void
    {
        $riskWord = '零风险承诺';
        $content = str_repeat('普通说明。', 140).$riskWord.str_repeat('后续说明。', 140);
        $sample = (new ArticleAiQualitySampleBuilder(maxCharacters: 900, maxRanges: 6))->build([
            'title' => '行业第一的服务',
            'content' => $content,
        ], [[
            'field' => 'title',
            'claim_hash' => hash('sha256', 'title-ranking'),
            'materiality' => 'high',
        ]], [[
            'field' => 'content',
            'word' => $riskWord,
            'count' => 1,
            'severity' => 'warning',
        ]]);

        $this->assertStringContainsString($riskWord, $sample['sampled_content']);
        $this->assertSame(2, $sample['mandatory_claims_total']);
        $this->assertSame(2, $sample['mandatory_claims_covered']);
        $this->assertSame(1, $sample['risk_matches_total']);
        $this->assertSame(1, $sample['risk_matches_covered']);
        $this->assertFalse($sample['mandatory_overflow']);
    }

    public function test_it_requires_review_when_a_normalized_risk_match_cannot_map_to_an_exact_range(): void
    {
        $sample = (new ArticleAiQualitySampleBuilder(maxCharacters: 500, maxRanges: 4))->build([
            'title' => '风险映射',
            'content' => '这是零 风 险的变体表达。',
        ], [], [[
            'field' => 'content',
            'word' => '零风险',
            'count' => 1,
            'severity' => 'warning',
        ]]);

        $this->assertTrue($sample['mandatory_overflow']);
        $this->assertSame(1, $sample['unresolved_mandatory_count']);
        $this->assertSame(1, $sample['risk_matches_total']);
        $this->assertSame(0, $sample['risk_matches_covered']);
    }

    public function test_it_covers_every_content_occurrence_of_a_deduplicated_high_risk_claim(): void
    {
        $claim = '经审计，客户转化率达到 82%。';
        $content = $claim.str_repeat('普通正文。', 180).$claim;
        $firstOffset = mb_strpos($content, $claim);
        $secondOffset = mb_strrpos($content, $claim);
        $facts = [[
            'field' => 'content',
            'quote' => $claim,
            'claim_hash' => hash('sha256', $claim),
            'materiality' => 'high',
            'start_offset' => $firstOffset,
            'end_offset' => $firstOffset + mb_strlen($claim),
            'occurrences' => [
                [
                    'field' => 'content',
                    'start_offset' => $firstOffset,
                    'end_offset' => $firstOffset + mb_strlen($claim),
                    'source_hash' => hash('sha256', 'first'),
                ],
                [
                    'field' => 'content',
                    'start_offset' => $secondOffset,
                    'end_offset' => $secondOffset + mb_strlen($claim),
                    'source_hash' => hash('sha256', 'second'),
                ],
            ],
        ]];

        $sample = (new ArticleAiQualitySampleBuilder(maxCharacters: 900, maxRanges: 6))->build([
            'title' => '重复主张覆盖',
            'content' => $content,
        ], $facts);

        $this->assertSame(2, $sample['mandatory_claims_total']);
        $this->assertSame(2, $sample['mandatory_claims_covered']);
        $this->assertFalse($sample['mandatory_overflow']);
    }
}
