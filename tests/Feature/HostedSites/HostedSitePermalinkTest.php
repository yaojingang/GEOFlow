<?php

namespace Tests\Feature\HostedSites;

use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HostedSitePermalinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
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

        $previewResponse = $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.article-permalink.preview', $channel), [
                'pattern' => '/news/{id}-{slug}.html',
            ])
            ->assertRedirect(route('admin.distribution.hosted-sites.edit', $channel))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('hosted_article_permalink_preview');
        $preview = $previewResponse->getSession()->get('hosted_article_permalink_preview');

        $migration = $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.hosted-sites.article-permalink.migration-map', [
                'hostedSite' => $channel,
                'preview_credential' => $preview['credential'],
            ]))
            ->assertOk()
            ->assertDownload('hosted-article-url-migration.csv');
        $csv = $migration->streamedContent();
        $this->assertStringContainsString("'=2+2", $csv);
        $this->assertStringNotContainsString("\n=2+2,", $csv);

        $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.article-permalink.activate', $channel), [
                'preview_credential' => $preview['credential'],
            ])
            ->assertSessionHasNoErrors();

        $freshChannel = $channel->fresh('hostedSiteProfile');
        $policy = ArticlePermalinkPolicy::fromRaw(data_get($freshChannel->site_settings, ArticlePermalinkPolicy::SETTING_KEY));
        $this->assertSame('/news/{id}-{slug}.html', $policy->currentPattern);
        $this->assertSame($oldVersion + 1, (int) $freshChannel->hostedSiteProfile->settings_version);
        $this->assertSame(
            'https://alpha.sites.test/news/'.$article->id.'-'.$article->slug.'.html',
            $distribution->fresh()->remote_url,
        );
    }

    public function test_preview_and_migration_map_only_include_public_articles_for_the_hosted_site(): void
    {
        [$channel] = $this->site('alpha', ArticlePermalinkPolicy::DEFAULT_PATTERN);
        $this->assignedArticle($channel, 'Draft assigned article', 'draft-assigned', 'draft', 'approved', 'distribution_only');
        $this->assignedArticle($channel, 'Pending assigned article', 'pending-assigned', 'private', 'pending', 'distribution_only');
        $this->assignedArticle($channel, 'Wrong scope assigned article', 'wrong-scope-assigned', 'private', 'approved', 'local_and_distribution');
        $admin = $this->admin();

        $previewResponse = $this->actingAs($admin, 'admin')
            ->post(route('admin.distribution.hosted-sites.article-permalink.preview', $channel), [
                'pattern' => '/{slug}.html',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('hosted_article_permalink_preview');
        $preview = $previewResponse->getSession()->get('hosted_article_permalink_preview');

        $this->assertSame(1, $preview['affected_articles']);
        $this->assertCount(1, $preview['examples']);

        $csv = $this->actingAs($admin, 'admin')
            ->get(route('admin.distribution.hosted-sites.article-permalink.migration-map', [
                'hostedSite' => $channel,
                'preview_credential' => $preview['credential'],
            ]))
            ->assertOk()
            ->streamedContent();

        $this->assertStringNotContainsString('Draft assigned article', $csv);
        $this->assertStringNotContainsString('Pending assigned article', $csv);
        $this->assertStringNotContainsString('Wrong scope assigned article', $csv);
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
