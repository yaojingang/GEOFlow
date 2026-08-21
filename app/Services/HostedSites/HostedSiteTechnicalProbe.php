<?php

namespace App\Services\HostedSites;

use App\Models\HostedSiteProfile;
use App\Services\Outbound\SafeOutboundHttpClient;
use Illuminate\Http\Client\Factory;
use Throwable;

final class HostedSiteTechnicalProbe
{
    public function __construct(
        private readonly SafeOutboundHttpClient $safeHttp,
        private readonly Factory $http,
    ) {}

    /**
     * @param  list<string>  $leadFormSlugs
     * @return array<string,bool>
     */
    public function check(HostedSiteProfile $profile, array $leadFormSlugs): array
    {
        if (! config('geoflow.hosted_sites.network_preflight_enabled', false)) {
            return [];
        }

        $baseUrl = 'https://'.$profile->hostname;
        $home = $this->fetch($baseUrl.'/');
        $maintenance = $profile->serving_status === HostedSiteProfile::SERVING_MAINTENANCE;
        $checks = [
            'dns_public' => $home['resolved'],
            'tls_https' => $home['responded'],
            'homepage_status' => $maintenance
                ? $home['status'] === 503
                : $home['status'] === 200,
            'homepage_no_5xx' => $maintenance
                ? $home['status'] === 503
                : $home['status'] !== null && $home['status'] < 500,
        ];

        if ($maintenance) {
            $checks['maintenance_noindex'] = str_contains(
                strtolower((string) $home['robots']),
                'noindex'
            );

            return $checks;
        }

        $about = $this->fetch($baseUrl.'/about');
        $robots = $this->fetch($baseUrl.'/robots.txt');
        $sitemap = $this->fetch($baseUrl.'/sitemap.xml');
        $checks += [
            'canonical' => str_contains($home['body'], 'href="'.$baseUrl.'/"'),
            'json_ld' => str_contains($home['body'], 'application/ld+json'),
            'about_page' => $about['status'] === 200
                && str_contains($about['body'], 'href="'.$baseUrl.'/about"'),
            'robots' => $robots['status'] === 200
                && str_contains($robots['body'], 'User-agent: *'),
            'sitemap' => $sitemap['status'] === 200
                && (str_contains($sitemap['body'], '<urlset')
                    || str_contains($sitemap['body'], '<sitemapindex')),
        ];

        $profile->loadMissing('channel');
        $themeId = (string) $profile->channel?->template_key;
        if ($themeId !== '' && $themeId !== 'default') {
            $themeCss = $this->fetch($baseUrl.'/themes/'.rawurlencode($themeId).'/theme.css');
            $checks['theme_css'] = $themeCss['status'] === 200;
            if (is_file(public_path('themes/'.$themeId.'/theme.js'))) {
                $themeJs = $this->fetch($baseUrl.'/themes/'.rawurlencode($themeId).'/theme.js');
                $checks['theme_js'] = $themeJs['status'] === 200;
            }
        }

        foreach ($leadFormSlugs as $slug) {
            $form = $this->fetch($baseUrl.'/forms/'.rawurlencode($slug));
            $checks['form_'.hash('sha256', $slug)] = $form['status'] === 200;
        }

        return $checks;
    }

    /** @return array{resolved:bool,responded:bool,status:?int,body:string,robots:string} */
    private function fetch(string $url): array
    {
        try {
            $this->safeHttp->resolveTarget($url);
        } catch (Throwable) {
            return ['resolved' => false, 'responded' => false, 'status' => null, 'body' => '', 'robots' => ''];
        }

        try {
            $timeout = (int) config('geoflow.hosted_sites.preflight_timeout_seconds', 8);
            $request = $this->http
                ->connectTimeout(min(5, $timeout))
                ->timeout($timeout)
                ->withHeaders([
                    'Accept' => 'text/html,application/xml,text/plain;q=0.9,*/*;q=0.5',
                    'User-Agent' => 'GEOFlow Hosted Site Preflight/1.0',
                ]);
            $response = $this->safeHttp->get($request, $url, 1_048_576, 0);

            return [
                'resolved' => true,
                'responded' => true,
                'status' => $response->status(),
                'body' => (string) $response->body(),
                'robots' => (string) $response->header('X-Robots-Tag', ''),
            ];
        } catch (Throwable) {
            return ['resolved' => true, 'responded' => false, 'status' => null, 'body' => '', 'robots' => ''];
        }
    }
}
