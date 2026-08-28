<?php

namespace App\Http\Middleware;

use App\Services\Site\SiteScopedArticleQuery;
use App\Support\Site\CurrentSite;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * 前台访问日志：为数据分析模块保存 PV、路径、来源和爬虫识别所需的 User-Agent。
 */
class RecordSiteViewLog
{
    public function __construct(
        private readonly CurrentSite $currentSite,
        private readonly SiteScopedArticleQuery $siteArticles,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (strtoupper((string) $request->method()) !== 'GET') {
            return $response;
        }

        if (! Schema::hasTable('view_logs')) {
            return $response;
        }

        try {
            DB::table('view_logs')->insert([
                'article_id' => $this->resolveArticleId($request, $response),
                'hosted_site_profile_id' => $this->currentSite->profileId(),
                'source' => $this->currentSite->isHosted() ? 'hosted_site' : 'local',
                'method' => strtoupper((string) $request->method()),
                'path' => $this->path($request),
                'route_name' => $request->route()?->getName(),
                'status_code' => $response->getStatusCode(),
                'ip_address' => (string) ($request->ip() ?? ''),
                'user_agent' => $request->userAgent(),
                'referer' => $this->limit($request->headers->get('referer'), 2048),
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // 日志写入不能影响前台访问。
        }

        return $response;
    }

    private function resolveArticleId(Request $request, Response $response): ?int
    {
        if ($response->getStatusCode() >= 400 || $request->route()?->getName() !== 'site.article') {
            return null;
        }

        $slug = trim((string) $request->route('slug', ''));
        if ($slug === '') {
            return null;
        }

        $articleId = $this->siteArticles->query()
            ->where('slug', $slug)
            ->value('id');

        return $articleId !== null ? (int) $articleId : null;
    }

    private function path(Request $request): string
    {
        $path = '/'.trim((string) $request->path(), '/');

        return $path === '/' ? '/' : $this->limit($path, 2048);
    }

    private function limit(?string $value, int $limit): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_substr($value, 0, $limit);
    }
}
