<?php

namespace Tests\PostgreSQL;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\Topic;
use App\Models\TopicPath;
use App\Services\Topics\TopicPathService;
use App\Services\Topics\TopicService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TopicConcurrencyTest extends PostgreSqlTestCase
{
    use DatabaseMigrations;

    public function test_concurrent_same_title_creation_has_one_topic_and_a_clear_conflict(): void
    {
        $outcomes = $this->race([
            static fn () => app(TopicService::class)->create('primary', ['title' => 'Concurrent topic']),
            static fn () => app(TopicService::class)->create('primary', ['title' => 'Concurrent topic']),
        ]);
        sort($outcomes);
        $this->assertSame(['conflict', 'ok'], $outcomes);
        $this->assertSame(1, Topic::query()->count());
    }

    public function test_concurrent_same_title_with_different_declared_scopes_creates_two_topics(): void
    {
        $outcomes = $this->race([
            static fn () => app(TopicService::class)->create('primary', ['title' => 'Scoped concurrent topic', 'summary' => ['scope' => 'First declared audience']]),
            static fn () => app(TopicService::class)->create('primary', ['title' => 'Scoped concurrent topic', 'summary' => ['scope' => 'Second declared audience']]),
        ]);
        $this->assertSame(['ok', 'ok'], $outcomes);
        $this->assertSame(2, Topic::query()->count());
        $this->assertSame(2, Topic::query()->distinct()->count('normalized_title_key'));
    }

    public function test_concurrent_edit_preserves_one_version_and_reports_the_other_conflict(): void
    {
        $topic = app(TopicService::class)->create('primary', ['title' => 'Version topic']);
        $outcomes = $this->race([
            static fn () => app(TopicService::class)->save($topic, ['intro' => 'First edit'], 1),
            static fn () => app(TopicService::class)->save($topic, ['intro' => 'Second edit'], 1),
        ]);
        sort($outcomes);
        $this->assertSame(['conflict', 'ok'], $outcomes);
        $this->assertSame(2, $topic->fresh()->draft_version);
        $this->assertContains($topic->fresh()->draft_payload['intro'], ['First edit', 'Second edit']);
    }

    public function test_duplicate_publication_request_creates_one_immutable_revision(): void
    {
        $topic = $this->completeTopic();
        $outcomes = $this->race([
            static fn () => app(TopicService::class)->publish($topic, 1, requestId: 'same-publication'),
            static fn () => app(TopicService::class)->publish($topic, 1, requestId: 'same-publication'),
        ]);
        $this->assertSame(['ok', 'ok'], $outcomes);
        $this->assertSame(1, $topic->revisions()->count());
        $this->assertNotNull($topic->fresh()->public_revision_id);
    }

    public function test_source_withdrawal_racing_publication_cannot_leave_a_public_topic(): void
    {
        $topic = $this->completeTopic();
        $id = $topic->draft_payload['articles'][0]['article_id'];
        $outcomes = $this->race([
            static fn () => app(TopicService::class)->publish($topic, 1),
            static fn () => DB::transaction(fn () => Article::query()->findOrFail($id)->update(['status' => 'private']), 3),
        ]);
        $this->assertContains($outcomes[0], ['ok', 'conflict']);
        $this->assertSame('ok', $outcomes[1]);
        $this->assertNull(app(TopicService::class)->publicView($topic->fresh()));
    }

    public function test_parallel_path_previews_cannot_overwrite_the_winning_change(): void
    {
        $topic = $this->completeTopic();
        app(TopicService::class)->publish($topic, 1);
        $paths = app(TopicPathService::class);
        $a = $paths->preview($topic->fresh(), 'path-first', 1);
        $b = $paths->preview($topic->fresh(), 'path-second', 1);
        $outcomes = $this->race([static fn () => app(TopicPathService::class)->confirm($topic, $a['token'], 1), static fn () => app(TopicPathService::class)->confirm($topic, $b['token'], 1)]);
        sort($outcomes);
        $this->assertSame(['conflict', 'ok'], $outcomes);
        $this->assertSame(2, $topic->fresh()->path_generation);
        $this->assertSame(2, TopicPath::query()->where('topic_id', $topic->id)->count());
    }

    public function test_parallel_topics_cannot_claim_the_same_new_path(): void
    {
        $first = app(TopicService::class)->create('primary', ['title' => 'First path owner']);
        $second = app(TopicService::class)->create('primary', ['title' => 'Second path owner']);
        $paths = app(TopicPathService::class);
        $a = $paths->preview($first, 'shared-new-path', 1);
        $b = $paths->preview($second, 'shared-new-path', 1);
        $outcomes = $this->race([static fn () => app(TopicPathService::class)->confirm($first, $a['token'], 1), static fn () => app(TopicPathService::class)->confirm($second, $b['token'], 1)]);
        sort($outcomes);
        $this->assertSame(['conflict', 'ok'], $outcomes);
        $this->assertSame(1, TopicPath::query()->where('slug', 'shared-new-path')->count());
    }

    protected function tearDown(): void
    {
        if ($this->app !== null && Schema::hasTable('topics')) {
            DB::table('topic_build_runs')->delete();
            DB::table('topic_import_batches')->delete();
            DB::table('topic_paths')->delete();
            DB::table('topics')->delete();
        }
        parent::tearDown();
    }

    private function completeTopic(): Topic
    {
        $author = Author::query()->create(['name' => 'Topic PostgreSQL author']);
        $category = Category::query()->create(['name' => 'Topic category', 'slug' => 'topic-pg']);
        $ids = [];
        foreach ([1, 2] as $number) {
            $ids[] = Article::query()->create(['title' => 'Topic source '.$number, 'slug' => 'topic-source-'.$number, 'content' => 'Independent source '.$number, 'author_id' => $author->id, 'category_id' => $category->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()])->id;
        }

        return app(TopicService::class)->create('primary', ['title' => 'Complete concurrent topic', 'intro' => 'A sourced introduction', 'articles' => array_map(fn ($id) => ['article_id' => $id], $ids)]);
    }

    /** @param list<\Closure> $actions @return list<string> */
    private function race(array $actions): array
    {
        $directory = sys_get_temp_dir().'/topic-race-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $children = [];
        DB::disconnect('pgsql');
        try {
            foreach ($actions as $index => $action) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new RuntimeException('Cannot fork test worker.');
                }
                if ($pid === 0) {
                    try {
                        DB::purge('pgsql');
                        DB::reconnect('pgsql');
                        DB::statement("SET lock_timeout = '5s'");
                        DB::statement("SET statement_timeout = '10s'");
                        file_put_contents($directory.'/ready-'.$index, '1');
                        $deadline = microtime(true) + 10;
                        while (! is_file($directory.'/ready-'.(1 - $index))) {
                            if (microtime(true) > $deadline) {
                                throw new RuntimeException('Race barrier timed out.');
                            }
                            usleep(1000);
                        }
                        try {
                            $action();
                            $result = 'ok';
                        } catch (ValidationException) {
                            $result = 'conflict';
                        }
                        file_put_contents($directory.'/result-'.$index, $result);
                        DB::disconnect('pgsql');
                        exit(0);
                    } catch (Throwable $exception) {
                        file_put_contents($directory.'/result-'.$index, $exception::class.': '.$exception->getMessage());
                        exit(1);
                    }
                }
                $children[] = $pid;
            }
            $outcomes = [];
            foreach ($children as $index => $pid) {
                pcntl_waitpid($pid, $status);
                $result = file_get_contents($directory.'/result-'.$index);
                $this->assertSame(0, pcntl_wexitstatus($status), $result);
                $outcomes[] = $result;
            }
            DB::purge('pgsql');
            DB::reconnect('pgsql');

            return $outcomes;
        } finally {
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status, WNOHANG);
            }
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
            DB::purge('pgsql');
            DB::reconnect('pgsql');
        }
    }
}
