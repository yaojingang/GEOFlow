<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleCitationMarkerCleaner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ArticleCitationMarkerCleanerTest extends TestCase
{
    #[DataProvider('markerCases')]
    public function test_it_removes_confirmed_citation_marker_forms(string $input, string $expected): void
    {
        $cleaner = new ArticleCitationMarkerCleaner;

        $this->assertSame($expected, $cleaner->cleanContent($input));
    }

    /** @return array<string, array{string, string}> */
    public static function markerCases(): array
    {
        return [
            'adjacent markers' => ['甲[K1][K2]，乙', '甲，乙'],
            'adjacent markers after whitespace' => ['正文 [K1][K2]。', '正文。'],
            'ranges and full width digits' => ['甲【K1–K3】，乙［Ｋ ４］', '甲，乙'],
            'outer parentheses' => ['甲（[K1]），乙', '甲，乙'],
            'superscript' => ['甲<sup>[K1]</sup>，乙', '甲，乙'],
            'markdown link' => ['甲[K1](https://example.com/source)，乙', '甲，乙'],
            'escaped and encoded brackets' => ['甲\\[K1\\]，乙&#91;K2&#93;', '甲，乙'],
            'numeric citations' => ['甲[1][2-3]，乙<sup>4</sup>', '甲，乙'],
            'entity and zero width ids' => ["甲[K&#49;]，乙[K\u{2060}2]，丙&lsqb;K&#x33;&rsqb;", '甲，乙，丙'],
            'space separated ids' => ['甲[K1 K2]，乙', '甲，乙'],
            'footnote definition' => ["正文[^K1]。\n\n[^K1]: source\n下一段", "正文。\n\n下一段"],
            'ascii word spacing' => ['See [K1]details', 'See details'],
            'markdown list spacing' => ['- [K1] statement', '- statement'],
            'markdown table spacing' => ['| [K1] | x |', '| | x |'],
            'chinese citation cluster' => ['内容[K1]、[K2]。', '内容。'],
            'english citation cluster' => ['Evidence [1], [2], and [3].', 'Evidence.'],
            'wrapped citation cluster' => ['内容（[K1]、[K2]）。', '内容。'],
            'relative markdown link' => ['内容[K1](/sources/1)。', '内容。'],
            'markdown reference link' => ['内容[K1][source]。', '内容。'],
            'contextual bare marker' => ['资料显示 K1，结果成立。', '资料显示，结果成立。'],
        ];
    }

    public function test_it_preserves_legitimate_k_terms_and_parenthetical_content(): void
    {
        $cleaner = new ArticleCitationMarkerCleaner;
        $input = 'Vitamin K1、K2 山峰、K1 签证、ABC-K1-Pro、/K1/。正文 [K3] (仅适用专业版)。';

        $this->assertSame(
            'Vitamin K1、K2 山峰、K1 签证、ABC-K1-Pro、/K1/。正文 (仅适用专业版)。',
            $cleaner->cleanContent($input),
        );
    }

    public function test_it_preserves_numeric_enumerators_outside_square_citation_forms(): void
    {
        $cleaner = new ArticleCitationMarkerCleaner;
        $input = '步骤（1）准备，（2）执行。版本 (1) 适用，编号【1】保留。';

        $this->assertSame($input, $cleaner->cleanContent($input));
    }

    public function test_generated_content_preserves_legitimate_bare_k_terms_during_source_id_collisions(): void
    {
        $cleaner = new ArticleCitationMarkerCleaner;

        $this->assertSame(
            'Vitamin K1 有助于凝血。K1 签证申请指南。',
            $cleaner->cleanGeneratedContent('Vitamin K1 有助于凝血。K1 签证申请指南。[K1]', '来源 [K1][K2]'),
        );
    }

    public function test_article_field_cleanup_preserves_legitimate_summary_k_terms(): void
    {
        $cleaner = new ArticleCitationMarkerCleaner;
        $fields = $cleaner->cleanArticleFields([
            'content' => '维生素 K1 有助于凝血。[K1]',
            'excerpt' => '维生素 K1 有助于凝血。',
            'meta_description' => 'K1 签证申请指南',
        ]);

        $this->assertSame('维生素 K1 有助于凝血。', $fields['content']);
        $this->assertSame('维生素 K1 有助于凝血。', $fields['excerpt']);
        $this->assertSame('K1 签证申请指南', $fields['meta_description']);
    }

    public function test_cleaning_is_idempotent_and_does_not_reformat_unrelated_text(): void
    {
        $cleaner = new ArticleCitationMarkerCleaner;
        $input = 'Hello , world。Vitamin K1。';

        $this->assertSame($input, $cleaner->cleanContent($input));
        $this->assertFalse($cleaner->contains($input));
        $this->assertSame('Summary with trailing space ', $cleaner->cleanGeneratedSummary('Summary with trailing space '));
    }

    public function test_oversized_invalid_marker_does_not_block_later_valid_marker_cleanup(): void
    {
        $cleaner = new ArticleCitationMarkerCleaner;
        $input = '['.str_repeat('K1 ', 5000).'X] trailing [K9]';

        $this->assertStringEndsWith('trailing', trim($cleaner->cleanContent($input)));
    }
}
