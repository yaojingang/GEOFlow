<?php

namespace Tests\Feature\HostedSites;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\LeadForm;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HostedSitePublicRenderingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('geoflow.hosted_sites.enabled', true);
        config()->set('geoflow.hosted_sites.primary_hosts', ['primary.test']);
        config()->set('geoflow.hosted_sites.root_domains', ['sites.test']);
    }

    public function test_home_article_category_and_settings_are_isolated_by_host(): void
    {
        [$alpha, $alphaArticle] = $this->siteFixture('alpha', 'Alpha Site', 'Alpha article');
        [, $betaArticle] = $this->siteFixture('beta', 'Beta Site', 'Beta article');
        $primaryArticle = $this->articleFixture('Primary article', 'primary-article', 'published');

        $this->get('http://alpha.sites.test/')
            ->assertOk()
            ->assertSee('Alpha Site')
            ->assertSee('Alpha article')
            ->assertDontSee('Beta article')
            ->assertDontSee('Primary article')
            ->assertSee('application/ld+json')
            ->assertSee('https://alpha.sites.test/', false)
            ->assertSee('content="noindex, nofollow"', false);

        $this->get('http://beta.sites.test/')
            ->assertOk()
            ->assertSee('Beta Site')
            ->assertSee('Beta article')
            ->assertDontSee('Alpha article');

        $this->get('http://alpha.sites.test/article/'.$alphaArticle->slug)
            ->assertOk()
            ->assertSee('https://alpha.sites.test/article/'.$alphaArticle->slug, false);

        $this->get('http://beta.sites.test/article/'.$alphaArticle->slug)
            ->assertNotFound();

        $this->get('http://alpha.sites.test/category/'.$alphaArticle->category->slug)
            ->assertOk()
            ->assertSee('Alpha article')
            ->assertDontSee('Beta article');

        $this->assertDatabaseHas('view_logs', [
            'hosted_site_profile_id' => $alpha->id,
            'source' => 'hosted_site',
            'route_name' => 'site.article',
        ]);
        $this->assertNotNull($primaryArticle->id);
        $this->assertNotNull($betaArticle->id);
    }

    public function test_hosted_about_forms_and_submission_ownership_use_the_site_allowlist(): void
    {
        [$profile] = $this->siteFixture('alpha', 'Alpha Site', 'Alpha article', [
            'about_title' => 'About Alpha',
            'about_content' => 'Alpha is a focused AI publication.',
            'lead_form_slugs' => ['contact-alpha'],
        ]);
        $allowed = $this->leadForm('contact-alpha');
        $this->leadForm('private-form');

        $this->get('http://alpha.sites.test/about')
            ->assertOk()
            ->assertSee('About Alpha')
            ->assertSee('Alpha is a focused AI publication.');

        $this->get('http://alpha.sites.test/forms/contact-alpha')->assertOk();
        $this->get('http://alpha.sites.test/forms/private-form')->assertNotFound();

        $response = $this->from('http://alpha.sites.test/forms/contact-alpha')
            ->post('http://alpha.sites.test/forms/contact-alpha/submissions', [
                'name' => 'Hosted visitor',
                'website' => '',
            ])
            ->assertRedirect('http://alpha.sites.test/forms/contact-alpha');

        $this->assertNull(config('session.domain'));
        foreach ($response->headers->getCookies() as $cookie) {
            $this->assertNull($cookie->getDomain());
        }

        $this->assertDatabaseHas('lead_submissions', [
            'lead_form_id' => $allowed->id,
            'hosted_site_profile_id' => $profile->id,
        ]);
    }

    public function test_robots_and_sitemap_follow_the_site_indexing_gate(): void
    {
        config()->set('geoflow.hosted_sites.sitemap_url_limit', 2);
        [$profile, $article] = $this->siteFixture('alpha', 'Alpha Site', 'Alpha article');
        [, $betaArticle] = $this->siteFixture('beta', 'Beta Site', 'Beta article');
        $secondArticle = $this->articleFixture('Second Alpha article', 'alpha-second-article', 'private');
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $secondArticle->id,
            'hosted_site_profile_id' => $profile->id,
            'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
            'content_fingerprint' => hash('sha256', 'alpha-second'),
            'capacity_date' => now()->toDateString(),
            'assigned_at' => now(),
            'published_at' => now(),
        ]);

        $this->get('http://alpha.sites.test/robots.txt')
            ->assertOk()
            ->assertSee('Disallow: /');
        $this->get('http://alpha.sites.test/sitemap.xml')
            ->assertOk()
            ->assertDontSee($article->slug);

        $profile->update([
            'indexing_status' => HostedSiteProfile::INDEXING_INDEX,
            'quality_status' => HostedSiteProfile::QUALITY_PASSED,
        ]);

        $this->get('http://alpha.sites.test/robots.txt')
            ->assertOk()
            ->assertSee('Sitemap: https://alpha.sites.test/sitemap.xml')
            ->assertDontSee('Disallow: /');
        $this->get('http://alpha.sites.test/sitemap.xml')
            ->assertOk()
            ->assertSee('<sitemapindex', false)
            ->assertSee('https://alpha.sites.test/sitemaps/pages-1.xml', false)
            ->assertSee('https://alpha.sites.test/sitemaps/pages-2.xml', false)
            ->assertDontSee($article->slug);
        $firstShard = $this->get('http://alpha.sites.test/sitemaps/pages-1.xml')->assertOk();
        $secondShard = $this->get('http://alpha.sites.test/sitemaps/pages-2.xml')->assertOk();
        $combinedShards = $firstShard->getContent().$secondShard->getContent();
        $this->assertStringContainsString('https://alpha.sites.test/article/'.$article->slug, $combinedShards);
        $this->assertStringContainsString('https://alpha.sites.test/article/'.$secondArticle->slug, $combinedShards);
        $this->assertStringNotContainsString($betaArticle->slug, $combinedShards);
        $this->get('http://alpha.sites.test/sitemaps/pages-3.xml')->assertNotFound();
    }

    public function test_hosted_about_and_footer_never_fall_back_to_the_primary_brand(): void
    {
        $this->siteFixture('alpha', 'Alpha Publication', 'Alpha article', [
            'site_description' => 'Independent Alpha coverage.',
            'site_keywords' => 'alpha,coverage',
        ]);

        $this->get('http://alpha.sites.test/about')
            ->assertOk()
            ->assertSee('关于 Alpha Publication')
            ->assertSee('Independent Alpha coverage.')
            ->assertDontSee('GEOFlow');
    }

    public function test_hosted_storage_assets_are_served_only_after_host_resolution(): void
    {
        $this->siteFixture('alpha', 'Alpha Site', 'Alpha article');
        Storage::disk('public')->put('hosted-sites/alpha-test.png', 'fake-png');

        try {
            $this->get('http://alpha.sites.test/storage/hosted-sites/alpha-test.png')
                ->assertOk()
                ->assertHeader('Cache-Control', 'immutable, max-age=604800, public');
            $this->get('http://unknown.sites.test/storage/hosted-sites/alpha-test.png')
                ->assertNotFound();
        } finally {
            Storage::disk('public')->delete('hosted-sites/alpha-test.png');
        }
    }

    /** @param array<string,mixed> $extraSettings @return array{HostedSiteProfile,Article} */
    private function siteFixture(string $label, string $siteName, string $articleTitle, array $extraSettings = []): array
    {
        $hostname = $label.'.sites.test';
        $channel = DistributionChannel::query()->create([
            'name' => $siteName,
            'domain' => $hostname,
            'endpoint_url' => 'https://'.$hostname,
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
            'site_settings' => ['site_name' => $siteName] + $extraSettings,
        ]);
        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id,
            'hostname' => $hostname,
            'root_domain' => 'sites.test',
            'topic' => 'AI',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
        ]);
        $article = $this->articleFixture($articleTitle, $label.'-article', 'private');
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $article->id,
            'hosted_site_profile_id' => $profile->id,
            'status' => HostedSiteArticleAssignment::STATUS_PUBLISHED,
            'content_fingerprint' => hash('sha256', $label),
            'capacity_date' => now()->toDateString(),
            'assigned_at' => now(),
            'published_at' => now(),
        ]);

        return [$profile, $article];
    }

    private function articleFixture(string $title, string $slug, string $status): Article
    {
        $task = Task::query()->create([
            'name' => $title.' task',
            'status' => 'active',
            'publish_scope' => 'distribution_only',
        ]);
        $category = Category::query()->firstOrCreate(
            ['slug' => 'ai'],
            ['name' => 'AI', 'description' => '', 'sort_order' => 0]
        );
        $author = Author::query()->firstOrCreate(
            ['email' => 'public-hosted@example.test'],
            ['name' => 'Hosted Author', 'bio' => '', 'avatar' => '', 'website' => '']
        );

        return Article::query()->create([
            'title' => $title,
            'slug' => $slug,
            'content' => $title.' body',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'task_id' => $task->id,
            'status' => $status,
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }

    private function leadForm(string $slug): LeadForm
    {
        return LeadForm::query()->create([
            'name' => $slug,
            'slug' => $slug,
            'status' => LeadForm::STATUS_ACTIVE,
            'fields' => [[
                'name' => 'name',
                'label' => 'Name',
                'type' => 'text',
                'required' => true,
                'options' => [],
            ]],
        ]);
    }
}
