<?php

namespace App\Services\Geo;

use App\Models\Article;
use App\Models\Author;
use App\Models\BrandProfile;
use App\Models\Category;
use App\Models\GeoArticleDraft;
use App\Support\GeoFlow\ArticleWorkflow;
use Illuminate\Support\Facades\DB;

class GeoArticlePublisher
{
    public function convertDraftToArticle(GeoArticleDraft $draft): Article
    {
        $draft->loadMissing(['article', 'writingTask']);

        if ($draft->article instanceof Article) {
            return $draft->article;
        }

        return DB::transaction(function () use ($draft): Article {
            $category = $this->resolveCategory();
            $author = $this->resolveAuthor();
            $question = $this->sourceQuestion($draft);

            $article = Article::query()->create([
                'title' => (string) $draft->title,
                'slug' => ArticleWorkflow::generateUniqueSlug((string) $draft->title),
                'excerpt' => $this->excerpt($draft),
                'content' => (string) $draft->content_markdown,
                'category_id' => $category->id,
                'author_id' => $author->id,
                'original_keyword' => $question,
                'keywords' => $question,
                'meta_description' => (string) ($draft->seo_description ?: $draft->summary),
                'status' => 'draft',
                'review_status' => 'pending',
                'view_count' => 0,
                'is_ai_generated' => 1,
                'is_hot' => false,
                'is_featured' => false,
                'metadata' => $this->metadata($draft, $question),
            ]);

            $draft->forceFill([
                'article_id' => $article->id,
                'status' => 'converted',
            ])->save();

            return $article;
        });
    }

    private function resolveCategory(): Category
    {
        return Category::query()->firstOrCreate(
            ['slug' => 'geo-content'],
            [
                'name' => 'GEO内容',
                'description' => '由 GEO 诊断报告生成的优化内容',
                'sort_order' => 0,
            ]
        );
    }

    private function resolveAuthor(): Author
    {
        return Author::query()->firstOrCreate(
            ['name' => 'GEOFlow'],
            ['bio' => 'Default GEOFlow author for automated content generation.']
        );
    }

    private function excerpt(GeoArticleDraft $draft): string
    {
        $summary = trim((string) $draft->summary);
        if ($summary !== '') {
            return mb_substr($summary, 0, 200, 'UTF-8');
        }

        $plain = preg_replace('/[#*_`>\[\]()]/u', ' ', (string) $draft->content_markdown) ?? '';
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return mb_substr(trim($plain), 0, 200, 'UTF-8');
    }

    private function sourceQuestion(GeoArticleDraft $draft): string
    {
        $brief = (array) ($draft->writingTask?->brief ?? []);
        $question = trim((string) ($brief['question'] ?? ''));

        return $question !== '' ? $question : trim((string) $draft->seo_title);
    }

    /**
     * @return array<string, mixed>
     */
    private function metadata(GeoArticleDraft $draft, string $question): array
    {
        $brief = (array) ($draft->writingTask?->brief ?? []);
        $briefSource = trim((string) ($brief['source'] ?? 'geo_report'));
        $references = collect((array) ($brief['references'] ?? []))
            ->map(static fn (mixed $reference): array => (array) $reference);

        $metadata = [
            'source' => match ($briefSource) {
                'reference_content' => 'geo_reference_content',
                'reference_imitation' => 'geo_reference_imitation',
                default => $briefSource,
            },
            'geo_writing_task_id' => (int) $draft->geo_writing_task_id,
            'target_question' => $question,
            'reference_urls' => $references
                ->map(static fn (array $reference): string => trim((string) ($reference['url'] ?? '')))
                ->filter()
                ->values()
                ->all(),
            'reference_titles' => $references
                ->map(static fn (array $reference): string => trim((string) ($reference['title'] ?? '')))
                ->filter()
                ->values()
                ->all(),
        ];

        $brandProfileId = BrandProfile::query()
            ->where('organization_id', $draft->organization_id)
            ->latest()
            ->value('id');

        if ($brandProfileId !== null) {
            $metadata['brand_profile_id'] = (int) $brandProfileId;
        }

        return $metadata;
    }
}
