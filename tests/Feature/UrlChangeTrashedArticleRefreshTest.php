<?php

namespace Tests\Feature;

use App\Jobs\BuildSitemapManifest;
use App\Jobs\CheckUrlChange;
use App\Jobs\ReconcileArticleAiQualityJob;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ChecksUrlChanges;
use Tests\TestCase;

class UrlChangeTrashedArticleRefreshTest extends TestCase
{
    use ChecksUrlChanges;
    use RefreshDatabase;

    #[DataProvider('urlChanges')]
    public function test_completed_change_refreshes_synced_urls_before_trashed_articles_are_restored(string $operation, string $value, string $expectedPath): void
    {
        $this->prepareUrlQueue();
        Queue::fake([CheckUrlChange::class, RefreshUrlChange::class, BuildSitemapManifest::class, ReconcileArticleAiQualityJob::class]);
        [$channel, $article, $distribution, $deletion] = $this->trashedHostedArticle();
        $admin = Admin::query()->create(['username' => 'url_restore_admin', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $service = app(UrlChangeService::class);
        $service->start($admin, $operation, $operation === 'hosted' ? $channel->id : $article->category_id, $value);
        $change = $this->checkedChange();

        $service->confirm($admin, $change, $service->credential($change), $service->phrase($change));
        for ($step = 0; $step < 10 && $change->refresh()->status !== 'completed'; $step++) {
            app()->call([new RefreshUrlChange($change->id), 'handle']);
        }

        $this->assertSame('completed', $change->refresh()->status);
        $this->assertSame('https://restore.sites.test'.$expectedPath, $distribution->refresh()->remote_url);
        $this->assertSame('https://restore.sites.test/news/restored-article', $deletion->refresh()->remote_url);
        $this->assertSoftDeleted($article);

        $this->actingAs($admin, 'admin')->post(route('admin.articles.restore', $article->id))->assertSessionHasNoErrors();

        $this->assertNotSoftDeleted($article);
        $this->assertSame('https://restore.sites.test'.$expectedPath, $distribution->refresh()->remote_url);
        Queue::assertPushed(RefreshUrlChange::class);
        Queue::assertPushed(ReconcileArticleAiQualityJob::class);
    }

    public static function urlChanges(): array
    {
        return [
            'hosted rule changes while article is in trash' => ['hosted', '/{slug}.html', '/restored-article.html'],
            'category slug changes while article is in trash' => ['category', 'updated-news', '/updated-news/restored-article'],
        ];
    }

    public function test_legacy_refresh_updates_trashed_synced_articles_and_preserves_delete_records(): void
    {
        [$channel, $article, $distribution, $deletion] = $this->trashedHostedArticle();
        $policy = ArticlePermalinkPolicy::defaults()->activate('/{slug}.html');
        $channel->update(['site_settings' => [ArticlePermalinkPolicy::SETTING_KEY => $policy->toArray()]]);

        (new RefreshHostedSitePermalinkUrls((int) $channel->id, $policy->revision))->handle(app(HostedSiteUrlGenerator::class));
        $article->restore();

        $this->assertSame('https://restore.sites.test/restored-article.html', $distribution->refresh()->remote_url);
        $this->assertSame('https://restore.sites.test/news/restored-article', $deletion->refresh()->remote_url);
    }

    /** @return array{DistributionChannel,Article,ArticleDistribution,ArticleDistribution} */
    private function trashedHostedArticle(): array
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor']);
        $task = Task::query()->create(['name' => 'Hosted task', 'status' => 'active', 'publish_scope' => 'distribution_only']);
        $channel = DistributionChannel::query()->create([
            'name' => 'Restore', 'domain' => 'restore.sites.test', 'endpoint_url' => 'https://restore.sites.test',
            'channel_type' => 'hosted_site', 'status' => 'active',
            'site_settings' => [ArticlePermalinkPolicy::SETTING_KEY => ArticlePermalinkPolicy::defaults()->activate('/{category}/{slug}')->toArray()],
        ]);
        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id, 'hostname' => 'restore.sites.test', 'root_domain' => 'sites.test',
            'topic' => 'News', 'serving_status' => 'online',
        ]);
        $article = Article::query()->create([
            'title' => 'Restored article', 'slug' => 'restored-article', 'content' => 'Body',
            'category_id' => $category->id, 'author_id' => $author->id, 'task_id' => $task->id,
            'status' => 'private', 'review_status' => 'approved',
        ]);
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $article->id, 'hosted_site_profile_id' => $profile->id, 'status' => 'published',
            'content_fingerprint' => hash('sha256', 'restore'), 'capacity_date' => now()->toDateString(), 'assigned_at' => now(), 'published_at' => now(),
        ]);
        $records = [];
        foreach (['publish', 'delete'] as $action) {
            $records[] = ArticleDistribution::query()->create([
                'article_id' => $article->id, 'distribution_channel_id' => $channel->id, 'action' => $action, 'status' => 'synced',
                'remote_url' => 'https://restore.sites.test/news/restored-article', 'idempotency_key' => 'restore-'.$action,
            ]);
        }
        $article->delete();

        return [$channel->fresh('hostedSiteProfile'), $article, ...$records];
    }
}
