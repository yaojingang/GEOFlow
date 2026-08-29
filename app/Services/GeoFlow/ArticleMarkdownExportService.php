<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use UnexpectedValueException;
use ZipArchive;

class ArticleMarkdownExportService
{
    public const MAX_ARTICLES = 500;

    public const MAX_UNCOMPRESSED_BYTES = 268_435_456;

    public const MAX_PREPARE_REQUEST_BYTES = 131_072;

    public const DOWNLOAD_TTL_MINUTES = 10;

    public const PRUNE_AFTER_MINUTES = 60;

    public const BUILD_LOCK_SECONDS = 900;

    public const MAX_RETAINED_EXPORTS_PER_ADMIN = 3;

    public const MAX_RETAINED_BYTES_PER_ADMIN = 536_870_912;

    public const MIN_FREE_DISK_BYTES = 536_870_912;

    private const ARTICLE_CHUNK_SIZE = 25;

    private string $exportRoot;

    private int $maxUncompressedBytes;

    private int $maxRetainedExportsPerAdmin;

    private int $maxRetainedBytesPerAdmin;

    private int $minFreeDiskBytes;

    public function __construct(
        ?string $exportRoot = null,
        ?int $maxUncompressedBytes = null,
        ?int $maxRetainedExportsPerAdmin = null,
        ?int $maxRetainedBytesPerAdmin = null,
        ?int $minFreeDiskBytes = null,
    ) {
        $this->exportRoot = rtrim($exportRoot ?? storage_path('app/tmp/article-exports'), DIRECTORY_SEPARATOR);
        $this->maxUncompressedBytes = $maxUncompressedBytes ?? self::MAX_UNCOMPRESSED_BYTES;
        $this->maxRetainedExportsPerAdmin = $maxRetainedExportsPerAdmin ?? self::MAX_RETAINED_EXPORTS_PER_ADMIN;
        $this->maxRetainedBytesPerAdmin = $maxRetainedBytesPerAdmin ?? self::MAX_RETAINED_BYTES_PER_ADMIN;
        $this->minFreeDiskBytes = $minFreeDiskBytes ?? self::MIN_FREE_DISK_BYTES;

        if ($this->exportRoot === ''
            || $this->maxUncompressedBytes < 1
            || $this->maxRetainedExportsPerAdmin < 1
            || $this->maxRetainedBytesPerAdmin < 1
            || $this->minFreeDiskBytes < 0) {
            throw new RuntimeException(__('admin.articles.export.errors.build_failed'));
        }
    }

    /**
     * @param  list<int>  $articleIds
     * @return array{token: string, path: string, filename: string, count: int}
     */
    public function prepare(int $adminId, array $articleIds): array
    {
        $articleIds = $this->validatedArticleIds($articleIds);
        if ($adminId < 1) {
            throw new RuntimeException(__('admin.articles.export.errors.build_failed'));
        }

        $this->pruneExpired();
        $this->assertArticlesAvailable($articleIds);
        $this->assertStorageCapacity($adminId, afterBuild: false);

        [$token, $adminDirectory, $buildingDirectory, $zipPath] = $this->createExportPaths($adminId);
        $zip = null;
        $zipOpened = false;

        try {
            $zip = new ZipArchive;
            $openResult = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($openResult !== true) {
                throw new RuntimeException(__('admin.articles.export.errors.build_failed'));
            }
            $zipOpened = true;

            $totalBytes = 0;
            $sequence = 0;
            foreach (array_chunk($articleIds, self::ARTICLE_CHUNK_SIZE) as $chunk) {
                $articles = Article::query()
                    ->select([
                        'id', 'title', 'slug', 'excerpt', 'content', 'category_id', 'author_id',
                        'original_keyword', 'keywords', 'meta_description', 'status', 'review_status',
                        'is_ai_generated', 'is_hot', 'is_featured', 'created_at', 'updated_at', 'published_at',
                    ])
                    ->with(['category:id,name', 'author:id,name'])
                    ->whereIn('id', $chunk)
                    ->get()
                    ->keyBy(static fn (Article $article): int => (int) $article->id);

                foreach ($chunk as $articleId) {
                    $article = $articles->get($articleId);
                    if (! $article instanceof Article) {
                        throw ValidationException::withMessages([
                            'article_ids' => __('admin.articles.export.errors.invalid_selection'),
                        ]);
                    }

                    $sequence++;
                    $entryName = $this->entryName($sequence, $article);
                    $markdown = $this->markdown($article);
                    $totalBytes += strlen($markdown);
                    if ($totalBytes > $this->maxUncompressedBytes) {
                        throw ValidationException::withMessages([
                            'article_ids' => __('admin.articles.export.errors.too_large'),
                        ]);
                    }

                    $sourcePath = $buildingDirectory.DIRECTORY_SEPARATOR.sprintf('%03d.md', $sequence);
                    $written = file_put_contents($sourcePath, $markdown, LOCK_EX);
                    if ($written !== strlen($markdown)) {
                        throw new RuntimeException(__('admin.articles.export.errors.build_failed'));
                    }
                    @chmod($sourcePath, 0600);

                    if (! $zip->addFile($sourcePath, $entryName, flags: ZipArchive::FL_ENC_UTF_8)) {
                        throw new RuntimeException(__('admin.articles.export.errors.build_failed'));
                    }
                }
            }

            if (! $zip->close()) {
                throw new RuntimeException(__('admin.articles.export.errors.build_failed'));
            }
            $zipOpened = false;
            @chmod($zipPath, 0600);
            $this->assertArticlesAvailable($articleIds);
            $this->assertStorageCapacity($adminId, afterBuild: true);

            return [
                'token' => $token,
                'path' => $zipPath,
                'filename' => 'geoflow-articles-'.now()->format('Ymd-His').'.zip',
                'count' => count($articleIds),
            ];
        } catch (Throwable $exception) {
            if ($zipOpened && $zip instanceof ZipArchive) {
                try {
                    $zip->close();
                } catch (Throwable) {
                }
            }
            File::delete($zipPath);

            throw $exception;
        } finally {
            $this->deleteDirectorySafely($buildingDirectory);
        }
    }

    public function resolveDownload(int $adminId, string $token): ?string
    {
        if ($adminId < 1 || preg_match('/\A[A-Za-z0-9]{40}\z/D', $token) !== 1) {
            return null;
        }

        $adminDirectory = $this->exportRoot.DIRECTORY_SEPARATOR.$adminId;
        if (is_link($adminDirectory) || ! is_dir($adminDirectory)) {
            return null;
        }

        $adminRealPath = realpath($adminDirectory);
        if (! is_string($adminRealPath)) {
            return null;
        }

        $path = $adminRealPath.DIRECTORY_SEPARATOR.$token.'.zip';
        clearstatcache(true, $path);
        if (is_link($path) || ! is_file($path)) {
            return null;
        }

        $realPath = realpath($path);
        if (! is_string($realPath) || ! hash_equals($path, $realPath)) {
            return null;
        }

        return $realPath;
    }

    public function pruneExpired(): int
    {
        $lock = Cache::lock('geoflow:article-markdown-export:prune', 300);
        if (! $lock->get()) {
            return 0;
        }

        try {
            return $this->pruneExpiredUnlocked();
        } finally {
            $lock->release();
        }
    }

    private function pruneExpiredUnlocked(): int
    {
        if (is_link($this->exportRoot) || ! is_dir($this->exportRoot)) {
            return 0;
        }

        $cutoff = now()->subMinutes(self::PRUNE_AFTER_MINUTES)->getTimestamp();
        $deleted = 0;
        try {
            $adminDirectories = new FilesystemIterator($this->exportRoot, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            return 0;
        }

        foreach ($adminDirectories as $adminDirectory) {
            if ($adminDirectory->isLink() || ! $adminDirectory->isDir() || ! ctype_digit($adminDirectory->getFilename())) {
                continue;
            }

            try {
                $artifacts = new FilesystemIterator($adminDirectory->getPathname(), FilesystemIterator::SKIP_DOTS);
            } catch (UnexpectedValueException) {
                continue;
            }
            foreach ($artifacts as $artifact) {
                if ($artifact->isLink() || $artifact->getMTime() > $cutoff) {
                    continue;
                }

                $name = $artifact->getFilename();
                if ($artifact->isFile() && preg_match('/\A[A-Za-z0-9]{40}\.zip\z/D', $name) === 1) {
                    if (@unlink($artifact->getPathname())) {
                        $deleted++;
                    }

                    continue;
                }

                if ($artifact->isDir() && preg_match('/\A\.[A-Za-z0-9]{40}\.building\z/D', $name) === 1) {
                    $this->deleteDirectorySafely($artifact->getPathname());
                    if (! is_dir($artifact->getPathname())) {
                        $deleted++;
                    }
                }
            }

            if ($this->isDirectoryEmpty($adminDirectory->getPathname())) {
                @rmdir($adminDirectory->getPathname());
            }
        }

        return $deleted;
    }

    private function assertStorageCapacity(int $adminId, bool $afterBuild): void
    {
        $this->ensureDirectory($this->exportRoot);
        $this->assertFreeDiskCapacity();

        $adminDirectory = $this->exportRoot.DIRECTORY_SEPARATOR.$adminId;
        if (! is_dir($adminDirectory)) {
            return;
        }
        if (is_link($adminDirectory)) {
            $this->storageCapacityExceeded();
        }

        $archiveCount = 0;
        $archiveBytes = 0;
        try {
            $artifacts = new FilesystemIterator($adminDirectory, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            $this->storageCapacityExceeded();
        }

        foreach ($artifacts as $artifact) {
            if ($artifact->isLink()
                || ! $artifact->isFile()
                || preg_match('/\A[A-Za-z0-9]{40}\.zip\z/D', $artifact->getFilename()) !== 1) {
                continue;
            }

            $archiveCount++;
            $archiveBytes += max(0, (int) $artifact->getSize());
        }

        $countExceeded = $afterBuild
            ? $archiveCount > $this->maxRetainedExportsPerAdmin
            : $archiveCount >= $this->maxRetainedExportsPerAdmin;
        $bytesExceeded = $afterBuild
            ? $archiveBytes > $this->maxRetainedBytesPerAdmin
            : $archiveBytes >= $this->maxRetainedBytesPerAdmin;
        if ($countExceeded || $bytesExceeded) {
            $this->storageCapacityExceeded();
        }
    }

    private function assertFreeDiskCapacity(): void
    {
        $freeBytes = @disk_free_space($this->exportRoot);
        if (! is_float($freeBytes)) {
            throw new RuntimeException(__('admin.articles.export.errors.build_failed'));
        }

        $requiredBytes = ($this->maxUncompressedBytes * 2) + $this->minFreeDiskBytes;
        if ($freeBytes < $requiredBytes) {
            $this->storageCapacityExceeded();
        }
    }

    private function storageCapacityExceeded(): never
    {
        throw ValidationException::withMessages([
            'article_ids' => __('admin.articles.export.errors.capacity_reached'),
        ]);
    }

    /** @param list<int> $articleIds */
    private function assertArticlesAvailable(array $articleIds): void
    {
        $availableIds = Article::query()
            ->whereIn('id', $articleIds)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        sort($availableIds);
        $expectedIds = $articleIds;
        sort($expectedIds);
        if ($availableIds !== $expectedIds) {
            throw ValidationException::withMessages([
                'article_ids' => __('admin.articles.export.errors.invalid_selection'),
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $articleIds
     * @return list<int>
     */
    private function validatedArticleIds(array $articleIds): array
    {
        if ($articleIds === [] || count($articleIds) > self::MAX_ARTICLES) {
            throw ValidationException::withMessages([
                'article_ids' => __('admin.articles.export.errors.invalid_selection'),
            ]);
        }

        $normalized = [];
        foreach ($articleIds as $articleId) {
            if (! is_int($articleId) || $articleId < 1 || isset($normalized[$articleId])) {
                throw ValidationException::withMessages([
                    'article_ids' => __('admin.articles.export.errors.invalid_selection'),
                ]);
            }
            $normalized[$articleId] = true;
        }

        return array_keys($normalized);
    }

    /** @return array{string, string, string, string} */
    private function createExportPaths(int $adminId): array
    {
        $this->ensureDirectory($this->exportRoot);
        $adminDirectory = $this->exportRoot.DIRECTORY_SEPARATOR.$adminId;
        for ($attempt = 0; $attempt < 5; $attempt++) {
            // The scheduled cleanup may remove a newly created empty admin directory.
            // Recreate it for every attempt so a concurrent prune cannot break an export.
            $this->ensureDirectory($adminDirectory);
            $token = Str::random(40);
            $buildingDirectory = $adminDirectory.DIRECTORY_SEPARATOR.'.'.$token.'.building';
            if (@mkdir($buildingDirectory, 0700)) {
                return [
                    $token,
                    $adminDirectory,
                    $buildingDirectory,
                    $adminDirectory.DIRECTORY_SEPARATOR.$token.'.zip',
                ];
            }
        }

        throw new RuntimeException(__('admin.articles.export.errors.build_failed'));
    }

    private function ensureDirectory(string $path): void
    {
        if (is_link($path)) {
            throw new RuntimeException(__('admin.articles.export.errors.build_failed'));
        }
        if (! is_dir($path) && ! @mkdir($path, 0700, true) && ! is_dir($path)) {
            throw new RuntimeException(__('admin.articles.export.errors.build_failed'));
        }
        if (is_link($path) || ! is_dir($path)) {
            throw new RuntimeException(__('admin.articles.export.errors.build_failed'));
        }
        @chmod($path, 0700);
    }

    private function entryName(int $sequence, Article $article): string
    {
        $safeTitle = preg_replace('/[<>:"\/\\\\|?*\x00-\x1F\x7F]+/u', '-', (string) $article->title) ?? '';
        $safeTitle = preg_replace('/\s+/u', '-', $safeTitle) ?? '';
        $safeTitle = preg_replace('/-+/u', '-', $safeTitle) ?? '';
        $safeTitle = trim($safeTitle, ' .-');
        $prefix = sprintf('%03d-%d-', $sequence, (int) $article->id);
        $suffix = '.md';
        $safeTitle = $this->truncateUtf8Bytes($safeTitle, max(1, 240 - strlen($prefix) - strlen($suffix)));
        if ($safeTitle === '') {
            $safeTitle = 'article';
        }

        return $prefix.$safeTitle.$suffix;
    }

    private function truncateUtf8Bytes(string $value, int $maxBytes): string
    {
        if (strlen($value) <= $maxBytes) {
            return $value;
        }

        return mb_strcut($value, 0, $maxBytes, 'UTF-8');
    }

    private function markdown(Article $article): string
    {
        $frontMatter = [
            'id' => (int) $article->id,
            'title' => (string) $article->title,
            'slug' => (string) $article->slug,
            'excerpt' => $article->excerpt !== null ? (string) $article->excerpt : null,
            'category' => $article->category?->name !== null ? (string) $article->category->name : null,
            'author' => $article->author?->name !== null ? (string) $article->author->name : null,
            'original_keyword' => $article->original_keyword !== null ? (string) $article->original_keyword : null,
            'keywords' => $article->keywords !== null ? (string) $article->keywords : null,
            'meta_description' => $article->meta_description !== null ? (string) $article->meta_description : null,
            'status' => (string) $article->status,
            'review_status' => (string) $article->review_status,
            'is_ai_generated' => (bool) $article->is_ai_generated,
            'is_hot' => (bool) $article->is_hot,
            'is_featured' => (bool) $article->is_featured,
            'created_at' => $article->created_at?->toIso8601String(),
            'updated_at' => $article->updated_at?->toIso8601String(),
            'published_at' => $article->published_at?->toIso8601String(),
        ];

        $lines = [];
        foreach ($frontMatter as $key => $value) {
            $lines[] = $key.': '.$this->yamlScalar($value);
        }

        $title = Str::squish((string) $article->title);
        $body = str_replace(["\r\n", "\r"], "\n", (string) $article->content);
        $body = rtrim($body, "\n");
        $markdown = "---\n".implode("\n", $lines)."\n---\n\n# ".$title."\n";

        return $body === '' ? $markdown : $markdown."\n".$body."\n";
    }

    private function yamlScalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return (string) $value;
        }

        return json_encode(
            (string) $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );
    }

    private function deleteDirectorySafely(string $directory): void
    {
        if (is_link($directory)) {
            @unlink($directory);

            return;
        }
        if (! is_dir($directory)) {
            return;
        }

        try {
            $items = new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS);
        } catch (UnexpectedValueException) {
            return;
        }
        foreach ($items as $item) {
            if ($item->isLink() || $item->isFile()) {
                @unlink($item->getPathname());

                continue;
            }
            if ($item->isDir()) {
                $this->deleteDirectorySafely($item->getPathname());
            }
        }
        @rmdir($directory);
    }

    private function isDirectoryEmpty(string $directory): bool
    {
        if (! is_dir($directory) || is_link($directory)) {
            return false;
        }

        try {
            return ! (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS))->valid();
        } catch (UnexpectedValueException) {
            return false;
        }
    }
}
