<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualityScorer;
use PHPUnit\Framework\TestCase;

class ArticleAiQualityScorerTest extends TestCase
{
    public function test_clean_result_receives_full_score_and_passes(): void
    {
        $result = (new ArticleAiQualityScorer)->score([
            'promotion_context' => 'informational',
            'knowledge_coverage' => 'sufficient',
            'issues' => [],
            'uncertainties' => [],
        ], 85, 70);

        $this->assertSame(100, $result['score']);
        $this->assertSame([
            'knowledge_consistency' => 35,
            'data_traceability' => 25,
            'advertising_compliance' => 30,
            'content_integrity' => 10,
        ], $result['dimension_scores']);
        $this->assertSame('passed', $result['decision']);
    }

    public function test_duplicate_issues_are_deducted_once_and_thresholds_are_applied(): void
    {
        $issue = [
            'code' => 'data_mismatch',
            'severity' => 'high',
            'field' => 'content',
            'quote' => '增长率达到 48%',
            'knowledge_refs' => ['K2'],
        ];

        $result = (new ArticleAiQualityScorer)->score([
            'promotion_context' => 'promotional',
            'knowledge_coverage' => 'sufficient',
            'issues' => [$issue, $issue],
            'uncertainties' => [],
        ], 90, 70);

        $this->assertSame(88, $result['score']);
        $this->assertSame(13, $result['dimension_scores']['data_traceability']);
        $this->assertCount(1, $result['issues']);
        $this->assertSame('needs_review', $result['decision']);
    }

    public function test_critical_issue_blocks_even_when_score_is_above_pass_threshold(): void
    {
        $result = (new ArticleAiQualityScorer)->score([
            'promotion_context' => 'promotional',
            'knowledge_coverage' => 'sufficient',
            'issues' => [[
                'code' => 'content_integrity',
                'severity' => 'critical',
                'field' => 'content',
                'quote' => '伪造来源',
                'knowledge_refs' => [],
            ]],
            'uncertainties' => [],
        ], 75, 60);

        $this->assertSame(90, $result['score']);
        $this->assertSame('blocked', $result['decision']);
    }

    public function test_high_severity_issue_requires_review_even_when_score_stays_above_pass_threshold(): void
    {
        $result = (new ArticleAiQualityScorer)->score([
            'promotion_context' => 'informational',
            'knowledge_coverage' => 'sufficient',
            'issues' => [[
                'code' => 'data_mismatch',
                'severity' => 'high',
                'field' => 'content',
                'quote' => '系统默认启用该策略',
                'knowledge_refs' => ['K1'],
            ]],
            'uncertainties' => [],
        ], 85, 70);

        $this->assertSame(88, $result['score']);
        $this->assertSame('needs_review', $result['decision']);
    }

    public function test_incomplete_knowledge_coverage_forces_manual_review(): void
    {
        $result = (new ArticleAiQualityScorer)->score([
            'promotion_context' => 'informational',
            'knowledge_coverage' => 'partial',
            'issues' => [],
            'uncertainties' => [],
        ], 85, 70);

        $this->assertSame(100, $result['score']);
        $this->assertSame('needs_review', $result['decision']);
    }

    public function test_removed_ai_generation_disclosure_code_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AI quality issue code is invalid.');

        (new ArticleAiQualityScorer)->score([
            'promotion_context' => 'informational',
            'knowledge_coverage' => 'sufficient',
            'issues' => [[
                'code' => 'ai_generated_disclosure',
                'severity' => 'high',
                'field' => 'content',
                'quote' => '文章正文',
                'knowledge_refs' => [],
            ]],
            'uncertainties' => [],
        ], 85, 70);

    }
}
