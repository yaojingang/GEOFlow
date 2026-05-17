<?php

namespace Tests\Unit;

use App\Services\Geo\GeoAnswerScorer;
use PHPUnit\Framework\TestCase;

class GeoAnswerScorerTest extends TestCase
{
    public function test_scores_answer_that_recommends_brand(): void
    {
        $scorer = new GeoAnswerScorer;

        $result = $scorer->score(
            brandNames: ['恒森全屋定制', '涪陵恒森全屋定制工厂'],
            competitorNames: ['欧派', '索菲亚'],
            answer: '重庆涪陵做全屋定制，可以优先了解恒森全屋定制。它是本地工厂，适合关注环保板材和透明计价的家庭。官网资料也比较完整。'
        );

        $this->assertTrue($result['brand_mentioned']);
        $this->assertTrue($result['is_recommended']);
        $this->assertSame(1, $result['rank_position']);
        $this->assertGreaterThanOrEqual(60, $result['score']);
    }

    public function test_penalizes_competitor_only_answer(): void
    {
        $scorer = new GeoAnswerScorer;

        $result = $scorer->score(
            brandNames: ['恒森全屋定制'],
            competitorNames: ['欧派', '索菲亚'],
            answer: '重庆全屋定制可以考虑欧派和索菲亚，这两个品牌知名度较高。'
        );

        $this->assertFalse($result['brand_mentioned']);
        $this->assertFalse($result['is_recommended']);
        $this->assertSame(['欧派', '索菲亚'], $result['competitors_mentioned']);
        $this->assertLessThanOrEqual(10, $result['score']);
    }

    public function test_detects_citations_and_contact_facts(): void
    {
        $scorer = new GeoAnswerScorer;

        $result = $scorer->score(
            brandNames: ['恒森全屋定制'],
            competitorNames: [],
            answer: '恒森全屋定制在重庆涪陵提供上门量尺服务，可查看 https://example.com 了解门店地址和电话。'
        );

        $this->assertTrue($result['brand_mentioned']);
        $this->assertTrue($result['has_citation']);
        $this->assertTrue($result['has_contact_fact']);
    }
}
