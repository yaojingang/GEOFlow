<?php

namespace App\Services\Admin\SiteThemeReplication;

use App\Models\Article;
use App\Models\Category;
use App\Models\SiteThemeReplication;
use App\Models\SiteThemeReplicationVersion;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Contracts\View\View as ViewContract;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ThemePreviewRenderer
{
    /**
     * @return ViewContract
     */
    public function render(SiteThemeReplication $replication, string $page): ViewContract
    {
        $page = $this->normalizePage($page);
        $version = $this->latestVersion($replication);
        $this->preparePreviewLocation($replication, $version);

        return view('theme.'.$replication->theme_id.'.'.$page, $this->previewData($replication, $page));
    }

    public function assetResponse(SiteThemeReplication $replication, string $assetPath): Response
    {
        $assetPath = trim(str_replace('\\', '/', $assetPath), '/');
        if ($assetPath === '' || str_contains($assetPath, '..') || ! preg_match('/^[a-zA-Z0-9._\/-]+$/', $assetPath)) {
            abort(404);
        }

        $version = $this->latestVersion($replication);
        $base = trim((string) $version->draft_assets_path, '/');
        $storagePath = $base.'/'.$assetPath;
        if (! Storage::disk('local')->exists($storagePath)) {
            abort(404);
        }

        $mime = match (strtolower(pathinfo($assetPath, PATHINFO_EXTENSION))) {
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            default => 'application/octet-stream',
        };

        return response((string) Storage::disk('local')->get($storagePath), 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=60',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function normalizePage(string $page): string
    {
        return match ($page) {
            'home', 'category', 'article' => $page,
            default => throw new InvalidArgumentException('Unsupported preview page.'),
        };
    }

    private function latestVersion(SiteThemeReplication $replication): SiteThemeReplicationVersion
    {
        $version = $replication->versions()->latest('version')->first();
        if (! $version instanceof SiteThemeReplicationVersion) {
            abort(404);
        }

        return $version;
    }

    private function preparePreviewLocation(SiteThemeReplication $replication, SiteThemeReplicationVersion $version): void
    {
        $source = Storage::disk('local')->path(trim((string) $version->draft_views_path, '/'));
        if (! is_dir($source)) {
            abort(404);
        }

        $previewRelativeRoot = 'geoflow-theme-replications-preview/'.$replication->id.'/v'.$version->version;
        $previewRoot = Storage::disk('local')->path($previewRelativeRoot);
        $target = $previewRoot.'/theme/'.$replication->theme_id;
        File::deleteDirectory($target);
        File::ensureDirectoryExists($target);
        File::copyDirectory($source, $target);

        View::addLocation($previewRoot);
    }

    /**
     * @return array<string, mixed>
     */
    private function previewData(SiteThemeReplication $replication, string $page): array
    {
        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $siteKeywords = (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', ''));
        $siteSubtitle = (string) ($map['site_subtitle'] ?? '');

        $data = [
            'activeNav' => $page,
            'siteTitle' => $siteTitle,
            'siteName' => $siteTitle,
            'siteSubtitle' => $siteSubtitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => $siteKeywords,
            'pageTitle' => $replication->name.' Preview - '.$siteTitle,
            'pageDescription' => $siteDescription,
            'canonicalUrl' => route('admin.site-settings.theme-replications.preview', [
                'replicationId' => (int) $replication->id,
                'page' => $page,
            ]),
            'themeAssetBaseUrl' => route('admin.site-settings.theme-replications.assets', [
                'replicationId' => (int) $replication->id,
                'assetPath' => 'theme.css',
            ]),
            'themeScriptUrl' => route('admin.site-settings.theme-replications.assets', [
                'replicationId' => (int) $replication->id,
                'assetPath' => 'theme.js',
            ]),
        ];

        return array_merge($data, match ($page) {
            'home' => $this->homeData($siteTitle, $siteSubtitle, $siteDescription),
            'category' => $this->categoryData($siteTitle, $siteDescription, $siteKeywords),
            'article' => $this->articleData($siteTitle, $siteDescription),
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function homeData(string $siteTitle, string $siteSubtitle, string $siteDescription): array
    {
        $articles = Article::query()
            ->with(['category', 'author'])
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate(6);

        return [
            'search' => '',
            'category' => null,
            'categoryMissing' => false,
            'categoryId' => 0,
            'articles' => $articles,
            'featuredArticles' => collect(),
            'hotArticles' => collect(),
            'cardSummaries' => $this->summaries($articles->items()),
            'homepageCarouselSlides' => [],
            'viewTitle' => __('site.home_latest'),
            'pageTitle' => ($siteSubtitle !== '' ? $siteSubtitle.' - '.$siteTitle : $siteTitle),
            'pageDescription' => $siteDescription,
            'perPage' => 6,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function categoryData(string $siteTitle, string $siteDescription, string $siteKeywords): array
    {
        $category = Category::query()
            ->whereHas('articles', fn ($query) => $query->published())
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        if (! $category instanceof Category) {
            $category = Category::query()->orderBy('sort_order')->orderBy('id')->first();
        }

        if (! $category instanceof Category) {
            $category = new Category([
                'name' => __('admin.theme_replication.preview.sample_category'),
                'slug' => 'preview-category',
                'description' => __('admin.theme_replication.preview.sample_category_desc'),
            ]);
        }

        $articlesQuery = Article::query()
            ->with(['category', 'author'])
            ->published()
            ->where('category_id', $category->id ?: 0)
            ->orderByDesc('published_at')
            ->orderByDesc('id');

        $articles = $articlesQuery->paginate(6);

        return [
            'category' => $category,
            'articles' => $articles,
            'hotArticles' => collect(),
            'cardSummaries' => $this->summaries($articles->items()),
            'siteKeywords' => $siteKeywords,
            'pageTitle' => $category->name.' - '.$siteTitle,
            'pageDescription' => trim((string) $category->description) !== ''
                ? (string) $category->description
                : $category->name.' - '.$siteDescription,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function articleData(string $siteTitle, string $siteDescription): array
    {
        $article = Article::query()
            ->with(['category', 'author'])
            ->published()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->first();

        if (! $article instanceof Article) {
            $article = new Article([
                'title' => __('admin.theme_replication.preview.sample_article'),
                'slug' => 'preview-article',
                'excerpt' => __('admin.theme_replication.preview.sample_excerpt'),
                'content' => "## ".__('admin.theme_replication.preview.sample_section')."\n\n".__('admin.theme_replication.preview.sample_body'),
                'status' => 'published',
                'published_at' => now(),
            ]);
        }

        $body = ArticleHtmlPresenter::stripLeadingTitleHeading((string) $article->content, (string) $article->title);
        $excerpt = ArticleHtmlPresenter::stripLeadingTitleHeading((string) $article->excerpt, (string) $article->title);

        return [
            'article' => $article,
            'contentHtml' => ArticleHtmlPresenter::markdownToHtml($body),
            'excerptPlain' => $excerpt,
            'tags' => [],
            'relatedArticles' => collect(),
            'pageTitle' => $article->title.' - '.$siteTitle,
            'pageDescription' => $excerpt !== '' ? $excerpt : $siteDescription,
            'stickyAd' => null,
        ];
    }

    /**
     * @param  array<int, Article>  $articles
     * @return array<int, string>
     */
    private function summaries(array $articles): array
    {
        $summaries = [];
        foreach ($articles as $article) {
            if ($article instanceof Article && $article->id) {
                $summaries[(int) $article->id] = ArticleHtmlPresenter::cardSummary($article, 120);
            }
        }

        return $summaries;
    }
}
