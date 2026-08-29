<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleFactCandidateExtractor;
use PHPUnit\Framework\TestCase;

class ArticleFactCandidateExtractorTest extends TestCase
{
    public function test_it_extracts_every_data_and_material_claim_with_stable_ids(): void
    {
        $candidates = (new ArticleFactCandidateExtractor)->extract([
            'title' => '行业第一的企业服务',
            'excerpt' => '',
            'content' => "标准价格为 980 元。\n2026 年客户增长率达到 48%。\n公司已经获得 ISO 9001 资质。",
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame(['F1', 'F2', 'F3', 'F4'], array_column($candidates, 'id'));
        $this->assertSame(['ranking', 'amount', 'percentage', 'qualification'], array_column($candidates, 'type'));
        $this->assertSame('title', $candidates[0]['field']);
        $this->assertSame('content', $candidates[1]['field']);
        $this->assertSame('标准价格为 980 元。', $candidates[1]['quote']);
        $this->assertSame('high', $candidates[1]['materiality']);
    }

    public function test_it_removes_transient_citation_markers_and_groups_repeated_claims_across_fields(): void
    {
        $candidates = (new ArticleFactCandidateExtractor)->extract([
            'title' => '增长率达到 48%',
            'excerpt' => '增长率达到 48%[K12]。',
            'content' => '增长率达到 48%[K2]。',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertCount(1, $candidates);
        $this->assertSame('增长率达到 48%', $candidates[0]['normalized_claim']);
        $this->assertSame(hash('sha256', '增长率达到 48%'), $candidates[0]['claim_hash']);
        $this->assertSame(['title', 'excerpt', 'content'], array_column($candidates[0]['occurrences'], 'field'));
        $this->assertStringNotContainsString('[K', $candidates[0]['quote']);
    }

    public function test_it_excludes_list_numbers_and_caps_candidates_by_materiality(): void
    {
        $content = "1. 先收集资料。\n2. 再完成审核。\n";
        for ($index = 1; $index <= 15; $index++) {
            $content .= "第 {$index} 项统计显示增长率达到 {$index}%。\n";
        }

        $candidates = (new ArticleFactCandidateExtractor)->extract([
            'title' => '',
            'excerpt' => '',
            'content' => $content,
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertCount(12, $candidates);
        $this->assertNotContains('1. 先收集资料。', array_column($candidates, 'quote'));
        $this->assertNotContains('2. 再完成审核。', array_column($candidates, 'quote'));
    }

    public function test_high_materiality_claims_are_kept_before_earlier_medium_claims_when_capped(): void
    {
        $candidates = (new ArticleFactCandidateExtractor)->extract([
            'title' => '',
            'excerpt' => '',
            'content' => '现有客户 10 家。服务项目 20 个。',
            'keywords' => '',
            'meta_description' => '标准价格为 980 元。',
        ], 2);

        $this->assertSame(['amount', 'quantity'], array_column($candidates, 'type'));
        $this->assertSame('meta_description', $candidates[0]['field']);
    }

    public function test_a_descriptive_price_heading_without_an_amount_is_not_a_fact_claim(): void
    {
        $candidates = (new ArticleFactCandidateExtractor)->extract([
            'title' => '产品价格说明',
            'excerpt' => '',
            'content' => '',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame([], $candidates);
    }

    public function test_a_descriptive_growth_rate_heading_without_a_value_is_not_a_fact_claim(): void
    {
        $candidates = (new ArticleFactCandidateExtractor)->extract([
            'title' => '客户增长率分析',
            'excerpt' => '',
            'content' => '',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame([], $candidates);
    }

    public function test_a_descriptive_certification_heading_without_an_assertion_is_not_a_fact_claim(): void
    {
        $candidates = (new ArticleFactCandidateExtractor)->extract([
            'title' => '行业认证介绍',
            'excerpt' => '',
            'content' => '',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame([], $candidates);
    }

    public function test_it_marks_attributed_reports_and_quotes_as_high_materiality_citations(): void
    {
        $candidates = (new ArticleFactCandidateExtractor)->extract([
            'title' => '',
            'excerpt' => '',
            'content' => '据行业研究报告显示，该方案适用于大型团队。“该方案值得优先考虑。”',
            'keywords' => '',
            'meta_description' => '',
        ]);

        $this->assertSame(['citation'], array_values(array_unique(array_column($candidates, 'type'))));
        $this->assertSame(['high'], array_values(array_unique(array_column($candidates, 'materiality'))));
    }
}
