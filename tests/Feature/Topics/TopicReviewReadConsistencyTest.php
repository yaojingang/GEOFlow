<?php

namespace Tests\Feature\Topics;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Models\Topic;
use App\Models\TopicSourceInvalidation;
use App\Services\Topics\TopicNamespaceGuard;
use App\Services\Topics\TopicReadModel;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicSiteSettings;
use Illuminate\Support\Facades\DB;
use PDO;
use Tests\TestCase;

class TopicReviewReadConsistencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    }

    private function withLaggingReplica(callable $changePrimary, callable $verify, bool $lagBeforeChange = false): void
    {
        $connection = DB::connection();
        $path = tempnam(sys_get_temp_dir(), 'topic-review-replica-');
        try {
            $connection->getPdo()->exec('VACUUM INTO '.$connection->getPdo()->quote($path));
            if ($lagBeforeChange) {
                $connection->forgetRecordModificationState();
                $connection->useWriteConnectionWhenReading(false)->setReadPdo(new PDO('sqlite:'.$path));
            }
            $changePrimary();
            $connection->forgetRecordModificationState();
            if (! $lagBeforeChange) {
                $connection->useWriteConnectionWhenReading(false)->setReadPdo(new PDO('sqlite:'.$path));
            }
            $verify();
        } finally {
            $connection->setReadPdo($connection->getPdo());
            unlink($path);
        }
    }

    private function hosted(): HostedSiteProfile
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Replica site', 'domain' => 'replica.sites.test', 'endpoint_url' => 'https://replica.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE, 'status' => DistributionChannel::STATUS_ACTIVE,
            'site_settings' => ['topics' => ['enabled' => true]],
        ]);

        return HostedSiteProfile::query()->create(['distribution_channel_id' => $channel->id, 'hostname' => 'replica.sites.test', 'root_domain' => 'sites.test']);
    }

    /** @return array{Topic,list<Article>} */
    private function publishedTopic(bool $publish = true): array
    {
        $author = Author::query()->create(['name' => 'Read consistency fixture']);
        $category = Category::query()->create(['name' => 'Read consistency', 'slug' => 'read-consistency']);
        $sources = array_map(fn ($i) => Article::query()->create(['title' => 'Source '.$i, 'slug' => 'read-source-'.$i, 'content' => 'Distinct body '.$i, 'author_id' => $author->id, 'category_id' => $category->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()]), [1, 2, 3]);
        $topic = app(TopicService::class)->create('primary', ['title' => 'Read consistency topic', 'intro' => 'Source interpretation.', 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)]);
        if ($publish) {
            app(TopicService::class)->publish($topic, 1);
        }

        return [$topic, $sources];
    }

    public function test_primary_channel_disable_is_immediate_even_when_the_read_replica_still_has_old_settings(): void
    {
        $author = Author::query()->create(['name' => 'Read consistency fixture']);
        $category = Category::query()->create(['name' => 'Read consistency', 'slug' => 'read-consistency']);
        $sources = array_map(fn ($i) => Article::query()->create(['title' => 'Source '.$i, 'slug' => 'read-source-'.$i, 'content' => 'Distinct body '.$i, 'author_id' => $author->id, 'category_id' => $category->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()]), [1, 2]);
        $topic = app(TopicService::class)->create('primary', ['title' => 'Read consistency topic', 'intro' => 'Source interpretation.', 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)]);
        app(TopicService::class)->publish($topic, 1);
        $this->withLaggingReplica(
            fn () => SiteSetting::query()->updateOrCreate(['setting_key' => 'topics'], ['setting_value' => json_encode(['enabled' => false])]),
            function (): void {
                $this->assertFalse(app(TopicSiteSettings::class)->get('primary')['enabled']);
                $this->assertTrue(app(TopicReadModel::class)->all('primary')->isEmpty());
            },
        );
    }

    public function test_hosted_channel_disable_reads_the_primary_channel_configuration(): void
    {
        $profile = $this->hosted();
        $key = 'hosted:'.$profile->id;
        $this->withLaggingReplica(
            fn () => $profile->channel()->firstOrFail()->update(['site_settings' => ['topics' => ['enabled' => false]]]),
            function () use ($key): void {
                $this->assertFalse(app(TopicSiteSettings::class)->get($key)['enabled']);
                $this->assertTrue(app(TopicReadModel::class)->all($key)->isEmpty());
            },
        );
    }

    public function test_primary_namespace_conflicts_remain_immediate_with_a_lagging_read_replica(): void
    {
        $pattern = '/topics/{slug}';
        $this->withLaggingReplica(
            fn () => SiteSetting::query()->updateOrCreate(['setting_key' => 'article_permalink_policy'], ['setting_value' => json_encode(['schema_version' => 1, 'revision' => 1, 'current_pattern' => $pattern, 'history' => []])]),
            fn () => $this->assertSame([$pattern], app(TopicNamespaceGuard::class)->conflicts('primary')),
        );
    }

    public function test_hosted_namespace_conflicts_read_current_channel_policy_instead_of_replica_policy(): void
    {
        $profile = $this->hosted();
        $pattern = '/topics/{slug}';
        $this->withLaggingReplica(
            fn () => $profile->channel()->firstOrFail()->update(['site_settings' => ['topics' => ['enabled' => true], 'article_permalink_policy' => ['schema_version' => 1, 'revision' => 1, 'current_pattern' => $pattern, 'history' => []]]]),
            fn () => $this->assertSame([$pattern], app(TopicNamespaceGuard::class)->conflicts('hosted:'.$profile->id)),
        );
    }

    public function test_source_withdrawal_history_pauses_public_detail_even_after_restore_with_a_lagging_replica(): void
    {
        [$topic, $sources] = $this->publishedTopic();
        $this->withLaggingReplica(
            function () use ($topic, $sources): void {
                $sources[0]->update(['status' => 'private']);
                $sources[0]->update(['status' => 'published']);
                $this->assertGreaterThan(0, TopicSourceInvalidation::query()->useWritePdo()->where('topic_revision_id', $topic->fresh()->public_revision_id)->count());
            },
            function () use ($topic): void {
                $this->assertNull(app(TopicService::class)->publicView($topic));
                $this->get('/topics/'.$topic->slug)->assertNotFound();
            },
        );
    }

    public function test_source_withdrawal_history_pauses_public_list_and_sitemap_with_a_lagging_replica(): void
    {
        [$topic, $sources] = $this->publishedTopic();
        $this->withLaggingReplica(
            function () use ($sources): void {
                $sources[0]->update(['status' => 'private']);
                $sources[0]->update(['status' => 'published']);
            },
            function () use ($topic, $sources): void {
                $this->assertTrue(app(TopicReadModel::class)->all('primary')->isEmpty());
                $this->assertTrue(app(TopicReadModel::class)->related('primary', $sources[1]->id)->isEmpty());
                $this->get('/sitemap.txt')->assertDontSee('/topics/'.$topic->slug, false);
            },
        );
    }

    public function test_source_mutation_then_restore_records_history_when_replica_lag_precedes_the_mutation(): void
    {
        [$topic, $sources] = $this->publishedTopic();
        $originalBody = $sources[0]->content;
        $this->withLaggingReplica(
            function () use ($sources, $originalBody): void {
                $sources[0]->update(['content' => 'Changed source evidence during replica lag']);
                $sources[0]->update(['content' => $originalBody]);
            },
            function () use ($topic): void {
                $this->assertGreaterThan(0, TopicSourceInvalidation::query()->useWritePdo()->where('topic_revision_id', $topic->fresh()->public_revision_id)->count());
                $this->assertGreaterThan(0, TopicSourceInvalidation::query()->useWritePdo()->where('topic_id', $topic->id)->whereNull('topic_revision_id')->count());
                $this->assertNull(app(TopicService::class)->publicView($topic));
                $this->assertTrue(app(TopicReadModel::class)->all('primary')->isEmpty());
            },
            true,
        );
    }

    public function test_source_mutation_invalidates_a_revision_published_after_the_replica_snapshot(): void
    {
        [$topic, $sources] = $this->publishedTopic(false);
        $originalBody = $sources[0]->content;
        $this->withLaggingReplica(
            function () use ($topic, $sources, $originalBody): void {
                app(TopicService::class)->publish($topic, 1);
                $sources[0]->update(['content' => 'Changed evidence after new revision publication']);
                $sources[0]->update(['content' => $originalBody]);
            },
            function () use ($topic): void {
                $current = Topic::query()->useWritePdo()->findOrFail($topic->id);
                $this->assertGreaterThan(0, TopicSourceInvalidation::query()->useWritePdo()->where('topic_revision_id', $current->public_revision_id)->count());
                $this->assertNull(app(TopicService::class)->publicView($topic));
            },
            true,
        );
    }

    public function test_task_source_policy_change_finds_new_articles_on_primary_and_records_history_before_restore(): void
    {
        $topic = null;
        $this->withLaggingReplica(
            function () use (&$topic): void {
                $task = Task::query()->create(['name' => 'New task during replica lag', 'publish_scope' => 'site_only', 'need_review' => false]);
                $author = Author::query()->create(['name' => 'New task source author']);
                $category = Category::query()->create(['name' => 'New task source', 'slug' => 'new-task-source']);
                $sources = array_map(fn ($i) => Article::query()->create(['title' => 'Task source '.$i, 'slug' => 'task-source-'.$i, 'content' => 'Distinct task evidence '.$i, 'task_id' => $task->id, 'author_id' => $author->id, 'category_id' => $category->id, 'status' => 'published', 'review_status' => 'auto_approved', 'published_at' => now()]), [1, 2]);
                $topic = app(TopicService::class)->create('primary', ['title' => 'New task topic', 'intro' => 'Task source interpretation.', 'articles' => array_map(fn ($a) => ['article_id' => $a->id], $sources)]);
                app(TopicService::class)->publish($topic, 1);
                $task->update(['need_review' => true]);
                $task->update(['need_review' => false]);
            },
            function () use (&$topic): void {
                $current = Topic::query()->useWritePdo()->findOrFail($topic->id);
                $this->assertGreaterThan(0, TopicSourceInvalidation::query()->useWritePdo()->where('topic_revision_id', $current->public_revision_id)->count());
                $this->assertNull(app(TopicService::class)->publicView($topic));
            },
            true,
        );
    }
}
