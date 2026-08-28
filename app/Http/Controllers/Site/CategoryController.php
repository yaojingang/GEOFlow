<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Services\Site\SiteScopedArticleQuery;
use App\Services\Site\SiteUrlGenerator;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 前台分类列表页（对齐旧版 category.php）。
 */
class CategoryController extends Controller
{
    public function __construct(
        private readonly SiteScopedArticleQuery $siteArticles,
        private readonly SiteUrlGenerator $urls,
    ) {}

    public function show(string $slug): View
    {
        $category = Category::query()
            ->where('slug', $slug)
            ->whereHas('articles', fn ($query) => $this->siteArticles->apply($query))
            ->first();
        if (! $category instanceof Category) {
            throw new NotFoundHttpException(__('site.category_not_found'));
        }

        $map = SiteSettingsBag::all();
        $perPage = max(1, min(200, (int) ($map['per_page'] ?? config('geoflow.items_per_page', 12))));
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $siteKeywords = (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', ''));

        $articles = $this->siteArticles->query()
            ->with(['category', 'author'])
            ->where('category_id', $category->id)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $summaries = [];
        foreach ($articles as $row) {
            if ($row instanceof Article) {
                $summaries[$row->id] = ArticleHtmlPresenter::cardSummary($row, 120);
            }
        }

        $hotArticles = collect();
        if (Schema::hasColumn('articles', 'is_hot')) {
            $hotArticles = $this->siteArticles->query()
                ->with(['category', 'author'])
                ->where('category_id', $category->id)
                ->where('is_hot', true)
                ->orderByDesc('published_at')
                ->orderByDesc('id')
                ->limit(6)
                ->get();
        }

        $pageTitle = $category->name.' - '.$siteTitle;
        $pageDescription = trim((string) $category->description) !== ''
            ? (string) $category->description
            : $category->name.' - '.$siteDescription;

        return SiteThemeViewResolver::first('category', [
            'activeNav' => 'category',
            'category' => $category,
            'articles' => $articles,
            'hotArticles' => $hotArticles,
            'cardSummaries' => $summaries,
            'siteTitle' => $siteTitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => $siteKeywords,
            'pageTitle' => $pageTitle,
            'pageDescription' => $pageDescription,
            'pageKeywords' => $siteKeywords,
            'pageOgType' => 'website',
            'canonicalUrl' => $this->urls->category($category),
        ]);
    }
}
