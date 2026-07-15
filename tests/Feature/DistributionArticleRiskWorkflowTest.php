<?php

namespace Tests\Feature;

use App\Exceptions\ArticleRiskGateException;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Models\SensitiveWord;
use App\Models\Task;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DistributionArticleRiskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Queue::fake();
    }

    public function test_risky_article_is_not_enqueued_for_distribution(): void
    {
        SensitiveWord::query()->create(['word' => 'restricted claim']);
        [$article] = $this->createDistributionArticle('Contains a restricted claim.');

        app(DistributionOrchestrator::class)->enqueueForArticle($article);

        $this->assertDatabaseCount('article_distributions', 0);
        $this->assertSame('warning', $article->fresh()->latestRiskScan?->status);
        $this->assertSame('distribution_enqueue', $article->fresh()->latestRiskScan?->trigger);
        Queue::assertNothingPushed();
    }

    public function test_clean_article_is_scanned_and_enqueued_for_distribution(): void
    {
        SensitiveWord::query()->create(['word' => 'restricted claim']);
        [$article] = $this->createDistributionArticle('Safe content.');

        app(DistributionOrchestrator::class)->enqueueForArticle($article);

        $this->assertDatabaseHas('article_distributions', [
            'article_id' => (int) $article->id,
            'action' => 'publish',
            'status' => 'queued',
        ]);
        $this->assertSame('clean', $article->fresh()->latestRiskScan?->status);
        $this->assertSame('distribution_enqueue', $article->fresh()->latestRiskScan?->trigger);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
    }

    public function test_distribution_send_rechecks_content_changed_after_enqueue(): void
    {
        SensitiveWord::query()->create(['word' => 'restricted claim']);
        [$article] = $this->createDistributionArticle('Safe content.');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $article->update(['content' => 'Now contains a restricted claim.']);
        Http::fake();

        try {
            $orchestrator->process($distribution);
            $this->fail('Expected the distribution risk gate to reject the stale queued article.');
        } catch (ArticleRiskGateException) {
            $this->assertSame('queued', $distribution->fresh()->status);
            $this->assertSame('warning', $article->fresh()->latestRiskScan?->status);
            $this->assertSame('distribution_send', $article->fresh()->latestRiskScan?->trigger);
            Http::assertNothingSent();
        }
    }

    public function test_distribution_send_rejects_an_article_that_was_downgraded_after_enqueue(): void
    {
        [$article] = $this->createDistributionArticle('Safe content.');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $article->update([
            'status' => 'draft',
            'review_status' => 'pending',
            'published_at' => null,
        ]);
        Http::fake();

        try {
            $orchestrator->process($distribution);
            $this->fail('Expected distribution to reject an article that is no longer publishable.');
        } catch (\RuntimeException) {
            $this->assertSame('queued', $distribution->fresh()->status);
            Http::assertNothingSent();
        }
    }

    public function test_distribution_builds_the_payload_from_the_same_fresh_article_snapshot_that_passed_the_gate(): void
    {
        SensitiveWord::query()->create([
            'word' => 'blocked stale content',
            'severity' => 'blocked',
        ]);
        [$article, , $channel] = $this->createDistributionArticle('blocked stale content');
        $staleArticle = Article::query()->findOrFail($article->id);
        $article->update(['content' => 'Fresh safe content.']);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'attempt_count' => 0,
            'idempotency_key' => 'fresh-payload-snapshot',
        ]);
        $distribution->setRelation('article', $staleArticle);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'gfk_fresh_payload',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_fresh_payload_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'remote_id' => 'remote-1',
                'remote_url' => 'https://risk-target.example.com/articles/remote-1',
            ]),
        ]);

        app(DistributionOrchestrator::class)->process($distribution);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return data_get($payload, 'article.content') === 'Fresh safe content.'
                && data_get($payload, 'article.content') !== 'blocked stale content';
        });
    }

    public function test_legacy_published_distribution_without_a_task_remains_sendable(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Legacy safe content.');
        $article->update([
            'task_id' => null,
            'review_status' => 'pending',
        ]);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'legacy-published-distribution',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'gfk_legacy_distribution',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_legacy_distribution_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        Http::fake([
            '*' => Http::response([
                'ok' => true,
                'remote_id' => 'legacy-remote-1',
                'remote_url' => 'https://risk-target.example.com/articles/legacy-remote-1',
            ]),
        ]);

        app(DistributionOrchestrator::class)->process($distribution);

        $this->assertSame('synced', $distribution->fresh()->status);
        Http::assertSentCount(1);
    }

    /** @return array{Article, Task, DistributionChannel} */
    private function createDistributionArticle(string $content): array
    {
        $task = Task::query()->create([
            'name' => 'Risk distribution task',
            'status' => 'active',
            'schedule_enabled' => 1,
            'publish_scope' => 'local_and_distribution',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => 'Risk distribution target',
            'domain' => 'risk-target.example.com',
            'endpoint_url' => 'https://risk-target.example.com',
            'status' => 'active',
        ]);
        app(DistributionOrchestrator::class)->syncTaskChannels($task, [(int) $channel->id]);
        $category = Category::query()->create([
            'name' => 'Distribution risk',
            'slug' => 'distribution-risk-'.uniqid(),
        ]);
        $author = Author::query()->create([
            'name' => 'Distribution risk author',
            'email' => uniqid().'@example.com',
        ]);
        $article = Article::query()->create([
            'title' => 'Distribution risk article',
            'slug' => 'distribution-risk-article-'.uniqid(),
            'excerpt' => 'Distribution excerpt.',
            'content' => $content,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);

        return [$article, $task, $channel];
    }
}
