<?php

namespace Tests\Support;

use App\Contracts\Outbound\OutboundTransport;
use App\Services\Outbound\ResolvedOutboundTarget;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;

final class HostedSiteProbeTransport implements OutboundTransport
{
    public bool $healthy = true;

    public bool $maintenance = false;

    public function send(
        PendingRequest $request,
        string $method,
        ResolvedOutboundTarget $target,
        array $data,
        int $maxBytes,
        bool $crossOrigin = false,
    ): Response {
        $path = (string) parse_url($target->url, PHP_URL_PATH);
        if (! $this->healthy && $path === '/') {
            return new Response(new PsrResponse(500, [], 'probe failure'));
        }
        if ($this->maintenance && $path === '/') {
            return new Response(new PsrResponse(503, ['X-Robots-Tag' => 'noindex, nofollow'], 'maintenance'));
        }

        $baseUrl = $target->scheme.'://'.$target->host;
        $body = match (true) {
            $path === '/' => '<link rel="canonical" href="'.$baseUrl.'/"><script type="application/ld+json">{}</script>',
            $path === '/about' => '<link rel="canonical" href="'.$baseUrl.'/about">',
            $path === '/robots.txt' => "User-agent: *\nAllow: /\n",
            $path === '/sitemap.xml' => '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></sitemapindex>',
            str_starts_with($path, '/forms/') => '<form method="post"></form>',
            default => '',
        };

        return new Response(new PsrResponse(200, [], $body));
    }
}
