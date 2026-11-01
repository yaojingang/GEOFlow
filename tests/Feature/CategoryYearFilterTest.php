<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Models\Task;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CategoryYearFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_year_filter_uses_the_full_category_and_preserves_all_year_options(): void
    {
        $category = Category::query()->create(['name' => '新闻', 'slug' => 'news']);
        $author = Author::query()->create(['name' => '编辑', 'email' => 'news-year@example.test']);

        foreach ([2024, 2025] as $year) {
            Article::query()->create([
                'title' => "News in {$year}",
                'slug' => "news-in-{$year}",
                'content' => 'News body',
                'category_id' => $category->id,
                'author_id' => $author->id,
                'status' => 'published',
                'review_status' => 'approved',
                'published_at' => "{$year}-06-01 12:00:00",
            ]);
        }

        $this->get('/category/news?year=2025')
            ->assertOk()
            ->assertViewHas('selectedYear', 2025)
            ->assertViewHas('yearOptions', [2025, 2024])
            ->assertViewHas('articles', fn ($articles) => $articles->count() === 1
                && $articles->first()->title === 'News in 2025');
    }

    public function test_year_filter_handles_date_boundaries_and_creation_date_fallback(): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor', 'email' => 'boundaries@example.test']);
        foreach ([
            ['previous', '2024-12-31 23:59:59', '2025-06-01 00:00:00'],
            ['start', '2025-01-01 00:00:00', '2024-06-01 00:00:00'],
            ['end', '2025-12-31 23:59:59', '2024-06-01 00:00:00'],
            ['next', '2026-01-01 00:00:00', '2025-06-01 00:00:00'],
            ['fallback', null, '2025-06-01 00:00:00'],
        ] as [$slug, $publishedAt, $createdAt]) {
            $article = Article::query()->create([
                'title' => $slug, 'slug' => $slug, 'content' => 'Body',
                'category_id' => $category->id, 'author_id' => $author->id,
                'status' => 'published', 'review_status' => 'approved',
                'published_at' => $publishedAt,
            ]);
            $article->forceFill(['created_at' => $createdAt])->save();
        }
        SiteSetting::query()->updateOrCreate(['setting_key' => 'per_page'], ['setting_value' => '2']);
        SiteSettingsBag::forget();

        $response = $this->get('/category/news?year=2025')->assertOk()
            ->assertViewHas('yearOptions', [2026, 2025, 2024]);
        $articles = $response->viewData('articles');
        $this->assertSame(3, $articles->total());
        $this->assertSame(['end', 'start'], $articles->pluck('slug')->all());
        $this->assertStringContainsString('year=2025', $articles->nextPageUrl());
        $this->get('/category/news?year=2025&page=2')->assertOk()
            ->assertViewHas('yearOptions', [2026, 2025, 2024])
            ->assertViewHas('articles', fn ($rows): bool => $rows->pluck('slug')->all() === ['fallback']);
    }

    #[DataProvider('unfilteredQueries')]
    public function test_missing_and_invalid_years_leave_the_category_unfiltered(string $query): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor', 'email' => 'invalid-year@example.test']);
        foreach ([2024, 2025] as $year) {
            Article::query()->create([
                'title' => 'News '.$year, 'slug' => 'news-'.$year, 'content' => 'Body',
                'category_id' => $category->id, 'author_id' => $author->id,
                'status' => 'published', 'published_at' => $year.'-06-01 00:00:00',
            ]);
        }

        $this->get('/category/news'.$query)->assertOk()
            ->assertViewHas('selectedYear', null)
            ->assertViewHas('articles', fn ($articles): bool => $articles->total() === 2);
    }

    public static function unfilteredQueries(): array
    {
        return [
            'absent' => [''], 'empty' => ['?year='], 'array' => ['?year[]=2025'],
            'before range' => ['?year=1899'], 'after range' => ['?year=2100'],
            'embedded newline' => ['?year=20%0A25'], 'query text' => ['?year=2025%20OR%201=1'],
        ];
    }

    #[DataProvider('yearBounds')]
    public function test_year_options_stay_within_the_supported_range(array $years, array $expected): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $author = Author::query()->create(['name' => 'Editor', 'email' => 'year-bounds@example.test']);
        foreach ($years as $year) {
            Article::query()->create([
                'title' => 'News '.$year, 'slug' => 'news-'.$year, 'content' => 'Body',
                'category_id' => $category->id, 'author_id' => $author->id,
                'status' => 'published', 'published_at' => $year.'-06-01 00:00:00',
            ]);
        }

        $this->get('/category/news')->assertOk()->assertViewHas('yearOptions', $expected);
    }

    public static function yearBounds(): array
    {
        return [
            'entirely before range' => [[1899], []],
            'entirely after range' => [[2100], []],
            'lower edge' => [[1899, 1900, 1901], [1901, 1900]],
            'upper edge' => [[2098, 2099, 2100], [2099, 2098]],
        ];
    }

    public function test_year_choices_and_results_exclude_other_categories_drafts_and_distribution_only_articles(): void
    {
        $category = Category::query()->create(['name' => 'News', 'slug' => 'news']);
        $other = Category::query()->create(['name' => 'Other', 'slug' => 'other']);
        $author = Author::query()->create(['name' => 'Editor', 'email' => 'scope-year@example.test']);
        $task = Task::query()->create(['name' => 'Channel only', 'publish_scope' => 'distribution_only']);
        foreach ([
            ['visible', $category->id, 'published', null, 2025],
            ['other', $other->id, 'published', null, 2024],
            ['draft', $category->id, 'draft', null, 2023],
            ['channel', $category->id, 'published', $task->id, 2022],
        ] as [$slug, $categoryId, $status, $taskId, $year]) {
            Article::query()->create([
                'title' => $slug, 'slug' => $slug, 'content' => 'Body',
                'category_id' => $categoryId, 'author_id' => $author->id,
                'status' => $status, 'task_id' => $taskId, 'published_at' => $year.'-06-01 00:00:00',
            ]);
        }

        $this->get('/category/news?year=2025')->assertOk()
            ->assertViewHas('yearOptions', [2025])
            ->assertViewHas('articles', fn ($articles): bool => $articles->pluck('slug')->all() === ['visible']);
    }
}
