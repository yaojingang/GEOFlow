<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Services\GeoFlow\ArticleSlugRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ArticleSlugRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_slug_changes_are_historical_and_can_be_restored_by_the_same_article(): void
    {
        $article = $this->article('first-slug');
        $registry = app(ArticleSlugRegistry::class);

        $registry->change($article, 'second-slug');
        $this->assertSame('second-slug', $article->fresh()->slug);
        $this->assertDatabaseHas('article_slug_histories', [
            'article_id' => $article->id,
            'slug' => 'first-slug',
        ]);

        $registry->change($article->fresh(), 'first-slug');
        $this->assertSame('first-slug', $article->fresh()->slug);
        $this->assertDatabaseHas('article_slug_histories', [
            'article_id' => $article->id,
            'slug' => 'second-slug',
        ]);
        $this->assertDatabaseMissing('article_slug_histories', ['slug' => 'first-slug']);
    }

    public function test_current_and_historical_slugs_cannot_be_reused_by_another_article(): void
    {
        $first = $this->article('first-slug');
        $second = $this->article('second-slug');
        $registry = app(ArticleSlugRegistry::class);
        $registry->change($first, 'retitled-slug');

        foreach (['retitled-slug', 'first-slug'] as $unavailableSlug) {
            try {
                $registry->change($second->fresh(), $unavailableSlug);
                $this->fail('Expected a slug conflict.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('slug', $exception->errors());
            }
        }

        $this->assertSame('second-slug', $second->fresh()->slug);
    }

    public function test_invalid_slug_segments_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(ArticleSlugRegistry::class)->change($this->article('valid-slug'), 'bad/slug');
    }

    private function article(string $slug): Article
    {
        $category = Category::query()->firstOrCreate(['slug' => 'ai'], ['name' => 'AI']);
        $author = Author::query()->firstOrCreate(['email' => 'slug@example.test'], ['name' => 'GEOFlow']);

        return Article::query()->create([
            'title' => $slug,
            'slug' => $slug,
            'content' => 'Body',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'draft',
            'review_status' => 'pending',
        ]);
    }
}
