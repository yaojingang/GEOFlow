<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class RenderAiWorkspaceJsonErrors
{
    public static function responseFor(Throwable $exception): JsonResponse
    {
        if ($exception instanceof ModelNotFoundException
            || ($exception instanceof NotFoundHttpException && $exception->getPrevious() instanceof ModelNotFoundException)) {
            return new JsonResponse(['message' => '资源不存在。', 'code' => 'not_found'], 404);
        }

        if ($exception instanceof ValidationException) {
            return new JsonResponse([
                'message' => $exception->getMessage(),
                'code' => 'validation_failed',
                'errors' => $exception->errors(),
            ], 422);
        }

        if ($exception instanceof AuthorizationException) {
            return new JsonResponse(['message' => '当前管理员没有权限执行该操作。', 'code' => 'forbidden'], 403);
        }

        if ($exception instanceof HttpExceptionInterface) {
            return new JsonResponse([
                'message' => $exception->getMessage() !== '' ? $exception->getMessage() : Response::$statusTexts[$exception->getStatusCode()],
                'code' => 'http_error',
            ], $exception->getStatusCode(), $exception->getHeaders());
        }

        if ($exception::class === RuntimeException::class) {
            return new JsonResponse(['message' => $exception->getMessage(), 'code' => 'workflow_conflict'], 409);
        }

        return new JsonResponse(['message' => 'AI 工作台暂时不可用。', 'code' => 'internal_error'], 500);
    }
}
