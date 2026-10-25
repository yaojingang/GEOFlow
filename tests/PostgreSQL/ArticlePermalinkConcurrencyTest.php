<?php

namespace Tests\PostgreSQL;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\HostedSiteProfile;
use App\Models\SiteSetting;
use App\Services\GeoFlow\ArticleSlugRegistry;
use App\Services\Site\UrlChangeService;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ArticlePermalinkConcurrencyTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_only_one_primary_policy_activation_can_commit_the_same_revision(): void
    {
        $results = $this->runConcurrent([
            ['action' => 'activate', 'pattern' => '/{slug}.html'],
            ['action' => 'activate', 'pattern' => '/article/{id}.html'],
        ]);

        $this->assertSame([0, 0], array_column($results, 'exit'), json_encode($results));
        $outcomes = array_column($results, 'result');
        sort($outcomes);
        $this->assertSame(['activated', 'conflict'], $outcomes);

        DB::purge('pgsql');
        DB::reconnect('pgsql');
        $policy = ArticlePermalinkPolicy::fromRaw(SiteSetting::query()
            ->where('setting_key', ArticlePermalinkPolicy::SETTING_KEY)
            ->value('setting_value'));
        $this->assertSame(1, $policy->revision);
        $this->assertContains($policy->currentPattern, ['/{slug}.html', '/article/{id}.html']);
    }

    public function test_concurrent_slug_changes_keep_one_global_owner(): void
    {
        $category = Category::query()->create(['name' => 'Permalink concurrency', 'slug' => 'permalink-concurrency']);
        $author = Author::query()->create(['name' => 'Permalink concurrency author']);
        $articles = collect(['first-owner', 'second-owner'])->map(fn (string $slug): Article => Article::query()->create([
            'title' => $slug,
            'slug' => $slug,
            'content' => 'Concurrent slug body.',
            'category_id' => $category->id,
            'author_id' => $author->id,
        ]));

        $results = $this->runConcurrent($articles
            ->map(fn (Article $article): array => ['action' => 'slug', 'article_id' => (int) $article->id])
            ->all());

        $this->assertSame([0, 0], array_column($results, 'exit'), json_encode($results));
        $outcomes = array_column($results, 'result');
        sort($outcomes);
        $this->assertSame(['changed', 'conflict'], $outcomes);

        DB::purge('pgsql');
        DB::reconnect('pgsql');
        $this->assertSame(1, Article::query()->where('slug', 'shared-permalink-slug')->count());
        $this->assertSame(1, DB::table('article_slug_histories')->count());
    }

    public function test_only_one_hosted_policy_activation_can_commit_the_same_revision(): void
    {
        $channel = DistributionChannel::query()->create([
            'name' => 'Permalink concurrency hosted site',
            'domain' => 'permalink-concurrency.sites.test',
            'endpoint_url' => 'https://permalink-concurrency.sites.test',
            'channel_type' => DistributionChannel::TYPE_HOSTED_SITE,
            'status' => DistributionChannel::STATUS_ACTIVE,
        ]);
        $profile = HostedSiteProfile::query()->create([
            'distribution_channel_id' => $channel->id,
            'hostname' => 'permalink-concurrency.sites.test',
            'root_domain' => 'sites.test',
            'topic' => 'Permalink concurrency',
            'serving_status' => HostedSiteProfile::SERVING_ONLINE,
        ]);

        $results = $this->runConcurrent([
            ['action' => 'hosted_activate', 'channel_id' => (int) $channel->id, 'pattern' => '/{slug}.html'],
            ['action' => 'hosted_activate', 'channel_id' => (int) $channel->id, 'pattern' => '/article/{id}.html'],
        ]);

        $this->assertSame([0, 0], array_column($results, 'exit'), json_encode($results));
        $outcomes = array_column($results, 'result');
        sort($outcomes);
        $this->assertSame(['activated', 'conflict'], $outcomes);

        DB::purge('pgsql');
        DB::reconnect('pgsql');
        $channel = DistributionChannel::query()->findOrFail($channel->id);
        $policy = ArticlePermalinkPolicy::fromRaw(
            data_get($channel->site_settings, ArticlePermalinkPolicy::SETTING_KEY),
        );
        $this->assertSame(1, $policy->revision);
        $this->assertSame(
            (int) $profile->settings_version + 1,
            (int) $channel->hostedSiteProfile()->value('settings_version'),
        );
    }

    /**
     * @param  list<array{action:string,pattern?:string,article_id?:int,channel_id?:int}>  $actions
     * @return list<array{exit:int,result:string}>
     */
    private function runConcurrent(array $actions): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('The pcntl extension is required.');
        }

        $startAt = microtime(true) + 0.35;
        Queue::fake();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = Admin::query()->create(['username' => 'permalink-concurrent', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $children = [];
        foreach ($actions as $index => $action) {
            $resultPath = tempnam(sys_get_temp_dir(), 'geoflow-permalink-concurrency-');
            $this->assertIsString($resultPath);
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('Unable to fork a permalink concurrency worker.');
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

                    try {
                        if (in_array($action['action'], ['activate', 'hosted_activate'], true)) {
                            $service = app(UrlChangeService::class);
                            $change = $service->start($admin, $action['action'] === 'activate' ? 'primary' : 'hosted', $action['channel_id'] ?? null, $action['pattern']);
                            for ($step = 0; $step < 30 && $change->refresh()->status === 'checking'; $step++) {
                                $service->checkSegment($change);
                            }
                            $service->confirm($admin, $change, $service->credential($change), $service->phrase($change));
                            file_put_contents($resultPath, 'activated');
                        } else {
                            $article = Article::query()->findOrFail((int) $action['article_id']);
                            app(ArticleSlugRegistry::class)->change($article, 'shared-permalink-slug');
                            file_put_contents($resultPath, 'changed');
                        }
                    } catch (ValidationException) {
                        file_put_contents($resultPath, 'conflict');
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
}
