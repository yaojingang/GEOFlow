<?php

namespace Tests\Unit;

use App\Support\Site\ArticleHtmlPresenter;
use PHPUnit\Framework\TestCase;

class ArticleHtmlPresenterTest extends TestCase
{
    public function test_markdown_renderer_supports_github_flavored_markdown(): void
    {
        $html = ArticleHtmlPresenter::markdownToHtml(<<<'MD'
## Checklist

| 项目 | 状态 |
| --- | --- |
| 表格 | 正常 |

- [x] 已完成
- [ ] 未完成

~~删除线~~
https://example.com/docs
MD);

        $this->assertStringContainsString('<div class="article-table-wrap"><table class="article-table">', $html);
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('checked=""', $html);
        $this->assertStringContainsString('<del>删除线</del>', $html);
        $this->assertStringContainsString('<a href="https://example.com/docs">https://example.com/docs</a>', $html);
    }

    public function test_markdown_renderer_strips_unsafe_html(): void
    {
        $html = ArticleHtmlPresenter::markdownToHtml('<script>alert("x")</script>[bad](javascript:alert(1))');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }
}
