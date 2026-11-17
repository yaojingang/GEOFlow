<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Support\GeoFlow\ImageUrlNormalizer;
use App\Support\Site\ArticleHtmlPresenter;

class DistributionPayloadBuilder
{
    private const MAX_EMBEDDED_IMAGE_BYTES = 5 * 1024 * 1024;

    public function __construct(private readonly ArticleTranslationService $translations) {}

    /**
     * 构造目标站 Agent 可稳定消费的文章分发载荷。
     *
     * 原文始终是中文；传 $targetLocale 时优先使用该语言译文，译文不可用时回落原文。
     *
     * @return array<string,mixed>
     */
    public function build(Article $article, ?string $targetLocale = null): array
    {
        $article->loadMissing([
            'category:id,name,slug',
            'author:id,name',
            'task:id,name',
            'articleImages.image',
        ]);

        $sourceLocale = (string) ($article->locale ?? 'zh');
        $contentLocale = $sourceLocale;
        $title = (string) $article->title;
        $content = (string) $article->content;
        $excerptText = (string) ($article->excerpt ?? '');
        $metaDescription = (string) ($article->meta_description ?? '');

        if ($this->translations->needsTranslation($article, $targetLocale)) {
            $translation = $this->translations->resolve($article, $targetLocale);
            if ($translation instanceof ArticleTranslation && trim((string) $translation->content) !== '') {
                $title = (string) $translation->title;
                $content = (string) $translation->content;
                $excerptText = (string) ($translation->excerpt ?? '');
                $metaDescription = (string) ($translation->meta_description ?? '');
                $contentLocale = (string) $translation->locale;
            }
        }

        $body = ArticleHtmlPresenter::stripLeadingTitleHeading($content, $title);
        $contentHtml = ArticleHtmlPresenter::markdownToHtml($body);
        $heroImageUrl = $this->heroImageUrl($article);

        return [
            'version' => '1.0',
            'source' => 'geoflow',
            'event' => 'article.publish',
            'article' => [
                'id' => (int) $article->id,
                'title' => $title,
                'slug' => (string) $article->slug,
                'excerpt' => $excerptText,
                'content' => $content,
                'content_format' => 'markdown',
                'content_html' => $contentHtml,
                'hero_image_url' => $heroImageUrl,
                'keywords' => (string) ($article->keywords ?? ''),
                'meta_description' => $metaDescription,
                'status' => (string) $article->status,
                'is_featured' => (bool) $article->is_featured,
                'is_hot' => (bool) $article->is_hot,
                'published_at' => $article->published_at?->toISOString(),
                'created_at' => $article->created_at?->toISOString(),
                'updated_at' => $article->updated_at?->toISOString(),
                'source_lang' => $contentLocale,
                'content_locale' => $contentLocale,
                'default_locale' => $sourceLocale,
                'category' => $article->category ? [
                    'id' => (int) $article->category->id,
                    'name' => (string) $article->category->name,
                    'slug' => (string) ($article->category->slug ?? ''),
                ] : null,
                'author' => $article->author ? [
                    'id' => (int) $article->author->id,
                    'name' => (string) $article->author->name,
                ] : null,
                'task' => $article->task ? [
                    'id' => (int) $article->task->id,
                    'name' => (string) $article->task->name,
                ] : null,
            ],
            'assets' => [
                'images' => $this->extractImageAssets($content, $contentHtml, $heroImageUrl !== '' ? [$heroImageUrl] : []),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function heroImageUrl(Article $article): string
    {
        $image = $article->articleImages->sortBy('position')->first()?->image;
        if (! $image) {
            return '';
        }

        return ImageUrlNormalizer::toPublicUrl((string) ($image->file_path ?? ''));
    }

    /**
     * @param  list<string>  $additionalUrls
     * @return list<array<string,string>>
     */
    private function extractImageAssets(string $markdown, string $html, array $additionalUrls = []): array
    {
        $urls = $additionalUrls;
        if (preg_match_all('/!\[[^\]]*\]\(([^)\s]+)(?:\s+["\'][^"\']*["\'])?\)/u', $markdown, $matches)) {
            foreach ($matches[1] ?? [] as $url) {
                $urls[] = trim((string) $url);
            }
        }
        if (preg_match_all('/<img\b[^>]*\bsrc=(["\'])(.*?)\1/iu', $html, $matches)) {
            foreach ($matches[2] ?? [] as $url) {
                $urls[] = trim((string) $url);
            }
        }

        $assets = [];
        foreach (array_values(array_unique(array_filter($urls))) as $url) {
            $asset = $this->buildImageAsset((string) $url);
            if ($asset !== null) {
                $assets[] = $asset;
            }
        }

        return array_slice($assets, 0, 20);
    }

    /**
     * @return array<string,string>|null
     */
    private function buildImageAsset(string $url): ?array
    {
        if ($url === '' || str_starts_with(strtolower($url), 'data:') || str_starts_with(strtolower($url), 'javascript:')) {
            return null;
        }

        $asset = [
            'source_url' => $url,
            'filename' => $this->imageAssetFilename($url, ''),
        ];

        $path = $this->publicPathForImageUrl($url);
        if ($path !== null && is_file($path) && is_readable($path)) {
            $size = filesize($path);
            if (is_int($size) && $size > self::MAX_EMBEDDED_IMAGE_BYTES) {
                $asset['skip_reason'] = 'file_too_large';

                return $asset;
            }

            $contents = file_get_contents($path);
            if (is_string($contents) && $contents !== '') {
                $mimeType = mime_content_type($path) ?: 'application/octet-stream';
                $asset['mime_type'] = $mimeType;
                $asset['content_base64'] = base64_encode($contents);
                $asset['filename'] = $this->imageAssetFilename($url, (string) $mimeType);
            }
        }

        return $asset;
    }

    private function publicPathForImageUrl(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($path) || $path === '') {
            return null;
        }
        if (! str_starts_with($path, '/')) {
            return null;
        }

        $relativePath = ltrim($path, '/');
        if (str_contains($relativePath, '..')) {
            return null;
        }

        $publicPath = public_path($relativePath);
        if (is_file($publicPath)) {
            return $publicPath;
        }

        if (str_starts_with($relativePath, 'storage/')) {
            return storage_path('app/public/'.substr($relativePath, strlen('storage/')));
        }

        return $publicPath;
    }

    private function imageAssetFilename(string $url, string $mimeType): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $extension = pathinfo(is_string($path) ? $path : '', PATHINFO_EXTENSION);
        if ($extension === '') {
            $extension = match ($mimeType) {
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'image/svg+xml' => 'svg',
                default => 'img',
            };
        }

        return hash('sha256', $url).'.'.strtolower($extension);
    }
}
