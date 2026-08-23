<?php

namespace Tests\Feature;

use App\Ai\Workspace\AiPlanCompiler;
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

    public function test_ai_workspace_enqueue_surfaces_an_approved_payload_mismatch(): void
    {
        [$article] = $this->createDistributionArticle('Safe content.');

        try {
            app(DistributionOrchestrator::class)->enqueueForArticle($article, 'publish', [
                'expected_payload_digest' => str_repeat('f', 64),
            ]);
            $this->fail('Expected the AI workspace payload mismatch to be surfaced.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('AI 工作台分发载荷在审批后已变化。', $exception->getMessage());
            $this->assertDatabaseCount('article_distributions', 0);
            Queue::assertNothingPushed();
        }
    }

    public function test_ai_workspace_distribution_rejects_a_channel_changed_after_enqueue(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Immutable target content.');
        $summary = app(AiPlanCompiler::class)->targetSummaryFor('distribution.publish', [
            'article_ids' => [$article->id],
            'channel_ids' => [$channel->id],
        ]);
        $channelRevision = (string) data_get($summary, 'channel_snapshots.0.revision');
        $distributionIds = app(DistributionOrchestrator::class)->enqueueForArticle($article, 'publish', [
            'expected_payload_digest' => (string) data_get($summary, 'article_snapshots.0.outbound_payload_digest'),
            'approved_channel_revisions' => [$channel->id => $channelRevision],
        ]);
        $this->assertCount(1, $distributionIds);
        $channel->forceFill(['endpoint_url' => 'https://changed-target.example.com/api'])->save();
        Http::fake();

        try {
            app(DistributionOrchestrator::class)->process(ArticleDistribution::query()->findOrFail($distributionIds[0]));
            $this->fail('Expected the changed AI workspace target to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('AI 工作台分发目标在审批后已变化。', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_ai_workspace_distribution_rejects_a_secret_rotated_after_approval(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Immutable credential target.');
        $oldSecret = DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'approved-secret',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('approved-secret-value'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        $summary = app(AiPlanCompiler::class)->targetSummaryFor('distribution.publish', [
            'article_ids' => [$article->id],
            'channel_ids' => [$channel->id],
        ]);
        $approvedRevision = (string) data_get($summary, 'channel_snapshots.0.revision');
        $oldSecret->forceFill(['last_used_at' => now()])->save();
        $afterUseSummary = app(AiPlanCompiler::class)->targetSummaryFor('distribution.publish', [
            'article_ids' => [$article->id],
            'channel_ids' => [$channel->id],
        ]);
        $this->assertSame($approvedRevision, (string) data_get($afterUseSummary, 'channel_snapshots.0.revision'));
        $distributionIds = app(DistributionOrchestrator::class)->enqueueForArticle($article, 'publish', [
            'expected_payload_digest' => (string) data_get($summary, 'article_snapshots.0.outbound_payload_digest'),
            'approved_channel_revisions' => [
                $channel->id => $approvedRevision,
            ],
        ]);
        $oldSecret->forceFill(['status' => 'revoked'])->save();
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => (int) $channel->id,
            'key_id' => 'rotated-secret',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('rotated-secret-value'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        Http::fake();

        try {
            app(DistributionOrchestrator::class)->process(ArticleDistribution::query()->findOrFail($distributionIds[0]));
            $this->fail('Expected the changed AI workspace credential target to be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('AI 工作台分发目标在审批后已变化。', $exception->getMessage());
            Http::assertNothingSent();
        }
    }

    public function test_ai_workspace_enqueue_does_not_claim_an_existing_sending_distribution(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Already sending content.');
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->enqueueForArticle($article);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $distribution->forceFill(['status' => 'sending'])->save();
        $summary = app(AiPlanCompiler::class)->targetSummaryFor('distribution.publish', [
            'article_ids' => [$article->id],
            'channel_ids' => [$channel->id],
        ]);

        $distributionIds = $orchestrator->enqueueForArticle($article, 'publish', [
            'expected_payload_digest' => (string) data_get($summary, 'article_snapshots.0.outbound_payload_digest'),
            'approved_channel_revisions' => [
                $channel->id => (string) data_get($summary, 'channel_snapshots.0.revision'),
            ],
        ]);

        $this->assertSame([], $distributionIds);
        $this->assertNull(data_get($distribution->fresh()->remote_meta, 'ai_workspace_guard'));
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

    public function test_distribution_send_holds_a_channel_operation_lease_until_the_result_is_saved(): void
    {
        [$article, , $channel] = $this->createDistributionArticle('Lease protected content.');
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'queued',
            'idempotency_key' => 'lease-protected-distribution',
        ]);
        DistributionChannelSecret::query()->create([
            'distribution_channel_id' => $channel->id,
            'key_id' => 'gfk_lease_protected',
            'secret_ciphertext' => app(ApiKeyCrypto::class)->encrypt('gfsec_lease_protected_secret'),
            'status' => 'active',
            'scopes' => ['article.publish'],
        ]);
        Http::fake(function () use ($channel, $distribution) {
            $this->assertDatabaseHas('distribution_channel_operations', [
                'distribution_channel_id' => (int) $channel->id,
                'operation' => 'article_publish',
            ]);
            $this->assertDatabaseHas('article_distributions', [
                'id' => (int) $distribution->id,
                'status' => 'sending',
            ]);

            return Http::response([
                'ok' => true,
                'remote_id' => 'lease-remote-1',
                'remote_url' => 'https://risk-target.example.com/articles/lease-remote-1',
            ]);
        });

        app(DistributionOrchestrator::class)->process($distribution);

        $this->assertSame('synced', $distribution->fresh()->status);
        $this->assertDatabaseCount('distribution_channel_operations', 0);
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
