<?php

namespace Tests\Feature;

use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Models\Task;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionRetryPolicy;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WordPressDistributionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unchanged_synced_article_is_not_queued_for_wordpress_again(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ], 201),
        ]);
        [$article] = $this->createWordPressArticle();
        $orchestrator = app(DistributionOrchestrator::class);
        $distributionId = $orchestrator->enqueueForArticle($article)[0];
        $orchestrator->process(ArticleDistribution::query()->findOrFail($distributionId));

        $queuedIds = $orchestrator->enqueueForArticle($article->fresh());

        $this->assertSame([], $queuedIds);
        $this->assertSame('synced', ArticleDistribution::query()->findOrFail($distributionId)->status);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
        Http::assertSentCount(1);
    }

    public function test_changed_synced_article_updates_the_original_wordpress_post(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ], 201),
            'https://wp.example.com/wp-json/wp/v2/posts/123' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ]),
        ]);
        [$article] = $this->createWordPressArticle();
        $orchestrator = app(DistributionOrchestrator::class);
        $distributionId = $orchestrator->enqueueForArticle($article)[0];
        $orchestrator->process(ArticleDistribution::query()->findOrFail($distributionId));
        $article->forceFill(['content' => 'Updated content.'])->save();

        $updatedDistributionId = $orchestrator->enqueueForArticle($article->fresh())[0];
        $orchestrator->process(ArticleDistribution::query()->findOrFail($updatedDistributionId));

        $distribution = ArticleDistribution::query()->findOrFail($distributionId);
        $this->assertSame($distributionId, $updatedDistributionId);
        $this->assertSame('synced', $distribution->status);
        $this->assertSame('123', $distribution->remote_id);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts/123'
            && str_contains((string) $request['content'], 'Updated content.'));
    }

    public function test_immediate_update_preserves_the_original_publish_mapping(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts/123' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ]),
        ]);
        [$article, , $channel] = $this->createWordPressArticle();
        $publishDistribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => '123',
            'remote_url' => 'https://wp.example.com/hello-world/',
            'remote_meta' => ['wordpress_post_id' => 123],
            'idempotency_key' => 'wordpress-publish-123',
        ]);

        app(DistributionOrchestrator::class)->updateRemoteArticle($publishDistribution);

        $publishDistribution->refresh();
        $this->assertSame('publish', $publishDistribution->action);
        $this->assertSame('synced', $publishDistribution->status);
        $this->assertSame('123', $publishDistribution->remote_id);
        $this->assertNull(data_get($publishDistribution->remote_meta, 'wordpress_remote_deleted_at'));
        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'update',
            'status' => 'synced',
            'remote_id' => '123',
        ]);
        Http::assertSentCount(1);
    }

    public function test_successful_immediate_update_refreshes_the_publish_fingerprint(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ], 201),
            'https://wp.example.com/wp-json/wp/v2/posts/123' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ]),
        ]);
        [$article] = $this->createWordPressArticle();
        $orchestrator = app(DistributionOrchestrator::class);
        $publishDistributionId = $orchestrator->enqueueForArticle($article)[0];
        $orchestrator->process(ArticleDistribution::query()->findOrFail($publishDistributionId));
        $article->forceFill(['content' => 'Updated through the immediate action.'])->save();

        $orchestrator->updateRemoteArticle(ArticleDistribution::query()->findOrFail($publishDistributionId));
        $queuedIds = $orchestrator->enqueueForArticle($article->fresh());

        $this->assertSame([], $queuedIds);
        $this->assertSame('synced', ArticleDistribution::query()->findOrFail($publishDistributionId)->status);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
        Http::assertSentCount(2);
    }

    public function test_legacy_update_record_reuses_its_wordpress_post_for_a_later_publish(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts/123' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ]),
        ]);
        [$article, , $channel] = $this->createWordPressArticle('Updated content.');
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'update',
            'status' => 'synced',
            'remote_id' => '123',
            'remote_url' => 'https://wp.example.com/hello-world/',
            'remote_meta' => ['wordpress_post_id' => 123],
            'idempotency_key' => 'legacy-wordpress-update-123',
        ]);
        $orchestrator = app(DistributionOrchestrator::class);

        $distributionId = $orchestrator->enqueueForArticle($article)[0];
        $orchestrator->process(ArticleDistribution::query()->findOrFail($distributionId));

        $publishDistribution = ArticleDistribution::query()
            ->where('article_id', (int) $article->id)
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('action', 'publish')
            ->firstOrFail();
        $this->assertSame('synced', $publishDistribution->status);
        $this->assertSame('123', $publishDistribution->remote_id);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts/123');
    }

    public function test_unknown_wordpress_create_outcome_cannot_be_requeued_by_saving_the_article(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        [$article, , $channel] = $this->createWordPressArticle();
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'outcome_unknown',
            'next_retry_at' => null,
            'last_error_message' => 'Remote outcome requires reconciliation.',
            'idempotency_key' => 'wordpress-unknown-create',
        ]);

        $queuedIds = app(DistributionOrchestrator::class)->enqueueForArticle($article);

        $distribution->refresh();
        $this->assertSame([], $queuedIds);
        $this->assertSame('outcome_unknown', $distribution->status);
        $this->assertNull($distribution->next_retry_at);
        Queue::assertNothingPushed();
    }

    public function test_immediate_update_is_blocked_while_a_wordpress_delivery_is_in_progress(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [$article, , $channel] = $this->createWordPressArticle();
        $publishDistribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'sending',
            'idempotency_key' => 'wordpress-publish-in-progress',
        ]);

        $thrown = null;
        try {
            app(DistributionOrchestrator::class)->updateRemoteArticle($publishDistribution);
        } catch (\RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame(
            'WordPress 分发正在处理中或等待人工对账，当前操作已阻止。',
            $thrown->getMessage(),
        );
        $this->assertDatabaseMissing('article_distributions', [
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'update',
        ]);
        Http::assertSentCount(0);
    }

    public function test_conflicting_wordpress_post_ids_preserve_the_publish_mapping_and_block_delivery(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        [$article, , $channel] = $this->createWordPressArticle();
        $publishDistribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => '123',
            'remote_meta' => ['wordpress_post_id' => 123],
            'idempotency_key' => 'wordpress-publish-123',
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'update',
            'status' => 'synced',
            'remote_id' => '456',
            'remote_meta' => ['wordpress_post_id' => 456],
            'idempotency_key' => 'wordpress-update-456',
        ]);

        $queuedIds = app(DistributionOrchestrator::class)->enqueueForArticle($article);

        $publishDistribution->refresh();
        $this->assertSame([], $queuedIds);
        $this->assertSame('outcome_unknown', $publishDistribution->status);
        $this->assertSame('123', $publishDistribution->remote_id);
        $this->assertSame(
            [123, 456],
            data_get($publishDistribution->remote_meta, 'wordpress_identity_conflict.known_post_ids'),
        );
        Queue::assertNothingPushed();
    }

    public function test_conflicting_legacy_action_rows_create_a_blocking_publish_record(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        [$article, , $channel] = $this->createWordPressArticle();
        foreach ([['update', '123'], ['delete', '456']] as [$action, $remoteId]) {
            ArticleDistribution::query()->create([
                'article_id' => (int) $article->id,
                'distribution_channel_id' => (int) $channel->id,
                'action' => $action,
                'status' => 'synced',
                'remote_id' => $remoteId,
                'remote_meta' => ['wordpress_post_id' => (int) $remoteId],
                'idempotency_key' => 'legacy-wordpress-'.$action.'-'.$remoteId,
            ]);
        }

        $queuedIds = app(DistributionOrchestrator::class)->enqueueForArticle($article);

        $publishDistribution = ArticleDistribution::query()
            ->where('article_id', (int) $article->id)
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('action', 'publish')
            ->firstOrFail();
        $this->assertSame([], $queuedIds);
        $this->assertSame('outcome_unknown', $publishDistribution->status);
        $this->assertNotEmpty($publishDistribution->idempotency_key);
        $this->assertSame(
            [123, 456],
            data_get($publishDistribution->remote_meta, 'wordpress_identity_conflict.known_post_ids'),
        );
        Queue::assertNothingPushed();
    }

    public function test_immediate_update_is_blocked_when_action_rows_have_conflicting_post_ids(): void
    {
        Http::preventStrayRequests();
        Http::fake();
        [$article, , $channel] = $this->createWordPressArticle();
        $publishDistribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => '123',
            'remote_meta' => ['wordpress_post_id' => 123],
            'idempotency_key' => 'wordpress-publish-123',
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'update',
            'status' => 'synced',
            'remote_id' => '456',
            'remote_meta' => ['wordpress_post_id' => 456],
            'idempotency_key' => 'wordpress-update-456',
        ]);

        $thrown = null;
        try {
            app(DistributionOrchestrator::class)->updateRemoteArticle($publishDistribution);
        } catch (\RuntimeException $exception) {
            $thrown = $exception;
        }

        $publishDistribution->refresh();
        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame('检测到多个 WordPress 远端文章 ID，需要人工对账。', $thrown->getMessage());
        $this->assertSame('outcome_unknown', $publishDistribution->status);
        $this->assertSame('123', $publishDistribution->remote_id);
        $this->assertSame(
            [123, 456],
            data_get($publishDistribution->remote_meta, 'wordpress_identity_conflict.known_post_ids'),
        );
        Http::assertSentCount(0);
    }

    public function test_mismatched_wordpress_update_response_preserves_the_known_remote_id(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ], 201),
            'https://wp.example.com/wp-json/wp/v2/posts/123' => Http::response([
                'id' => 456,
                'link' => 'https://wp.example.com/unexpected-post/',
            ]),
        ]);
        [$article] = $this->createWordPressArticle();
        $orchestrator = app(DistributionOrchestrator::class);
        $distributionId = $orchestrator->enqueueForArticle($article)[0];
        $orchestrator->process(ArticleDistribution::query()->findOrFail($distributionId));
        $article->forceFill(['content' => 'Updated content.'])->save();
        $orchestrator->enqueueForArticle($article->fresh());

        $processed = $orchestrator->process(ArticleDistribution::query()->findOrFail($distributionId));

        $distribution = ArticleDistribution::query()->findOrFail($distributionId);
        $this->assertFalse($processed);
        $this->assertSame('outcome_unknown', $distribution->status);
        $this->assertSame('123', $distribution->remote_id);
        $this->assertSame(
            ['expected_post_id' => 123, 'returned_post_id' => 456],
            data_get($distribution->remote_meta, 'wordpress_identity_conflict'),
        );
        Http::assertSentCount(2);
    }

    public function test_mismatched_immediate_update_response_preserves_the_publish_mapping(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts/123' => Http::response([
                'id' => 456,
                'link' => 'https://wp.example.com/unexpected-post/',
            ]),
        ]);
        [$article, , $channel] = $this->createWordPressArticle();
        $publishDistribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => '123',
            'remote_url' => 'https://wp.example.com/hello-world/',
            'remote_meta' => ['wordpress_post_id' => 123],
            'idempotency_key' => 'wordpress-publish-123',
        ]);

        $thrown = null;
        try {
            app(DistributionOrchestrator::class)->updateRemoteArticle($publishDistribution);
        } catch (\RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame(
            'WordPress 返回的文章 ID 与已知远端身份不一致，需要人工对账。',
            $thrown->getMessage(),
        );
        $publishDistribution->refresh();
        $updateDistribution = ArticleDistribution::query()
            ->where('article_id', (int) $article->id)
            ->where('distribution_channel_id', (int) $channel->id)
            ->where('action', 'update')
            ->firstOrFail();
        $this->assertSame('123', $publishDistribution->remote_id);
        $this->assertSame('synced', $publishDistribution->status);
        $this->assertSame('123', $updateDistribution->remote_id);
        $this->assertSame('outcome_unknown', $updateDistribution->status);
        $this->assertSame(
            ['expected_post_id' => 123, 'returned_post_id' => 456],
            data_get($updateDistribution->remote_meta, 'wordpress_identity_conflict'),
        );
    }

    public function test_manual_reconciliation_cannot_replace_a_known_wordpress_post_id(): void
    {
        Http::preventStrayRequests();
        [$article, , $channel] = $this->createWordPressArticle();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts*' => Http::response([[
                'id' => 456,
                'link' => 'https://wp.example.com/unexpected-post/',
                'slug' => (string) $article->slug,
            ]]),
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'outcome_unknown',
            'remote_id' => '123',
            'remote_url' => 'https://wp.example.com/hello-world/',
            'remote_meta' => [
                'wordpress_post_id' => 123,
                'distribution_payload' => [
                    'article' => ['slug' => (string) $article->slug],
                ],
            ],
            'idempotency_key' => 'wordpress-publish-123',
        ]);

        $reconciled = app(DistributionOrchestrator::class)->reconcileUnknownOutcome($distribution);

        $distribution->refresh();
        $this->assertFalse($reconciled);
        $this->assertSame('outcome_unknown', $distribution->status);
        $this->assertSame('123', $distribution->remote_id);
        $this->assertSame(
            ['expected_post_id' => 123, 'returned_post_id' => 456],
            data_get($distribution->remote_meta, 'wordpress_identity_conflict'),
        );
        Http::assertSentCount(1);
    }

    public function test_manual_reconciliation_checks_the_known_id_on_sibling_action_rows(): void
    {
        Http::preventStrayRequests();
        [$article, , $channel] = $this->createWordPressArticle();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts*' => Http::response([[
                'id' => 456,
                'link' => 'https://wp.example.com/unexpected-post/',
                'slug' => (string) $article->slug,
            ]]),
        ]);
        $publishDistribution = ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'publish',
            'status' => 'outcome_unknown',
            'remote_meta' => [
                'distribution_payload' => [
                    'article' => ['slug' => (string) $article->slug],
                ],
            ],
            'idempotency_key' => 'wordpress-publish-unknown',
        ]);
        ArticleDistribution::query()->create([
            'article_id' => (int) $article->id,
            'distribution_channel_id' => (int) $channel->id,
            'action' => 'update',
            'status' => 'synced',
            'remote_id' => '123',
            'remote_meta' => ['wordpress_post_id' => 123],
            'idempotency_key' => 'wordpress-update-123',
        ]);

        $reconciled = app(DistributionOrchestrator::class)->reconcileUnknownOutcome($publishDistribution);

        $publishDistribution->refresh();
        $this->assertFalse($reconciled);
        $this->assertSame('outcome_unknown', $publishDistribution->status);
        $this->assertNull($publishDistribution->remote_id);
        $this->assertSame(
            ['expected_post_id' => 123, 'returned_post_id' => 456],
            data_get($publishDistribution->remote_meta, 'wordpress_identity_conflict'),
        );
        Http::assertSentCount(1);
    }

    public function test_unconfirmed_standard_wordpress_create_stops_without_an_automatic_retry(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts*' => Http::sequence()
                ->push(['message' => 'remote failure'], 500)
                ->push([], 200),
        ]);
        [$article] = $this->createWordPressArticle();
        $distributionId = app(DistributionOrchestrator::class)->enqueueForArticle($article)[0];

        (new ProcessArticleDistributionJob($distributionId))->handle(
            app(DistributionOrchestrator::class),
            app(DistributionRetryPolicy::class),
        );

        $distribution = ArticleDistribution::query()->findOrFail($distributionId);
        $this->assertSame('outcome_unknown', $distribution->status);
        $this->assertNull($distribution->next_retry_at);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
        Http::assertSentCount(2);
    }

    public function test_standard_wordpress_create_failure_reconciles_a_draft_post_by_slug(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        [$article] = $this->createWordPressArticle();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts*' => Http::sequence()
                ->push(['message' => 'remote failure'], 500)
                ->push([[
                    'id' => 123,
                    'link' => 'https://wp.example.com/hello-world/',
                    'slug' => (string) $article->slug,
                ]], 200),
        ]);
        $distributionId = app(DistributionOrchestrator::class)->enqueueForArticle($article)[0];

        (new ProcessArticleDistributionJob($distributionId))->handle(
            app(DistributionOrchestrator::class),
            app(DistributionRetryPolicy::class),
        );

        $distribution = ArticleDistribution::query()->findOrFail($distributionId);
        $this->assertSame('synced', $distribution->status);
        $this->assertSame('123', $distribution->remote_id);
        $this->assertTrue((bool) data_get($distribution->remote_meta, 'outcome_reconciled'));
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
        Http::assertSentCount(2);
    }

    public function test_wordpress_create_response_without_an_id_requires_reconciliation(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts*' => Http::sequence()
                ->push(['id' => 0, 'link' => 'https://wp.example.com/hello-world/'], 201)
                ->push([], 200),
        ]);
        [$article] = $this->createWordPressArticle();
        $distributionId = app(DistributionOrchestrator::class)->enqueueForArticle($article)[0];

        (new ProcessArticleDistributionJob($distributionId))->handle(
            app(DistributionOrchestrator::class),
            app(DistributionRetryPolicy::class),
        );

        $distribution = ArticleDistribution::query()->findOrFail($distributionId);
        $this->assertSame('outcome_unknown', $distribution->status);
        $this->assertNull($distribution->remote_id);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
        Http::assertSentCount(2);
    }

    public function test_saving_an_unchanged_published_article_does_not_publish_to_wordpress_again(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ], 201),
        ]);
        [$article] = $this->createWordPressArticle();
        $distributionId = app(DistributionOrchestrator::class)->enqueueForArticle($article)[0];
        app(DistributionOrchestrator::class)->process(ArticleDistribution::query()->findOrFail($distributionId));
        $admin = Admin::query()->create([
            'username' => 'wordpress_distribution_admin',
            'password' => 'secret-123',
            'email' => 'wordpress-distribution@example.com',
            'display_name' => 'WordPress Distribution Admin',
            'role' => 'admin',
            'status' => 'active',
        ]);
        $this->travel(2)->seconds();

        $this->actingAs($admin, 'admin')
            ->put(route('admin.articles.update', ['articleId' => (int) $article->id]), [
                'title' => (string) $article->title,
                'excerpt' => (string) $article->excerpt,
                'content' => (string) $article->content,
                'keywords' => (string) $article->keywords,
                'meta_description' => (string) $article->meta_description,
                'category_id' => (int) $article->category_id,
                'author_id' => (int) $article->author_id,
                'status' => 'published',
                'review_status' => 'approved',
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => (int) $article->id]));

        $this->assertSame('synced', ArticleDistribution::query()->findOrFail($distributionId)->status);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
        Http::assertSentCount(1);
    }

    public function test_republishing_after_a_remote_delete_restores_the_original_wordpress_post(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ], 201),
            'https://wp.example.com/wp-json/wp/v2/posts/123' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ]),
            'https://wp.example.com/wp-json/wp/v2/posts/123*' => Http::response([
                'deleted' => false,
                'previous' => ['id' => 123],
            ]),
        ]);
        [$article] = $this->createWordPressArticle();
        $orchestrator = app(DistributionOrchestrator::class);
        $publishDistributionId = $orchestrator->enqueueForArticle($article)[0];
        $orchestrator->process(ArticleDistribution::query()->findOrFail($publishDistributionId));
        $orchestrator->deleteRemoteArticle(ArticleDistribution::query()->findOrFail($publishDistributionId));

        $republishDistributionId = $orchestrator->enqueueForArticle($article->fresh())[0];
        $orchestrator->process(ArticleDistribution::query()->findOrFail($republishDistributionId));

        $publishDistribution = ArticleDistribution::query()->findOrFail($publishDistributionId);
        $this->assertSame($publishDistributionId, $republishDistributionId);
        $this->assertSame('publish', $publishDistribution->action);
        $this->assertSame('synced', $publishDistribution->status);
        $this->assertSame('123', $publishDistribution->remote_id);
        $this->assertNull(data_get($publishDistribution->remote_meta, 'wordpress_remote_deleted_at'));
        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $article->id,
            'action' => 'delete',
            'status' => 'synced',
            'remote_id' => '123',
        ]);
        Http::assertSentCount(3);
    }

    public function test_overlapping_wordpress_jobs_create_only_one_remote_post(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        $distributionId = null;
        $overlappingJobRan = false;
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => function () use (&$distributionId, &$overlappingJobRan) {
                $overlappingJobRan = true;
                (new ProcessArticleDistributionJob((int) $distributionId))->handle(
                    app(DistributionOrchestrator::class),
                    app(DistributionRetryPolicy::class),
                );

                return Http::response([
                    'id' => 123,
                    'link' => 'https://wp.example.com/hello-world/',
                ], 201);
            },
        ]);
        [$article] = $this->createWordPressArticle();
        $distributionId = app(DistributionOrchestrator::class)->enqueueForArticle($article)[0];

        (new ProcessArticleDistributionJob($distributionId))->handle(
            app(DistributionOrchestrator::class),
            app(DistributionRetryPolicy::class),
        );

        $this->assertTrue($overlappingJobRan);
        $this->assertSame('synced', ArticleDistribution::query()->findOrFail($distributionId)->status);
        Http::assertSentCount(1);
    }

    public function test_wordpress_channel_configuration_change_updates_the_existing_post(): void
    {
        Queue::fake([ProcessArticleDistributionJob::class]);
        Http::preventStrayRequests();
        Http::fake([
            'https://wp.example.com/wp-json/wp/v2/posts' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ], 201),
            'https://wp.example.com/wp-json/wp/v2/posts/123' => Http::response([
                'id' => 123,
                'link' => 'https://wp.example.com/hello-world/',
            ]),
        ]);
        [$article, , $channel] = $this->createWordPressArticle();
        $orchestrator = app(DistributionOrchestrator::class);
        $distributionId = $orchestrator->enqueueForArticle($article)[0];
        $orchestrator->process(ArticleDistribution::query()->findOrFail($distributionId));
        $channelConfig = $channel->resolvedChannelConfig();
        $channelConfig['wordpress_post_status'] = 'draft';
        $channel->forceFill(['channel_config' => $channelConfig])->save();

        $updatedDistributionId = $orchestrator->enqueueForArticle($article->fresh())[0];
        $orchestrator->process(ArticleDistribution::query()->findOrFail($updatedDistributionId));

        $this->assertSame($distributionId, $updatedDistributionId);
        $this->assertSame('123', ArticleDistribution::query()->findOrFail($distributionId)->remote_id);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://wp.example.com/wp-json/wp/v2/posts/123'
            && $request['status'] === 'draft');
    }

    /** @return array{Article,Task,DistributionChannel} */
    private function createWordPressArticle(string $content = 'Original content.'): array
    {
        $category = Category::query()->create([
            'name' => 'WordPress distribution',
            'slug' => 'wordpress-distribution-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'GEOFlow',
        ]);
        $task = Task::query()->create([
            'name' => 'WordPress distribution task',
            'status' => 'active',
            'schedule_enabled' => true,
            'publish_scope' => 'local_and_distribution',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => 'WordPress',
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
            'key_id' => 'wp_distribution_test',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('app password'),
            'status' => 'active',
            'scopes' => ['wordpress.rest'],
        ]);
        $task->distributionChannels()->sync([(int) $channel->id]);
        $article = Article::query()->create([
            'title' => 'Hello World',
            'slug' => 'hello-world-'.uniqid(),
            'excerpt' => 'Summary',
            'content' => $content,
            'category_id' => (int) $category->id,
            'author_id' => (int) $author->id,
            'task_id' => (int) $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        return [$article, $task, $channel];
    }
}
