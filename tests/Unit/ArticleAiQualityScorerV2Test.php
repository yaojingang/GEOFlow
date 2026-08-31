<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualityScorerV2;
use PHPUnit\Framework\TestCase;

class ArticleAiQualityScorerV2Test extends TestCase
{
    public function test_same_citation_root_is_deducted_once_and_keeps_all_occurrences(): void
    {
        $issues = [];
        foreach (['摘要', '正文一', '正文二', '描述'] as $quote) {
            $issues[] = [
                'code' => 'citation_scope_mismatch',
                'severity' => 'medium',
                'claim_hash' => 'claim-1',
                'field' => 'content',
                'quote' => $quote,
                'evidence_keys' => ['7:12:content-hash'],
                'evidence_status' => 'contradicted',
            ];
        }

        $result = (new ArticleAiQualityScorerV2)->score($this->qualityResult($issues), 85, 70);

        $this->assertSame(96, $result['score']);
        $this->assertSame(21, $result['dimension_scores']['data_traceability']);
        $this->assertCount(1, $result['issues']);
        $this->assertCount(4, $result['issues'][0]['occurrences']);
        $this->assertSame('passed', $result['decision']);
        $this->assertSame('v2', $result['scoring_version']);
    }

    public function test_seo_integrity_deductions_share_a_three_point_category_cap(): void
    {
        $result = (new ArticleAiQualityScorerV2)->score($this->qualityResult([
            [
                'code' => 'content_integrity',
                'code_family' => 'seo_truncation',
                'severity' => 'medium',
                'field' => 'excerpt',
                'quote' => '摘要被截断',
            ],
            [
                'code' => 'content_integrity',
                'code_family' => 'seo_truncation',
                'severity' => 'low',
                'field' => 'meta_description',
                'quote' => '描述被截断',
            ],
        ]), 85, 70);

        $this->assertSame(97, $result['score']);
        $this->assertSame(7, $result['dimension_scores']['content_integrity']);
    }

    public function test_category_caps_do_not_weaken_a_critical_hard_blocker(): void
    {
        $result = (new ArticleAiQualityScorerV2)->score($this->qualityResult([[
            'code' => 'citation_missing',
            'severity' => 'critical',
            'claim_hash' => 'fabricated-source',
            'field' => 'content',
            'quote' => '某权威报告指出',
        ]]), 85, 70);

        $this->assertSame(96, $result['score']);
        $this->assertSame('blocked', $result['decision']);
        $this->assertContains('confirmed_hard_blocker', $result['gate_reasons']);
    }

    public function test_unverified_evidence_changes_the_gate_without_deducting_quality_points(): void
    {
        $result = (new ArticleAiQualityScorerV2)->score($this->qualityResult([[
            'code' => 'citation_missing',
            'severity' => 'medium',
            'claim_hash' => 'market-share',
            'field' => 'content',
            'quote' => '市场份额达到 48%',
            'evidence_status' => 'unverified',
        ]]), 85, 70);

        $this->assertSame(100, $result['score']);
        $this->assertSame('needs_review', $result['decision']);
        $this->assertContains('unverified_material_claim', $result['gate_reasons']);
    }

    public function test_confirmed_high_severity_issue_requires_review_above_the_pass_threshold(): void
    {
        $result = (new ArticleAiQualityScorerV2)->score($this->qualityResult([[
            'code' => 'data_mismatch',
            'severity' => 'high',
            'claim_hash' => 'default-setting',
            'field' => 'content',
            'quote' => '系统默认启用该策略',
            'evidence_status' => 'contradicted',
        ]]), 85, 70);

        $this->assertSame(92, $result['score']);
        $this->assertSame('needs_review', $result['decision']);
        $this->assertContains('confirmed_high_severity_issue', $result['gate_reasons']);
    }

    public function test_removed_ai_generation_disclosure_code_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AI quality issue code is invalid.');

        (new ArticleAiQualityScorerV2)->score($this->qualityResult([[
            'code' => 'ai_generated_disclosure',
            'severity' => 'high',
            'claim_hash' => '',
            'field' => 'content',
            'quote' => '文章正文',
            'evidence_status' => 'supported',
        ]]), 85, 70);

    }

    /** @param list<array<string, mixed>> $issues @return array<string, mixed> */
    private function qualityResult(array $issues): array
    {
        return [
            'promotion_context' => 'informational',
            'knowledge_coverage' => 'sufficient',
            'issues' => $issues,
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ];
    }
}
