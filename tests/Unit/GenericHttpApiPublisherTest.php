<?php

namespace Tests\Unit;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\GenericHttpApiPublisher;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class GenericHttpApiPublisherTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_publishes_article_with_bearer_token_and_maps_response(): void
    {
        Http::fake([
            'https://api.example.com/articles' => Http::response([
                'data' => [
                    'id' => 'remote-123',
                    'url' => 'https://api.example.com/a/remote-123',
                ],
            ], 201),
        ]);

        [$channel, $distribution] = $this->makeDistribution([
            'generic_remote_id_path' => 'data.id',
            'generic_remote_url_path' => 'data.url',
        ]);

        $result = app(GenericHttpApiPublisher::class)->publish($distribution, [
            'event' => 'article.publish',
            'article' => [
                'title' => 'Hello Generic API',
                'slug' => 'hello-generic-api',
                'content' => 'Hello',
            ],
            'assets' => ['images' => []],
        ]);

        $this->assertSame('remote-123', $result['remote_id']);
        $this->assertSame('https://api.example.com/a/remote-123', $result['remote_url']);
        $this->assertSame(201, $result['remote_meta']['generic_http']['status_code']);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.example.com/articles'
                && $request->hasHeader('Authorization', 'Bearer api-token')
                && $request->hasHeader('X-GEOFlow-Event', 'article.publish')
                && $request['article']['title'] === 'Hello Generic API';
        });

        $channel->activeSecret?->refresh();
        $this->assertNotNull($channel->activeSecret?->last_used_at);
    }

    public function test_it_updates_and_deletes_using_remote_id_path_token(): void
    {
        Http::fake([
            'https://api.example.com/articles/remote-123' => Http::response([
                'id' => 'remote-123',
                'url' => 'https://api.example.com/a/remote-123',
            ]),
        ]);

        [, $distribution] = $this->makeDistribution([], ['remote_id' => 'remote-123']);

        $update = app(GenericHttpApiPublisher::class)->update($distribution, [
            'event' => 'article.update',
            'article' => [
                'title' => 'Updated Generic API',
                'slug' => 'hello-generic-api',
                'content' => 'Updated',
            ],
        ]);
        $delete = app(GenericHttpApiPublisher::class)->delete($distribution);

        $this->assertSame('remote-123', $update['remote_id']);
        $this->assertSame('remote-123', $delete['remote_id']);
        $this->assertTrue($delete['deleted']);

        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.example.com/articles/remote-123');
        Http::assertSent(fn ($request): bool => $request->method() === 'DELETE'
            && $request->url() === 'https://api.example.com/articles/remote-123');
    }

    public function test_health_supports_no_auth_channels(): void
    {
        Http::fake([
            'https://api.example.com/health' => Http::response(['ok' => true]),
        ]);

        [$channel] = $this->makeDistribution(['generic_auth_type' => 'none'], [], false);

        $result = app(GenericHttpApiPublisher::class)->health($channel);

        $this->assertTrue($result['ok']);
        $this->assertSame('generic_http_api', $result['channel_type']);
        $this->assertSame(200, $result['status_code']);

        Http::assertSent(fn ($request): bool => $request->method() === 'GET'
            && $request->url() === 'https://api.example.com/health'
            && ! $request->hasHeader('Authorization'));
    }

    public function test_site_settings_sync_is_skipped_when_endpoint_is_blank(): void
    {
        Http::fake();

        [$channel] = $this->makeDistribution(['generic_settings_path' => '']);

        $result = app(GenericHttpApiPublisher::class)->syncSiteSettings($channel);

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['skipped']);
        $this->assertSame('generic_settings_path_empty', $result['reason']);
        Http::assertNothingSent();
    }

    public function test_hmac_auth_sends_signature_headers(): void
    {
        Http::fake([
            'https://api.example.com/articles' => Http::response([
                'id' => 'remote-hmac',
                'url' => 'https://api.example.com/a/remote-hmac',
            ], 201),
        ]);

        [, $distribution] = $this->makeDistribution(['generic_auth_type' => 'hmac']);

        app(GenericHttpApiPublisher::class)->publish($distribution, [
            'event' => 'article.publish',
            'article' => [
                'title' => 'Signed Generic API',
                'slug' => 'signed-generic-api',
                'content' => 'Signed',
            ],
        ]);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'POST'
                && $request->url() === 'https://api.example.com/articles'
                && $request->hasHeader('X-GEOFlow-Key-Id', 'gapi_test')
                && $request->hasHeader('X-GEOFlow-Signature')
                && $request->hasHeader('X-GEOFlow-Timestamp')
                && $request->hasHeader('X-GEOFlow-Nonce')
                && $request->hasHeader('X-GEOFlow-Body-SHA256')
                && ! $request->hasHeader('Authorization');
        });
    }

    public function test_orchestrator_persists_generic_remote_metadata(): void
    {
        Http::fake([
            'https://api.example.com/articles' => Http::response([
                'id' => 'remote-999',
                'url' => 'https://api.example.com/a/remote-999',
            ], 201),
        ]);

        [, $distribution] = $this->makeDistribution();

        app(DistributionOrchestrator::class)->process($distribution);

        $distribution->refresh();
        $this->assertSame('synced', (string) $distribution->status);
        $this->assertSame('remote-999', (string) $distribution->remote_id);
        $this->assertSame(201, $distribution->remote_meta['generic_http']['status_code'] ?? null);
    }

    public function test_remote_error_bodies_and_target_details_are_redacted(): void
    {
        Http::fake([
            'https://api.example.com/articles' => Http::response('token=super-secret target=10.0.0.9', 500),
        ]);
        [, $distribution] = $this->makeDistribution();

        try {
            app(GenericHttpApiPublisher::class)->publish($distribution, ['article' => []]);
            $this->fail('Expected publisher failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString('super-secret', $exception->getMessage());
            $this->assertStringNotContainsString('10.0.0.9', $exception->getMessage());
            $this->assertStringNotContainsString('api.example.com', $exception->getMessage());
            $this->assertStringContainsString('HTTP 500', $exception->getMessage());
        }
    }

    /**
     * @param  array<string,mixed>  $configOverrides
     * @param  array<string,mixed>  $distributionOverrides
     * @return array{0:DistributionChannel,1:ArticleDistribution}
     */
    private function makeDistribution(array $configOverrides = [], array $distributionOverrides = [], bool $withSecret = true): array
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Generic API',
            'domain' => 'api.example.com',
            'endpoint_url' => 'https://api.example.com',
            'channel_type' => 'generic_http_api',
            'channel_config' => array_merge([
                'generic_auth_type' => 'bearer',
                'generic_success_statuses' => [200, 201, 202, 204],
                'generic_health_method' => 'GET',
                'generic_health_path' => '/health',
                'generic_publish_method' => 'POST',
                'generic_publish_path' => '/articles',
                'generic_update_method' => 'POST',
                'generic_update_path' => '/articles/{remote_id}',
                'generic_delete_method' => 'DELETE',
                'generic_delete_path' => '/articles/{remote_id}',
                'generic_settings_method' => 'POST',
                'generic_settings_path' => '/site-settings',
                'generic_remote_id_path' => 'id',
                'generic_remote_url_path' => 'url',
                'generic_payload_wrapper' => 'none',
            ], $configOverrides),
            'status' => 'active',
        ]);

        if ($withSecret) {
            DistributionChannelSecret::query()->create([
                'distribution_channel_id' => (int) $channel->id,
                'key_id' => 'gapi_test',
                'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('api-token'),
                'status' => 'active',
                'scopes' => ['generic.http'],
            ]);
        }

        $category = Category::query()->create([
            'name' => '科技资讯',
            'slug' => 'tech',
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $article = Article::query()->create([
            'title' => 'Hello Generic API',
            'slug' => 'hello-generic-api',
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
            'idempotency_key' => 'generic-test-key',
        ], $distributionOverrides));

        return [$channel, $distribution];
    }
}
