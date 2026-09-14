<?php

namespace Tests\Performance;

use App\Models\Article;
use App\Models\Category;
use App\Services\Site\ArticlePermalinkService;
use App\Support\Site\ArticlePermalinkPolicy;
use IteratorAggregate;
use Tests\TestCase;
use Traversable;

class ArticlePermalinkScaleTest extends TestCase
{
    public function test_one_hundred_thousand_articles_with_ten_retired_rules_stay_within_the_budget(): void
    {
        $articleCount = 100_000;
        $category = (new Category)->forceFill([
            'id' => 1,
            'name' => 'AI',
            'slug' => 'ai',
        ]);
        $articles = new class($articleCount, $category) implements IteratorAggregate
        {
            public function __construct(
                private readonly int $count,
                private readonly Category $category,
            ) {}

            public function getIterator(): Traversable
            {
                for ($id = 1; $id <= $this->count; $id++) {
                    $article = (new Article)->forceFill([
                        'id' => $id,
                        'title' => 'Scale article '.$id,
                        'slug' => 'scale-article-'.$id,
                        'category_id' => $this->category->id,
                        'created_at' => '2026-09-12 08:30:00',
                    ]);
                    $article->exists = true;
                    $article->setRelation('category', $this->category);

                    yield $id => $article;
                }
            }
        };
        $currentPolicy = ArticlePermalinkPolicy::defaults();
        foreach (range(1, 10) as $revision) {
            $currentPolicy = $currentPolicy->activate('/legacy-'.$revision.'/{slug}');
        }
        $previewPolicy = $currentPolicy->activate('/{category}/{slug}');

        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $baselineMemory = memory_get_usage(true);
        $startedAt = hrtime(true);

        $inspection = app(ArticlePermalinkService::class)->inspectArticles(
            static fn (): IteratorAggregate => $articles,
            $currentPolicy,
            $previewPolicy,
        );

        $durationMilliseconds = (hrtime(true) - $startedAt) / 1_000_000;
        $peakMemoryDelta = memory_get_peak_usage(true) - $baselineMemory;

        $this->assertSame($articleCount, $inspection['affected_articles']);
        $this->assertSame([], $inspection['conflicts']);
        $this->assertLessThanOrEqual(
            30_000,
            $durationMilliseconds,
            sprintf('Permalink inspection took %.2f ms.', $durationMilliseconds),
        );
        $this->assertLessThanOrEqual(
            256 * 1024 * 1024,
            $peakMemoryDelta,
            sprintf('Permalink inspection used %.2f MiB.', $peakMemoryDelta / 1024 / 1024),
        );
    }
}
