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
}
