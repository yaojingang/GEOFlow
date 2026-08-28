<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualityResultValidator;
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
