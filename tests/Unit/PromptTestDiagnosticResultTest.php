<?php

namespace Tests\Unit;

use App\Support\GeoFlow\PromptTestAnchors;
use App\Support\GeoFlow\PromptTestDiagnosticResult;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PromptTestDiagnosticResultTest extends TestCase
{
    public function test_scores_use_the_fixed_weights_and_unknown_evidence_has_no_numeric_score(): void
    {
        $raw = $this->diagnosticFixture();
        $validator = new PromptTestDiagnosticResult;
        $result = $validator->validate($raw, '文章正文', '提示词');
        $this->assertSame(60, $result['overall']);
        $this->assertSame(100, $result['dimensions']['readability']['score']);
        $this->assertSame(0, $result['dimensions']['eeat']['score']);
        $raw['criteria'][4] = array_replace($raw['criteria'][4], ['level' => null, 'status' => 'unverified', 'scope' => '外部资历尚无材料']);
        $result = $validator->validate($raw, '文章正文', '提示词');
        $this->assertNull($result['overall']);
        $this->assertNull($result['dimensions']['eeat']['score']);
        $this->assertSame(3, $result['dimensions']['eeat']['assessed']);
    }

    public function test_invented_article_quotes_invalidate_the_scores(): void
    {
        $raw = $this->diagnosticFixture();
        $raw['criteria'][0]['quote'] = '文章中不存在';
        $this->expectException(InvalidArgumentException::class);
        (new PromptTestDiagnosticResult)->validate($raw, '文章正文', '提示词');
    }

    public function test_repeated_criteria_cannot_replace_missing_criteria(): void
    {
        $raw = $this->diagnosticFixture();
        $raw['criteria'][1] = $raw['criteria'][0];
        $this->expectException(InvalidArgumentException::class);
        (new PromptTestDiagnosticResult)->validate($raw, '文章正文', '提示词');
    }

    public function test_anchors_keep_whitespace_and_use_utf16_positions_with_emoji(): void
    {
        $prompt = PromptTestAnchors::normalize("  # 要求\r\n😀请写事实。\r\n请写事实。\n");
        $anchor = PromptTestAnchors::resolve($prompt, 'p3', '请写事实。', 'replace');
        $this->assertSame(15, $anchor['start']);
        $this->assertSame(20, $anchor['end']);
        $this->assertSame('要求', $anchor['section']);
        $this->assertSame(3, $anchor['line']);
        $this->assertNull(PromptTestAnchors::resolve('重复重复', 'p1', '重复', 'replace'));
        $this->assertNull(PromptTestAnchors::resolve('哈哈哈', 'p1', '哈哈', 'replace'));
        $this->assertNull(PromptTestAnchors::resolve('aaa', 'p1', 'aa', 'replace'));
        $this->assertNull(PromptTestAnchors::resolve($prompt, 'p30', '请写事实。', 'replace'));
    }

    public function test_only_suggestions_with_verified_article_and_prompt_quotes_are_actionable(): void
    {
        $raw = $this->diagnosticFixture();
        $suggestion = [
            'dimension' => 'geo', 'priority' => 'high', 'problem' => '缺乏依据',
            'article_quote' => '文章正文', 'article_scope' => '', 'segment_id' => 'p1',
            'prompt_quote' => '请写事实', 'action' => 'insert_after', 'replacement' => '请标明来源。',
            'reason' => '让依据可追溯', 'expected_improvement' => '便于核验', 'needed_evidence' => '来源资料',
        ];
        $raw['suggestions'] = [$suggestion, array_replace($suggestion, ['segment_id' => 'p99']), array_replace($suggestion, ['article_quote' => '编造引文'])];
        $result = (new PromptTestDiagnosticResult)->validate($raw, '文章正文', '请写事实');
        $this->assertCount(1, $result['suggestions']);
        $this->assertSame(2, $result['unresolved_suggestions']);
        $this->assertSame('insert_after', $result['suggestions'][0]['anchor']['action']);
    }

    private function diagnosticFixture(): array
    {
        $criteria = [];
        foreach (PromptTestDiagnosticResult::DIMENSIONS as $dimension => $ids) {
            foreach ($ids as $id) {
                $criteria[] = ['id' => $id, 'level' => ['readability' => 4, 'eeat' => 0, 'geo' => 3][$dimension],
                    'status' => 'observed', 'quote' => '文章正文', 'scope' => '', 'reason' => '依据正文评估'];
            }
        }

        return ['criteria' => $criteria, 'suggestions' => []];
    }
}
