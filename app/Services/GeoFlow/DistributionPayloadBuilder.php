<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Support\Site\ArticleHtmlPresenter;

class DistributionPayloadBuilder
{
    private const MAX_EMBEDDED_IMAGE_BYTES = 5 * 1024 * 1024;

    /**
     * 构造目标站 Agent 可稳定消费的文章分发载荷。
     *
     * @return array<string,mixed>
     */
    public function build(Article $article): array
    {
        $article->loadMissing([
            'category:id,name,slug',
            'author:id,name',
            'task:id,name',
        ]);
        $title = (string) $article->title;
        $content = (string) $article->content;
        $body = ArticleHtmlPresenter::stripLeadingTitleHeading($content, $title);
        $contentHtml = ArticleHtmlPresenter::markdownToHtml($body);

        return [
            'version' => '1.0',
            'source' => 'geoflow',
            'event' => 'article.publish',
            'article' => [
                'id' => (int) $article->id,
                'title' => $title,
                'slug' => (string) $article->slug,
                'excerpt' => (string) ($article->excerpt ?? ''),
                'content' => $content,
                'content_format' => 'markdown',
                'content_html' => $contentHtml,
                'keywords' => (string) ($article->keywords ?? ''),
                'meta_description' => (string) ($article->meta_description ?? ''),
                'status' => (string) $article->status,
                'published_at' => $article->published_at?->toISOString(),
                'updated_at' => $article->updated_at?->toISOString(),
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
                'images' => $this->extractImageAssets($content, $contentHtml),
            ],
        ];
    }

    /**
     * @return list<array<string,string>>
     */
    private function extractImageAssets(string $markdown, string $html): array
    {
        $urls = [];
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

        return public_path($relativePath);
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
