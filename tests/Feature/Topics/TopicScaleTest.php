<?php

namespace Tests\Feature\Topics;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\Topics\TopicReadModel;
use App\Services\Topics\TopicService;
use App\Services\Topics\TopicSitemapManifest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

class TopicScaleTest extends TestCase
{
    use RefreshDatabase;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_five_thousand_topics_use_bounded_source_queries_and_lean_list_cards(): void
    {
        $author = Author::query()->create(['name' => 'Scale author']);
        $category = Category::query()->create(['name' => 'Scale category', 'slug' => 'topic-scale']);
        $ids = [];
        foreach ([1, 2] as $n) {
            $ids[] = Article::query()->create(['title' => 'Source '.$n, 'slug' => 'scale-source-'.$n, 'content' => 'Independent body '.$n, 'excerpt' => 'Brief source '.$n, 'author_id' => $author->id, 'category_id' => $category->id, 'status' => 'published', 'review_status' => 'approved', 'published_at' => now()])->id;
        }
        $service = app(TopicService::class);
        $base = $service->create('primary', ['title' => 'Scale base', 'intro' => 'Two independent sources support this collection.', 'articles' => array_map(fn ($id) => ['article_id' => $id], $ids)]);
        $revision = $service->publish($base, 1);
        $stamp = now()->toDateTimeString();
        DB::transaction(function () use ($base, $revision, $stamp): void {
            foreach (array_chunk(range(1, 4999), 250) as $numbers) {
                DB::table('topics')->insert(array_map(fn ($n) => ['site_key' => 'primary', 'slug' => 'scale-topic-'.$n, 'title' => 'Scale topic '.$n, 'normalized_title_key' => hash('sha256', 'Scale topic '.$n), 'draft_payload' => json_encode($base->draft_payload), 'draft_source_hashes' => json_encode($base->draft_source_hashes), 'draft_version' => 1, 'created_at' => $stamp, 'updated_at' => $stamp, 'published_at' => $stamp, 'first_published_at' => $stamp], $numbers));
            }
            foreach (DB::table('topics')->where('id', '!=', $base->id)->orderBy('id')->get()->chunk(250) as $topics) {
                DB::table('topic_revisions')->insert($topics->map(function ($topic) use ($revision, $stamp): array {
                    $payload = $revision->payload;
                    $payload['title'] = $topic->title;

                    return ['topic_id' => $topic->id, 'number' => 1, 'draft_version' => 1, 'payload' => json_encode($payload), 'created_at' => $stamp];
                })->all());
            }
            $sourceRows = $revision->articles()->get();
            foreach (DB::table('topic_revisions')->where('id', '!=', $revision->id)->get()->chunk(250) as $revisions) {
                $rows = [];
                foreach ($revisions as $copy) {
                    foreach ($sourceRows as $source) {
                        $rows[] = ['topic_revision_id' => $copy->id, 'article_id' => $source->article_id, 'sort_order' => $source->sort_order, 'group' => '', 'reason' => '', 'content_hash' => $source->content_hash, 'snapshot' => json_encode($source->snapshot)];
                    }
                }
                DB::table('topic_revision_articles')->insert($rows);
            }
            DB::statement('UPDATE topics SET public_revision_id = (SELECT id FROM topic_revisions WHERE topic_id = topics.id AND number = 1)');
        });
        $read = app(TopicReadModel::class);
        $read->reset();
        DB::flushQueryLog();
        DB::enableQueryLog();
        memory_reset_peak_usage();
        $started = microtime(true);
        $items = $read->all('primary');
        $elapsed = microtime(true) - $started;
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();
        $this->assertCount(5000, $items);
        $this->assertArrayNotHasKey('articles', $items->first());
        $this->assertArrayNotHasKey('freshness_snapshot_json', $items->first());
        $this->assertLessThan(800, $queryCount, 'Source reads must scale by batch rather than by topic.');
        $this->assertLessThan(20, $elapsed, 'Local scale fixture exceeded its read budget.');
        $this->assertLessThan(256 * 1024 * 1024, memory_get_peak_usage(true));
        config(['geoflow.hosted_sites.sitemap_url_limit' => 1000]);
        $steps = 0;
        while (! app(TopicSitemapManifest::class)->buildStep('primary')) {
            $this->assertLessThan(12, ++$steps);
        }
        $manifest = app(TopicSitemapManifest::class)->current('primary');
        $this->assertSame(5000, $manifest['count']);
        $this->assertSame(6, $manifest['pages']);
        file_put_contents(sys_get_temp_dir().'/topic-scale-evidence.json', json_encode(['topics' => count($items), 'queries' => $queryCount, 'seconds' => round($elapsed, 3), 'peak_bytes' => memory_get_peak_usage(true), 'manifest_steps' => $steps + 1], JSON_THROW_ON_ERROR));
    }
}
