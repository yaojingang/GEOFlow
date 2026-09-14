<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleSlugHistory;
use App\Models\Author;
use App\Models\Category;
use App\Models\SiteSetting;
use App\Services\Site\ArticlePermalinkService;
use App\Services\Site\SiteUrlGenerator;
use App\Support\Site\ArticlePermalinkPattern;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ArticlePermalinkRoutingTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('presetPaths')]
    public function test_every_preset_generates_and_serves_the_canonical_article_url(string $pattern, string $path): void
    {
        $article = $this->article();
        $this->setPolicy($pattern);
        $baseUrl = rtrim((string) config('app.url'), '/');

        $this->assertSame($baseUrl.$path, app(SiteUrlGenerator::class)->article($article));
        $response = $this->get($path)
            ->assertOk()
            ->assertSee('Permalink article')
            ->assertSee('<link rel="canonical" href="'.$baseUrl.$path.'">', false)
            ->assertSee('<meta property="og:url" content="'.$baseUrl.$path.'">', false);
        preg_match_all(
            '/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s',
            $response->getContent(),
            $schemaMatches,
        );
        $articleSchema = collect($schemaMatches[1] ?? [])
            ->map(static fn (string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR))
            ->first(static fn (array $schema): bool => in_array($schema['@type'] ?? null, ['Article', 'NewsArticle'], true));
        $this->assertIsArray($articleSchema);
        $mainEntity = $articleSchema['mainEntityOfPage'] ?? null;
        $this->assertSame(
            $baseUrl.$path,
            is_array($mainEntity) ? ($mainEntity['@id'] ?? null) : $mainEntity,
        );
        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<loc>'.$baseUrl.$path.'</loc>', false);
    }

    /** @return array<string,array{string,string}> */
    public static function presetPaths(): array
    {
        return [
            'default' => ['/article/{slug}', '/article/permalink-article'],
            'root category and slug' => ['/{category}/{slug}', '/ai/permalink-article'],
            'html' => ['/{slug}.html', '/permalink-article.html'],
            'id and slug' => ['/article/{id}-{slug}.html', '/article/1-permalink-article.html'],
            'category' => ['/article/{category}/{slug}.html', '/article/ai/permalink-article.html'],
            'date' => ['/article/{year}/{month}/{slug}.html', '/article/2026/09/permalink-article.html'],
            'id' => ['/article/{id}.html', '/article/1.html'],
            'root category and id custom pattern' => ['/{category}/{id}', '/ai/1'],
        ];
    }

    public function test_legacy_and_descriptive_paths_redirect_once_to_the_current_url_and_preserve_query(): void
    {
        $article = $this->article();
        $this->setPolicy('/article/{category}/{slug}.html');
        $baseUrl = rtrim((string) config('app.url'), '/');

        $this->get('/article/'.$article->slug.'?utm_source=test')
            ->assertRedirect($baseUrl.'/article/ai/permalink-article.html?utm_source=test')
            ->assertStatus(301);
        $this->get('/article/old-category/'.$article->slug.'.html')
            ->assertRedirect($baseUrl.'/article/ai/permalink-article.html')
            ->assertStatus(301);
        $query = '?tag=first&tag=second&term=a%20b&literal=%2B';
        $this->head('/article/'.$article->slug.$query)
            ->assertStatus(301)
            ->assertRedirect($baseUrl.'/article/ai/permalink-article.html'.$query);

        $this->assertSame(0, $article->fresh()->view_count);
        $this->get('/article/ai/permalink-article.html')->assertOk();
        $this->assertSame(1, $article->fresh()->view_count);
        $this->head('/article/ai/permalink-article.html')->assertOk();
        $this->assertSame(1, $article->fresh()->view_count);
    }

    public function test_large_primary_sitemap_is_sharded_and_uses_canonical_permalink_urls(): void
    {
        config()->set('geoflow.hosted_sites.sitemap_url_limit', 2);
        $first = $this->article();
        $second = Article::query()->create([
            'title' => 'Second permalink article',
            'slug' => 'second-permalink-article',
            'content' => 'Second permalink body',
            'category_id' => $first->category_id,
            'author_id' => $first->author_id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        $this->setPolicy('/{category}/{slug}');
        $baseUrl = rtrim((string) config('app.url'), '/');

        $this->get('/sitemap.xml')
            ->assertOk()
            ->assertSee('<sitemapindex', false)
            ->assertSee($baseUrl.'/sitemaps/pages-1.xml', false)
            ->assertSee($baseUrl.'/sitemaps/pages-2.xml', false);

        $combinedShards = $this->get('/sitemaps/pages-1.xml')->assertOk()->streamedContent()
            .$this->get('/sitemaps/pages-2.xml')->assertOk()->streamedContent();
        $this->assertStringContainsString($baseUrl.'/ai/'.$first->slug, $combinedShards);
        $this->assertStringContainsString($baseUrl.'/ai/'.$second->slug, $combinedShards);
        $this->get('/sitemaps/pages-3.xml')->assertNotFound();
    }

    public function test_historical_pattern_and_slug_redirect_directly_to_the_current_canonical_url(): void
    {
        $article = $this->article();
        ArticleSlugHistory::query()->create([
            'article_id' => $article->id,
            'slug' => 'retired-slug',
            'created_at' => now(),
            'last_used_at' => now(),
        ]);
        $this->setPolicy('/article/{id}.html', ['/stories/{slug}.html']);
        $baseUrl = rtrim((string) config('app.url'), '/');

        $this->get('/stories/retired-slug.html')
            ->assertRedirect($baseUrl.'/article/'.$article->id.'.html')
            ->assertStatus(301);
        $this->get('/article/retired-slug')
            ->assertRedirect($baseUrl.'/article/'.$article->id.'.html')
            ->assertStatus(301);
    }

    public function test_stale_date_and_trailing_slash_redirect_directly_to_the_canonical_url(): void
    {
        $article = $this->article();
        $this->setPolicy('/article/{year}/{month}/{slug}.html');
        $baseUrl = rtrim((string) config('app.url'), '/');
        $canonical = '/article/2026/09/'.$article->slug.'.html';

        $this->get('/article/2025/08/'.$article->slug.'.html')
            ->assertRedirect($baseUrl.$canonical)
            ->assertStatus(301);
        $resolution = app(ArticlePermalinkService::class)->resolve($canonical.'/');
        $this->assertNotNull($resolution);
        $this->assertFalse($resolution->isCanonical);
        $this->assertSame($canonical, $resolution->canonicalPath);
    }

    public function test_draft_missing_and_illegally_encoded_paths_are_not_served(): void
    {
        $article = $this->article(status: 'draft');
        $this->setPolicy('/{slug}.html');

        $this->get('/'.$article->slug.'.html')->assertNotFound();
        $this->get('/missing.html')->assertNotFound();
        $this->get('/article/bad%2Fslug')->assertNotFound();
    }

    public function test_soft_deleted_articles_are_not_served_by_current_or_legacy_paths(): void
    {
        $article = $this->article();
        $article->delete();
        $this->setPolicy('/{slug}.html');

        $this->get('/'.$article->slug.'.html')->assertNotFound();
        $this->get('/article/'.$article->slug)->assertNotFound();
    }

    public function test_every_explicit_public_get_route_is_reserved_from_permalink_templates(): void
    {
        $reserved = ArticlePermalinkPattern::reservedFirstSegments();

        foreach (Route::getRoutes() as $route) {
            if (array_intersect(['GET', 'HEAD'], $route->methods()) === []
                || in_array($route->getName(), ['site.article', 'site.article.resolve', 'site.asset'], true)) {
                continue;
            }
            $firstSegment = explode('/', ltrim($route->uri(), '/'), 2)[0];
            if ($firstSegment === '' || str_starts_with($firstSegment, '{')) {
                continue;
            }

            $this->assertContains(
                $firstSegment,
                $reserved,
                "Public route /{$firstSegment} is missing from the article permalink reservation registry.",
            );
        }
    }

    public function test_generating_one_hundred_default_article_links_has_a_bounded_query_count(): void
    {
        $articles = collect(range(1, 100))->map(static function (int $id): Article {
            $article = new Article([
                'slug' => 'bounded-query-'.$id,
                'category_id' => 999,
                'created_at' => '2026-09-12 08:30:00',
            ]);
            $article->id = $id;
            $article->exists = true;

            return $article;
        });
        SiteSettingsBag::forget();
        DB::flushQueryLog();
        DB::enableQueryLog();

        $generator = app(SiteUrlGenerator::class);
        foreach ($articles as $article) {
            $generator->article($article);
        }

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(3, $queryCount);
    }

    public function test_failed_article_render_does_not_increment_the_read_count(): void
    {
        $article = $this->article();
        $articleView = collect(SiteThemeViewResolver::candidateViews('article'))
            ->first(static fn (string $view): bool => View::exists($view));
        $this->assertIsString($articleView);
        View::composer($articleView, static function (): never {
            throw new RuntimeException('Synthetic article render failure.');
        });

        $this->get('/article/'.$article->slug)->assertServerError();

        $this->assertSame(0, $article->fresh()->view_count);
    }

    private function article(string $status = 'published'): Article
    {
        Carbon::setTestNow('2026-09-12 08:30:00');
        $category = Category::query()->create(['name' => 'AI', 'slug' => 'ai']);
        $author = Author::query()->create(['name' => 'GEOFlow']);

        $article = Article::query()->create([
            'title' => 'Permalink article',
            'slug' => 'permalink-article',
            'excerpt' => 'Permalink description',
            'content' => 'Permalink body',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => $status,
            'review_status' => $status === 'published' ? 'approved' : 'pending',
            'published_at' => $status === 'published' ? now() : null,
        ]);
        Carbon::setTestNow();

        return $article;
    }

    /** @param list<string> $history */
    private function setPolicy(string $pattern, array $history = []): void
    {
        SiteSetting::query()->updateOrCreate(
            ['setting_key' => ArticlePermalinkPolicy::SETTING_KEY],
            ['setting_value' => json_encode([
                'schema_version' => 1,
                'revision' => 1,
                'current_pattern' => $pattern,
                'activated_at' => now()->toIso8601String(),
                'history' => array_map(
                    static fn (string $item): array => ['pattern' => $item, 'retired_at' => now()->toIso8601String()],
                    $history,
                ),
            ], JSON_UNESCAPED_SLASHES)]
        );
        SiteSettingsBag::forget();
    }
}
