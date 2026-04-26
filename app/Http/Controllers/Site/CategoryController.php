<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
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
    public function show(string $slug): View
    {
        $category = Category::query()->where('slug', $slug)->first();
        if (! $category instanceof Category) {
            throw new NotFoundHttpException(__('site.category_not_found'));
        }

        $map = SiteSettingsBag::all();
        $perPage = max(1, min(200, (int) ($map['per_page'] ?? config('geoflow.items_per_page', 12))));
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $siteKeywords = (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', ''));

        $articles = Article::query()
            ->with(['category', 'author'])
            ->published()
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
            $hotArticles = Article::query()
                ->with(['category', 'author'])
                ->published()
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
            'canonicalUrl' => route('site.category', $category->slug),
        ]);
    }
}
