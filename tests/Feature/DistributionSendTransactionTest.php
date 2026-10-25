<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\Task;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPublisherInterface;
use App\Services\GeoFlow\DistributionPublisherManager;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DistributionSendTransactionTest extends TestCase
{
    use DatabaseTruncation;

    protected function beforeTruncatingDatabase(): void
    {
        RefreshDatabaseState::$migrated = false;
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            RefreshDatabaseState::$migrated = false;
        }
    }

    public function test_remote_publish_is_not_repeated_after_local_deadlock(): void
    {
        Queue::fake();
        $task = Task::create(['name' => 'Transaction test', 'status' => 'active', 'schedule_enabled' => 1, 'need_review' => 0, 'publish_scope' => 'local_and_distribution']);
        $channel = DistributionChannel::create(['name' => 'Channel', 'domain' => 'example.test', 'endpoint_url' => 'https://example.test', 'status' => 'active']);
        $article = Article::create(['task_id' => $task->id, 'title' => 'Factual content', 'slug' => 'factual', 'content' => 'Factual content.', 'category_id' => Category::create(['name' => 'Test', 'slug' => 'test'])->id, 'author_id' => Author::create(['name' => 'Author'])->id, 'status' => 'published', 'review_status' => 'auto_approved']);
        $sent = 0;
        $publisher = \Mockery::mock(DistributionPublisherInterface::class);
        $publisher->shouldReceive('publish')->andReturnUsing(function () use (&$sent): array {
            $sent++;

            return ['remote_id' => 'post-'.$sent, 'remote_url' => 'https://example.test/post-'.$sent];
        });
        $manager = \Mockery::mock(DistributionPublisherManager::class);
        $manager->shouldReceive('forChannel')->andReturn($publisher);
        $this->app->instance(DistributionPublisherManager::class, $manager);
        $orchestrator = app(DistributionOrchestrator::class);
        $orchestrator->syncTaskChannels($task, [$channel->id]);
        $orchestrator->enqueueForArticle($article, throwOnFailure: true);
        $once = false;
        ArticleDistribution::updating(function ($delivery) use (&$once): void {
            if (! $once && $delivery->isDirty('status') && $delivery->status === 'synced') {
                $once = true;
                throw new \PDOException('deadlock detected');
            }
        });
        try {
            $orchestrator->process(ArticleDistribution::sole());
            $this->fail('A failed local commit must surface without repeating the transport.');
        } catch (\PDOException $exception) {
            $this->assertSame('deadlock detected', $exception->getMessage());
        }
        $this->assertTrue($once);
        $this->assertSame('outcome_unknown', ArticleDistribution::sole()->status);
        $this->assertSame('post-1', ArticleDistribution::sole()->remote_id);
        $this->assertSame(1, $sent, 'Only local commit recovery may retry after the remote response succeeds.');
    }
}
