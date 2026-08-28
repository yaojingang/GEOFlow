<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Article;
use App\Support\Site\ArticleHtmlPresenter;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * 文章归档总览与按年月列表。
 */
class ArchiveController extends Controller
{
    public function index(): View
    {
        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $siteKeywords = (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', ''));

        [$yearExpression, $monthExpression] = match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => [
                'CAST(YEAR(COALESCE(published_at, created_at)) AS CHAR)',
                "LPAD(CAST(MONTH(COALESCE(published_at, created_at)) AS CHAR), 2, '0')",
            ],
            'sqlite' => [
                "strftime('%Y', COALESCE(published_at, created_at))",
                "strftime('%m', COALESCE(published_at, created_at))",
            ],
            'sqlsrv' => [
                'CAST(DATEPART(year, COALESCE(published_at, created_at)) AS varchar(4))',
                "RIGHT('0' + CAST(DATEPART(month, COALESCE(published_at, created_at)) AS varchar(2)), 2)",
            ],
            default => [
                "TO_CHAR(COALESCE(published_at, created_at), 'YYYY')",
                "TO_CHAR(COALESCE(published_at, created_at), 'MM')",
            ],
        };

        $archives = Article::query()
            ->published()
            ->selectRaw("{$yearExpression} AS archive_year")
            ->selectRaw("{$monthExpression} AS archive_month")
            ->selectRaw('COUNT(*) AS archive_count')
            ->groupByRaw("{$yearExpression}, {$monthExpression}")
            ->orderByRaw("{$yearExpression} DESC, {$monthExpression} DESC")
            ->get()
            ->map(fn (Article $article): array => [
                'year' => (string) $article->getAttribute('archive_year'),
                'month' => (string) $article->getAttribute('archive_month'),
                'count' => (int) $article->getAttribute('archive_count'),
            ])
            ->all();

        $pageTitle = __('site.archive_title').' - '.$siteTitle;

        return SiteThemeViewResolver::first('archive-index', [
            'activeNav' => 'archive',
            'archives' => $archives,
            'siteTitle' => $siteTitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => $siteKeywords,
            'pageTitle' => $pageTitle,
            'pageDescription' => $siteDescription,
            'pageKeywords' => $siteKeywords,
            'pageOgType' => 'website',
            'canonicalUrl' => route('site.archive'),
        ]);
    }

    public function month(string $year, string $month): View
    {
        if (! preg_match('/^\d{4}$/', $year) || ! preg_match('/^\d{2}$/', $month)) {
            throw new NotFoundHttpException;
        }

        try {
            $start = Carbon::createFromDate((int) $year, (int) $month, 1)->startOfDay();
        } catch (\Throwable) {
            throw new NotFoundHttpException;
        }
        if ($start->format('Y') !== $year || $start->format('m') !== $month) {
            throw new NotFoundHttpException;
        }
        $end = $start->copy()->endOfMonth()->endOfDay();
        $map = SiteSettingsBag::all();
        $perPage = max(1, min(200, (int) ($map['per_page'] ?? config('geoflow.items_per_page', 12))));
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $siteKeywords = (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', ''));

        $articles = Article::query()
            ->with(['category', 'author'])
            ->published()
            ->where(function ($query) use ($start, $end): void {
                $query->whereBetween('published_at', [$start, $end])
                    ->orWhere(function ($fallback) use ($start, $end): void {
                        $fallback->whereNull('published_at')->whereBetween('created_at', [$start, $end]);
                    });
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();

        $summaries = [];
        foreach ($articles as $article) {
            if ($article instanceof Article) {
                $summaries[$article->id] = ArticleHtmlPresenter::cardSummary($article, 120);
            }
        }

        $periodLabel = app()->getLocale() === 'en'
            ? $start->translatedFormat('F Y')
            : $year.'年'.$month.'月';
        $pageTitle = __('site.archive_month_title', ['period' => $periodLabel]).' - '.$siteTitle;

        return SiteThemeViewResolver::first('archive-month', [
            'activeNav' => 'archive',
            'year' => $year,
            'month' => $month,
            'periodLabel' => $periodLabel,
            'articles' => $articles,
            'cardSummaries' => $summaries,
            'siteTitle' => $siteTitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => $siteKeywords,
            'pageTitle' => $pageTitle,
            'pageDescription' => $pageTitle,
            'pageKeywords' => $siteKeywords,
            'pageOgType' => 'website',
            'canonicalUrl' => route('site.archive.month', ['year' => $year, 'month' => $month]),
        ]);
    }
}
