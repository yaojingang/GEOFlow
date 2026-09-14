<?php

namespace Tests\PostgreSQL;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Task;
use App\Services\GeoFlow\ArticleGeoFlowService;
use App\Services\GeoFlow\TaskLifecycleService;
use App\Services\Site\UrlChangeGuard;
use App\Services\Site\UrlChangeService;
use App\Support\Site\ArticlePermalinkPolicy;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;

class UrlChangeWriteOrderingTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    #[DataProvider('renamedCategoryCases')]
    public function test_api_category_move_survives_concurrent_category_url_confirmation(bool $renameDestination): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = Admin::query()->create(['username' => 'rename-super', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $editor = Admin::query()->create(['username' => 'move-editor', 'password' => 'password', 'role' => 'admin', 'status' => 'active']);
        $category = Category::query()->create(['name' => 'First', 'slug' => 'first']);
        $other = Category::query()->create(['name' => 'Second', 'slug' => 'second']);
        $author = Author::query()->create(['name' => 'Editor']);
        $article = Article::query()->create(['title' => 'Article', 'slug' => 'article-example', 'content' => 'Content', 'category_id' => $category->id, 'author_id' => $author->id]);
        $service = app(UrlChangeService::class);
        $change = $service->start($admin, 'category', $renameDestination ? $other->id : $category->id, 'renamed');
        for ($step = 0; $step < 30 && $change->refresh()->status === 'checking'; $step++) {
            $service->checkSegment($change);
        }
        $this->assertSame('ready', $change->refresh()->status);
        $credential = $service->credential($change);

        $this->assertConcurrentArticleMoveSucceeds(
            $article,
            $editor,
            ['category_id' => $other->id],
            fn () => $service->confirm($admin, $change, $credential, $service->phrase($change)),
        );

        $this->assertSame($other->id, $article->refresh()->category_id);
    }

    public function test_api_category_and_task_move_survives_target_task_publish_scope_update(): void
    {
        Queue::fake();
        $admin = Admin::query()->create(['username' => 'task-move-super', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $category = Category::query()->create(['name' => 'First', 'slug' => 'first']);
        $other = Category::query()->create(['name' => 'Second', 'slug' => 'second']);
        $author = Author::query()->create(['name' => 'Editor']);
        $article = Article::query()->create(['title' => 'Article', 'slug' => 'article-example', 'content' => 'Content', 'category_id' => $category->id, 'author_id' => $author->id]);
        $modelId = DB::table('ai_models')->insertGetId(['name' => 'Task move model', 'api_key' => 'test-key', 'model_id' => 'test-model', 'created_at' => now(), 'updated_at' => now()]);
        $promptId = DB::table('prompts')->insertGetId(['name' => 'Task move prompt', 'type' => 'content', 'content' => 'Write content.', 'created_at' => now(), 'updated_at' => now()]);
        $libraryId = DB::table('title_libraries')->insertGetId(['name' => 'Task move titles', 'created_at' => now(), 'updated_at' => now()]);
        $task = Task::query()->create(['name' => 'Empty target task', 'title_library_id' => $libraryId, 'prompt_id' => $promptId, 'ai_model_id' => $modelId, 'status' => 'paused', 'publish_scope' => 'local_and_distribution', 'ai_quality_enabled' => false]);

        $this->assertConcurrentArticleMoveSucceeds(
            $article,
            $admin,
            ['category_id' => $other->id, 'task_id' => $task->id],
            fn () => app(TaskLifecycleService::class)->updateTask($task->id, ['publish_scope' => 'local_only'], true, $admin->id),
            [0],
        );

        $this->assertSame($other->id, $article->refresh()->category_id);
        $this->assertSame($task->id, $article->task_id);
        $this->assertSame('local_only', $task->refresh()->publish_scope);
    }

    /** @param array<string,int> $values @param list<int> $allowedCompetingOutcomes */
    private function assertConcurrentArticleMoveSucceeds(Article $article, Admin $editor, array $values, \Closure $competingWrite, array $allowedCompetingOutcomes = [0, 10]): void
    {
        $moveSockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $renameSockets = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($moveSockets);
        $this->assertIsArray($renameSockets);
        foreach ([...$moveSockets, ...$renameSockets] as $socket) {
            stream_set_timeout($socket, 10);
        }
        DB::disconnect('pgsql');
        $mover = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $mover);
        if ($mover === 0) {
            fclose($moveSockets[0]);
            DB::purge('pgsql');
            try {
                DB::statement("SET deadlock_timeout = '100ms'");
                DB::statement("SET lock_timeout = '8s'");
                $backend = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
                $paused = false;
                DB::listen(function (QueryExecuted $query) use ($moveSockets, $backend, &$paused): void {
                    if (! $paused && str_contains($query->sql, 'url_change_scope_states') && str_contains($query->sql, 'for update')) {
                        $paused = true;
                        fwrite($moveSockets[1], $backend."\n");
                        if (trim((string) fgets($moveSockets[1])) !== 'continue') {
                            throw new \RuntimeException('The category move did not receive its continuation signal.');
                        }
                    }
                });
                app(ArticleGeoFlowService::class)->updateArticle($article->id, $values, $editor->id);
                fwrite($moveSockets[1], "ok\n");
                exit(0);
            } catch (\Throwable $exception) {
                fwrite($moveSockets[1], get_class($exception).': '.$exception->getMessage()."\n");
                exit(20);
            }
        }
        fclose($moveSockets[1]);
        $moverBackend = (int) fgets($moveSockets[0]);
        $renamer = pcntl_fork();
        $this->assertGreaterThanOrEqual(0, $renamer);
        if ($renamer === 0) {
            fclose($renameSockets[0]);
            DB::purge('pgsql');
            try {
                DB::statement("SET deadlock_timeout = '10s'");
                DB::statement("SET lock_timeout = '8s'");
                fwrite($renameSockets[1], DB::selectOne('select pg_backend_pid() as pid')->pid."\n");
                $competingWrite();
                exit(0);
            } catch (ValidationException) {
                exit(10);
            } catch (\Throwable $exception) {
                fwrite($renameSockets[1], get_class($exception).': '.$exception->getMessage()."\n");
                exit(20);
            }
        }
        fclose($renameSockets[1]);
        $renamerBackend = (int) fgets($renameSockets[0]);
        DB::purge('pgsql');
        $blocked = false;
        try {
            $deadline = microtime(true) + 5;
            do {
                $blocked = (bool) DB::selectOne('select ? = any(pg_blocking_pids(?)) as blocked', [$moverBackend, $renamerBackend])->blocked;
                if ($blocked) {
                    break;
                }
                usleep(1000);
            } while (microtime(true) < $deadline);
        } finally {
            fwrite($moveSockets[0], "continue\n");
            pcntl_waitpid($mover, $moverStatus);
            pcntl_waitpid($renamer, $renamerStatus);
        }
        $moveResult = stream_get_contents($moveSockets[0]);
        $renameResult = stream_get_contents($renameSockets[0]);
        fclose($moveSockets[0]);
        fclose($renameSockets[0]);
        DB::purge('pgsql');

        $this->assertTrue($blocked, 'The competing write must overlap the pending article category move.');
        $this->assertSame(0, pcntl_wexitstatus($moverStatus), $moveResult);
        $this->assertContains(pcntl_wexitstatus($renamerStatus), $allowedCompetingOutcomes, $renameResult);
    }

    public static function renamedCategoryCases(): array
    {
        return [
            'source category is renamed' => [false],
            'destination category is renamed' => [true],
        ];
    }

    public function test_an_allowed_category_move_cannot_cross_a_concurrent_rule_confirmation(): void
    {
        Queue::fake();
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $admin = Admin::query()->create(['username' => 'ordering-super', 'password' => 'password', 'role' => 'super_admin', 'status' => 'active']);
        $editor = Admin::query()->create(['username' => 'ordering-editor', 'password' => 'password', 'role' => 'admin', 'status' => 'active']);
        $category = Category::query()->create(['name' => 'First', 'slug' => 'first']);
        $other = Category::query()->create(['name' => 'Second', 'slug' => 'second']);
        $author = Author::query()->create(['name' => 'Editor']);
        $article = Article::query()->create(['title' => 'Article', 'slug' => 'article-example', 'content' => 'Content', 'category_id' => $category->id, 'author_id' => $author->id]);
        $service = app(UrlChangeService::class);
        $change = $service->start($admin, 'primary', null, '/{category}/{slug}');
        for ($step = 0; $step < 30 && $change->refresh()->status === 'checking'; $step++) {
            $service->checkSegment($change);
        }
        $this->assertSame('ready', $change->refresh()->status);
        $credential = $service->credential($change);
        $barrier = tempnam(sys_get_temp_dir(), 'url-write-order-');
        $children = [];
        DB::disconnect('pgsql');
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            $this->assertGreaterThanOrEqual(0, $pid);
            if ($pid === 0) {
                DB::purge('pgsql');
                try {
                    DB::statement("SET lock_timeout = '3s'");
                    if ($worker === 0) {
                        DB::transaction(function () use ($article, $other, $editor, $barrier): void {
                            $locked = Article::query()->whereKey($article->id)->lockForUpdate()->firstOrFail();
                            app(UrlChangeGuard::class)->article($locked, ['category_id' => $other->id], $editor);
                            file_put_contents($barrier, 'guard-complete');
                            usleep(400000);
                            $locked->update(['category_id' => $other->id]);
                        });
                        exit(0);
                    }
                    $deadline = microtime(true) + 5;
                    while (file_get_contents($barrier) !== 'guard-complete') {
                        if (microtime(true) > $deadline) {
                            throw new \RuntimeException('Category update did not reach its permission check.');
                        }
                        usleep(1000);
                    }
                    $service->confirm($admin, $change, $credential, $service->phrase($change));
                    exit(0);
                } catch (ValidationException) {
                    exit(10);
                } catch (\Throwable $exception) {
                    fwrite(STDERR, $exception->getMessage());
                    exit(20);
                }
            }
            $children[] = $pid;
        }
        $outcomes = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $outcomes[] = pcntl_wexitstatus($status);
        }
        unlink($barrier);
        DB::purge('pgsql');
        $this->assertSame([0, 10], $outcomes);
        $this->assertSame($other->id, $article->refresh()->category_id);
        $this->assertDatabaseMissing('site_settings', ['setting_key' => ArticlePermalinkPolicy::SETTING_KEY]);
    }
}
