<?php

namespace Tests\Unit;

use App\Services\BrowserOperations\PublicationPayloadBuilder;
use App\Services\GeoFlow\Distribution\PlatformWeb\PlatformWebContentFormatter;
use App\Support\Site\ArticleHtmlPresenter;
use Tests\TestCase;

class PlatformWebContentFormatterTest extends TestCase
{
    public function test_saved_html_and_legacy_markdown_produce_the_shared_clipboard_contract(): void
    {
        $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/platform-web/clipboard.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ([false, true] as $legacy) {
            $snapshot = ['title' => $fixture['title'], 'content' => $fixture['markdown']];
            if (! $legacy) {
                $snapshot['content_html'] = ArticleHtmlPresenter::markdownToHtml($fixture['markdown']);
                $snapshot['content'] = 'This raw Markdown must not override saved rendered HTML.';
            }
            $plain = PlatformWebContentFormatter::fromSnapshot($snapshot);
            $payload = (new PublicationPayloadBuilder)->build(['type' => 'post', 'platform' => 'toutiao',
                'source_snapshot' => $snapshot, 'content' => $plain]);
            $this->assertSame($fixture['plain'], $payload['body_plain']);
            $this->assertStringNotContainsString('https://example.test', $payload['body_plain']);
        }
    }
}
