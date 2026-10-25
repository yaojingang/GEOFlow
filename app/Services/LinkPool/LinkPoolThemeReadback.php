<?php

namespace App\Services\LinkPool;

use App\Exceptions\ApiException;
use App\Models\HostedSiteProfile;
use App\Models\ThemeRevision;
use App\Services\Api\ThemeReleaseService;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Support\Site\CurrentSite;
use App\Support\Site\LinkPoolThemeReadbackSignature;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

final class LinkPoolThemeReadback
{
    public function __construct(private readonly SafeOutboundHttpClient $safeHttp) {}

    public function check(string $siteKey, ThemeRevision $revision, ?string $articleUrl): void
    {
        $base = $siteKey === 'primary'
            ? rtrim((string) config('geoflow.site_url', config('app.url')), '/')
            : 'https://'.HostedSiteProfile::query()->findOrFail((int) substr($siteKey, 7))->hostname;
        $url = $articleUrl ?: $base.'/';
        $oldRequest = app('request');
        $currentSite = app(CurrentSite::class);
        $resolved = $currentSite->isResolved();
        $oldType = $resolved ? $currentSite->type() : null;
        $oldHost = $resolved ? $currentSite->hostname() : null;
        $oldProfile = $currentSite->profile();
        try {
            $this->request($url, true, $revision->id);
            $this->externalRequest($url, true, $revision->id);
            foreach (array_keys($revision->files ?? []) as $path) {
                $prefix = 'public/themes/'.$revision->theme_id.'/';
                if (str_starts_with($path, $prefix)) {
                    $assetUrl = $base.'/theme-assets/'.$revision->id.'/'.substr($path, strlen($prefix));
                    $hash = (string) $revision->files[$path]['sha256'];
                    $this->request($assetUrl, false, $hash);
                    $this->externalRequest($assetUrl, false, $hash);
                    break;
                }
            }
        } finally {
            app()->instance('request', $oldRequest);
            if ($oldType === CurrentSite::TYPE_HOSTED && $oldProfile !== null) {
                $currentSite->setHosted($oldProfile);
            } elseif ($oldType === CurrentSite::TYPE_PRIMARY) {
                $currentSite->setPrimary((string) $oldHost);
            } else {
                $currentSite->clear();
            }
        }
    }

    private function externalRequest(string $url, bool $html, string $expected): void
    {
        $endpoint = ThemeReleaseService::trustedReadbackBase();
        if ($endpoint === null) {
            if (app()->environment('testing')) {
                return;
            }
            throw new ApiException('theme_http_readback_unavailable', '当前部署缺少可信的内部 HTTP 回读地址', 503);
        }
        $host = parse_url($url, PHP_URL_HOST);
        $path = parse_url($url, PHP_URL_PATH);
        if (! is_string($host) || ! is_string($path) || ! str_starts_with($path, '/')) {
            throw new ApiException('theme_http_readback_unavailable', '站点回读地址无效', 503);
        }
        try {
            $request = Http::connectTimeout(3)->timeout(10)->withoutRedirecting()->withHeaders([
                'X-GEOFlow-Theme-Readback-Host' => $host, 'User-Agent' => 'GEOFlow-Theme-Readback',
                'X-GEOFlow-Theme-Readback' => LinkPoolThemeReadbackSignature::forUrl($url),
            ]);
            $response = $this->safeHttp->get($request, $endpoint.$path, 8 * 1024 * 1024, 0);
        } catch (\Throwable) {
            throw new ApiException('theme_http_readback_failed', '正式站点 HTTP 回读失败，已撤回适配', 503);
        }
        if ($response->status() !== 200
            || ($html && (! str_contains((string) $response->header('Content-Type'), 'text/html')
                || $response->header('X-GEOFlow-Theme-Revision') !== $expected))
            || (! $html && trim((string) $response->header('ETag'), '"') !== $expected)) {
            throw new ApiException('theme_http_readback_failed', '正式站点页面或主题资源回读失败，已撤回适配', 503);
        }
    }

    private function request(string $url, bool $html, string $expected): void
    {
        $request = Request::create($url, 'GET');
        $request->headers->set('User-Agent', 'GEOFlow-Theme-Readback');
        $request->attributes->set('link_pool.theme_readback', true);
        $kernel = app(Kernel::class);
        $response = $kernel->handle($request);
        try {
            if ($response->getStatusCode() !== 200
                || ($html && (! str_contains((string) $response->headers->get('Content-Type'), 'text/html')
                    || $response->headers->get('X-GEOFlow-Theme-Revision') !== $expected))
                || (! $html && trim((string) $response->headers->get('ETag'), '"') !== $expected)) {
                throw new ApiException('theme_http_readback_failed', '正式站点页面或主题资源回读失败，已撤回适配', 503);
            }
        } finally {
            $kernel->terminate($request, $response);
        }
    }
}
