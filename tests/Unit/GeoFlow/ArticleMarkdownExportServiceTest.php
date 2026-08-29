<?php

namespace Tests\Unit\GeoFlow;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\GeoFlow\ArticleMarkdownExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;
use ZipArchive;

class ArticleMarkdownExportServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $exportRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->exportRoot = storage_path('framework/testing/article-exports-'.Str::uuid());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->exportRoot);

        parent::tearDown();
    }

    public function test_prepares_one_markdown_file_per_article_in_request_order(): void
    {
        [$category, $author] = $this->articleRelations();
        $first = $this->article($category, $author, [
            'title' => '第一篇：GEO / 内容',
            'slug' => 'first-geo-article',
            'content' => "第一段。\r\n\r\n## 小节\r后续内容。",
            'keywords' => 'GEO,结构化数据',
            'is_ai_generated' => 1,
        ]);
        $second = $this->article($category, $author, [
            'title' => '第二篇文章',
            'slug' => 'second-article',
            'content' => '第二篇正文。',
        ]);

        $result = $this->service()->prepare(7, [$second->id, $first->id]);

        $this->assertSame(2, $result['count']);
        $this->assertMatchesRegularExpression('/\Ageoflow-articles-\d{8}-\d{6}\.zip\z/', $result['filename']);
        $this->assertFileExists($result['path']);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9]{40}\z/', $result['token']);

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['path']));
        $this->assertSame(2, $zip->numFiles);
        $this->assertSame(sprintf('001-%d-第二篇文章.md', $second->id), $zip->getNameIndex(0));
        $this->assertSame(sprintf('002-%d-第一篇：GEO-内容.md', $first->id), $zip->getNameIndex(1));

        $markdown = $zip->getFromIndex(1);
        $zip->close();

        $this->assertIsString($markdown);
        $this->assertStringContainsString("# 第一篇：GEO / 内容\n\n第一段。\n\n## 小节\n后续内容。\n", $markdown);
        $this->assertStringNotContainsString("\r", $markdown);
        $this->assertSame([
            'id' => $first->id,
            'title' => '第一篇：GEO / 内容',
            'slug' => 'first-geo-article',
            'excerpt' => '测试摘要',
            'category' => '内容工程',
            'author' => 'GEOFlow',
            'original_keyword' => '',
            'keywords' => 'GEO,结构化数据',
            'meta_description' => '测试 SEO 描述',
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => true,
            'is_hot' => false,
            'is_featured' => false,
            'created_at' => $first->created_at->toIso8601String(),
            'updated_at' => $first->updated_at->toIso8601String(),
            'published_at' => null,
        ], $this->frontMatter($markdown));
        $this->assertDirectoryDoesNotExist(dirname($result['path']).'/.'.$result['token'].'.building');
    }

    public function test_allows_the_exact_markdown_byte_budget_and_cleans_up_an_export_one_byte_over(): void
    {
        [$category, $author] = $this->articleRelations();
        $article = $this->article($category, $author, [
            'content' => str_repeat('正文', 1_000),
        ]);

        $measurement = $this->service()->prepare(7, [$article->id]);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($measurement['path']));
        $markdown = $zip->getFromIndex(0);
        $zip->close();
        $this->assertIsString($markdown);
        File::delete($measurement['path']);

        $exact = $this->service(maxBytes: strlen($markdown))->prepare(7, [$article->id]);
        $this->assertFileExists($exact['path']);
        File::delete($exact['path']);

        try {
            $this->service(maxBytes: strlen($markdown) - 1)->prepare(7, [$article->id]);
            $this->fail('Expected the export byte budget to reject the request.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('admin.articles.export.errors.too_large'),
                $exception->errors()['article_ids'][0] ?? null,
            );
        }

        $this->assertSame([], File::allFiles($this->exportRoot));
    }

    public function test_long_multibyte_titles_use_portable_archive_entry_names(): void
    {
        [$category, $author] = $this->articleRelations();
        $article = $this->article($category, $author, [
            'title' => str_repeat('😀', 80),
            'content' => '长标题文章正文',
        ]);

        $result = $this->service()->prepare(7, [$article->id]);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($result['path']));
        $entryName = $zip->getNameIndex(0);
        $markdown = $zip->getFromIndex(0);
        $zip->close();

        $this->assertIsString($entryName);
        $this->assertLessThanOrEqual(240, strlen($entryName));
        $this->assertTrue(mb_check_encoding($entryName, 'UTF-8'));
        $this->assertStringEndsWith('.md', $entryName);
        $this->assertIsString($markdown);
        $this->assertStringContainsString('长标题文章正文', $markdown);
    }

    public function test_rejects_an_export_when_an_earlier_chunk_is_deleted_during_the_build(): void
    {
        [$category, $author] = $this->articleRelations();
        $articles = collect(range(1, 26))->map(fn (int $number): Article => $this->article(
            $category,
            $author,
            [
                'title' => '并发删除 '.$number,
                'slug' => 'concurrent-delete-'.$number.'-'.Str::lower(Str::random(6)),
            ],
        ));
        $first = $articles->first();
        $last = $articles->last();
        $this->assertInstanceOf(Article::class, $first);
        $this->assertInstanceOf(Article::class, $last);
        $lastId = (int) $last->id;

        Event::listen('eloquent.retrieved: '.Article::class, function (Article $article) use ($first, $lastId): void {
            if ((int) $article->id === $lastId && ! $first->trashed()) {
                $first->delete();
            }
        });

        try {
            $this->service()->prepare(7, $articles->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $this->fail('Expected a deleted article to invalidate the completed archive.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('admin.articles.export.errors.invalid_selection'),
                $exception->errors()['article_ids'][0] ?? null,
            );
        }

        $this->assertSame([], File::allFiles($this->exportRoot));
    }

    public function test_limits_the_number_of_retained_archives_for_each_admin(): void
    {
        [$category, $author] = $this->articleRelations();
        $article = $this->article($category, $author);
        $service = $this->service(maxRetainedExports: 1);
        $first = $service->prepare(7, [$article->id]);

        try {
            $service->prepare(7, [$article->id]);
            $this->fail('Expected the retained archive quota to reject a second export.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('admin.articles.export.errors.capacity_reached'),
                $exception->errors()['article_ids'][0] ?? null,
            );
        }

        $this->assertFileExists($first['path']);
        $this->assertCount(1, File::files(dirname($first['path'])));
    }

    public function test_download_resolution_is_admin_scoped_and_pruning_stays_inside_managed_artifacts(): void
    {
        [$category, $author] = $this->articleRelations();
        $article = $this->article($category, $author);
        $service = $this->service();
        $result = $service->prepare(7, [$article->id]);

        $this->assertSame(realpath($result['path']), $service->resolveDownload(7, $result['token']));
        $this->assertNull($service->resolveDownload(8, $result['token']));
        $this->assertNull($service->resolveDownload(7, '../'.$result['token']));

        $adminDirectory = dirname($result['path']);
        $oldToken = str_repeat('A', 40);
        $oldZip = $adminDirectory.'/'.$oldToken.'.zip';
        File::put($oldZip, 'old zip');
        touch($oldZip, now()->subHours(2)->getTimestamp());
        $buildingDirectory = $adminDirectory.'/.'.str_repeat('B', 40).'.building';
        File::makeDirectory($buildingDirectory, 0700);
        File::put($buildingDirectory.'/source.md', 'temporary');
        touch($buildingDirectory, now()->subHours(2)->getTimestamp());
        $unmanaged = $adminDirectory.'/keep.txt';
        File::put($unmanaged, 'keep');
        touch($unmanaged, now()->subHours(2)->getTimestamp());

        $this->assertSame(2, $service->pruneExpired());
        $this->assertFileDoesNotExist($oldZip);
        $this->assertDirectoryDoesNotExist($buildingDirectory);
        $this->assertFileExists($unmanaged);
        $this->assertFileExists($result['path']);
    }

    public function test_concurrent_pruners_do_not_scan_the_export_tree_together(): void
    {
        $adminDirectory = $this->exportRoot.'/7';
        File::makeDirectory($adminDirectory, 0700, true);
        $expiredPath = $adminDirectory.'/'.str_repeat('C', 40).'.zip';
        File::put($expiredPath, 'expired');
        touch($expiredPath, now()->subHours(2)->getTimestamp());
        $lock = Cache::lock('geoflow:article-markdown-export:prune', 300);
        $this->assertTrue($lock->get());

        try {
            $this->assertSame(0, $this->service()->pruneExpired());
            $this->assertFileExists($expiredPath);
        } finally {
            $lock->release();
        }

        $this->assertSame(1, $this->service()->pruneExpired());
        $this->assertFileDoesNotExist($expiredPath);
    }

    private function service(
        int $maxBytes = ArticleMarkdownExportService::MAX_UNCOMPRESSED_BYTES,
        int $maxRetainedExports = ArticleMarkdownExportService::MAX_RETAINED_EXPORTS_PER_ADMIN,
    ): ArticleMarkdownExportService {
        return new ArticleMarkdownExportService(
            exportRoot: $this->exportRoot,
            maxUncompressedBytes: $maxBytes,
            maxRetainedExportsPerAdmin: $maxRetainedExports,
        );
    }

    /** @return array{Category, Author} */
    private function articleRelations(): array
    {
        return [
            Category::query()->create(['name' => '内容工程', 'slug' => 'content-engineering']),
            Author::query()->create(['name' => 'GEOFlow']),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function article(Category $category, Author $author, array $overrides = []): Article
    {
        return Article::query()->create(array_merge([
            'title' => '测试文章',
            'slug' => 'test-article-'.Str::lower(Str::random(8)),
            'excerpt' => '测试摘要',
            'content' => '测试正文',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'keywords' => '',
            'meta_description' => '测试 SEO 描述',
            'status' => 'draft',
            'review_status' => 'pending',
            'is_ai_generated' => 0,
            'is_hot' => false,
            'is_featured' => false,
        ], $overrides));
    }

    /** @return array<string, mixed> */
    private function frontMatter(string $markdown): array
    {
        $matched = preg_match('/\A---\n(?<yaml>.*?)\n---\n/s', $markdown, $matches);
        $this->assertSame(1, $matched);

        return Yaml::parse($matches['yaml']);
    }
}
