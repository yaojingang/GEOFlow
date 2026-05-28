<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Models\Category;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * 前台首页：最新列表、分类筛选、搜索；模板优先 {@see resources/views/theme/{themeId}/home.blade.php}，否则 {@see resources/views/site/home.blade.php}。
 */
class HomeController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search', ''));
        $categoryId = max(0, (int) $request->query('category', 0));
        $page = max(1, (int) $request->query('page', 1));

        $map = SiteSettingsBag::all();
        $perPage = max(1, min(200, (int) ($map['per_page'] ?? config('geoflow.items_per_page', 12))));
        $featuredLimit = max(1, min(5, (int) ($map['featured_limit'] ?? 5)));

        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteSubtitle = (string) ($map['site_subtitle'] ?? '');
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $siteKeywords = (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', ''));
        $homepageCarouselSlides = $this->parseHomepageCarouselSlides((string) ($map['home_carousel_slides'] ?? '[]'));

        $category = null;
        $categoryMissing = false;
        if ($categoryId > 0) {
            $category = Category::query()->find($categoryId);
            $categoryMissing = ! $category instanceof Category;
        }

        $query = Article::query()->with(['category', 'author'])->published();

        if ($search !== '') {
            $escaped = $this->escapeLike(mb_strtolower($search));
            $like = '%'.$escaped.'%';
            $query->where(function ($q) use ($like): void {
                $q->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(excerpt, ?)) LIKE ?', ['', $like]);
            });
        } elseif ($category instanceof Category) {
            $query->where('category_id', $category->id);
        } elseif ($categoryMissing) {
            $query->whereRaw('1 = 0');
        }

        $articles = (clone $query)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $featuredArticles = collect();
        $hotArticles = collect();
        if ($search === '' && ! $category && $page === 1) {
            if (Schema::hasColumn('articles', 'is_featured')) {
                $featuredArticles = Article::query()
                    ->with(['category', 'author'])
                    ->published()
                    ->where('is_featured', true)
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->limit($featuredLimit)
                    ->get();
            }

            if (Schema::hasColumn('articles', 'is_hot')) {
                $hotArticles = Article::query()
                    ->with(['category', 'author'])
                    ->published()
                    ->where('is_hot', true)
                    ->orderByDesc('published_at')
                    ->orderByDesc('id')
                    ->limit(6)
                    ->get();
            }
        }

        $viewTitle = __('site.home_latest');
        if ($search !== '') {
            $viewTitle = __('site.search_breadcrumb', ['term' => $search]);
        } elseif ($category instanceof Category) {
            $viewTitle = $category->name;
        } elseif ($categoryMissing) {
            $viewTitle = __('site.category_not_found');
        } else {
            $viewTitle = __('site.home_latest');
        }

        $pageTitle = $search !== '' || $category instanceof Category
            ? $viewTitle.' - '.$siteTitle
            : ($siteSubtitle !== '' ? $siteSubtitle.' - '.$siteTitle : $siteTitle);

        $pageDescription = $siteDescription;
        if ($search !== '') {
            $pageDescription = $search.' - '.$siteDescription;
        } elseif ($category instanceof Category && trim((string) $category->description) !== '') {
            $pageDescription = (string) $category->description;
        }

        $summaries = [];
        foreach ($articles as $row) {
            if ($row instanceof Article) {
                $summaries[$row->id] = ArticleHtmlPresenter::cardSummary($row, 120);
            }
        }
        foreach ($featuredArticles as $row) {
            if ($row instanceof Article && ! isset($summaries[$row->id])) {
                $summaries[$row->id] = ArticleHtmlPresenter::cardSummary($row, 120);
            }
        }
        foreach ($hotArticles as $row) {
            if ($row instanceof Article && ! isset($summaries[$row->id])) {
                $summaries[$row->id] = ArticleHtmlPresenter::cardSummary($row, 120);
            }
        }

        $canonicalUrl = route('site.home');
        if ($search !== '') {
            $canonicalUrl = route('site.home', ['search' => $search]);
        } elseif ($category instanceof Category) {
            $canonicalUrl = route('site.category', $category->slug);
        } elseif ($categoryMissing) {
            $canonicalUrl = route('site.home', ['category' => $categoryId]);
        }

        return SiteThemeViewResolver::first('home', [
            'activeNav' => 'home',
            'search' => $search,
            'category' => $category,
            'categoryMissing' => $categoryMissing,
            'categoryId' => $categoryId,
            'articles' => $articles,
            'featuredArticles' => $featuredArticles,
            'hotArticles' => $hotArticles,
            'cardSummaries' => $summaries,
            'siteTitle' => $siteTitle,
            'siteSubtitle' => $siteSubtitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => $siteKeywords,
            'homepageCarouselSlides' => $homepageCarouselSlides,
            'viewTitle' => $viewTitle,
            'pageTitle' => $pageTitle,
            'pageDescription' => $pageDescription,
            'perPage' => $perPage,
            'canonicalUrl' => $canonicalUrl,
        ]);
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * @return array<int, array{image_url:string,title:string,link_url:string}>
     */
    private function parseHomepageCarouselSlides(string $raw): array
    {
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        $slides = [];
        foreach ($decoded as $item) {
            if (! is_array($item) || empty($item['enabled'])) {
                continue;
            }

            $imageUrl = trim((string) ($item['image_url'] ?? ''));
            if ($imageUrl === '') {
                continue;
            }

            $slides[] = [
                'image_url' => $imageUrl,
                'title' => trim((string) ($item['title'] ?? '')),
                'link_url' => trim((string) ($item['link_url'] ?? '')),
            ];

            if (count($slides) >= 3) {
                break;
            }
        }

        return $slides;
    }
}
