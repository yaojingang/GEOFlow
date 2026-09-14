<?php

namespace Tests\Feature\HostedSites;

use App\Jobs\RefreshHostedSitePermalinkUrls;
use App\Jobs\RefreshUrlChange;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Services\HostedSites\HostedSiteUrlGenerator;
use App\Services\Site\UrlChangeService;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ChecksUrlChanges;
use Tests\TestCase;

class HostedSitePermalinkTest extends TestCase
{
    use ChecksUrlChanges;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareUrlQueue();
        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.primary_hosts', ['primary.test', 'localhost']);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
    }

    public function test_two_hosted_domains_use_independent_permalink_policies(): void
    {
        [$alpha, $alphaArticle] = $this->site('alpha', '/{slug}.html');
        [, $betaArticle] = $this->site('beta', '/article/{id}.html');

        $this->get('http://alpha.sites.test/'.$alphaArticle->slug.'.html')
            ->assertOk()
            ->assertSee('https://alpha.sites.test/'.$alphaArticle->slug.'.html', false);
        $this->get('http://alpha.sites.test/article/'.$alphaArticle->slug)
            ->assertStatus(301)
            ->assertRedirect('https://alpha.sites.test/'.$alphaArticle->slug.'.html');
        $this->get('http://beta.sites.test/article/'.$betaArticle->id.'.html')->assertOk();
        $this->get('http://beta.sites.test/'.$alphaArticle->slug.'.html')->assertNotFound();
        $this->get('http://alpha.sites.test/article/'.$betaArticle->id.'.html')->assertNotFound();
        $this->assertNotNull($alpha->id);
    }

    public function test_hosted_site_form_shows_the_root_category_preset_and_format_help(): void
    {
        [$channel] = $this->site('alpha', ArticlePermalinkPolicy::DEFAULT_PATTERN);

        $this->actingAs($this->admin(), 'admin')
            ->get(route('admin.distribution.hosted-sites.edit', $channel))
            ->assertOk()
            ->assertSee('分类短链（推荐）')
            ->assertSee('/{category}/{slug}')
            ->assertSee('路径层级请直接使用 / 分隔，令牌前后不要留空格。');
    }

    public function test_confirmed_hosted_policy_activation_increments_version_and_refreshes_remote_urls(): void
    {
        [$channel, $article] = $this->site('alpha', ArticlePermalinkPolicy::DEFAULT_PATTERN);
        $article->update(['title' => '=2+2']);
        $distribution = ArticleDistribution::query()->create([
            'article_id' => $article->id,
            'distribution_channel_id' => $channel->id,
            'action' => 'publish',
            'status' => 'synced',
            'remote_id' => 'remote-1',
            'remote_url' => 'https://alpha.sites.test/article/'.$article->slug,
            'idempotency_key' => 'hosted-permalink-test',
            'attempt_count' => 1,
            'payload_hash' => hash('sha256', 'payload'),
        ]);
        $admin = $this->admin();
        $oldVersion = (int) $channel->hostedSiteProfile->settings_version;

        $this->actingAs($admin, 'admin')->post(route('admin.distribution.hosted-sites.article-permalink.preview', $channel), ['pattern' => '/news/{id}-{slug}.html'])->assertSessionHasNoErrors();
        $change = $this->checkedChange();
        $csv = $this->get(route('admin.url-changes.download', $change))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=2+2", $csv);
        $this->assertStringNotContainsString("\n=2+2,", $csv);
        $service = app(UrlChangeService::class);
        $this->post(route('admin.distribution.hosted-sites.article-permalink.activate', $channel), ['change_id' => $change->id, 'credential' => $service->credential($change), 'confirmation' => $service->phrase($change)])->assertSessionHasNoErrors();
        for ($i = 0; $i < 10 && $change->refresh()->status !== 'completed'; $i++) {
            app()->call([new RefreshUrlChange($change->id), 'handle']);
        }
        $this->assertSame('completed', $change->refresh()->status);

        $freshChannel = $channel->fresh('hostedSiteProfile');
        $policy = ArticlePermalinkPolicy::fromRaw(data_get($freshChannel->site_settings, ArticlePermalinkPolicy::SETTING_KEY));
        $this->assertSame('/news/{id}-{slug}.html', $policy->currentPattern);
        $this->assertSame($oldVersion + 1, (int) $freshChannel->hostedSiteProfile->settings_version);
        $this->assertSame(
            'https://alpha.sites.test/news/'.$article->id.'-'.$article->slug.'.html',
            $distribution->fresh()->remote_url,
        );
    }

    public function test_remote_url_refresh_processes_one_bounded_batch_and_dispatches_a_continuation(): void
    {
        [$channel] = $this->site('alpha', '/news/{id}.html');
        $category = Category::query()->firstOrFail();
        $author = Author::query()->firstOrFail();
        $task = Task::query()->firstOrFail();

        foreach (range(1, 201) as $index) {
            $article = Article::query()->create([
                'title' => 'Refresh article '.$index,
                'slug' => 'refresh-article-'.$index,
                'content' => 'Body',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'task_id' => $task->id,
                'status' => 'private',
                'review_status' => 'approved',
                'published_at' => now(),
            ]);
            ArticleDistribution::query()->create([
                'article_id' => $article->id,
                'distribution_channel_id' => $channel->id,
                'action' => 'publish',
                'status' => 'synced',
                'remote_id' => 'refresh-'.$index,
                'remote_url' => 'https://alpha.sites.test/article/'.$article->slug,
                'idempotency_key' => 'hosted-permalink-refresh-'.$index,
                'attempt_count' => 1,
                'payload_hash' => hash('sha256', 'refresh-'.$index),
            ]);
        }

        Queue::fake();
        $policy = ArticlePermalinkPolicy::fromRaw(
            data_get($channel->site_settings, ArticlePermalinkPolicy::SETTING_KEY)
        );
        (new RefreshHostedSitePermalinkUrls((int) $channel->id, $policy->revision))
            ->handle(app(HostedSiteUrlGenerator::class));

        $this->assertSame(200, ArticleDistribution::query()
            ->where('distribution_channel_id', $channel->id)
            ->where('remote_url', 'like', 'https://alpha.sites.test/news/%')
            ->count());
        $this->assertSame(1, ArticleDistribution::query()
            ->where('distribution_channel_id', $channel->id)
            ->where('remote_url', 'like', 'https://alpha.sites.test/article/%')
            ->count());
        $continuation = null;
        Queue::assertPushed(
            RefreshHostedSitePermalinkUrls::class,
            function (RefreshHostedSitePermalinkUrls $job) use (&$continuation): bool {
                $continuation = $job;

                return true;
            },
        );
        $this->assertInstanceOf(RefreshHostedSitePermalinkUrls::class, $continuation);

        $continuation->handle(app(HostedSiteUrlGenerator::class));

        $this->assertSame(201, ArticleDistribution::query()
            ->where('distribution_channel_id', $channel->id)
            ->where('remote_url', 'like', 'https://alpha.sites.test/news/%')
            ->count());
        Queue::assertPushed(RefreshHostedSitePermalinkUrls::class, 1);
    }

    public function test_preview_and_migration_map_distinguish_public_and_potential_hosted_urls(): void
    {
        [$channel] = $this->site('alpha', ArticlePermalinkPolicy::DEFAULT_PATTERN);
        $this->assignedArticle($channel, 'Draft assigned article', 'draft-assigned', 'draft', 'approved', 'distribution_only');
        $this->assignedArticle($channel, 'Pending assigned article', 'pending-assigned', 'private', 'pending', 'distribution_only');
        $this->assignedArticle($channel, 'Wrong scope assigned article', 'wrong-scope-assigned', 'private', 'approved', 'local_and_distribution');
        $this->actingAs($this->admin(), 'admin')->post(route('admin.distribution.hosted-sites.article-permalink.preview', $channel), ['pattern' => '/{slug}.html'])->assertSessionHasNoErrors();
        $change = $this->checkedChange();
        $this->assertSame(1, $change->summary['changed_urls']);
        $this->assertSame(3, $change->summary['potential_urls']);
        $this->assertSame(4, $change->summary['associated']);
        $csv = $this->get(route('admin.url-changes.download', $change))->assertOk()->streamedContent();
        $this->assertStringContainsString('Draft assigned article', $csv);
        $this->assertStringContainsString('currently_public', $csv);
        $this->assertStringContainsString(',no', $csv);
    }

    /** @return array{DistributionChannel,Article} */
    private function site(string $label, string $pattern): array
    {
        $policy = ArticlePermalinkPolicy::defaults()->activate($pattern)->toArray();
        $channel = DistributionChannel::query()->create([
            'name' => ucfirst($label),
            'domain' => $label.'.sites.test',
            'endpoint_url' => 'https://'.$label.'.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
            'site_settings' => [
                'site_name' => ucfirst($label),
                ArticlePermalinkPolicy::SETTING_KEY => $policy,
            ],
        ]);
        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id,
            'hostname' => $label.'.sites.test',
            'root_domain' => 'sites.test',
            'topic' => 'AI',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
        ]);
        $task = Task::query()->create([
            'name' => $label.' task',
            'status' => 'active',
            'publish_scope' => 'distribution_only',
        ]);
        $category = Category::query()->firstOrCreate(['slug' => 'ai'], ['name' => 'AI']);
        $author = Author::query()->firstOrCreate(['email' => 'hosted-permalink@example.test'], ['name' => 'Hosted']);
        $article = Article::query()->create([
            'title' => ucfirst($label).' permalink article',
            'slug' => $label.'-article',
            'content' => 'Body',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => 'private',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $article->id,
            'hosted_site_profile_id' => $profile->id,
            'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
            'content_fingerprint' => hash('sha256', $label),
            'capacity_date' => now()->toDateString(),
            'assigned_at' => now(),
            'published_at' => now(),
        ]);

        return [$channel->fresh('hostedSiteProfile'), $article];
    }

    private function admin(): Admin
    {
        return Admin::query()->create([
            'username' => 'hosted_permalink_admin',
            'password' => 'Password123!',
            'email' => 'hosted-permalink-admin@example.test',
            'display_name' => 'Hosted Permalink Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
    }

    private function assignedArticle(
        DistributionChannel $channel,
        string $title,
        string $slug,
        string $status,
        string $reviewStatus,
        string $publishScope,
    ): Article {
        $task = Task::query()->create([
            'name' => $title.' task',
            'status' => 'active',
            'publish_scope' => $publishScope,
        ]);
        $category = Category::query()->firstOrCreate(['slug' => 'ai'], ['name' => 'AI']);
        $author = Author::query()->firstOrCreate(['email' => 'hosted-permalink@example.test'], ['name' => 'Hosted']);
        $article = Article::query()->create([
            'title' => $title,
            'slug' => $slug,
            'content' => 'Body',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => $status,
            'review_status' => $reviewStatus,
            'published_at' => now(),
        ]);
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $article->id,
            'hosted_site_profile_id' => $channel->hostedSiteProfile->id,
            'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
            'content_fingerprint' => hash('sha256', $slug),
            'capacity_date' => now()->toDateString(),
            'assigned_at' => now(),
            'published_at' => now(),
        ]);

        return $article;
    }
}
