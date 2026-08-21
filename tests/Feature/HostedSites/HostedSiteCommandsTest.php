<?php

namespace Tests\Feature\HostedSites;

use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Services\HostedSites\HostedSiteAllocationRequestService;
use App\Services\HostedSites\HostedSiteAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HostedSiteCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
    }

    public function test_preflight_and_cache_invalidation_support_safe_preview_and_all_modes(): void
    {
        [$channel] = $this->fixtures();

        $this->artisan('hosted-sites:preflight', ['--all' => true, '--dry-run' => true])
            ->expectsOutputToContain('alpha.sites.test: passed')
            ->assertSuccessful();
        $this->assertNull($channel->fresh()->last_health_status);

        $this->artisan('hosted-sites:preflight', ['hostname' => 'alpha.sites.test'])
            ->assertSuccessful();
        $this->assertSame('ok', $channel->fresh()->last_health_status);

        $this->artisan('hosted-sites:invalidate-cache', ['--all' => true, '--dry-run' => true])
            ->expectsOutputToContain('Matched hosted site caches: 1 (dry run)')
            ->assertSuccessful();
        $this->artisan('hosted-sites:invalidate-cache', ['hostname' => 'alpha.sites.test'])
            ->assertSuccessful();
    }

    public function test_reconcile_is_idempotent_and_allocates_due_requests(): void
    {
        Queue::fake();
        [, $article] = $this->fixtures();
        app(HostedSiteAllocationRequestService::class)
            ->request($article)
            ->update(['next_attempt_at' => now()->subMinute()]);

        $this->artisan('hosted-sites:reconcile', ['--limit' => 50])->assertSuccessful();
        $this->artisan('hosted-sites:reconcile', ['--limit' => 50])->assertSuccessful();

        $this->assertDatabaseCount('hosted_site_article_assignments', 1);
        $this->assertDatabaseHas('hosted_site_allocation_requests', [
            'article_id' => $article->id,
            'status' => HostedSiteAllocationRequest::STATUS_ASSIGNED,
        ]);
    }

    public function test_reconcile_repairs_the_published_assignment_sending_crash_window(): void
    {
        Queue::fake();
        [, $article] = $this->fixtures();
        $request = app(HostedSiteAllocationRequestService::class)->request($article);
        $assignment = app(HostedSiteAllocator::class)->allocate($request);
        $assignment?->update([
            'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
            'reservation_expires_at' => null,
            'published_at' => now()->subMinutes(5),
        ]);
        $distribution = ArticleDistribution::query()->firstOrFail();
        $distribution->update([
            'status' => 'sending',
            'last_attempt_at' => now()->subMinutes(5),
        ]);

        $this->artisan('hosted-sites:reconcile', ['--limit' => 50])->assertSuccessful();

        $this->assertSame('synced', $distribution->fresh()->status);
        $this->assertSame((string) $assignment?->id, $distribution->fresh()->remote_id);
    }

    /** @return array{DistributionChannel,Article} */
    private function fixtures(): array
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Alpha',
            'domain' => 'alpha.sites.test',
            'endpoint_url' => 'https://alpha.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'template_key' => 'default',
            'site_settings' => [
                'site_name' => 'Alpha',
                'site_description' => 'Description',
                'about_title' => 'About Alpha',
                'about_content' => 'Alpha site information.',
            ],
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id,
            'hostname' => 'alpha.sites.test',
            'root_domain' => 'sites.test',
            'topic' => 'AI',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
            'min_publish_interval_minutes' => 0,
        ]);
        $task = Task::query()->create([
            'name' => 'Hosted command task',
            'status' => 'active',
            'publish_scope' => 'distribution_only',
        ]);
        $task->distributionChannels()->attach($channel->id, [
            'trigger' => 'after_local_publish',
            'remote_status' => 'follow_local',
            'failure_policy' => 'ignore_distribution_failure',
            'max_attempts' => 3,
            'sort_order' => 0,
        ]);
        $category = Category::query()->create(['name' => 'AI', 'slug' => 'ai', 'sort_order' => 0]);
        $author = Author::query()->create([
            'name' => 'Author',
            'email' => 'hosted-command@example.test',
            'bio' => '',
            'avatar' => '',
            'website' => '',
        ]);
        $article = Article::query()->create([
            'title' => 'Hosted command article',
            'slug' => 'hosted-command-article',
            'content' => 'Content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'private',
            'review_status' => 'approved',
        ]);

        return [$channel, $article];
    }
}
