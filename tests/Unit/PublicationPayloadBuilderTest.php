<?php

namespace Tests\Unit;

use App\Services\BrowserOperations\PublicationPayloadBuilder;
use PHPUnit\Framework\TestCase;

class PublicationPayloadBuilderTest extends TestCase
{
    public function test_maps_supported_platform_post_to_platform_target_action_and_appends_extras(): void
    {
        $builder = new PublicationPayloadBuilder;

        $payload = $builder->build([
            'type' => 'post',
            'platform' => 'toutiao',
            'content' => 'Body',
            'source_snapshot' => ['title' => 'T'],
            'publication_payload_extras' => [
                'append_source_link' => true,
                'source_url' => 'https://example.com/a',
                'images' => [['url' => 'https://example.com/1.jpg', 'alt' => '']],
            ],
        ]);

        $this->assertSame('toutiao_post', $payload['target_action']);
        $this->assertTrue($payload['append_source_link']);
        $this->assertSame('https://example.com/a', $payload['source_url']);
        $this->assertCount(1, $payload['images']);
        $this->assertSame('https://example.com/1.jpg', $payload['images'][0]['url']);
        // existing shape stays intact
        $this->assertSame(1, $payload['schema_version']);
        $this->assertSame('T', $payload['title']);
        $this->assertSame('Body', $payload['body_plain']);
        $this->assertSame('Body', $payload['body_markdown']);
        $this->assertSame([], $payload['tags']);
        $this->assertSame([], $payload['asset_ids']);
        $this->assertArrayNotHasKey('publication_payload_extras', $payload);
    }

    public function test_maps_sohu_and_netease_post_target_actions(): void
    {
        $builder = new PublicationPayloadBuilder;

        $sohu = $builder->build(['type' => 'post', 'platform' => 'sohu']);
        $netease = $builder->build(['type' => 'post', 'platform' => 'netease']);

        $this->assertSame('sohu_post', $sohu['target_action']);
        $this->assertSame('netease_post', $netease['target_action']);
    }

    public function test_zhihu_post_still_maps_to_zhihu_answer(): void
    {
        $builder = new PublicationPayloadBuilder;

        $payload = $builder->build(['type' => 'post', 'platform' => 'zhihu']);

        $this->assertSame('zhihu_answer', $payload['target_action']);
    }

    public function test_comment_type_wins_over_platform(): void
    {
        $builder = new PublicationPayloadBuilder;

        $payload = $builder->build(['type' => 'comment', 'platform' => 'toutiao']);

        $this->assertSame('manual_comment', $payload['target_action']);
    }

    public function test_defaults_when_no_extras_provided(): void
    {
        $builder = new PublicationPayloadBuilder;

        $payload = $builder->build(['type' => 'post', 'platform' => 'toutiao']);

        $this->assertSame([], $payload['images']);
        $this->assertFalse($payload['append_source_link']);
        $this->assertNull($payload['source_url']);
    }
}
