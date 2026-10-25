<?php

namespace Tests\PostgreSQL;

use App\Ai\Workspace\AiPayloadDigest;
use App\Exceptions\ApiException;
use App\Models\Admin;
use App\Models\Article;
use App\Models\ArticleDistribution;
use App\Models\Author;
use App\Models\Category;
use App\Models\DistributionChannel;
use App\Models\DistributionLog;
use App\Models\ManualPublicationAccount;
use App\Models\ManualPublicationPersona;
use App\Models\Task;
use App\Services\BrowserOperations\ManualPublicationBrowserService;
use App\Services\GeoFlow\ArticlePublicationEligibilityService;
use App\Services\GeoFlow\ArticleWorkflowTransitionService;
use App\Services\GeoFlow\Distribution\PlatformWeb\PlatformWebDistributionBridge;
use App\Services\GeoFlow\Distribution\PlatformWeb\PlatformWebWorkOrderGuard;
use App\Services\GeoFlow\DistributionChannelDeletionService;
use App\Services\GeoFlow\DistributionPayloadBuilder;
use App\Services\GeoFlow\ManualPublicationService;
use App\Services\GeoFlow\TaskLifecycleService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;

class PlatformWebPublicationConcurrencyTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_claim_and_channel_deletion_serialize_and_preserve_unknown_external_outcome(): void
    {
        [$admin, $task, $channel, $publication, $tokenId, $distribution] = $this->fixtures();
        $barrier = tempnam(sys_get_temp_dir(), 'platform-claim-lock-');
        $peer = tempnam(sys_get_temp_dir(), 'platform-delete-peer-');
        try {
            $results = $this->concurrent([
                fn () => DB::transaction(function () use ($admin, $channel, $publication, $tokenId, $barrier, $peer): string {
                    DistributionChannel::whereKey($channel->id)->lockForUpdate()->firstOrFail();
                    file_put_contents($barrier, 'locked');
                    $this->waitForBlockedConnection($peer);
                    app(ManualPublicationBrowserService::class)->claim($admin, $tokenId, $publication->id, $publication->revision);

                    return 'claimed';
                }),
                function () use ($channel, $barrier, $peer): string {
                    $this->waitForBarrier($barrier);
                    file_put_contents($peer, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    app(DistributionChannelDeletionService::class)->prepare($channel);

                    return 'deleting';
                },
            ]);
            $this->assertSame(['claimed', 'deleting'], $results);
            $this->assertSame('outcome_unknown', $publication->fresh()->status);
            $this->assertSame(3, $publication->fresh()->revision);
            $this->assertNull($publication->fresh()->browser_claimed_by_token_id);
            $this->assertSame('outcome_unknown', $distribution->fresh()->status);
        } finally {
            DB::table('task_trash_entries')->where('task_id', $task->id)->delete();
            @unlink($barrier);
            @unlink($peer);
        }
    }

    public function test_task_deletion_and_claim_serialize_without_reviving_cancelled_distribution(): void
    {
        [$admin, $task, $channel, $publication, $tokenId, $distribution] = $this->fixtures();
        $barrier = tempnam(sys_get_temp_dir(), 'platform-task-lock-');
        $peer = tempnam(sys_get_temp_dir(), 'platform-claim-peer-');
        try {
            $results = $this->concurrent([
                fn () => DB::transaction(function () use ($task, $barrier, $peer): string {
                    Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
                    file_put_contents($barrier, 'locked');
                    $this->waitForBlockedConnection($peer);
                    app(TaskLifecycleService::class)->deleteTask($task->id);

                    return 'deleted';
                }),
                function () use ($admin, $publication, $tokenId, $barrier, $peer): string {
                    $this->waitForBarrier($barrier);
                    file_put_contents($peer, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    try {
                        app(ManualPublicationBrowserService::class)->claim($admin, $tokenId, $publication->id, $publication->revision);
                    } catch (ApiException) {
                        return 'claim_rejected';
                    }

                    return 'unexpected_claim';
                },
            ]);
            $this->assertSame(['deleted', 'claim_rejected'], $results);
            $this->assertTrue($task->fresh()->trashed());
            $this->assertSame('cancelled', $publication->fresh()->status);
            $this->assertSame('failed', $distribution->fresh()->status);
        } finally {
            DB::table('task_trash_entries')->where('task_id', $task->id)->delete();
            @unlink($barrier);
            @unlink($peer);
        }
    }

    public function test_bounded_receipt_revision_query_converges_once_on_postgresql(): void
    {
        [, , , $publication, , $distribution] = $this->fixtures();
        $publication->update(['status' => 'completed', 'completion_url' => 'https://mp.toutiao.com/known-result']);
        $bridge = app(PlatformWebDistributionBridge::class);
        $this->assertSame(1, $bridge->reconcile(1));
        $this->assertSame('synced', $distribution->fresh()->status);
        $this->assertSame(0, $bridge->reconcile(1));
    }

    public function test_human_hold_and_claim_share_the_source_workflow_lock(): void
    {
        [$admin, $task, , $publication, $tokenId, $distribution] = $this->fixtures();
        $barrier = tempnam(sys_get_temp_dir(), 'platform-hold-lock-');
        $peer = tempnam(sys_get_temp_dir(), 'platform-claim-peer-');
        try {
            $results = $this->concurrent([
                fn () => DB::transaction(function () use ($admin, $task, $distribution, $barrier, $peer): string {
                    Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
                    file_put_contents($barrier, 'locked');
                    $this->waitForBlockedConnection($peer);
                    app(ArticleWorkflowTransitionService::class)->humanAction(Article::findOrFail($distribution->article_id), 'hold', $admin->id);

                    return 'held';
                }),
                function () use ($admin, $publication, $tokenId, $barrier, $peer): string {
                    $this->waitForBarrier($barrier);
                    file_put_contents($peer, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    try {
                        app(ManualPublicationBrowserService::class)->claim($admin, $tokenId, $publication->id, $publication->revision);
                    } catch (ApiException $exception) {
                        return $exception->getErrorCode();
                    }

                    return 'unexpected_claim';
                },
            ]);
            $this->assertSame(['held', 'publication_source_superseded'], $results);
            $this->assertSame('ready', $publication->fresh()->status);
        } finally {
            @unlink($barrier);
            @unlink($peer);
        }
    }

    public function test_duplicate_unpublished_confirmations_serialize_one_new_revision(): void
    {
        [$admin, , $channel, $publication, $tokenId, $distribution] = $this->fixtures();
        $browser = app(ManualPublicationBrowserService::class);
        $claim = $browser->claim($admin, $tokenId, $publication->id, $publication->revision);
        $finished = $browser->recordReceipt($admin, $tokenId, $publication->id, $claim->revision, [
            'outcome' => 'failed', 'target_origin' => 'https://mp.toutiao.com',
        ], '0.1.0');
        $barrier = tempnam(sys_get_temp_dir(), 'platform-confirm-lock-');
        $peer = tempnam(sys_get_temp_dir(), 'platform-confirm-peer-');
        try {
            $results = $this->concurrent([
                fn () => DB::transaction(function () use ($admin, $channel, $finished, $barrier, $peer): string {
                    DistributionChannel::whereKey($channel->id)->lockForUpdate()->firstOrFail();
                    file_put_contents($barrier, 'locked');
                    $this->waitForBlockedConnection($peer);

                    return app(ManualPublicationBrowserService::class)->confirmUnpublishedRetry($admin, $finished->id, $finished->revision)->status;
                }),
                function () use ($admin, $finished, $barrier, $peer): string {
                    $this->waitForBarrier($barrier);
                    file_put_contents($peer, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    try {
                        app(ManualPublicationBrowserService::class)->confirmUnpublishedRetry($admin, $finished->id, $finished->revision);

                        return 'unexpectedly_repeated';
                    } catch (ApiException $exception) {
                        return $exception->getErrorCode();
                    }
                },
            ]);
            $this->assertSame(['ready', 'revision_conflict'], $results);
            $ready = $publication->fresh();
            $this->assertSame($finished->revision + 1, $ready->revision);
            $this->assertCount(1, $ready->execution_receipt['previous_attempts']);
            $this->assertSame('awaiting_extension', $distribution->fresh()->status);
            $this->assertSame('in_progress', $browser->claim($admin, $tokenId, $ready->id, $ready->revision)->status);
        } finally {
            @unlink($barrier);
            @unlink($peer);
        }
    }

    public function test_human_hold_wins_against_unpublished_confirmation_without_reviving_delivery(): void
    {
        [$admin, $task, , $publication, $tokenId, $distribution] = $this->fixtures();
        $browser = app(ManualPublicationBrowserService::class);
        $claim = $browser->claim($admin, $tokenId, $publication->id, $publication->revision);
        $finished = $browser->recordReceipt($admin, $tokenId, $publication->id, $claim->revision, [
            'outcome' => 'failed', 'target_origin' => 'https://mp.toutiao.com',
        ], '0.1.0');
        $barrier = tempnam(sys_get_temp_dir(), 'platform-confirm-hold-');
        $peer = tempnam(sys_get_temp_dir(), 'platform-confirm-wait-');
        try {
            $results = $this->concurrent([
                fn () => DB::transaction(function () use ($admin, $task, $distribution, $barrier, $peer): string {
                    Task::whereKey($task->id)->lockForUpdate()->firstOrFail();
                    file_put_contents($barrier, 'locked');
                    $this->waitForBlockedConnection($peer);
                    $article = Article::findOrFail($distribution->article_id);
                    app(ArticleWorkflowTransitionService::class)->humanAction($article, 'hold', $admin->id, expectedVersion: $article->workflow_version);

                    return 'held';
                }),
                function () use ($admin, $finished, $barrier, $peer): string {
                    $this->waitForBarrier($barrier);
                    file_put_contents($peer, (string) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid);
                    try {
                        app(ManualPublicationBrowserService::class)->confirmUnpublishedRetry($admin, $finished->id, $finished->revision);

                        return 'unexpectedly_authorized';
                    } catch (ApiException $exception) {
                        return $exception->getErrorCode();
                    }
                },
            ]);
            $this->assertSame(['held', 'publication_source_superseded'], $results);
            $this->assertSame('failed', $publication->fresh()->status);
            $this->assertSame($finished->revision, $publication->fresh()->revision);
            $this->assertSame('failed', $distribution->fresh()->status);
        } finally {
            @unlink($barrier);
            @unlink($peer);
        }
    }

    public function test_deleting_unknown_query_does_not_starve_a_newer_completion_on_postgresql(): void
    {
        [$admin, , $channel, $old, $tokenId, $oldDistribution] = $this->fixtures();
        app(ManualPublicationBrowserService::class)->claim($admin, $tokenId, $old->id, $old->revision);
        app(DistributionChannelDeletionService::class)->prepare($channel);
        $oldState = $oldDistribution->fresh()->getAttributes();
        [, , , $new, , $newDistribution] = $this->fixtures('-new');
        $new->update(['status' => 'completed', 'completion_url' => 'https://www.toutiao.com/article/123456789/']);
        $bridge = app(PlatformWebDistributionBridge::class);
        $this->assertSame(1, $bridge->reconcile(1));
        $this->assertSame('synced', $newDistribution->fresh()->status);
        $logs = DistributionLog::count();
        $this->assertSame(0, $bridge->reconcile(1));
        $this->assertSame($logs, DistributionLog::count());
        $this->assertSame($oldState, $oldDistribution->fresh()->getAttributes());
    }

    private function fixtures(string $suffix = ''): array
    {
        $admin = Admin::create(['username' => 'platform-concurrency'.$suffix, 'password' => 'test', 'role' => 'super_admin', 'status' => 'active']);
        $library = DB::table('title_libraries')->insertGetId(['name' => 'Platform titles', 'created_at' => now(), 'updated_at' => now()]);
        $prompt = DB::table('prompts')->insertGetId(['name' => 'Platform prompt', 'type' => 'content', 'content' => 'Write.', 'created_at' => now(), 'updated_at' => now()]);
        $model = DB::table('ai_models')->insertGetId(['name' => 'No outbound model', 'api_key' => 'test', 'model_id' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        $task = Task::create(['title_library_id' => $library, 'prompt_id' => $prompt, 'ai_model_id' => $model, 'name' => 'Platform task', 'status' => 'active', 'need_review' => 0, 'publish_scope' => 'distribution_only']);
        $category = Category::create(['name' => 'Platform', 'slug' => 'platform'.$suffix]);
        $author = Author::create(['name' => 'Author']);
        $article = Article::create(['title' => 'Platform verified facts', 'slug' => 'platform-facts'.$suffix, 'content' => 'Verified descriptive content.', 'task_id' => $task->id, 'category_id' => $category->id, 'author_id' => $author->id, 'status' => 'private', 'review_status' => 'approved', 'publication_intent' => 'none']);
        $persona = ManualPublicationPersona::create(['name' => 'Operator', 'is_active' => true]);
        $account = ManualPublicationAccount::create(['persona_id' => $persona->id, 'platform' => 'toutiao', 'account_name' => 'Test operator']);
        $channel = DistributionChannel::create(['name' => 'Platform channel', 'domain' => 'mp.toutiao.com', 'endpoint_url' => 'https://mp.toutiao.com', 'channel_type' => 'platform_web', 'status' => 'active', 'created_by_admin_id' => $admin->id, 'channel_config' => ['platform' => 'toutiao', 'manual_publication_account_id' => $account->id]]);
        $task->distributionChannels()->attach($channel->id);
        $distribution = ArticleDistribution::create(['article_id' => $article->id, 'distribution_channel_id' => $channel->id, 'action' => 'publish', 'status' => 'awaiting_extension', 'idempotency_key' => 'concurrent-platform'.$suffix]);
        $publication = app(ManualPublicationService::class)->create(['type' => 'post', 'platform' => 'toutiao', 'article_id' => $article->id, 'persona_id' => $persona->id, 'account_id' => $account->id, 'assigned_admin_id' => $admin->id, 'status' => 'ready', 'content' => 'Verified descriptive content.', 'target_url' => 'https://mp.toutiao.com/profile_v4/graph/articles/publish'], $admin);
        $publication->update(['source_distribution_id' => $distribution->id]);
        $payload = app(DistributionPayloadBuilder::class)->build($article);
        $article->load('task');
        $distribution->forceFill([
            'payload_hash' => AiPayloadDigest::make($payload),
            'remote_meta' => ['distribution_payload' => $payload,
                'workflow_fence' => app(ArticlePublicationEligibilityService::class)->fence($article, 'manual'),
                'platform_web_work_order_digest' => PlatformWebWorkOrderGuard::digest($publication->fresh())],
        ])->save();
        $token = $admin->createToken('concurrent-browser', ['browser-operations:read', 'browser-operations:execute']);

        return [$admin, $task, $channel, $publication, $token->accessToken->id, $distribution];
    }

    private function waitForBarrier(string $path): void
    {
        $deadline = microtime(true) + 4;
        while (file_get_contents($path) !== 'locked' && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertSame('locked', file_get_contents($path), 'The competing transaction must acquire its lock first.');
    }

    private function waitForBlockedConnection(string $path): void
    {
        $deadline = microtime(true) + 4;
        do {
            $pid = (int) file_get_contents($path);
            if ($pid > 0 && DB::selectOne('SELECT pg_backend_pid() = ANY(pg_blocking_pids(?)) AS blocked', [$pid])?->blocked === true) {
                return;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);

        $this->fail('The competing service did not wait on the real PostgreSQL row lock.');
    }

    private function concurrent(array $actions): array
    {
        $this->assertTrue(function_exists('pcntl_fork'), 'PostgreSQL acceptance requires pcntl.');
        DB::disconnect('pgsql');
        $children = [];
        foreach ($actions as $action) {
            $path = tempnam(sys_get_temp_dir(), 'workflow-child-');
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid);
            if ($pid === 0) {
                try {
                    DB::purge('pgsql');
                    DB::reconnect('pgsql');
                    DB::statement("SET lock_timeout TO '5s'");
                    file_put_contents($path, json_encode(['result' => $action()], JSON_THROW_ON_ERROR));
                    exit(0);
                } catch (\Throwable $exception) {
                    file_put_contents($path, $exception::class.': '.$exception->getMessage());
                    exit(1);
                }
            }
            $children[] = [$pid, $path];
        }
        $results = [];
        foreach ($children as [$pid, $path]) {
            pcntl_waitpid($pid, $status);
            $output = file_get_contents($path);
            unlink($path);
            $this->assertSame(0, pcntl_wexitstatus($status), $output);
            $results[] = json_decode($output, true, flags: JSON_THROW_ON_ERROR)['result'];
        }
        DB::purge('pgsql');
        DB::reconnect('pgsql');

        return $results;
    }
}
