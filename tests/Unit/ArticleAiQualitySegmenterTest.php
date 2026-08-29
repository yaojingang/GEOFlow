<?php

namespace Tests\Unit;

use App\Services\GeoFlow\ArticleAiQualitySegmenter;
use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

class ArticleAiQualitySegmenterTest extends TestCase
{
    public function test_markdown_is_split_with_global_unicode_offsets(): void
    {
        $content = "# 第一部分\n\n产品价格为 980 元，适用于企业版。\n\n## 第二部分\n\n".str_repeat('这是很长的一段说明。', 12);

        $segments = (new ArticleAiQualitySegmenter(maxCharacters: 60))->segment($content);

        $this->assertGreaterThan(2, count($segments));
        $this->assertSame(0, $segments[0]['index']);
        foreach ($segments as $segment) {
            $this->assertLessThanOrEqual(60, Str::length($segment['content']));
            $this->assertSame(
                $segment['content'],
                Str::substr($content, $segment['start_offset'], $segment['end_offset'] - $segment['start_offset']),
            );
        }
    }
}
