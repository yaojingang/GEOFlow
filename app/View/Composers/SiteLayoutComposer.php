<?php

namespace App\View\Composers;

use App\Models\Category;
use App\Models\HostedSiteProfile;
use App\Services\Site\SiteScopedArticleQuery;
use App\Support\Site\CurrentSite;
use App\Support\Site\SiteSettingsBag;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

/**
 * 为前台 Blade 布局注入站点名称、分类导航等公共变量。
 */
final class SiteLayoutComposer
{
    public function __construct(
        private readonly SiteScopedArticleQuery $siteArticles,
        private readonly CurrentSite $currentSite,
    ) {}

    public function compose(View $view): void
    {
        $map = SiteSettingsBag::all();
        $siteName = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteLogo = (string) ($map['site_logo'] ?? '');
        $siteFavicon = (string) ($map['site_favicon'] ?? '');
        $copyright = (string) ($map['copyright_info'] ?? '');
        $filingInfo = trim((string) ($map['filing_info'] ?? ''));
        $filingUrl = trim((string) ($map['filing_url'] ?? ''));
        $analyticsCode = (string) ($map['analytics_code'] ?? '');

        $categories = collect();
        if (Schema::hasTable('categories')) {
            $categories = Category::query()
                ->whereHas('articles', function ($q): void {
                    $this->siteArticles->apply($q);
                })
                ->orderBy('sort_order')
                ->orderBy('id')
                ->withCount([
                    'articles as published_count' => function ($q): void {
                        $this->siteArticles->apply($q);
                    },
                ])
                ->get();
        }

        $view->with([
            'siteName' => $siteName,
            'siteLogo' => $siteLogo,
            'siteFavicon' => $siteFavicon,
            'footerCopyright' => $copyright,
            'footerFilingInfo' => $filingInfo,
            'footerFilingUrl' => $filingUrl,
            'headAnalyticsCode' => $analyticsCode,
            'navCategories' => $categories,
            'siteIndexingAllowed' => ! $this->currentSite->isHosted()
                || $this->currentSite->profile()?->indexing_status === HostedSiteProfile::INDEXING_INDEX,
        ]);
    }
}
