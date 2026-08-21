<?php

namespace Tests\PostgreSQL;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteAllocationRequest;
use App\Models\HostedSiteArticleAssignment;
use App\Models\HostedSiteProfile;
use App\Models\Task;
use App\Services\HostedSites\HostedSiteAllocator;
use App\Services\HostedSites\HostedSiteLifecycleService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

class HostedSiteConcurrencyTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_postgresql_row_locks_keep_one_article_to_one_site_under_concurrency(): void
    {
        [$firstRequest] = $this->fixtures(dailyLimit: 3, articleCount: 1);

        $results = $this->runConcurrent([$firstRequest->id, $firstRequest->id]);

        $this->assertSame([0, 0], array_column($results, 'exit'));
        DB::purge('pgsql');
        DB::reconnect('pgsql');
        $this->assertSame(1, HostedSiteArticleAssignment::query()->count());
        $this->assertSame(1, DB::table('article_distributions')->count());
        $this->assertSame(1, HostedSiteArticleAssignment::query()->distinct('article_id')->count('article_id'));
    }

    public function test_postgresql_profile_lock_never_overbooks_daily_capacity(): void
    {
        $requests = $this->fixtures(dailyLimit: 1, articleCount: 2);

        $results = $this->runConcurrent([$requests[0]->id, $requests[1]->id]);

        $this->assertSame([0, 0], array_column($results, 'exit'));
        DB::purge('pgsql');
        DB::reconnect('pgsql');
        $this->assertSame(1, HostedSiteArticleAssignment::query()->count());
        $this->assertSame(1, HostedSiteAllocationRequest::query()->where('status', 'assigned')->count());
        $this->assertSame(1, HostedSiteAllocationRequest::query()->where('status', 'pending')->count());
    }

    public function test_postgresql_global_fingerprint_conflict_becomes_a_terminal_business_result(): void
    {
        $firstRequest = $this->fixtures(
            dailyLimit: 3,
            articleCount: 1,
            prefix: 'fingerprint-a',
            sharedTitle: 'Identical hosted article',
            sharedContent: 'The same normalized hosted content.',
        )[0];
        $secondRequest = $this->fixtures(
            dailyLimit: 3,
            articleCount: 1,
            prefix: 'fingerprint-b',
            sharedTitle: 'Identical hosted article',
            sharedContent: 'The same normalized hosted content.',
        )[0];

        $results = $this->runConcurrent([$firstRequest->id, $secondRequest->id]);

        $this->assertSame([0, 0], array_column($results, 'exit'), json_encode($results));
        DB::purge('pgsql');
        DB::reconnect('pgsql');
        $this->assertSame(1, HostedSiteArticleAssignment::query()->count());
        $this->assertSame(1, HostedSiteAllocationRequest::query()->where('status', 'assigned')->count());
        $this->assertSame(1, HostedSiteAllocationRequest::query()
            ->where('status', HostedSiteAllocationRequest::STATUS_CANCELLED)
            ->where('last_error_code', 'duplicate_content')
            ->count());
    }

    public function test_postgresql_allocator_and_archive_follow_one_lock_order_without_deadlock(): void
    {
        $request = $this->fixtures(dailyLimit: 3, articleCount: 1)[0];
        $channel = $request->task->distributionChannels()->firstOrFail();

        $results = $this->runConcurrentActions([
            ['action' => 'allocate', 'id' => (int) $request->id],
            ['action' => 'archive', 'id' => (int) $channel->id, 'hostname' => (string) $channel->domain],
        ]);

        $this->assertSame([0, 0], array_column($results, 'exit'), json_encode($results));
        DB::purge('pgsql');
        DB::reconnect('pgsql');
        $this->assertSame(HostedSiteProfile::SERVING_ARCHIVED, HostedSiteProfile::query()->sole()->serving_status);
        $this->assertSame(0, HostedSiteArticleAssignment::query()->where('status', 'reserved')->count());
        $this->assertSame(0, DB::table('article_distributions')->whereIn('status', ['queued', 'sending'])->count());
    }

    /** @param list<int> $requestIds @return list<array{exit:int,result:string}> */
    private function runConcurrent(array $requestIds): array
    {
        return $this->runConcurrentActions(array_map(
            static fn (int $requestId): array => ['action' => 'allocate', 'id' => $requestId],
            $requestIds,
        ));
    }

    /**
     * @param  list<array{action:string,id:int,hostname?:string}>  $actions
     * @return list<array{exit:int,result:string}>
     */
    private function runConcurrentActions(array $actions): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required.');
        }

        $startAt = microtime(true) + 0.35;
        $children = [];
        foreach ($actions as $index => $action) {
            $resultPath = tempnam(sys_get_temp_dir(), 'geoflow-pg-concurrency-');
            $this->assertIsString($resultPath);
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork PostgreSQL concurrency worker.');
            }
            if ($pid === 0) {
                try {
                    $waitMicros = (int) max(0, ($startAt - microtime(true)) * 1_000_000);
                    if ($waitMicros > 0) {
                        usleep($waitMicros);
                    }
                    DB::purge('pgsql');
                    DB::reconnect('pgsql');
                    DB::statement("SET lock_timeout TO '5s'");
                    if ($action['action'] === 'archive') {
                        $channel = DistributionChannel::query()->findOrFail($action['id']);
                        app(HostedSiteLifecycleService::class)->archive($channel, (string) $action['hostname']);
                        file_put_contents($resultPath, 'archived');
                    } else {
                        $request = HostedSiteAllocationRequest::query()->findOrFail($action['id']);
                        $assignment = app(HostedSiteAllocator::class)->allocate($request);
                        file_put_contents($resultPath, $assignment?->id === null ? 'none' : (string) $assignment->id);
                    }
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($resultPath, $exception::class.': '.$exception->getMessage());
                    exit(1);
                }
            }
            $children[] = ['pid' => $pid, 'path' => $resultPath, 'index' => $index];
        }

        DB::disconnect('pgsql');
        $results = [];
        foreach ($children as $child) {
            $status = 0;
            pcntl_waitpid($child['pid'], $status);
            $results[$child['index']] = [
                'exit' => pcntl_wexitstatus($status),
                'result' => (string) file_get_contents($child['path']),
            ];
            unlink($child['path']);
        }
        ksort($results);

        return array_values($results);
    }

    /** @return list<HostedSiteAllocationRequest> */
    private function fixtures(
        int $dailyLimit,
        int $articleCount,
        string $prefix = 'pg',
        ?string $sharedTitle = null,
        ?string $sharedContent = null,
    ): array {
        $channel = DistributionChannel::query()->create([
            'name' => 'PostgreSQL hosted site '.$prefix,
            'domain' => $prefix.'.sites.test',
            'endpoint_url' => 'https://'.$prefix.'.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id,
            'hostname' => $prefix.'.sites.test',
            'root_domain' => 'sites.test',
            'topic' => 'AI',
            'daily_publish_limit' => $dailyLimit,
            'min_publish_interval_minutes' => 0,
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
        ]);
        $aiModelId = DB::table('ai_models')->insertGetId([
            'name' => 'PostgreSQL test model '.$prefix,
            'api_key' => 'test-key',
            'model_id' => 'test-model',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $promptId = DB::table('prompts')->insertGetId([
            'name' => 'PostgreSQL test prompt '.$prefix,
            'type' => 'content',
            'content' => 'Write content.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $titleLibraryId = DB::table('title_libraries')->insertGetId([
            'name' => 'PostgreSQL title library '.$prefix,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $task = Task::query()->create([
            'name' => 'PostgreSQL hosted task '.$prefix,
            'title_library_id' => $titleLibraryId,
            'prompt_id' => $promptId,
            'ai_model_id' => $aiModelId,
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
        $category = Category::query()->create(['name' => 'AI '.$prefix, 'slug' => 'ai-'.$prefix, 'sort_order' => 0]);
        $author = Author::query()->create([
            'name' => 'PostgreSQL Author '.$prefix,
            'email' => 'postgres-hosted-'.$prefix.'@example.test',
            'bio' => '',
            'avatar' => '',
            'website' => '',
        ]);

        $requests = [];
        for ($index = 1; $index <= $articleCount; $index++) {
            $article = Article::query()->create([
                'title' => $sharedTitle ?? 'PostgreSQL article '.$prefix.' '.$index,
                'slug' => 'postgresql-article-'.$prefix.'-'.$index,
                'content' => $sharedContent ?? 'PostgreSQL content '.$prefix.' '.$index,
                'category_id' => $category->id,
                'author_id' => $author->id,
                'task_id' => $task->id,
                'status' => 'private',
                'review_status' => 'approved',
            ]);
            $requests[] = HostedSiteAllocationRequest::query()->create([
                'article_id' => $article->id,
                'task_id' => $task->id,
                'hosted_site_profile_id' => $profile->id,
                'status' => HostedSiteAllocationRequest::STATUS_PENDING,
                'next_attempt_at' => now(),
            ]);
        }

        return $requests;
    }
}
