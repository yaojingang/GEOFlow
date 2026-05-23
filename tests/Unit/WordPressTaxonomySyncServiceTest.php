<?php

namespace Tests\Unit;

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\WordPressTaxonomySyncService;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordPressTaxonomySyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_matches_existing_category_by_slug(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/categories*' => Http::response([
                ['id' => 9, 'name' => 'Tech', 'slug' => 'tech'],
            ]),
        ]);

        $ids = app(WordPressTaxonomySyncService::class)->categoryIds($this->makeChannel(), [
            'article' => [
                'category' => ['name' => 'Tech', 'slug' => 'tech'],
            ],
        ]);

        $this->assertSame([9], $ids);
    }

    public function test_it_creates_category_when_strategy_is_match_or_create(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/categories?slug=geo' => Http::response([]),
            'https://wp.example.com/wp-json/wp/v2/categories' => Http::response([
                'id' => 17,
                'name' => 'GEO',
                'slug' => 'geo',
            ], 201),
        ]);

        $ids = app(WordPressTaxonomySyncService::class)->categoryIds($this->makeChannel(), [
            'article' => [
                'category' => ['name' => 'GEO', 'slug' => 'geo'],
            ],
        ]);

        $this->assertSame([17], $ids);
    }

    public function test_it_returns_empty_categories_when_match_only_misses(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/categories?slug=geo' => Http::response([]),
        ]);

        $ids = app(WordPressTaxonomySyncService::class)->categoryIds($this->makeChannel([
            'wordpress_category_strategy' => 'match_only',
        ]), [
            'article' => [
                'category' => ['name' => 'GEO', 'slug' => 'geo'],
            ],
        ]);

        $this->assertSame([], $ids);
    }

    public function test_it_converts_keywords_to_wordpress_tags(): void
    {
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/tags?slug=geo' => Http::response([
                ['id' => 21, 'name' => 'geo', 'slug' => 'geo'],
            ]),
            'https://wp.example.com/wp-json/wp/v2/tags?slug=ai' => Http::response([]),
            'https://wp.example.com/wp-json/wp/v2/tags' => Http::response([
                'id' => 22,
                'name' => 'ai',
                'slug' => 'ai',
            ], 201),
        ]);

        $ids = app(WordPressTaxonomySyncService::class)->tagIds($this->makeChannel(), [
            'article' => ['keywords' => 'geo, ai'],
        ]);

        $this->assertSame([21, 22], $ids);
    }

    public function test_it_skips_tags_when_tag_strategy_disabled(): void
    {
        Http::fake();

        $ids = app(WordPressTaxonomySyncService::class)->tagIds($this->makeChannel([
            'wordpress_tag_strategy' => 'disabled',
        ]), [
            'article' => ['keywords' => 'geo, ai'],
        ]);

        $this->assertSame([], $ids);
        Http::assertNothingSent();
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
                'wordpress_category_strategy' => 'match_or_create',
                'wordpress_tag_strategy' => 'keywords_to_tags',
                'wordpress_image_strategy' => 'keep_original',
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
}
