<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Site\SiteUrlGenerator;
use App\Support\Site\CurrentSite;
use App\Support\Site\SiteSettingsBag;
use App\Support\Site\SiteThemeViewResolver;
use Illuminate\View\View;

/**
 * GEOFlow 项目介绍页。
 */
class AboutController extends Controller
{
    public function __construct(
        private readonly SiteUrlGenerator $urls,
        private readonly CurrentSite $currentSite,
    ) {}

    public function index(): View
    {
        $map = SiteSettingsBag::all();
        $siteTitle = (string) ($map['site_name'] ?? config('geoflow.site_name', config('app.name')));
        $siteDescription = (string) ($map['site_description'] ?? config('geoflow.site_description', ''));
        $siteKeywords = (string) ($map['site_keywords'] ?? config('geoflow.site_keywords', ''));
        $isHosted = $this->currentSite->isHosted();
        $defaultDescription = $isHosted
            ? ($siteDescription !== '' ? $siteDescription : '关于 '.$siteTitle)
            : 'GEOFlow 是面向生成式引擎优化的开源智能内容工程与多站点分发系统，连接知识、生成、审核、发布、分发和数据分析。';
        $aboutTitle = trim((string) ($map['about_title'] ?? ''));
        $aboutContent = trim((string) ($map['about_content'] ?? ''));
        $pageDescription = $aboutContent !== '' ? $aboutContent : $defaultDescription;
        $view = $this->currentSite->isHosted() ? view('site.about') : null;

        $data = [
            'activeNav' => 'about',
            'siteTitle' => $siteTitle,
            'siteDescription' => $siteDescription,
            'siteKeywords' => $siteKeywords,
            'pageTitle' => $aboutTitle !== '' ? $aboutTitle : '关于 '.$siteTitle,
            'pageDescription' => $pageDescription,
            'pageKeywords' => $isHosted
                ? $siteKeywords
                : 'GEOFlow,GEO,生成式引擎优化,开源内容系统,知识库,多站点分发',
            'pageOgType' => 'website',
            'canonicalUrl' => $this->urls->about(),
            'repositoryUrl' => 'https://github.com/yaojingang/GEOFlow',
            'aboutTitle' => $aboutTitle !== '' ? $aboutTitle : '关于 '.$siteTitle,
            'aboutContent' => $aboutContent,
            'isHostedAbout' => $isHosted,
        ];

        return $view ? $view->with($data) : SiteThemeViewResolver::first('about', $data);
    }
}
