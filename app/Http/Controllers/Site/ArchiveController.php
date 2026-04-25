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
 * 文章归档：总览与按年月列表（PostgreSQL 总览用 SQL 聚合；年月区间查询兼容 SQLite）。
 */
class ArchiveController extends Controller
{
    public function index(): View
    {
        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));

        $driver = DB::getDriverName();
        $archives = [];
        if ($driver === 'pgsql') {
            $rows = DB::select("
                SELECT
                    EXTRACT(YEAR FROM COALESCE(published_at, created_at))::int AS y,
                    LPAD(EXTRACT(MONTH FROM COALESCE(published_at, created_at))::text, 2, '0') AS m,
                    COUNT(*)::int AS cnt
                FROM articles
                WHERE status = 'published' AND deleted_at IS NULL
                GROUP BY y, m
                ORDER BY y DESC, m DESC
            ");
            foreach ($rows as $row) {
                $archives[] = [
                    'year' => (string) ($row->y ?? ''),
                    'month' => (string) ($row->m ?? ''),
                    'count' => (int) ($row->cnt ?? 0),
                ];
            }
        }

        $pageTitle = __('site.archive_title').' - '.$siteTitle;

        return SiteThemeViewResolver::first('archive-index', [
            'activeNav' => 'archive',
            'archives' => $archives,
            'siteTitle' => $siteTitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => '',
            'pageTitle' => $pageTitle,
            'pageDescription' => $siteDescription,
            'canonicalUrl' => route('site.archive'),
        ]);
    }

    public function month(string $year, string $month): View
    {
        if (! preg_match('/^\d{4}$/', $year) || ! preg_match('/^\d{2}$/', $month)) {
            throw new NotFoundHttpException;
        }

        $map = SiteSettingsBag::all();
        $perPage = max(1, min(200, (int) ($map['per_page'] ?? config('geoflow.items_per_page', 12))));
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));

        $start = Carbon::createFromDate((int) $year, (int) $month, 1)->startOfDay();
        $end = (clone $start)->copy()->endOfMonth()->endOfDay();

        $articles = Article::query()
            ->with(['category', 'author'])
            ->published()
            ->where(function ($q) use ($start, $end): void {
                $q->whereBetween('published_at', [$start, $end])
                    ->orWhere(function ($q2) use ($start, $end): void {
                        $q2->whereNull('published_at')->whereBetween('created_at', [$start, $end]);
                    });
            })
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
            'siteKeywords' => '',
            'pageTitle' => $pageTitle,
            'pageDescription' => $pageTitle,
            'canonicalUrl' => route('site.archive.month', ['year' => $year, 'month' => $month]),
        ]);
    }
}
