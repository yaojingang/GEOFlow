<?php

namespace Tests\Feature\HostedSites;

use App\Exceptions\DistributionChannelDeletionBlocked;
use App\Jobs\ProcessArticleDistributionJob;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Services\GeoFlow\DistributionChannelDeletionConfirmation;
use App\Services\GeoFlow\DistributionChannelDeletionService;
use App\Services\GeoFlow\DistributionOrchestrator;
use App\Services\GeoFlow\DistributionPublisherManager;
use App\Services\HostedSites\HostedSiteAllocationRequestService;
use App\Services\HostedSites\HostedSiteAllocator;
use App\Services\HostedSites\HostedSiteLifecycleService;
use App\Services\HostedSites\HostedSiteReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HostedSiteDistributionFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
    }

    public function test_request_allocates_once_reserves_capacity_and_dispatches_after_commit(): void
    {
        Queue::fake();
        [$article, $profile] = $this->fixtures();

        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $assignment = app(HostedSiteAllocator::class)->allocate($request);
        $second = app(HostedSiteAllocator::class)->allocate($request->fresh());

        $this->assertSame($assignment->id, $second?->id);
        $this->assertSame(HostedSiteArticleAssignment::STATUS_RESERVED, $assignment->status);
        $this->assertSame($profile->id, $assignment->hosted_site_profile_id);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $assignment->content_fingerprint);
        $this->assertDatabaseCount('hosted_site_article_assignments', 1);
        $this->assertDatabaseCount('article_distributions', 1);
        $this->assertDatabaseHas('hosted_site_allocation_requests', [
            'article_id' => $article->id,
            'status' => HostedSiteAllocationRequest::STATUS_ASSIGNED,
            'hosted_site_article_assignment_id' => $assignment->id,
        ]);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
    }

    public function test_automatically_approved_article_completes_the_hosted_distribution_contract(): void
    {
        Queue::fake();
        [$article, $profile] = $this->fixtures();
        $article->update(['review_status' => 'auto_approved']);

        $request = app(HostedSiteAllocationRequestService::class)->request($article->fresh());
        $assignment = app(HostedSiteAllocator::class)->allocate($request);
        $distribution = ArticleDistribution::query()->where('article_id', $article->id)->firstOrFail();
        app(DistributionPublisherManager::class)->forChannel($profile->channel)
            ->publish($distribution, []);

        $this->assertSame(HostedSiteArticleAssignment::STATUS_PUBLISHED, $assignment?->fresh()->status);
    }

    public function test_daily_capacity_is_never_over_reserved(): void
    {
        Queue::fake();
        [$firstArticle, $profile] = $this->fixtures(dailyLimit: 1);
        $secondArticle = $this->createArticle($firstArticle->task, 'second');

        $firstRequest = app(HostedSiteAllocationRequestService::class)->request($firstArticle);
        $secondRequest = app(HostedSiteAllocationRequestService::class)->request($secondArticle);

        $this->assertNotNull(app(HostedSiteAllocator::class)->allocate($firstRequest));
        $this->assertNull(app(HostedSiteAllocator::class)->allocate($secondRequest));
        $this->assertDatabaseCount('hosted_site_article_assignments', 1);
        $this->assertDatabaseHas('hosted_site_allocation_requests', [
            'id' => $secondRequest->id,
            'status' => HostedSiteAllocationRequest::STATUS_PENDING,
            'last_error_code' => 'no_capacity',
        ]);
        $this->assertSame(1, $profile->assignments()->count());
    }

    public function test_local_publisher_publishes_updates_and_withdraws_the_assignment(): void
    {
        Queue::fake();
        [$article, $profile] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $assignment = app(HostedSiteAllocator::class)->allocate($request);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $publisher = app(DistributionPublisherManager::class)->forChannel($profile->channel);

        $published = $publisher->publish($distribution, ['title' => $article->title]);
        $this->assertSame('https://'.$profile->hostname.'/article/'.$article->slug, $published['remote_url']);
        $this->assertSame(HostedSiteArticleAssignment::STATUS_PUBLISHED, $assignment?->fresh()->status);

        $article->update(['content' => 'Updated hosted content']);
        $publisher->update($distribution, ['title' => $article->title]);
        $this->assertNotSame($assignment?->content_fingerprint, $assignment?->fresh()->content_fingerprint);

        $publisher->delete($distribution);
        $this->assertSame(HostedSiteArticleAssignment::STATUS_WITHDRAWN, $assignment?->fresh()->status);
    }

    public function test_hosted_task_contract_rejects_local_publish_scope_or_multiple_hosted_sites(): void
    {
        [$article, $profile] = $this->fixtures();
        $task = $article->task;
        $task->update(['publish_scope' => 'local_and_distribution']);

        $this->expectException(\DomainException::class);
        app(HostedSiteAllocationRequestService::class)->request($article->fresh());
    }

    public function test_distribution_orchestrator_routes_hosted_publish_through_the_allocator(): void
    {
        Queue::fake();
        [$article] = $this->fixtures();

        app(DistributionOrchestrator::class)->enqueueForArticle($article);

        $this->assertDatabaseHas('hosted_site_article_assignments', [
            'article_id' => $article->id,
            'status' => HostedSiteArticleAssignment::STATUS_RESERVED,
        ]);
        $this->assertDatabaseHas('article_distributions', [
            'article_id' => $article->id,
            'action' => 'publish',
            'status' => 'queued',
        ]);
        Queue::assertPushed(ProcessArticleDistributionJob::class, 1);
    }

    public function test_distribution_job_failed_hook_writes_a_safe_terminal_state_and_horizon_tags(): void
    {
        Queue::fake();
        [$article] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        app(HostedSiteAllocator::class)->allocate($request);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $job = new ProcessArticleDistributionJob((int) $distribution->id);

        $job->failed(new \RuntimeException('https://secret.test/api?token=do-not-store'));

        $this->assertSame('failed', $distribution->fresh()->status);
        $this->assertStringNotContainsString('secret.test', (string) $distribution->fresh()->last_error_message);
        $this->assertDatabaseHas('hosted_site_article_assignments', [
            'article_id' => $article->id,
            'status' => HostedSiteArticleAssignment::STATUS_FAILED,
        ]);
        $this->assertDatabaseHas('hosted_site_allocation_requests', [
            'article_id' => $article->id,
            'status' => HostedSiteAllocationRequest::STATUS_PENDING,
            'last_error_code' => 'publish_failed',
        ]);
        $this->assertContains('article:'.$article->id, $job->tags());
        $this->assertDatabaseHas('distribution_logs', [
            'article_distribution_id' => $distribution->id,
            'event' => 'distribution.job_failed',
        ]);
    }

    public function test_failed_assignment_is_not_reported_as_assigned_before_reconciliation(): void
    {
        Queue::fake();
        [$article] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $assignment = app(HostedSiteAllocator::class)->allocate($request);
        $assignment?->update([
            'status' => HostedSiteArticleAssignment::STATUS_FAILED,
            'reservation_expires_at' => null,
        ]);
        $request->update([
            'status' => HostedSiteAllocationRequest::STATUS_PENDING,
            'next_attempt_at' => now(),
        ]);

        $this->assertNull(app(HostedSiteAllocator::class)->allocate($request->fresh()));
        $this->assertDatabaseHas('hosted_site_allocation_requests', [
            'id' => $request->id,
            'status' => HostedSiteAllocationRequest::STATUS_PENDING,
            'last_error_code' => 'assignment_requires_recovery',
        ]);
    }

    public function test_pending_request_is_cancelled_when_article_or_task_contract_changes(): void
    {
        Queue::fake();
        [$article] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $article->update(['review_status' => 'rejected']);

        $this->assertNull(app(HostedSiteAllocator::class)->allocate($request));
        $this->assertDatabaseHas('hosted_site_allocation_requests', [
            'id' => $request->id,
            'status' => HostedSiteAllocationRequest::STATUS_CANCELLED,
            'last_error_code' => 'allocation_contract_changed',
        ]);
        $this->assertDatabaseCount('hosted_site_article_assignments', 0);
    }

    public function test_unassigned_pending_request_follows_an_explicit_task_rebind(): void
    {
        Queue::fake();
        [$article, $alpha] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $betaChannel = DistributionChannel::query()->create([
            'name' => 'Beta site',
            'domain' => 'beta.sites.test',
            'endpoint_url' => 'https://beta.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $beta = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $betaChannel->id,
            'hostname' => 'beta.sites.test',
            'root_domain' => 'sites.test',
            'topic' => 'AI',
            'daily_publish_limit' => 3,
            'min_publish_interval_minutes' => 0,
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
        ]);
        $article->task->distributionChannels()->detach($alpha->distribution_channel_id);
        $article->task->distributionChannels()->attach($betaChannel->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 0,
        ]);

        $refreshedRequest = app(HostedSiteAllocationRequestService::class)->request($article->fresh());
        $assignment = app(HostedSiteAllocator::class)->allocate($refreshedRequest);

        $this->assertSame($request->id, $refreshedRequest->id);
        $this->assertSame($beta->id, $refreshedRequest->hosted_site_profile_id);
        $this->assertSame($beta->id, $assignment?->hosted_site_profile_id);
        $this->assertDatabaseCount('hosted_site_article_assignments', 1);
    }

    public function test_publication_rechecks_cross_day_capacity_and_site_lifecycle(): void
    {
        Queue::fake();
        [$article, $profile] = $this->fixtures(dailyLimit: 1);
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $assignment = app(HostedSiteAllocator::class)->allocate($request);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $assignment?->update([
            'capacity_date' => now($profile->timezone)->subDay()->toDateString(),
            'reservation_expires_at' => now()->subMinute(),
        ]);
        $otherArticle = $this->createArticle($article->task, 'capacity-holder');
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $otherArticle->id,
            'hosted_site_profile_id' => $profile->id,
            'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
            'content_fingerprint' => str_repeat('f', 64),
            'capacity_date' => now($profile->timezone)->toDateString(),
            'assigned_at' => now(),
            'published_at' => now(),
        ]);

        try {
            app(DistributionPublisherManager::class)
                ->forChannel($profile->channel)
                ->publish($distribution, []);
            $this->fail('Cross-day capacity should block publication.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('capacity', strtolower($exception->getMessage()));
        }
        $this->assertSame(HostedSiteArticleAssignment::STATUS_RESERVED, $assignment?->fresh()->status);

        $profile->channel->update(['status' => DistributionChannel::STATUS_PAUSED]);
        try {
            app(DistributionPublisherManager::class)
                ->forChannel($profile->channel->fresh())
                ->publish($distribution, []);
            $this->fail('Paused hosted site should block publication.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('eligible', strtolower($exception->getMessage()));
        }
    }

    public function test_update_does_not_reset_publish_interval_and_withdrawn_article_can_be_restored(): void
    {
        Queue::fake();
        [$article, $profile] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $assignment = app(HostedSiteAllocator::class)->allocate($request);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $publisher = app(DistributionPublisherManager::class)->forChannel($profile->channel);
        $publisher->publish($distribution, []);
        $firstPublishedAt = $profile->fresh()->last_published_at;

        $this->travel(30)->minutes();
        $article->update(['content' => 'Updated without changing the publish interval.']);
        $publisher->update($distribution, []);
        $this->assertTrue($profile->fresh()->last_published_at?->equalTo($firstPublishedAt));

        app(DistributionOrchestrator::class)->deleteRemoteArticle($distribution);
        app(DistributionOrchestrator::class)->enqueueForArticle($article->fresh(), 'publish');

        $this->assertSame(HostedSiteArticleAssignment::STATUS_RESERVED, $assignment?->fresh()->status);
        $this->assertSame(1, ArticleDistribution::query()->where('action', 'publish')->count());
        Queue::assertPushed(ProcessArticleDistributionJob::class, 2);

        $restoredDistribution = ArticleDistribution::query()->where('action', 'publish')->firstOrFail();
        $this->assertTrue(app(DistributionOrchestrator::class)->process($restoredDistribution));
        app(DistributionOrchestrator::class)->deleteRemoteArticle($restoredDistribution->fresh());

        $this->assertSame(HostedSiteArticleAssignment::STATUS_WITHDRAWN, $assignment?->fresh()->status);
        $this->assertDatabaseCount('article_distributions', 2);
        $this->assertDatabaseHas('article_distributions', [
            'article_id' => $article->id,
            'action' => 'delete',
            'status' => 'synced',
        ]);
    }

    public function test_archive_closes_in_flight_reservations_and_permanent_delete_requires_archive(): void
    {
        Queue::fake();
        [$article, $profile] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $assignment = app(HostedSiteAllocator::class)->allocate($request);
        $distribution = ArticleDistribution::query()->firstOrFail();

        try {
            app(DistributionChannelDeletionService::class)->prepare($profile->channel);
            $this->fail('Hosted site deletion should require archive first.');
        } catch (DistributionChannelDeletionBlocked $exception) {
            $this->assertSame('hosted_archive_required', $exception->reason);
        }

        app(HostedSiteLifecycleService::class)->archive($profile->channel, $profile->hostname);

        $this->assertSame(HostedSiteArticleAssignment::STATUS_FAILED, $assignment?->fresh()->status);
        $this->assertSame('failed', $distribution->fresh()->status);
        $this->assertSame(HostedSiteAllocationRequest::STATUS_CANCELLED, $request->fresh()->status);
        $this->assertSame(HostedSiteProfile::SERVING_ARCHIVED, $profile->fresh()->serving_status);
    }

    public function test_feature_disable_pauses_inflight_publication_without_counting_a_business_failure(): void
    {
        Queue::fake();
        [$article, $profile] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $assignment = app(HostedSiteAllocator::class)->allocate($request);
        $distribution = ArticleDistribution::query()->firstOrFail();

        config()->set('geoflow.hosted_sites.enabled', false);
        app()->call([new ProcessArticleDistributionJob((int) $distribution->id), 'handle']);

        $distribution->refresh();
        $this->assertSame('queued', $distribution->status);
        $this->assertTrue((bool) data_get($distribution->remote_meta, 'hosted_feature_paused'));
        $this->assertSame(HostedSiteArticleAssignment::STATUS_RESERVED, $assignment?->fresh()->status);
        $this->assertSame(0, $profile->fresh()->consecutive_publish_failures);
        $this->assertSame(HostedSiteAllocationRequest::STATUS_ASSIGNED, $request->fresh()->status);

        config()->set('geoflow.hosted_sites.enabled', true);
        $result = app(HostedSiteReconciler::class)->reconcile();
        $this->assertSame(1, $result['feature_paused']);
        $this->assertSame(1, $result['dispatched']);
        $this->assertFalse((bool) data_get($distribution->fresh()->remote_meta, 'hosted_feature_paused'));
        Queue::assertPushed(ProcessArticleDistributionJob::class, 2);
    }

    public function test_reconcile_uses_distribution_action_for_stale_delete_windows(): void
    {
        Queue::fake();
        [$article, $profile] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $assignment = app(HostedSiteAllocator::class)->allocate($request);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $publisher = app(DistributionPublisherManager::class)->forChannel($profile->channel);
        $publisher->publish($distribution, []);
        $distribution->refresh()->forceFill([
            'action' => 'delete',
            'status' => 'sending',
            'idempotency_key' => 'article-'.$article->id.'-channel-'.$profile->channel->id.'-delete-v1',
            'last_attempt_at' => now()->subMinutes(5),
        ])->save();

        $result = app(HostedSiteReconciler::class)->reconcile();
        $this->assertSame(1, $result['dispatched']);
        $this->assertSame('queued', $distribution->fresh()->status);
        $this->assertSame(HostedSiteArticleAssignment::STATUS_PUBLISHED, $assignment?->fresh()->status);

        $distribution->refresh()->forceFill([
            'status' => 'sending',
            'last_attempt_at' => now()->subMinutes(5),
        ])->save();
        $publisher->delete($distribution);
        $result = app(HostedSiteReconciler::class)->reconcile();

        $this->assertSame(1, $result['repaired']);
        $this->assertSame('synced', $distribution->fresh()->status);
        $this->assertNull($distribution->fresh()->remote_url);
        $this->assertSame(HostedSiteArticleAssignment::STATUS_WITHDRAWN, $assignment?->fresh()->status);
    }

    public function test_job_failure_preserves_a_locally_committed_publication(): void
    {
        Queue::fake();
        [$article, $profile] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $assignment = app(HostedSiteAllocator::class)->allocate($request);
        $distribution = ArticleDistribution::query()->firstOrFail();
        app(DistributionPublisherManager::class)->forChannel($profile->channel)->publish($distribution, []);
        $distribution->update(['status' => 'sending', 'last_attempt_at' => now()->subMinute()]);

        (new ProcessArticleDistributionJob((int) $distribution->id))
            ->failed(new \RuntimeException('worker terminated'));

        $this->assertSame('synced', $distribution->fresh()->status);
        $this->assertSame(HostedSiteArticleAssignment::STATUS_PUBLISHED, $assignment?->fresh()->status);
        $this->assertSame(HostedSiteAllocationRequest::STATUS_ASSIGNED, $request->fresh()->status);
        $this->assertSame(0, $profile->fresh()->consecutive_publish_failures);
        $this->assertDatabaseHas('distribution_logs', [
            'article_distribution_id' => $distribution->id,
            'event' => 'distribution.commit_reconciled',
        ]);
    }

    public function test_withdrawal_keeps_the_existing_fingerprint_when_live_content_becomes_duplicate(): void
    {
        Queue::fake();
        [$firstArticle, $profile] = $this->fixtures();
        $secondArticle = $this->createArticle($firstArticle->task, 'second-withdrawal');
        $publisher = app(DistributionPublisherManager::class)->forChannel($profile->channel);
        $firstRequest = app(HostedSiteAllocationRequestService::class)->request($firstArticle);
        $firstAssignment = app(HostedSiteAllocator::class)->allocate($firstRequest);
        $firstDistribution = ArticleDistribution::query()->where('article_id', $firstArticle->id)->firstOrFail();
        $publisher->publish($firstDistribution, []);
        $secondRequest = app(HostedSiteAllocationRequestService::class)->request($secondArticle);
        app(HostedSiteAllocator::class)->allocate($secondRequest);
        $secondDistribution = ArticleDistribution::query()->where('article_id', $secondArticle->id)->firstOrFail();
        $publisher->publish($secondDistribution, []);
        $originalFingerprint = (string) $firstAssignment?->fresh()->content_fingerprint;
        $firstArticle->forceFill([
            'title' => $secondArticle->title,
            'content' => $secondArticle->content,
        ])->save();

        $publisher->delete($firstDistribution);

        $this->assertSame(HostedSiteArticleAssignment::STATUS_WITHDRAWN, $firstAssignment?->fresh()->status);
        $this->assertSame($originalFingerprint, $firstAssignment?->fresh()->content_fingerprint);
    }

    public function test_archived_site_keeps_pending_request_lineage_through_permanent_deletion(): void
    {
        Queue::fake();
        [$article, $profile] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $service = app(DistributionChannelDeletionService::class);

        app(HostedSiteLifecycleService::class)->archive($profile->channel, $profile->hostname);
        $this->assertSame(HostedSiteAllocationRequest::STATUS_CANCELLED, $request->fresh()->status);
        $this->assertSame($profile->id, $request->fresh()->hosted_site_profile_id);
        $this->assertSame(1, $service->inspect($profile->channel->fresh())['hosted_allocation_request_count']);

        $service->prepare($profile->channel->fresh());
        $impact = $service->inspect($profile->channel->fresh());
        $admin = Admin::query()->create([
            'username' => 'hosted-delete-admin',
            'password' => 'secret-123',
            'email' => 'hosted-delete@example.test',
            'display_name' => 'Hosted delete admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $service->delete($profile->channel->fresh(), $admin, new DistributionChannelDeletionConfirmation(
            impactFingerprint: (string) $impact['impact_fingerprint'],
            ackRemoteContent: true,
            ackTaskChanges: true,
            ackCredentials: true,
            ackHistory: true,
        ));

        $this->assertDatabaseMissing('distribution_channels', ['id' => $profile->distribution_channel_id]);
        $this->assertDatabaseMissing('hosted_site_allocation_requests', ['id' => $request->id]);
    }

    public function test_article_edit_atomically_rejects_a_duplicate_hosted_fingerprint(): void
    {
        Queue::fake();
        [$firstArticle, $profile] = $this->fixtures();
        $secondArticle = $this->createArticle($firstArticle->task, 'second');
        $publisher = app(DistributionPublisherManager::class)->forChannel($profile->channel);

        $firstRequest = app(HostedSiteAllocationRequestService::class)->request($firstArticle);
        app(HostedSiteAllocator::class)->allocate($firstRequest);
        $publisher->publish(ArticleDistribution::query()->where('article_id', $firstArticle->id)->firstOrFail(), []);
        $secondRequest = app(HostedSiteAllocationRequestService::class)->request($secondArticle);
        $secondAssignment = app(HostedSiteAllocator::class)->allocate($secondRequest);
        $secondDistribution = ArticleDistribution::query()->where('article_id', $secondArticle->id)->firstOrFail();
        $publisher->publish($secondDistribution, []);
        $originalFingerprint = (string) $secondAssignment?->fresh()->content_fingerprint;

        $admin = Admin::query()->create([
            'username' => 'hosted-fingerprint-admin',
            'password' => 'secret-123',
            'email' => 'hosted-fingerprint@example.test',
            'display_name' => 'Hosted fingerprint admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $this->actingAs($admin, 'admin')
            ->from(route('admin.articles.edit', ['articleId' => $secondArticle->id]))
            ->put(route('admin.articles.update', ['articleId' => $secondArticle->id]), [
                'title' => $firstArticle->title,
                'excerpt' => '',
                'content' => $firstArticle->content,
                'keywords' => '',
                'meta_description' => '',
                'category_id' => $secondArticle->category_id,
                'author_id' => $secondArticle->author_id,
                'status' => 'private',
                'review_status' => 'approved',
            ])
            ->assertRedirect(route('admin.articles.edit', ['articleId' => $secondArticle->id]))
            ->assertSessionHasErrors();

        $this->assertSame('Hosted second', $secondArticle->fresh()->title);
        $this->assertSame('Hosted content second', $secondArticle->fresh()->content);
        $this->assertSame($originalFingerprint, $secondAssignment?->fresh()->content_fingerprint);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.article.edit', ['distributionId' => $secondDistribution->id]))
            ->assertRedirect(route('admin.distribution.hosted-sites.show', $profile->channel));
    }

    /** @return array{Article,HostedSiteProfile} */
    private function fixtures(int $dailyLimit = 3): array
    {
        $task = Task::query()->create([
            'name' => 'Hosted task',
            'status' => 'active',
            'publish_scope' => 'distribution_only',
            'distribution_strategy' => 'broadcast',
        ]);
        $channel = DistributionChannel::query()->create([
            'name' => 'Alpha site',
            'domain' => 'alpha.sites.test',
            'endpoint_url' => 'https://alpha.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id,
            'hostname' => 'alpha.sites.test',
            'root_domain' => 'sites.test',
            'topic' => 'AI',
            'daily_publish_limit' => $dailyLimit,
            'min_publish_interval_minutes' => 0,
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
        ]);
        $task->distributionChannels()->attach($channel->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 0,
        ]);

        return [$this->createArticle($task, 'first'), $profile];
    }

    private function createArticle(Task $task, string $suffix): Article
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'hosted-flow'],
            ['name' => 'Hosted Flow', 'description' => '', 'sort_order' => 0]
        );
        $author = Author::query()->firstOrCreate(
            ['email' => 'hosted-flow@example.test'],
            ['name' => 'Hosted Flow Author', 'bio' => '', 'avatar' => '', 'website' => '']
        );

        return Article::query()->create([
            'title' => 'Hosted '.$suffix,
            'slug' => 'hosted-flow-'.$suffix,
            'content' => 'Hosted content '.$suffix,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'private',
            'review_status' => 'approved',
        ]);
    }
}
