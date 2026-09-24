<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualityResultValidator;
use App\Services\GeoFlow\ArticleAiQualityScorer;
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

    public function test_v2_scores_explicitly_unverified_claims_without_an_independent_veto(): void
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
        $this->assertSame(90, $scored['score']);
        $this->assertSame('passed', $scored['decision']);
        $this->assertSame([], $scored['gate_reasons']);
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

    public function test_v2_keeps_missing_high_materiality_claim_inspection_as_an_explicit_blocker(): void
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
        $this->assertSame(90, $scored['score']);
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
        $this->assertSame('unverified_material_claim', $validated['uncertainties'][0]['gate_reason']);
        foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
            $scored = $scorer->score($validated, 85, 70);
            $this->assertSame(90, $scored['score']);
            $this->assertSame('passed', $scored['decision']);
            $this->assertSame([], $scored['gate_reasons']);
        }
    }

    public function test_v2_omitting_a_claim_with_available_evidence_cannot_pass_either_scorer(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '未报告问题',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => [],
            'issues' => [],
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ], $this->article(), [[
            'claim_hash' => 'price-claim',
            'normalized_claim' => '标准价格为 1,980 元',
            'materiality' => 'high',
            'knowledge_refs' => ['K1'],
        ]], [[
            'id' => 'K1',
            'stable_key' => '3:19:evidence-hash',
            'content' => '标准价格为 980 元。',
        ]], $this->rules());

        foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
            $scored = $scorer->score($validated, 85, 70);
            $this->assertGreaterThanOrEqual(85, $scored['score']);
            $this->assertSame('needs_review', $scored['decision']);
            $this->assertContains('claim_coverage_incomplete', $scored['gate_reasons']);
        }
    }

    public function test_v2_derives_coverage_from_completed_material_claims_without_a_placeholder_penalty(): void
    {
        $facts = [
            ['claim_hash' => 'price-claim', 'normalized_claim' => '标准价格为 1,980 元', 'materiality' => 'high', 'knowledge_refs' => ['K1']],
            ['claim_hash' => 'service-claim', 'normalized_claim' => '支持标准服务', 'materiality' => 'medium', 'knowledge_refs' => ['K1']],
        ];
        $evidence = [['id' => 'K1', 'stable_key' => '3:19:evidence-hash', 'content' => '标准价格为 1,980 元，支持标准服务。']];
        foreach ([
            [[], [], 'sufficient', 100],
            [$facts, ['price-claim', 'service-claim'], 'sufficient', 100],
            [$facts, ['price-claim'], 'partial', 95],
            [$facts, [], 'insufficient', 90],
        ] as [$candidates, $reviewed, $coverage, $score]) {
            $validated = (new ArticleAiQualityResultValidator)->validate([
                'summary' => '完成核查',
                'promotion_context' => 'informational',
                'reviewed_claim_hashes' => $reviewed,
                'issues' => [],
                'uncertainties' => [],
                'truncated_issue_count' => 0,
            ], $this->article(), $candidates, $evidence, $this->rules());

            $this->assertSame($coverage, $validated['knowledge_coverage']);
            foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
                $scored = $scorer->score($validated, 85, 70);
                $this->assertSame($score, $scored['score']);
                if ($coverage === 'sufficient') {
                    $this->assertSame('passed', $scored['decision']);
                    $this->assertSame([], $scored['score_adjustments']);
                }
            }
        }
    }

    public function test_v2_explicit_unverified_results_are_scored_without_being_misclassified_as_omitted_inspection(): void
    {
        foreach ([['price-claim'], []] as $reviewed) {
            $validated = (new ArticleAiQualityResultValidator)->validate([
                'summary' => '现有来源不足以确认价格',
                'promotion_context' => 'informational',
                'reviewed_claim_hashes' => $reviewed,
                'issues' => [[
                    'code' => 'citation_missing',
                    'severity' => 'medium',
                    'claim_hash' => 'price-claim',
                    'field' => 'content',
                    'quote' => '标准价格为 1,980 元',
                    'evidence_keys' => ['K1'],
                    'evidence_status' => 'unverified',
                    'reason' => '来源未能确认当前价格',
                    'suggestion' => '补充当前价格凭证',
                    'confidence' => 0.7,
                ]],
                'uncertainties' => [],
                'truncated_issue_count' => 0,
            ], $this->article(), [[
                'claim_hash' => 'price-claim',
                'normalized_claim' => '标准价格为 1,980 元',
                'materiality' => 'high',
                'knowledge_refs' => ['K1'],
            ]], [[
                'id' => 'K1',
                'stable_key' => '3:19:evidence-hash',
                'content' => '产品提供标准服务。',
            ]], $this->rules());

            $this->assertCount(1, $validated['uncertainties']);
            $this->assertSame('unverified_material_claim', $validated['uncertainties'][0]['gate_reason']);
            $this->assertSame('insufficient', $validated['knowledge_coverage']);
            foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
                $scored = $scorer->score($validated, 85, 70);
                $this->assertSame(90, $scored['score']);
                $this->assertSame('passed', $scored['decision']);
                $this->assertSame([], $scored['gate_reasons']);
            }
        }
    }

    public function test_v2_confirmed_critical_conflict_stays_blocked_after_complete_inspection(): void
    {
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '已确认关键价格冲突',
            'promotion_context' => 'informational',
            'reviewed_claim_hashes' => ['price-claim'],
            'issues' => [[
                'code' => 'data_mismatch',
                'severity' => 'critical',
                'claim_hash' => 'price-claim',
                'field' => 'content',
                'quote' => '标准价格为 1,980 元',
                'evidence_keys' => ['K1'],
                'evidence_status' => 'contradicted',
                'reason' => '标准价格与证据金额冲突',
                'suggestion' => '核对并修正价格',
                'confidence' => 0.99,
            ]],
            'uncertainties' => [],
            'truncated_issue_count' => 0,
        ], $this->article(), [[
            'claim_hash' => 'price-claim',
            'normalized_claim' => '标准价格为 1,980 元',
            'materiality' => 'high',
            'knowledge_refs' => ['K1'],
        ]], [[
            'id' => 'K1',
            'stable_key' => '3:19:evidence-hash',
            'content' => '标准价格为 980 元。',
        ]], $this->rules());

        $this->assertSame('sufficient', $validated['knowledge_coverage']);
        $this->assertSame('critical', $validated['issues'][0]['severity']);
        foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
            $scored = $scorer->score($validated, 75, 60);
            $this->assertGreaterThanOrEqual(75, $scored['score']);
            $this->assertSame('blocked', $scored['decision']);
            $this->assertContains('confirmed_hard_blocker', $scored['gate_reasons']);
        }
    }

    public function test_v2_numeric_conflicts_compare_corresponding_quantities_without_losing_signs_or_decimals(): void
    {
        foreach ([
            ['2026 年服务费用为 800 元。', '2026 年服务费用为 900 元。', 'amount', 'critical'],
            ['服务费用为 800 元。', '服务费用为 9 万元。', 'amount', 'critical'],
            ['服务费用为 9 万元。', '服务费用为 800 元。', 'amount', 'critical'],
            ['服务费用为 1.25 千元。', '服务费用为 1250 元。', 'amount', 'high'],
            ['服务费用为 1.25 万元。', '服务费用为 12500 元。', 'amount', 'high'],
            ['服务费用为 0.00000001 亿元。', '服务费用为 1 元。', 'amount', 'high'],
            ['服务费用为 -0.0001 万元。', '服务费用为 -1 元。', 'amount', 'high'],
            ['服务费用为 -0.0001 万元。', '服务费用为 1 元。', 'amount', 'critical'],
            ['服务费用为 9007199254740993.0001 万元。', '服务费用为 90071992547409930001 元。', 'amount', 'high'],
            ['服务费用为 9007199254740993.0001 万元。', '服务费用为 90071992547409930002 元。', 'amount', 'critical'],

            ['2026 年增长率为 -10%。', '2026 年增长率为 10%。', 'percentage', 'critical'],
            ['增长率为 12.5%。', '增长率为 125%。', 'percentage', 'critical'],
            ['标准价格为 1,980.50 元。', '标准价格为 1980.5 元。', 'amount', 'high'],
            ['增长率为 +12.50%。', '增长率为 12.5%。', 'percentage', 'high'],
            ['2026 年服务费用为 800 元。', '2025 年服务费用为 800 元。', 'amount', 'high'],
            ['2026 年服务费用为 800 元。', '2026 年累计服务 900 家客户。', 'amount', 'high'],
            ['服务费用为 800 元。', '服务预算为 900 元。', 'amount', 'high'],
            ['服务费用为 800 元。', '服务费用为 900 美元。', 'amount', 'high'],
            ['增长率为 10%。', '满意度为 99%。', 'percentage', 'high'],
            ['服务费用为 800 元。', '报告编号 900', 'amount', 'high'],
            ['服务费用为 800 元。', '服务费用为 800 元。旧服务费用为 900 元。', 'amount', 'high'],
        ] as [$claim, $evidenceText, $type, $severity]) {
            $validated = (new ArticleAiQualityResultValidator)->validate([
                'summary' => '核对数字声明',
                'promotion_context' => 'informational',
                'reviewed_claim_hashes' => ['numeric-claim'],
                'issues' => [[
                    'code' => 'data_mismatch', 'severity' => 'critical',
                    'claim_hash' => 'numeric-claim', 'field' => 'content', 'quote' => $claim,
                    'evidence_keys' => ['K1'], 'evidence_status' => 'contradicted',
                    'reason' => '模型报告数字冲突', 'suggestion' => '按来源核对声明', 'confidence' => 1,
                ]],
                'uncertainties' => [], 'truncated_issue_count' => 0,
            ], ['content' => $claim], [[
                'claim_hash' => 'numeric-claim', 'normalized_claim' => $claim,
                'type' => $type, 'materiality' => 'high', 'knowledge_refs' => ['K1'],
            ]], [['id' => 'K1', 'stable_key' => 'numeric-source', 'content' => $evidenceText]], $this->rules());

            $this->assertSame($severity, $validated['issues'][0]['severity'], $claim.' / '.$evidenceText);
            foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
                $scored = $scorer->score($validated, 85, 70);
                $this->assertSame($severity === 'critical' ? 'blocked' : 'passed', $scored['decision']);
            }
        }
    }

    public function test_v2_distinct_validated_claims_with_empty_hashes_each_reduce_the_score(): void
    {
        $claims = ['服务覆盖所有行业', '方案适合所有企业', '产品支持所有渠道'];
        $issues = array_map(static fn (string $quote): array => [
            'code' => 'unsupported_claim', 'severity' => 'high', 'claim_hash' => '',
            'field' => 'content', 'quote' => $quote, 'evidence_keys' => ['K1'],
            'evidence_status' => 'contradicted', 'reason' => '来源说明存在适用限制',
            'suggestion' => '按来源说明适用范围', 'confidence' => 0.95,
        ], $claims);
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '发现三项独立事实问题', 'promotion_context' => 'informational',
            'reviewed_claim_hashes' => [], 'issues' => $issues, 'uncertainties' => [],
            'truncated_issue_count' => 0,
        ], ['content' => implode('。', $claims)], [], [[
            'id' => 'K1', 'stable_key' => 'scope-source',
            'content' => '服务有行业限制，仅支持部分企业和渠道。',
        ]], $this->rules());

        $legacy = (new ArticleAiQualityScorer)->score($validated, 85, 70);
        $current = (new ArticleAiQualityScorerV2)->score($validated, 85, 70);

        $this->assertSame(65, $legacy['score']);
        $this->assertSame('blocked', $legacy['decision']);
        $this->assertSame(70, $current['score']);
        $this->assertSame('needs_review', $current['decision']);
        $this->assertSame([10, 10, 10], array_column($current['issues'], 'deduction'));
    }

    public function test_v2_validated_issues_for_one_known_claim_keep_the_combined_deduction_cap(): void
    {
        $quote = '服务覆盖所有行业';
        $issues = array_map(static fn (string $code): array => [
            'code' => $code, 'severity' => 'high', 'claim_hash' => 'scope-claim',
            'field' => 'content', 'quote' => $quote, 'evidence_keys' => ['K1'],
            'evidence_status' => 'contradicted', 'reason' => '来源说明存在行业限制',
            'suggestion' => '按来源说明适用范围', 'confidence' => 0.95,
        ], ['unsupported_claim', 'knowledge_contradiction']);
        $validated = (new ArticleAiQualityResultValidator)->validate([
            'summary' => '同一事实有两项问题', 'promotion_context' => 'informational',
            'reviewed_claim_hashes' => ['scope-claim'], 'issues' => $issues, 'uncertainties' => [],
            'truncated_issue_count' => 0,
        ], ['content' => $quote], [[
            'claim_hash' => 'scope-claim', 'normalized_claim' => $quote,
            'materiality' => 'high', 'knowledge_refs' => ['K1'],
        ]], [[
            'id' => 'K1', 'stable_key' => 'scope-source', 'content' => '服务存在行业限制。',
        ]], $this->rules());

        $legacy = (new ArticleAiQualityScorer)->score($validated, 85, 70);
        $current = (new ArticleAiQualityScorerV2)->score($validated, 85, 70);

        $this->assertSame(76, $legacy['score']);
        $this->assertSame('needs_review', $legacy['decision']);
        $this->assertSame(90, $current['score']);
        $this->assertSame('passed', $current['decision']);
        $this->assertSame([10, 0], array_column($current['issues'], 'deduction'));
    }

    public function test_v2_uncertainty_deduplication_preserves_numeric_signs_and_decimal_points(): void
    {
        foreach ([
            [['增长率为 -10%', '增长率为 10%'], 88, 'needs_review'],
            [['增长率为 12.5%', '增长率为 125%'], 88, 'needs_review'],
            [['增长率为 -10%', '增长率为-10%。'], 94, 'passed'],
        ] as [$claims, $score, $decision]) {
            $validated = (new ArticleAiQualityResultValidator)->validate([
                'summary' => '增长率来源需要补充',
                'promotion_context' => 'informational',
                'reviewed_claim_hashes' => [],
                'issues' => [],
                'uncertainties' => array_map(static fn (string $claim): array => [
                    'claim' => $claim,
                    'materiality' => 'high',
                    'reason' => '缺少可核验来源',
                    'needed_evidence' => '补充统计报告',
                ], $claims),
                'truncated_issue_count' => 0,
            ], $this->article(), [], [], $this->rules());

            foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
                $scored = $scorer->score($validated, 90, 70);
                $this->assertSame($score, $scored['score']);
                $this->assertSame($decision, $scored['decision']);
            }
        }
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
