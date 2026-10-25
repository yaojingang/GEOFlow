<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualityScorer;
use App\Services\GeoFlow\ArticleAiQualityScorerV2;
use App\Services\GeoFlow\ArticleFactCandidateExtractor;
use PHPUnit\Framework\TestCase;

class ArticleAiQualityScoreReleaseTest extends TestCase
{
    public function test_ordinary_evidence_gaps_are_scored_and_release_at_the_exact_threshold(): void
    {
        foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
            $input = ['knowledge_coverage' => 'insufficient', 'issues' => [], 'uncertainties' => []];
            $result = $scorer->score($input, 90, 70);
            $this->assertSame(90, $result['score']);
            $this->assertSame('passed', $result['decision']);
            $this->assertSame([], $result['gate_reasons']);
            $this->assertSame(10, $result['score_adjustments'][0]['deduction']);
            $this->assertSame('needs_review', $scorer->score($input, 91, 70)['decision']);
        }
    }

    public function test_a_high_issue_uses_its_deduction_without_a_second_veto(): void
    {
        foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
            $result = $scorer->score(['knowledge_coverage' => 'sufficient', 'issues' => [[
                'code' => 'ad_false_or_misleading', 'severity' => 'high', 'quote' => '效果有所夸大',
            ]]], 85, 70);
            $this->assertSame(88, $result['score']);
            $this->assertSame('passed', $result['decision']);
        }
    }

    public function test_critical_risk_and_incomplete_output_remain_explicit_blockers(): void
    {
        foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
            $critical = $scorer->score(['knowledge_coverage' => 'sufficient', 'issues' => [[
                'code' => 'ad_false_or_misleading', 'severity' => 'critical', 'quote' => '已确认伪造证明',
            ]]], 75, 60);
            $this->assertSame('blocked', $critical['decision']);
            $this->assertContains('confirmed_hard_blocker', $critical['gate_reasons']);
            $incomplete = $scorer->score(['knowledge_coverage' => 'sufficient', 'truncated_issue_count' => 1], 85, 70);
            $this->assertSame('needs_review', $incomplete['decision']);
            $this->assertContains('model_output_truncated', $incomplete['gate_reasons']);
        }
    }

    public function test_coverage_does_not_charge_again_for_an_already_deducted_evidence_issue(): void
    {
        foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
            $result = $scorer->score(['knowledge_coverage' => 'insufficient', 'issues' => [[
                'code' => 'knowledge_contradiction', 'severity' => 'high', 'quote' => '产品能力不符',
            ]]], 85, 70);
            $this->assertSame('passed', $result['decision']);
            $this->assertGreaterThanOrEqual(88, $result['score']);
            $this->assertSame([], $result['score_adjustments']);
        }
    }

    public function test_ordinary_uncertainty_is_deducted_and_replay_does_not_charge_twice(): void
    {
        foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
            $input = ['knowledge_coverage' => 'sufficient', 'uncertainties' => [[
                'claim' => '可见度稳定', 'materiality' => 'high',
            ], ['claim' => '可见度稳定', 'materiality' => 'high']]];
            $result = $scorer->score($input, 85, 70);
            $this->assertSame(94, $result['score']);
            $this->assertSame('passed', $result['decision']);
            $this->assertSame($result['score'], $scorer->score(array_replace($input, $result), 85, 70)['score']);
        }
    }

    public function test_duplicate_reports_preserve_the_highest_risk_in_either_order(): void
    {
        foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
            $issue = ['code' => 'ad_false_or_misleading', 'field' => 'content', 'quote' => '相同声明'];
            $high = array_replace($issue, ['severity' => 'high']);
            $critical = array_replace($issue, ['severity' => 'critical']);
            foreach ([[$high, $critical], [$critical, $high], [$high, $high + ['hard_blocker' => true]]] as $issues) {
                $result = $scorer->score(['knowledge_coverage' => 'sufficient', 'issues' => $issues], 75, 60);
                $this->assertCount(1, $result['issues']);
                $this->assertSame('blocked', $result['decision']);
                $this->assertContains('confirmed_hard_blocker', $result['gate_reasons']);
            }
        }
    }

    public function test_distinct_numeric_claims_are_not_collapsed_when_the_model_omits_hashes(): void
    {
        foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
            $result = $scorer->score(['knowledge_coverage' => 'sufficient', 'issues' => [
                ['code' => 'ad_false_or_misleading', 'severity' => 'high', 'quote' => '收益 -10%'],
                ['code' => 'ad_false_or_misleading', 'severity' => 'high', 'quote' => '收益 10%'],
            ]], 85, 70);
            $this->assertCount(2, $result['issues']);
            $this->assertSame(76, $result['score']);
            $this->assertSame('needs_review', $result['decision']);
        }
    }

    public function test_adding_a_minor_issue_cannot_erase_material_uncertainty_or_raise_the_score(): void
    {
        foreach ([new ArticleAiQualityScorer, new ArticleAiQualityScorerV2] as $scorer) {
            $input = ['knowledge_coverage' => 'sufficient', 'uncertainties' => [['claim' => '服务承诺尚未证实', 'materiality' => 'high']]];
            $base = $scorer->score($input, 95, 70);
            foreach (['content_integrity', 'unsupported_claim', 'citation_missing'] as $code) {
                $result = $scorer->score($input + ['issues' => [['code' => $code, 'severity' => 'low', 'quote' => '服务承诺尚未证实']]], 95, 70);
                $this->assertLessThanOrEqual($base['score'], $result['score']);
                $this->assertSame('needs_review', $result['decision']);
            }
        }
    }

    public function test_negation_steps_and_suggestions_are_not_material_claims(): void
    {
        $extractor = new ArticleFactCandidateExtractor;
        foreach ([
            '答：不能保证。',
            '诊断报告应写明第一轮验证从哪个站点开始，避免一开始就全渠道铺开。',
            '在正式启动 GEOFlow 之前，建议先完成一份覆盖品牌数据、内容资产与平台适配三个维度的诊断报告。',
            '- 空洞承诺：保证“品牌被推荐进入前几名”。',
        ] as $quote) {
            $this->assertSame([], $extractor->extract(['content' => $quote]), $quote);
        }
        foreach (['我们保证效果。', '我们不能不保证效果。', '我们不得不保证结果。', '我们并非不能保证效果。', '我们排名第一。', '据研究报告显示，增长率达到 48%。'] as $quote) {
            $this->assertNotEmpty($extractor->extract(['content' => $quote]), $quote);
        }
        $this->assertNotEmpty($extractor->extract(['content' => '不能保证排名，但我们保证退款。']));
    }
}
