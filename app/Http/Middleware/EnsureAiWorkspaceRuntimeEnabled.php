<?php

namespace App\Http\Middleware;

use App\Services\AiWorkspace\AiWorkspaceRuntimeStatus;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAiWorkspaceRuntimeEnabled
{
    public function __construct(private readonly AiWorkspaceRuntimeStatus $runtimeStatus) {}

    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->runtimeStatus->enabled()) {
            return new JsonResponse([
                'message' => __('admin.ai_workspace.runtime_disabled_message'),
                'code' => 'ai_workspace_disabled',
            ], 503);
        }

        return $next($request);
    }
}
