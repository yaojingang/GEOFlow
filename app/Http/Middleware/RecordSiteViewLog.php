<?php

namespace App\Http\Middleware;

use App\Models\Article;
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
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! in_array(strtoupper((string) $request->method()), ['GET', 'HEAD'], true)) {
            return $response;
        }

        if (! Schema::hasTable('view_logs')) {
            return $response;
        }

        try {
            DB::table('view_logs')->insert([
                'article_id' => $this->resolveArticleId($request, $response),
                'source' => 'local',
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

        $articleId = Article::query()
            ->where('slug', $slug)
            ->whereNull('deleted_at')
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
