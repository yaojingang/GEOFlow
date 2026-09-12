<?php

namespace Tests\Unit;

use App\Services\GeoFlow\AiVisibility\AiVisibilitySourceData;
use App\Services\GeoFlow\AiVisibility\DeepSeekCompetitorParser;
use PHPUnit\Framework\TestCase;

class DeepSeekCompetitorParserTest extends TestCase
{
    public function test_it_accepts_markdown_json_and_rejects_evidence_urls_not_in_sources(): void
    {
        $sources = [new AiVisibilitySourceData(
            sourceType: 'web_search_result', citationKey: 'S1', title: '有效文章',
            url: 'https://news.example.com/a', domain: 'news.example.com', siteName: '中华网',
            rank: 2, authorityLevel: '3', metadata: ['authority_label' => '一般权威'],
        )];
        $text = <<<'JSON'
```json
{"competitors":[{"name":"同行甲","aliases":["甲品牌"],"confidence":0.9,"evidence":[{"url":"https://news.example.com/a","reason":"明确提及"},{"url":"https://invented.example/b","reason":"模型编造"}]}]}
```
JSON;

        $result = (new DeepSeekCompetitorParser)->parse($text, $sources);

        $this->assertSame('parsed', $result['status']);
        $this->assertSame('同行甲', $result['competitors'][0]['name']);
        $this->assertSame('verified', $result['competitors'][0]['verification']);
        $this->assertSame(1, $result['competitors'][0]['mention_count']);
        $this->assertCount(1, $result['competitors'][0]['evidence']);
        $this->assertSame('中华网', $result['competitors'][0]['evidence'][0]['site_name']);
        $this->assertSame('一般权威', $result['competitors'][0]['evidence'][0]['authority_label']);
    }

    public function test_it_normalizes_near_identical_evidence_urls(): void
    {
        $sources = [
            new AiVisibilitySourceData(
                sourceType: 'web_search_result', citationKey: 'S1', title: '文章一',
                url: 'https://news.example.com/a', domain: 'news.example.com', rank: 1,
            ),
            new AiVisibilitySourceData(
                sourceType: 'web_search_result', citationKey: 'S2', title: '文章二',
                url: 'https://example.cn/page?id=7', domain: 'example.cn', rank: 2,
            ),
        ];
        $text = <<<'JSON'
{"competitors":[
  {"name":"同行甲","evidence":[{"url":"http://News.Example.com/a/","reason":"改写协议、大小写和末尾斜杠"}]},
  {"name":"同行乙","evidence":[{"url":"https://www.example.cn/page","reason":"丢失 query 且新增 www 前缀"}]}
]}
JSON;

        $result = (new DeepSeekCompetitorParser)->parse($text, $sources);

        $this->assertSame('verified', $result['competitors'][0]['verification']);
        $this->assertSame('S1', $result['competitors'][0]['evidence'][0]['source_id']);
        $this->assertSame('verified', $result['competitors'][1]['verification']);
        $this->assertSame('S2', $result['competitors'][1]['evidence'][0]['source_id']);
    }

    public function test_it_keeps_competitors_without_matching_evidence_as_unverified(): void
    {
        $text = <<<'JSON'
{"competitors":[
  {"name":"同行甲","evidence":[{"url":"https://invented.example/b","reason":"模型编造"}]},
  {"name":"同行乙"}
]}
JSON;

        $result = (new DeepSeekCompetitorParser)->parse($text, []);

        $this->assertSame('parsed', $result['status']);
        $this->assertCount(2, $result['competitors']);
        $this->assertSame('unverified', $result['competitors'][0]['verification']);
        $this->assertSame(0, $result['competitors'][0]['mention_count']);
        $this->assertSame([], $result['competitors'][0]['evidence']);
        $this->assertSame('unverified', $result['competitors'][1]['verification']);
    }

    public function test_it_marks_invalid_json_for_review(): void
    {
        $result = (new DeepSeekCompetitorParser)->parse('这不是 JSON', []);

        $this->assertSame('review_required', $result['status']);
        $this->assertSame([], $result['competitors']);
    }
}
