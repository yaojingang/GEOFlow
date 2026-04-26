<?php

namespace Tests\Unit;

use App\Support\GeoFlow\ImageUrlNormalizer;
use Tests\TestCase;

class ImageUrlNormalizerTest extends TestCase
{
    public function test_relative_uploads_path_respects_configured_base_path(): void
    {
        config(['app.url' => 'https://example.com/wiki']);

        $this->assertSame('/wiki/storage/uploads/demo.png', ImageUrlNormalizer::toPublicUrl('uploads/demo.png'));
    }

    public function test_storage_path_respects_configured_base_path(): void
    {
        config(['app.url' => 'https://example.com/wiki']);

        $this->assertSame('/wiki/storage/uploads/demo.png', ImageUrlNormalizer::toPublicUrl('storage/uploads/demo.png'));
    }

    public function test_public_storage_path_is_normalized_once(): void
    {
        config(['app.url' => 'https://example.com/wiki']);

        $this->assertSame('/wiki/storage/uploads/demo.png', ImageUrlNormalizer::toPublicUrl('public/storage/uploads/demo.png'));
    }

    public function test_absolute_and_data_urls_are_not_changed(): void
    {
        config(['app.url' => 'https://example.com/wiki']);

        $this->assertSame('https://cdn.example.com/demo.png', ImageUrlNormalizer::toPublicUrl('https://cdn.example.com/demo.png'));
        $this->assertSame('data:image/png;base64,xxx', ImageUrlNormalizer::toPublicUrl('data:image/png;base64,xxx'));
    }

    public function test_path_already_contains_base_path_is_not_prefixed_twice(): void
    {
        config(['app.url' => 'https://example.com/wiki']);

        $this->assertSame('/wiki/storage/uploads/demo.png', ImageUrlNormalizer::toPublicUrl('/wiki/storage/uploads/demo.png'));
    }
}
