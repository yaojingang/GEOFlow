<?php

namespace App\Services\Site;

use App\Models\Article;
use App\Models\Category;
use App\Support\Site\ArticlePermalinkPolicy;
use App\Support\Site\CurrentSite;
use App\Support\Site\SiteThemePreviewContext;
use Illuminate\Support\Facades\Log;

final class SiteUrlGenerator
{
    public function __construct(
        private readonly CurrentSite $currentSite,
        private readonly ArticlePermalinkService $articlePermalinks,
    ) {}

    public function home(array $query = []): string
    {
        $url = $this->url('/');

        return $query === [] ? $url : $url.'?'.http_build_query($query);
    }

    public function about(): string
    {
        return $this->url('/about');
    }

    public function category(Category|string $category): string
    {
        $slug = $category instanceof Category ? $category->slug : $category;

        return $this->url('/category/'.rawurlencode((string) $slug));
    }

    public function article(Article|string $article): string
    {
        if ($article instanceof Article) {
            return $this->url($this->articlePermalinks->path($article));
        }

        if ($this->articlePermalinks->policy()->currentPattern !== ArticlePermalinkPolicy::DEFAULT_PATTERN) {
            Log::warning('A slug-only article URL call used the permanent compatibility path.', [
                'slug' => $article,
            ]);
        }

        return $this->url('/article/'.rawurlencode($article));
    }

    public function form(string $slug): string
    {
        return $this->url('/forms/'.rawurlencode($slug));
    }

    public function sitemap(): string
    {
        return $this->url('/sitemap.xml');
    }

    public function sitemapShard(int $page): string
    {
        return $this->url('/sitemaps/pages-'.$page.'.xml');
    }

    public function url(string $path): string
    {
        $previewUrl = app(SiteThemePreviewContext::class)->urlForPath($path);
        if ($previewUrl !== null) {
            return $previewUrl;
        }

        return rtrim($this->currentSite->baseUrl(), '/').'/'.ltrim($path, '/');
    }
}
