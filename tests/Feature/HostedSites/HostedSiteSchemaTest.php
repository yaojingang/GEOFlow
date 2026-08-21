<?php

namespace Tests\Feature\HostedSites;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HostedSiteSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_hosted_site_tables_and_attribution_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('hosted_site_profiles', [
            'distribution_channel_id',
            'hostname',
            'root_domain',
            'serving_status',
            'indexing_status',
            'quality_status',
            'settings_version',
        ]));
        $this->assertTrue(Schema::hasColumns('hosted_site_article_assignments', [
            'article_id',
            'hosted_site_profile_id',
            'status',
            'content_fingerprint',
            'capacity_date',
            'reservation_expires_at',
        ]));
        $this->assertTrue(Schema::hasColumns('hosted_site_allocation_requests', [
            'article_id',
            'task_id',
            'hosted_site_profile_id',
            'hosted_site_article_assignment_id',
            'status',
            'attempt_count',
            'next_attempt_at',
            'last_error_code',
        ]));
        $this->assertTrue(Schema::hasColumn('view_logs', 'hosted_site_profile_id'));
        $this->assertTrue(Schema::hasColumn('lead_submissions', 'hosted_site_profile_id'));
        $this->assertFalse(Schema::hasColumn('hosted_site_article_assignments', 'article_distribution_id'));
    }

    public function test_profile_and_request_models_apply_safe_phase_one_defaults(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Alpha',
            'domain' => 'alpha.sites.test',
            'endpoint_url' => 'https://alpha.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_PAUSED,
        ]);

        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id,
            'hostname' => 'alpha.sites.test',
            'root_domain' => 'sites.test',
            'topic' => 'AI',
        ]);

        $this->assertSame(HostedSiteProfile::SERVING_MAINTENANCE, $profile->serving_status);
        $this->assertSame(HostedSiteProfile::INDEXING_NOINDEX, $profile->indexing_status);
        $this->assertSame(HostedSiteProfile::QUALITY_PENDING, $profile->quality_status);
        $this->assertSame(3, $profile->daily_publish_limit);
        $this->assertSame(1, $profile->settings_version);
        $this->assertSame($profile->id, $channel->fresh()->hostedSiteProfile?->id);

        $article = $this->createArticle('request');
        $request = HostedSiteAllocationRequest::query()->create(['article_id' => $article->id]);

        $this->assertSame(HostedSiteAllocationRequest::STATUS_PENDING, $request->status);
        $this->assertSame(0, $request->attempt_count);
    }

    public function test_assignment_contract_enforces_one_site_per_article_and_unique_content(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Alpha',
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
        ]);
        $articles = collect([
            $this->createArticle('one'),
            $this->createArticle('two'),
        ]);

        HostedSiteArticleAssignment::query()->create([
            'article_id' => $articles[0]->id,
            'hosted_site_profile_id' => $profile->id,
            'status' => HostedSiteArticleAssignment::STATUS_RESERVED,
            'content_fingerprint' => str_repeat('a', 64),
            'capacity_date' => now()->toDateString(),
            'assigned_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        HostedSiteArticleAssignment::query()->create([
            'article_id' => $articles[1]->id,
            'hosted_site_profile_id' => $profile->id,
            'status' => HostedSiteArticleAssignment::STATUS_RESERVED,
            'content_fingerprint' => str_repeat('a', 64),
            'capacity_date' => now()->toDateString(),
            'assigned_at' => now(),
        ]);
    }

    public function test_hosted_site_migrations_support_up_down_and_up_again(): void
    {
        $paths = [
            database_path('migrations/2026_08_21_113529_create_hosted_site_profiles_table.php'),
            database_path('migrations/2026_08_21_113530_create_hosted_site_article_assignments_table.php'),
            database_path('migrations/2026_08_21_113531_create_hosted_site_allocation_requests_table.php'),
            database_path('migrations/2026_08_21_113532_add_hosted_site_profile_id_to_view_logs_and_lead_submissions.php'),
        ];
        $migrations = array_map(static fn (string $path): object => require $path, $paths);

        foreach (array_reverse($migrations) as $migration) {
            $migration->down();
        }

        $this->assertFalse(Schema::hasTable('hosted_site_profiles'));
        $this->assertFalse(Schema::hasTable('hosted_site_article_assignments'));
        $this->assertFalse(Schema::hasTable('hosted_site_allocation_requests'));
        $this->assertFalse(Schema::hasColumn('view_logs', 'hosted_site_profile_id'));
        $this->assertFalse(Schema::hasColumn('lead_submissions', 'hosted_site_profile_id'));

        foreach ($migrations as $migration) {
            $migration->up();
        }

        $this->assertTrue(Schema::hasTable('hosted_site_profiles'));
        $this->assertTrue(Schema::hasTable('hosted_site_article_assignments'));
        $this->assertTrue(Schema::hasTable('hosted_site_allocation_requests'));
        $this->assertTrue(Schema::hasColumn('view_logs', 'hosted_site_profile_id'));
        $this->assertTrue(Schema::hasColumn('lead_submissions', 'hosted_site_profile_id'));
    }

    private function createArticle(string $suffix): Article
    {
        $category = Category::query()->firstOrCreate(
            ['slug' => 'hosted-schema'],
            ['name' => 'Hosted Schema', 'description' => '', 'sort_order' => 0]
        );
        $author = Author::query()->firstOrCreate(
            ['email' => 'hosted-schema@example.test'],
            ['name' => 'Hosted Author', 'bio' => '', 'avatar' => '', 'website' => '']
        );

        return Article::query()->create([
            'title' => 'Hosted '.$suffix,
            'slug' => 'hosted-'.$suffix,
            'content' => 'Content '.$suffix,
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
    }
}
