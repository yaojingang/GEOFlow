<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Models\CategorySlugHistory;
use App\Services\Site\SiteScopedArticleQuery;
use App\Services\Site\SiteUrlGenerator;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

    public function show(Request $request, string $slug): View|RedirectResponse
    {
        $path = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
        if (preg_match('/%(?![0-9a-f]{2})|%(?:2f|5c)/i', $path) === 1
            || ! mb_check_encoding($slug, 'UTF-8')
            || preg_match('/[\x00-\x1f\x7f\\\\\/]/u', $slug) === 1
            || in_array($slug, ['.', '..'], true)) {
            throw new NotFoundHttpException(__('site.category_not_found'));
        }

        $visibleCategories = Category::query()
            ->whereHas('articles', fn ($query) => $this->siteArticles->apply($query));
        $category = (clone $visibleCategories)->where('slug', $slug)->first();
        if (! $category instanceof Category && Schema::hasTable('category_slug_histories')) {
            $categoryId = CategorySlugHistory::query()->where('slug', $slug)->value('category_id');
            $category = $categoryId !== null
                ? $visibleCategories->whereKey((int) $categoryId)->first()
                : null;
        }
        if (! $category instanceof Category) {
            throw new NotFoundHttpException(__('site.category_not_found'));
        }

        if (! hash_equals((string) $category->slug, $slug)) {
            $target = $this->urls->category($category);
            $query = explode('?', $request->getRequestUri(), 2)[1] ?? '';
            if (preg_match('/[\x00-\x1f\x7f]/', $query) === 1) {
                throw new NotFoundHttpException(__('site.category_not_found'));
            }
            if ($query !== '') {
                $target .= (str_contains($target, '?') ? '&' : '?').$query;
            }

            return redirect()->to($target, 301)->withHeaders([
                'Cache-Control' => 'public, max-age=3600, s-maxage=300',
            ]);
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
