<?php

/**
 * GEOFlow REST API 路由（Laravel 默认挂载在 /api 前缀下，本文件内为 v1 子路径）。
 *
 * 中间件：api.request_id 注入/透传 X-Request-Id；api.auth 校验 Bearer；
 * api.scope:* 校验 Sanctum token abilities。幂等写操作在控制器内按 route_key 处理。
 *
 * @see bak/api/v1/index.php 遗留单入口对照
 */

use App\Http\Controllers\Api\V1\ArticleController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrowserDeviceAuthorizationController;
use App\Http\Controllers\Api\V1\BrowserManualPublicationController;
use App\Http\Controllers\Api\V1\BrowserSessionController;
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\MaterialController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

// 实际路径形如：/api/v1/...
Route::prefix('v1')
    ->middleware(['api.request_id'])
    ->group(function (): void {
        // 公开：管理员登录，返回 API Token（无需 Bearer）
        Route::post('auth/login', [AuthController::class, 'login'])
            ->middleware('throttle:admin-login');

        Route::middleware(['browser.protocol'])
            ->prefix('browser-operations')
            ->group(function (): void {
                Route::post('device-authorizations', [BrowserDeviceAuthorizationController::class, 'store'])
                    ->middleware('throttle:5,1');
                Route::post('device-token', [BrowserDeviceAuthorizationController::class, 'token'])
                    ->middleware('throttle:30,1');
            });

        // 需有效 Token + 对应 scope
        Route::middleware(['api.auth'])->group(function (): void {
            Route::middleware(['browser.protocol', 'api.scope:browser-operations:read'])
                ->prefix('browser-operations')
                ->group(function (): void {
                    Route::get('session', [BrowserSessionController::class, 'show'])->middleware('throttle:120,1');
                    Route::delete('session', [BrowserSessionController::class, 'destroy'])
                        ->middleware(['api.scope:browser-operations:execute', 'throttle:30,1']);
                });

            Route::middleware(['browser.protocol', 'api.scope:browser-operations:read'])
                ->prefix('manual-publications')
                ->group(function (): void {
                    Route::get('/', [BrowserManualPublicationController::class, 'index'])->middleware('throttle:120,1');
                    Route::get('{manualPublicationId}', [BrowserManualPublicationController::class, 'show'])->whereNumber('manualPublicationId')->middleware('throttle:120,1');
                    Route::middleware('api.scope:browser-operations:execute')->group(function (): void {
                        Route::middleware('throttle:30,1')->group(function (): void {
                            Route::post('{manualPublicationId}/claim', [BrowserManualPublicationController::class, 'claim'])->whereNumber('manualPublicationId');
                            Route::post('{manualPublicationId}/heartbeat', [BrowserManualPublicationController::class, 'heartbeat'])->whereNumber('manualPublicationId');
                            Route::post('{manualPublicationId}/release', [BrowserManualPublicationController::class, 'release'])->whereNumber('manualPublicationId');
                            Route::post('{manualPublicationId}/receipt', [BrowserManualPublicationController::class, 'receipt'])->whereNumber('manualPublicationId');
                        });
                    });
                });
            // catalog:read — 下拉元数据（模型、提示词、库、作者、分类等）
            Route::get('catalog', [CatalogController::class, 'show'])->middleware('api.scope:catalog:read');

            // tasks:* — 任务 CRUD、启停、入队、子 Job 列表
            Route::get('tasks', [TaskController::class, 'index'])->middleware('api.scope:tasks:read');
            Route::post('tasks', [TaskController::class, 'store'])->middleware('api.scope:tasks:write');
            Route::get('tasks/{task}', [TaskController::class, 'show'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:read');
            Route::patch('tasks/{task}', [TaskController::class, 'update'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::delete('tasks/{task}', [TaskController::class, 'destroy'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::post('tasks/{task}/start', [TaskController::class, 'start'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::post('tasks/{task}/stop', [TaskController::class, 'stop'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::post('tasks/{task}/enqueue', [TaskController::class, 'enqueue'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:write');
            Route::get('tasks/{task}/jobs', [TaskController::class, 'jobs'])
                ->whereNumber('task')
                ->middleware('api.scope:tasks:read');

            // jobs:read — 单条 task_runs 执行记录
            Route::get('jobs/{job}', [JobController::class, 'show'])
                ->whereNumber('job')
                ->middleware('api.scope:jobs:read');

            // materials:* — 后台素材库 CRUD 与库内条目管理
            Route::get('materials', [MaterialController::class, 'summary'])->middleware('api.scope:materials:read');
            Route::get('materials/{type}', [MaterialController::class, 'index'])->middleware('api.scope:materials:read');
            Route::post('materials/{type}', [MaterialController::class, 'store'])->middleware('api.scope:materials:write');
            Route::get('materials/{type}/{id}', [MaterialController::class, 'show'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:read');
            Route::patch('materials/{type}/{id}', [MaterialController::class, 'update'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');
            Route::delete('materials/{type}/{id}', [MaterialController::class, 'destroy'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');
            Route::get('materials/{type}/{id}/items', [MaterialController::class, 'items'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:read');
            Route::post('materials/{type}/{id}/items', [MaterialController::class, 'storeItem'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');
            Route::delete('materials/{type}/{id}/items', [MaterialController::class, 'destroyItems'])
                ->whereNumber('id')
                ->middleware('api.scope:materials:write');

            // articles:* — 文章 CRUD、审核、发布、软删
            Route::get('articles', [ArticleController::class, 'index'])->middleware('api.scope:articles:read');
            Route::post('articles', [ArticleController::class, 'store'])
                ->middleware(['api.scope:articles:write', 'throttle:60,1']);
            Route::get('articles/{article}', [ArticleController::class, 'show'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:read');
            Route::get('articles/{article}/ai-quality/status', [ArticleController::class, 'aiQualityStatus'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:read', 'throttle:120,1']);
            Route::patch('articles/{article}', [ArticleController::class, 'update'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:write', 'throttle:60,1']);
            Route::post('articles/{article}/review', [ArticleController::class, 'review'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:publish', 'throttle:60,1']);
            Route::post('articles/{article}/publish', [ArticleController::class, 'publish'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:publish', 'throttle:60,1']);
            Route::post('articles/{article}/ai-quality/recheck', [ArticleController::class, 'recheckAiQuality'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:publish', 'throttle:api-ai-quality-manual']);
            Route::post('articles/{article}/ai-quality/override', [ArticleController::class, 'overrideAiQuality'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:publish', 'throttle:30,1']);
            Route::post('articles/{article}/ai-quality/optimization', [ArticleController::class, 'startAiOptimization'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:publish', 'throttle:api-ai-quality-manual'])
                ->name('api.v1.articles.ai-quality.optimization.store');
            Route::get('articles/{article}/ai-quality/optimization/candidate', [ArticleController::class, 'latestAiOptimizationCandidate'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:read', 'throttle:120,1'])
                ->name('api.v1.articles.ai-quality.optimization.latest-candidate');
            Route::get('articles/{article}/ai-quality/optimization/{run}/candidate', [ArticleController::class, 'aiOptimizationCandidate'])
                ->whereNumber(['article', 'run'])
                ->middleware(['api.scope:articles:read', 'throttle:120,1'])
                ->name('api.v1.articles.ai-quality.optimization.candidate');
            Route::post('articles/{article}/ai-quality/optimization/{run}/apply', [ArticleController::class, 'applyAiOptimization'])
                ->whereNumber(['article', 'run'])
                ->middleware(['api.scope:articles:publish', 'throttle:api-ai-quality-manual'])
                ->name('api.v1.articles.ai-quality.optimization.apply');
            Route::post('articles/{article}/ai-quality/optimization/{run}/cancel', [ArticleController::class, 'cancelAiOptimization'])
                ->whereNumber(['article', 'run'])
                ->middleware(['api.scope:articles:publish', 'throttle:api-ai-quality-manual'])
                ->name('api.v1.articles.ai-quality.optimization.cancel');
            Route::post('articles/{article}/ai-quality/optimization/{run}/rollback', [ArticleController::class, 'rollbackAiOptimization'])
                ->whereNumber(['article', 'run'])
                ->middleware(['api.scope:articles:publish', 'throttle:api-ai-quality-manual'])
                ->name('api.v1.articles.ai-quality.optimization.rollback');
            Route::post('articles/{article}/trash', [ArticleController::class, 'trash'])
                ->whereNumber('article')
                ->middleware(['api.scope:articles:write', 'throttle:60,1']);
        });
    });
