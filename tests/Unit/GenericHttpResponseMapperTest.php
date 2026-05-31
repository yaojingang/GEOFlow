<?php

namespace Tests\Unit;

use App\Services\GeoFlow\GenericHttpResponseMapper;
use Tests\TestCase;

class GenericHttpResponseMapperTest extends TestCase
{
    public function test_it_maps_remote_id_and_url_from_nested_paths(): void
    {
        $result = app(GenericHttpResponseMapper::class)->map([
            'data' => [
                'article' => [
                    'id' => 'remote-123',
                    'url' => 'https://target.example.com/article/remote-123',
                ],
            ],
        ], [
            'generic_remote_id_path' => 'data.article.id',
            'generic_remote_url_path' => 'data.article.url',
        ]);

        $this->assertSame('remote-123', $result['remote_id']);
        $this->assertSame('https://target.example.com/article/remote-123', $result['remote_url']);
        $this->assertSame('remote-123', $result['remote_meta']['generic_http_response']['data']['article']['id']);
    }
}
