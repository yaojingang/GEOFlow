<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\WordPressRestPublisher;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class WordPressRestPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_article_to_wordpress_posts_endpoint(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ], 201),
        ]);

        [$channel, $distribution] = $this->makeDistribution();

        $result = app(WordPressRestPublisher::class)->publish($distribution, [
            'article' => [
                'title' => 'Hello World',
                'slug' => 'hello-world',
                'excerpt' => 'Short summary',
                'content_html' => '<p>Hello</p>',
                'keywords' => 'geo, ai',
                'meta_description' => 'Meta summary',
            ],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('123', (string) $result['remote_id']);
        $this->assertSame('https://wp.example.com/hello-world/', $result['remote_url']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts'
                && $request['title'] === 'Hello World'
                && $request['status'] === 'publish'
                && $request['content'] === '<p>Hello</p>';
        });
    }

    public function test_it_updates_existing_wordpress_post_id(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts/123' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world-updated/',
            ]),
        ]);

        [$channel, $distribution] = $this->makeDistribution(['remote_id' => '123']);

        $result = app(WordPressRestPublisher::class)->update($distribution, [
            'article' => [
                'title' => 'Hello Updated',
                'slug' => 'hello-world',
                'excerpt' => '',
                'content_html' => '<p>Updated</p>',
                'keywords' => '',
                'meta_description' => '',
            ],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('123', (string) $result['remote_id']);
        $this->assertSame('https://wp.example.com/hello-world-updated/', $result['remote_url']);
    }

    public function test_it_deletes_existing_wordpress_post_id(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts/123*' => Http::response([
                'deleted' => false,
                'previous' => ['id' => 123],
            ]),
        ]);

        [$channel, $distribution] = $this->makeDistribution(['remote_id' => '123']);

        $result = app(WordPressRestPublisher::class)->delete($distribution);

        $this->assertSame('123', (string) $result['remote_id']);
        $this->assertTrue($result['deleted']);
    }

    public function test_health_uses_authenticated_current_user_endpoint(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json' => Http::response(['name' => 'WordPress']),
            'https://wp.example.com/wp-json/wp/v2/users/me*' => Http::response([
                'id' => 7,
                'name' => 'Editor',
                'capabilities' => ['edit_posts' => true, 'publish_posts' => true, 'upload_files' => true],
            ]),
        ]);

        [$channel] = $this->makeDistribution();

        $result = app(WordPressRestPublisher::class)->health($channel);

        $this->assertTrue($result['ok']);
        $this->assertSame('wordpress_rest', $result['channel_type']);
        $this->assertSame(7, $result['user_id']);
        $this->assertTrue($result['can_edit_posts']);
    }

    public function test_it_syncs_supported_site_settings_to_wordpress_settings_endpoint(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/settings' => Http::response([
                'title' => '远程 WordPress',
                'description' => '远程站点描述',
                'posts_per_page' => 16,
            ]),
        ]);

        [$channel] = $this->makeDistribution();
        $channel->forceFill([
            'site_settings' => [
                'site_name' => '远程 WordPress',
                'site_description' => '远程站点描述',
                'per_page' => 16,
            ],
        ])->save();

        $result = app(WordPressRestPublisher::class)->syncSiteSettings($channel);

        $this->assertTrue($result['ok']);
        $this->assertSame([
            'title' => '远程 WordPress',
            'description' => '远程站点描述',
            'posts_per_page' => 16,
        ], $result['settings']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://wp.example.com/wp-json/wp/v2/settings'
            && $request['title'] === '远程 WordPress'
            && $request['description'] === '远程站点描述'
            && $request['posts_per_page'] === 16);
    }

    public function test_orchestrator_persists_wordpress_remote_metadata(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ], 201),
        ]);

        [$channel, $distribution] = $this->makeDistribution();

        app(DistributionOrchestrator::class)->process($distribution);

        $distribution->refresh();
        $this->assertSame('synced', (string) $distribution->status);
        $this->assertSame('123', (string) $distribution->remote_id);
        $this->assertSame(123, $distribution->remote_meta['wordpress_post_id'] ?? null);
    }

    public function test_remote_wordpress_error_body_is_redacted(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response('password=super-secret target=10.0.0.9', 500),
        ]);
        [, $distribution] = $this->makeDistribution();

        try {
            app(WordPressRestPublisher::class)->publish($distribution, ['article' => [], 'assets' => []]);
            $this->fail('Expected publisher failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('super-secret', $exception->getMessage());
            $this->assertStringNotContainsString('10.0.0.9', $exception->getMessage());
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }
    }

    /**
     * @param  array<string,mixed>  $distributionOverrides
     * @return array{0:DistributionChannel,1:ArticleDistribution}
     */
    private function makeDistribution(array $distributionOverrides = []): array
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'WP',
            'domain' => 'wp.example.com',
            'endpoint_url' => 'https://wp.example.com',
            'channel_type' => 'wordpress_rest',
            'channel_config' => [
                'wordpress_username' => 'editor',
                'wordpress_post_status' => 'publish',
                'wordpress_category_strategy' => 'fixed',
                'wordpress_fixed_category' => '',
                'wordpress_tag_strategy' => 'disabled',
                'wordpress_image_strategy' => 'keep_original',
            ],
            'status' => 'active',
        ]);

        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'wp_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('app password'),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);

        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $article = Article::query()->create([
            'title' => 'Hello World',
            'slug' => 'hello-world',
            'content' => 'Hello',
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        $distribution = ArticleDistribution::query()->create(array_merge([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'wp-test-key',
        ], $distributionOverrides));

        return [$channel, $distribution];
    }
}
