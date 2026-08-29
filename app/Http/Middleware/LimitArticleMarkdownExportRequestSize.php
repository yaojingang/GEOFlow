<?php

namespace App\Http\Middleware;

use App\Services\GeoFlow\ArticleMarkdownExportService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class LimitArticleMarkdownExportRequestSize
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST') || ! $this->isPreparePath($request)) {
            return $next($request);
        }

        $contentLength = $request->server('CONTENT_LENGTH');
        if (is_numeric($contentLength)
            && (int) $contentLength > ArticleMarkdownExportService::MAX_PREPARE_REQUEST_BYTES) {
            return new JsonResponse([
                'message' => __('admin.articles.export.errors.request_too_large'),
                'code' => 'article_export_request_too_large',
            ], 413);
        }

        return $next($request);
    }

    private function isPreparePath(Request $request): bool
    {
        $adminPrefix = trim((string) config('geoflow.admin_base_path', '/geo_admin'), '/');
        $expected = $adminPrefix.'/articles/batch/export-markdown/prepare';

        return hash_equals($expected, trim($request->path(), '/'));
    }
}
