<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualityResultValidator;
use App\Services\GeoFlow\ArticleAiQualityScorerV2;
use Tests\TestCase;
use UnexpectedValueException;

class ArticleAiQualityResultValidatorTest extends TestCase
{
    public function test_it_rejects_unknown_output_fields(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('ai_quality_result_unknown_field');

        (new ArticleAiQualityResultValidator)->validate([
            'summary' => '完成核查',
            'promotion_context' => 'informational',
            'knowledge_coverage' => 'sufficient',
            'issues' => [],
            'uncertainties' => [],
            'score' => 100,
        ], $this->article(), [], [], $this->rules());
    }

    public function test_it_normalizes_confirmed_high_materiality_data_conflicts_to_critical(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '价格与知识依据冲突',
            'promotion_context' => 'promotional',
            'knowledge_coverage' => 'sufficient',
            'issues' => [[
                'code' => 'data_mismatch',
                'severity' => 'medium',
                'field' => 'content',
                'quote' => '标准价格为 1,980 元',
                'paragraph_index' => 1,
                'heading' => '价格说明',
                'fact_candidate_id' => 'F1',
                'article_claim' => '标准价格为 1,980 元',
                'evidence_value' => '标准价格为 980 元',
                'knowledge_refs' => ['K1'],
                'legal_refs' => ['CN-AD-LAW-08'],
                'reason' => '文章价格与已审核知识证据不一致',
                'suggestion' => '核实价格后修改',
            ]],
            'uncertainties' => [],
        ], $this->article(), [[
            'id' => 'F1',
            'type' => 'amount',
            'materiality' => 'high',
        ]], [[
            'id' => 'K1',
        ]], $this->rules());

        $this->assertSame('critical', $validated['issues'][0]['severity']);
        $this->assertTrue($validated['issues'][0]['references_valid']);
    }

    public function test_it_uses_the_current_segment_to_resolve_a_quote_repeated_elsewhere_in_the_article(): void
    {
        $article = $this->article();
        $article['content'] = "重复原文。\n\n中间段落。\n\n重复原文。";
        $segmentStart = mb_strrpos($article['content'], '重复原文。', 0, 'UTF-8');

        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '第二处原文存在问题',
            'promotion_context' => 'informational',
            'knowledge_coverage' => 'sufficient',
            'issues' => [[
                'code' => 'content_integrity',
                'severity' => 'medium',
                'field' => 'content',
                'quote' => '重复原文。',
                'paragraph_index' => 1,
                'heading' => '',
                'fact_candidate_id' => '',
                'article_claim' => '重复原文。',
                'evidence_value' => '',
                'knowledge_refs' => [],
                'legal_refs' => [],
                'reason' => '第二个分段中的原文需要修订',
                'suggestion' => '修订第二处原文',
            ]],
            'uncertainties' => [],
        ], $article, [], [], $this->rules(), [
            'start_offset' => $segmentStart,
            'end_offset' => mb_strlen($article['content'], 'UTF-8'),
        ]);

        $this->assertSame('resolved', $validated['issues'][0]['location_status']);
        $this->assertSame($segmentStart, $validated['issues'][0]['start_offset']);
        $this->assertSame(3, $validated['issues'][0]['paragraph_index']);
    }

    public function test_v2_accepts_stable_evidence_keys_and_derives_backend_locations(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '价格需要核对',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => ['price-claim'],
            'issues' => [[
                'code' => 'data_mismatch',
                'severity' => 'high',
                'claim_hash' => 'price-claim',
                'field' => 'content',
                'quote' => '标准价格为 1,980 元',
                'evidence_keys' => ['3:19:evidence-hash'],
                'evidence_status' => 'contradicted',
                'reason' => '数值不同',
                'suggestion' => '核实价格',
                'confidence' => 0.96,
            ]],
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ], $this->article(), [[
            'id' => 'F1',
            'claim_hash' => 'price-claim',
            'normalized_claim' => '标准价格为 1,980 元',
            'type' => 'amount',
            'materiality' => 'high',
        ]], [[
            'id' => 'K1',
            'stable_key' => '3:19:evidence-hash',
            'content' => '标准价格为 980 元。',
        ]], $this->rules());

        $this->assertTrue($validated['issues'][0]['references_valid']);
        $this->assertSame(['3:19:evidence-hash'], $validated['issues'][0]['evidence_keys']);
        $this->assertSame('resolved', $validated['issues'][0]['location_status']);
        $this->assertSame('high', $validated['issues'][0]['severity']);
    }

    public function test_v2_rejects_the_removed_ai_generation_disclosure_code(): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('ai_quality_issue_value_invalid');

        (new ArticleAiQualityResultValidator)->validate([
            'summary' => '发布标识待确认',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => [],
            'issues' => [[
                'code' => 'ai_generated_disclosure',
                'severity' => 'medium',
                'claim_hash' => '',
                'field' => 'content',
                'quote' => '标准价格为 1,980 元',
                'evidence_keys' => [],
                'evidence_status' => 'supported',
                'reason' => '缺少 AI 生成内容标识',
                'suggestion' => '补充标识',
                'confidence' => 0.9,
            ]],
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ], $this->article(), [], [], $this->rules());

    }

    public function test_v2_removes_ai_generation_disclosure_uncertainty_and_summary_noise(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '文章缺少 AI 生成内容标识，需要人工确认。',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => [],
            'issues' => [],
            'uncertainties' => [[
                'claim' => 'AI 生成内容标识状态',
                'materiality' => 'high',
                'reason' => '无法确认是否已声明 AI 生成',
                'needed_evidence' => '提供发布元数据标识',
            ]],
            'truncated_issue_count' => 0,
        ], $this->article(), [], [], $this->rules());

        $this->assertSame([], $validated['issues']);
        $this->assertSame([], $validated['uncertainties']);
        $this->assertSame('已完成当前启用规则的质检。', $validated['summary']);
    }

    public function test_v2_preserves_factual_uncertainty_about_ai_labeling_rules(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => 'AI 生成内容标识办法的适用范围需要知识依据。',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => [],
            'issues' => [],
            'uncertainties' => [[
                'claim' => 'AI 生成内容标识办法适用于全部内部文档',
                'materiality' => 'high',
                'reason' => '知识库未覆盖该办法的具体适用范围',
                'needed_evidence' => '补充该办法的官方条文',
            ]],
            'truncated_issue_count' => 0,
        ], $this->article(), [], [], $this->rules());

        $this->assertCount(1, $validated['uncertainties']);
        $this->assertSame('AI 生成内容标识办法的适用范围需要知识依据。', $validated['summary']);
    }

    public function test_v2_preserves_missing_official_basis_for_ai_labeling_regulation(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '缺少《AI 生成内容标识办法》的官方依据，适用范围待核验。',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => [],
            'issues' => [],
            'uncertainties' => [[
                'claim' => '《AI 生成内容标识办法》适用于全部内部文档',
                'materiality' => 'high',
                'reason' => '缺少《AI 生成内容标识办法》的官方依据，适用范围待核验',
                'needed_evidence' => '补充该办法的官方条文',
            ]],
            'truncated_issue_count' => 0,
        ], $this->article(), [], [], $this->rules());

        $this->assertCount(1, $validated['uncertainties']);
        $this->assertSame('缺少《AI 生成内容标识办法》的官方依据，适用范围待核验。', $validated['summary']);
    }

    public function test_v2_preserves_ai_generated_report_source_uncertainty(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '关键金额缺少可核验来源。',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => [],
            'issues' => [],
            'uncertainties' => [[
                'claim' => '合同金额为 100 万元',
                'materiality' => 'high',
                'reason' => '缺少 AI 生成报告的来源声明，无法核验关键金额',
                'needed_evidence' => '提供合同或受管知识来源',
            ]],
            'truncated_issue_count' => 0,
        ], $this->article(), [], [], $this->rules());

        $this->assertCount(1, $validated['uncertainties']);
        $this->assertSame('合同金额为 100 万元', $validated['uncertainties'][0]['claim']);
    }

    public function test_v2_moves_unverified_claims_to_uncertainties_without_a_score_deduction_issue(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '缺少市场份额来源',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => ['market-share'],
            'issues' => [[
                'code' => 'citation_missing',
                'severity' => 'medium',
                'claim_hash' => 'market-share',
                'field' => 'content',
                'quote' => '标准价格为 1,980 元',
                'evidence_keys' => [],
                'evidence_status' => 'unverified',
                'reason' => '未找到受管来源',
                'suggestion' => '补充来源',
                'confidence' => 0.7,
            ]],
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ], $this->article(), [[
            'id' => 'F1',
            'claim_hash' => 'market-share',
            'normalized_claim' => '标准价格为 1,980 元',
            'materiality' => 'high',
        ]], [], $this->rules());

        $this->assertSame([], $validated['issues']);
        $this->assertCount(1, $validated['uncertainties']);
        $this->assertSame('high', $validated['uncertainties'][0]['materiality']);
        $this->assertSame('unverified_material_claim', $validated['uncertainties'][0]['gate_reason']);

        $scored = (new ArticleAiQualityScorerV2)->score($validated, 85, 70);
        $this->assertSame(100, $scored['score']);
        $this->assertSame('needs_review', $scored['decision']);
        $this->assertContains('unverified_material_claim', $scored['gate_reasons']);
    }

    public function test_v2_derives_the_shared_seo_integrity_family_before_scoring(): void
    {
        $article = $this->article();
        $article['excerpt'] = '摘要内容出现截断';
        $article['meta_description'] = '描述内容出现截断';
        $issues = [];
        foreach ([
            ['field' => 'excerpt', 'quote' => $article['excerpt']],
            ['field' => 'meta_description', 'quote' => $article['meta_description']],
        ] as $item) {
            $issues[] = [
                'code' => 'content_integrity',
                'severity' => 'medium',
                'claim_hash' => '',
                'field' => $item['field'],
                'quote' => $item['quote'],
                'evidence_keys' => [],
                'evidence_status' => 'supported',
                'reason' => '内容不完整',
                'suggestion' => '补全内容',
                'confidence' => 0.9,
            ];
        }

        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '两个 SEO 字段需要补全',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => [],
            'issues' => $issues,
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ], $article, [], [], $this->rules());
        $scored = (new ArticleAiQualityScorerV2)->score($validated, 85, 70);

        $this->assertSame(['seo_truncation', 'seo_truncation'], array_column($validated['issues'], 'code_family'));
        $this->assertSame(97, $scored['score']);
        $this->assertSame(7, $scored['dimension_scores']['content_integrity']);
    }

    public function test_v2_routes_missing_high_materiality_claim_coverage_to_manual_review_without_deduction(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '未报告问题',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => [],
            'issues' => [],
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ], $this->article(), [[
            'claim_hash' => 'critical-price-claim',
            'normalized_claim' => '标准价格为 1,980 元',
            'materiality' => 'high',
        ]], [], $this->rules());

        $this->assertSame([], $validated['issues']);
        $this->assertSame('claim_coverage_incomplete', $validated['uncertainties'][0]['gate_reason']);

        $scored = (new ArticleAiQualityScorerV2)->score($validated, 85, 70);
        $this->assertSame(100, $scored['score']);
        $this->assertSame('needs_review', $scored['decision']);
        $this->assertContains('claim_coverage_incomplete', $scored['gate_reasons']);
    }

    public function test_v2_does_not_trust_model_claim_coverage_without_retrieval_evidence(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '模型声称已经核查全部主张',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => ['critical-price-claim'],
            'issues' => [],
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ], $this->article(), [[
            'claim_hash' => 'critical-price-claim',
            'normalized_claim' => '标准价格为 1,980 元',
            'materiality' => 'high',
            'knowledge_refs' => [],
        ]], [[
            'id' => 'K1',
            'stable_key' => '3:19:evidence-hash',
            'content' => '标准价格为 980 元。',
        ]], $this->rules());

        $this->assertSame([], $validated['reviewed_claim_hashes']);
        $this->assertSame('claim_coverage_incomplete', $validated['uncertainties'][0]['gate_reason']);
    }

    public function test_v2_resolves_model_evidence_ids_to_frozen_stable_keys(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '价格需要核对',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => ['price-claim'],
            'issues' => [[
                'code' => 'data_mismatch',
                'severity' => 'high',
                'claim_hash' => 'price-claim',
                'field' => 'content',
                'quote' => '标准价格为 1,980 元',
                'evidence_keys' => ['K1'],
                'evidence_status' => 'contradicted',
                'reason' => '数值不同',
                'suggestion' => '核实价格',
                'confidence' => 0.96,
            ]],
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ], $this->article(), [[
            'id' => 'F1',
            'claim_hash' => 'price-claim',
            'normalized_claim' => '标准价格为 1,980 元',
            'type' => 'amount',
            'materiality' => 'high',
            'knowledge_refs' => ['K1'],
        ]], [[
            'id' => 'K1',
            'stable_key' => '3:19:evidence-hash',
            'content' => '标准价格为 980 元。',
        ]], $this->rules());

        $this->assertTrue($validated['issues'][0]['references_valid']);
        $this->assertSame(['3:19:evidence-hash'], $validated['issues'][0]['evidence_keys']);
        $this->assertSame(['price-claim'], $validated['reviewed_claim_hashes']);
    }

    public function test_v2_rejects_non_object_or_unknown_materiality_uncertainties(): void
    {
        foreach ([
            ['关键金额无证据'],
            [[
                'claim' => '关键金额',
                'materiality' => 'critical',
                'reason' => '缺少证据',
                'needed_evidence' => '合同',
            ]],
        ] as $uncertainties) {
            try {
                (new ArticleAiQualityResultValidator)->validate([
                    'summary' => '待核验',
                    'promotion_context' => 'informational',
                    'reviewed_claim_hashes' => [],
                    'issues' => [],
                    'uncertainties' => $uncertainties,
                    'truncated_issue_count' => 0,
                ], $this->article(), [], [], $this->rules());
                $this->fail('Malformed uncertainty should be rejected.');
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_v2_rejects_negative_fractional_and_oversized_truncated_issue_counts(): void
    {
        foreach ([-1, 0.9, 65536] as $count) {
            try {
                (new ArticleAiQualityResultValidator)->validate([
                    'summary' => '未截断',
                    'promotion_context' => 'informational',
                    'reviewed_claim_hashes' => [],
                    'issues' => [],
                    'uncertainties' => [],
                    'truncated_issue_count' => $count,
                ], $this->article(), [], [], $this->rules());
                $this->fail('Invalid truncated issue count should be rejected.');
            } catch (UnexpectedValueException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @return array<string, string> */
    private function article(): array
    {
        return [
            'title' => '价格说明',
            'excerpt' => '',
            'content' => '标准价格为 1,980 元。',
            'keywords' => '',
            'meta_description' => '',
        ];
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'rules' => [[
                'id' => 'CN-AD-LAW-08',
                'source' => '中华人民共和国广告法第八条',
            ]],
        ];
    }
}
