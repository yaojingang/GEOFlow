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
use App\Http\Controllers\Api\V1\CatalogController;
use App\Http\Controllers\Api\V1\JobController;
use App\Http\Controllers\Api\V1\TaskController;
use Illuminate\Support\Facades\Route;

// 实际路径形如：/api/v1/...
Route::prefix('v1')
    ->middleware(['api.request_id'])
    ->group(function (): void {
        // 公开：管理员登录，返回 API Token（无需 Bearer）
        Route::post('auth/login', [AuthController::class, 'login']);

        // 需有效 Token + 对应 scope
        Route::middleware(['api.auth'])->group(function (): void {
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

            // articles:* — 文章 CRUD、审核、发布、软删
            Route::get('articles', [ArticleController::class, 'index'])->middleware('api.scope:articles:read');
            Route::post('articles', [ArticleController::class, 'store'])->middleware('api.scope:articles:write');
            Route::get('articles/{article}', [ArticleController::class, 'show'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:read');
            Route::patch('articles/{article}', [ArticleController::class, 'update'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:write');
            Route::post('articles/{article}/review', [ArticleController::class, 'review'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:publish');
            Route::post('articles/{article}/publish', [ArticleController::class, 'publish'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:publish');
            Route::post('articles/{article}/trash', [ArticleController::class, 'trash'])
                ->whereNumber('article')
                ->middleware('api.scope:articles:write');
        });
    });
