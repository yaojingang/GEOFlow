<?php

namespace App\Http\Middleware;

use App\Services\AiWorkspace\AiWorkspaceModelReadiness;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureAiWorkspaceRuntimeEnabled
{
    public function __construct(private readonly AiWorkspaceModelReadiness $readiness) {}

    /** @param Closure(Request):Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('ai-workspace.runtime_enabled', false)) {
            return new JsonResponse([
                'message' => 'AI 工作台运行时尚未开放。',
                'code' => 'ai_workspace_disabled',
            ], 503);
        }

        $status = $this->readiness->status();
        if (! $status['ready']) {
            return response()->json([
                'message' => $status['reason'],
                'code' => 'ai_workspace_model_unavailable',
            ], 503);
        }

        return $next($request);
    }
}
