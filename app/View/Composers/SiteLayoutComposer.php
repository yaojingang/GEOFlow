<?php

namespace App\View\Composers;

use App\Models\Category;
use App\Models\HostedSiteProfile;
use App\Services\Site\SiteScopedArticleQuery;
use App\Services\Site\SiteUrlGenerator;
use App\Support\Site\CurrentSite;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemePreviewContext;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

/**
 * 为前台 Blade 布局注入站点名称、分类导航等公共变量。
 */
final class SiteLayoutComposer
{
    public function __construct(
        private readonly SiteScopedArticleQuery $siteArticles,
        private readonly CurrentSite $currentSite,
        private readonly SiteUrlGenerator $siteUrls,
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
        $preview = app(SiteThemePreviewContext::class)->isActive();
        $analyticsCode = $preview ? '' : (string) ($map['analytics_code'] ?? '');
        $categories = $preview ? $this->loadNavigationCategories() : $this->cachedNavigationCategories();

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
            'siteUrls' => $this->siteUrls,
        ]);
    }

    /** @return Collection<int, Category> */
    private function cachedNavigationCategories(): Collection
    {
        $siteKey = $this->currentSite->isHosted() ? 'hosted:'.$this->currentSite->profileId() : 'primary';
        $key = 'geoflow:site:navigation:'.$siteKey;
        try {
            $cached = Cache::get($key);
            if ($cached instanceof Collection) {
                return $cached;
            }
            $lock = Cache::lock($key.':refresh', 10);
            $lock->block(1);
        } catch (LockTimeoutException) {
            return $this->loadNavigationCategories();
        } catch (Throwable $exception) {
            report($exception);

            return $this->loadNavigationCategories();
        }

        try {
            try {
                $cached = Cache::get($key);
            } catch (Throwable $exception) {
                report($exception);

                return $this->loadNavigationCategories();
            }
            if ($cached instanceof Collection) {
                return $cached;
            }

            $categories = $this->loadNavigationCategories();
            try {
                Cache::put($key, $categories, 60);
            } catch (Throwable $exception) {
                report($exception);
            }

            return $categories;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @return Collection<int, Category> */
    private function loadNavigationCategories(): Collection
    {
        if (! Schema::hasTable('categories')) {
            return collect();
        }

        $visibleArticles = fn ($query) => $this->siteArticles->apply($query);

        return Category::query()
            ->whereHas('articles', $visibleArticles)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->withCount(['articles as published_count' => $visibleArticles])
            ->get();
    }
}
