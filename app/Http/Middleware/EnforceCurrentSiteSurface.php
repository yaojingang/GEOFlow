<?php

namespace App\Http\Middleware;

use App\Models\HostedSiteProfile;
use App\Support\Site\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceCurrentSiteSurface
{
    public const ACTIVATION_HEADER = 'X-GEOFlow-Hosted-Activation';

    public function __construct(private readonly CurrentSite $currentSite) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->currentSite->isPrimary()) {
            return $next($request);
        }

        $profile = $this->currentSite->profile();
        if (! $profile instanceof HostedSiteProfile) {
            abort(404);
        }

        if (! $this->isAllowed($request)) {
            return response('', 404)->withHeaders($this->noindexHeaders());
        }

        if ($profile->serving_status === HostedSiteProfile::SERVING_ARCHIVED) {
            return response('Gone', 410)->withHeaders($this->noindexHeaders());
        }

        if ($profile->serving_status === HostedSiteProfile::SERVING_MAINTENANCE) {
            return response('Service Unavailable', 503)->withHeaders([
                ...$this->noindexHeaders(),
                'Retry-After' => '300',
            ]);
        }

        if ($this->activationProbePending($request, $profile)) {
            return response('Service Unavailable', 503)->withHeaders([
                ...$this->noindexHeaders(),
                'Retry-After' => '60',
            ]);
        }

        $response = $next($request);
        if ($profile->indexing_status !== HostedSiteProfile::INDEXING_INDEX) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }

    private function isAllowed(Request $request): bool
    {
        $path = '/'.ltrim($request->path(), '/');
        $method = strtoupper($request->method());

        if ($method === 'POST') {
            return preg_match('#^/forms/[a-zA-Z0-9_-]+/submissions$#', $path) === 1;
        }

        if (! in_array($method, ['GET', 'HEAD'], true)) {
            return false;
        }

        if (in_array($path, ['/', '/about', '/archive', '/robots.txt', '/sitemap.xml'], true)) {
            return true;
        }

        if ($path === '/favicon.ico'
            || preg_match('#^/(?:assets|js|storage|themes)/[a-zA-Z0-9._/-]+$#', $path) === 1
            || preg_match('#^/build/assets/[a-zA-Z0-9._-]+$#', $path) === 1) {
            return true;
        }

        return preg_match('#^/(?:category|article|forms)/[a-zA-Z0-9_-]+$#', $path) === 1
            || preg_match('#^/archive/[0-9]{4}/[0-9]{2}$#', $path) === 1
            || preg_match('#^/sitemaps/[a-zA-Z0-9._-]+$#', $path) === 1;
    }

    private function activationProbePending(Request $request, HostedSiteProfile $profile): bool
    {
        $profile->loadMissing('channel');
        $expected = trim((string) data_get(
            $profile->channel?->channel_config,
            'hosted_site_activation_token',
            ''
        ));
        if ($expected === '') {
            return false;
        }

        return ! hash_equals($expected, trim((string) $request->header(self::ACTIVATION_HEADER, '')));
    }

    /** @return array<string,string> */
    private function noindexHeaders(): array
    {
        return ['X-Robots-Tag' => 'noindex, nofollow'];
    }
}
