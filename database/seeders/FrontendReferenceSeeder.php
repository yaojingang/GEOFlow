<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use RuntimeException;

class FrontendReferenceSeeder extends Seeder
{
    public const PACK_VERSION = 'frontend-reference-v1';

    private bool $overwriteExistingRows = false;

    public function run(): void
    {
        $this->overwriteExistingRows = (bool) config('geoflow.seed_frontend_demo_overwrite', false);
        $manifest = $this->manifest();
        $author = $this->seedAuthor($manifest['author']);
        $categories = $this->seedCategories($manifest['categories']);

        foreach ($manifest['articles'] as $article) {
            $category = $categories[$article['category_slug']] ?? null;
            if (! $category instanceof Category) {
                throw new RuntimeException('Reference article category is missing: '.$article['category_slug']);
            }

            $this->seedArticle($article, $category, $author);
        }
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        $path = database_path('seeders/data/'.self::PACK_VERSION.'/manifest.json');
        if (! File::exists($path)) {
            throw new RuntimeException('Frontend reference manifest is missing.');
        }

        $manifest = File::json($path);
        if (($manifest['version'] ?? null) !== self::PACK_VERSION || count($manifest['articles'] ?? []) !== 50) {
            throw new RuntimeException('Frontend reference manifest is invalid.');
        }

        return $manifest;
    }

    /** @param array<string, mixed> $row */
    private function seedAuthor(array $row): Author
    {
        $author = Author::query()->where('email', $row['email'])->first();
        if ($author instanceof Author) {
            if ($this->overwriteExistingRows) {
                $author->fill($row)->save();
            }

            return $author;
        }

        return Author::query()->create($row);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, Category>
     */
    private function seedCategories(array $rows): array
    {
        $categories = [];
        foreach ($rows as $row) {
            $category = Category::query()->where('slug', $row['slug'])->first();
            if ($category instanceof Category) {
                if ($this->overwriteExistingRows) {
                    $category->fill($row)->save();
                }
            } else {
                $category = Category::query()->create($row);
            }

            $categories[$row['slug']] = $category;
        }

        return $categories;
    }

    /** @param array<string, mixed> $row */
    private function seedArticle(array $row, Category $category, Author $author): void
    {
        $contentPath = database_path('seeders/data/'.self::PACK_VERSION.'/'.$row['file']);
        if (! File::exists($contentPath)) {
            throw new RuntimeException('Reference article file is missing: '.$row['file']);
        }

        $values = [
            'title' => $row['title'],
            'excerpt' => $row['excerpt'],
            'content' => File::get($contentPath),
            'category_id' => $category->id,
            'author_id' => $author->id,
            'original_keyword' => $row['original_keyword'],
            'keywords' => $row['keywords'],
            'meta_description' => $row['meta_description'],
            'status' => 'published',
            'review_status' => 'approved',
            'view_count' => $row['view_count'],
            'is_ai_generated' => (int) $row['is_ai_generated'],
            'is_hot' => (bool) $row['is_hot'],
            'is_featured' => (bool) $row['is_featured'],
            'published_at' => now()->subDays((int) $row['published_offset_days'])->setTime(9, 30),
        ];

        $existing = Article::query()->withTrashed()->where('slug', $row['slug'])->first();
        if ($existing instanceof Article) {
            if ($this->overwriteExistingRows && $existing->deleted_at === null) {
                $existing->fill($values)->save();
            }

            return;
        }

        Article::query()->create(['slug' => $row['slug'], ...$values]);
    }
}
