<?php

namespace Tests\Unit;

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\WordPressMediaSyncService;
use App\Services\GeoFlow\WordPressRestPublisher;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordPressMediaSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uploads_base64_image_asset_to_wordpress_media(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/media' => Http::response([
                'id' => 456,
                'source_url' => 'https://wp.example.com/wp-content/uploads/image.jpg',
            ], 201),
        ]);

        $html = app(WordPressMediaSyncService::class)->rewriteContentImages(
            $this->makeChannel(),
            $this->payloadWithImage(),
            '<p><img src="/storage/uploads/images/demo.png" alt="demo"></p>'
        );

        $this->assertStringContainsString('https://wp.example.com/wp-content/uploads/image.jpg', $html);
        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://wp.example.com/wp-json/wp/v2/media'
                && $request->hasHeader('Content-Disposition', 'attachment; filename="demo.png"')
                && $request->body() === 'image-bytes';
        });
    }

    public function test_it_rewrites_content_html_image_src_to_wordpress_media_url(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/media' => Http::response([
                'id' => 456,
                'source_url' => 'https://wp.example.com/wp-content/uploads/image.jpg',
            ], 201),
        ]);

        $html = app(WordPressMediaSyncService::class)->rewriteContentImages(
            $this->makeChannel(),
            $this->payloadWithImage(),
            '<figure><img src="/storage/uploads/images/demo.png"></figure>'
        );

        $this->assertSame('<figure><img src="https://wp.example.com/wp-content/uploads/image.jpg"></figure>', $html);
    }

    public function test_it_keeps_original_src_when_asset_has_no_base64_content(): void
    {
        Http::fake();

        $html = app(WordPressMediaSyncService::class)->rewriteContentImages(
            $this->makeChannel(),
            [
                'assets' => [
                    'images' => [
                        ['source_url' => 'https://cdn.example.com/image.jpg', 'filename' => 'image.jpg'],
                    ],
                ],
            ],
            '<p><img src="https://cdn.example.com/image.jpg"></p>'
        );

        $this->assertSame('<p><img src="https://cdn.example.com/image.jpg"></p>', $html);
        Http::assertNothingSent();
    }

    public function test_it_skips_upload_when_image_strategy_is_keep_original(): void
    {
        Http::fake();

        $html = app(WordPressMediaSyncService::class)->rewriteContentImages(
            $this->makeChannel(['wordpress_image_strategy' => 'keep_original']),
            $this->payloadWithImage(),
            '<p><img src="/storage/uploads/images/demo.png"></p>'
        );

        $this->assertSame('<p><img src="/storage/uploads/images/demo.png"></p>', $html);
        Http::assertNothingSent();
    }

    public function test_publisher_uses_uploaded_media_url_in_post_content(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/media' => Http::response([
                'id' => 456,
                'source_url' => 'https://wp.example.com/wp-content/uploads/image.jpg',
            ], 201),
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello/',
            ], 201),
        ]);

        $channel = $this->makeChannel();
        $distribution = \App\Models\ArticleDistribution::query()->create([
            'article_id' => $this->makeArticleId(),
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'wp-media-test',
        ]);

        app(WordPressRestPublisher::class)->publish($distribution, [
            'article' => [
                'title' => 'Hello',
                'slug' => 'hello',
                'excerpt' => '',
                'content_html' => '<p><img src="/storage/uploads/images/demo.png"></p>',
                'keywords' => '',
            ],
            'assets' => $this->payloadWithImage()['assets'],
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts'
            && $request['content'] === '<p><img src="https://wp.example.com/wp-content/uploads/image.jpg"></p>');
    }

    /**
     * @param  array<string,string>  $configOverrides
     */
    private function makeChannel(array $configOverrides = []): DistributionChannel
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'WP',
            'domain' => 'wp.example.com',
            'endpoint_url' => 'https://wp.example.com',
            'channel_type' => 'wordpress_rest',
            'channel_config' => array_replace([
                'wordpress_username' => 'editor',
                'wordpress_category_strategy' => 'fixed',
                'wordpress_fixed_category' => '',
                'wordpress_tag_strategy' => 'disabled',
                'wordpress_image_strategy' => 'upload_to_media',
            ], $configOverrides),
            'status' => 'active',
        ]);

        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'wp_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('app password'),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);

        return $channel;
    }

    /**
     * @return array<string,mixed>
     */
    private function payloadWithImage(): array
    {
        return [
            'assets' => [
                'images' => [
                    [
                        'source_url' => '/storage/uploads/images/demo.png',
                        'filename' => 'demo.png',
                        'mime_type' => 'image/png',
                        'content_base64' => base64_encode('image-bytes'),
                    ],
                ],
            ],
        ];
    }

    private function makeArticleId(): int
    {
        $category = \App\Models\Category::query()->create(['name' => 'Tech', 'slug' => 'tech']);
        $author = \App\Models\Author::query()->create(['name' => 'GEOFlow']);
        $article = \App\Models\Article::query()->create([
            'title' => 'Hello',
            'slug' => 'hello',
            'content' => 'Hello',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
        ]);

        return (int) $article->id;
    }
}
