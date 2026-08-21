<?php

namespace App\Console\GeoFlowCli;

class OperationRegistry
{
    /**
     * @return array<string,array{name:string,method:string,path:string,auth:bool,idempotent:bool}>
     */
    public static function all(): array
    {
        return [
            'auth.login' => self::operation('auth.login', 'POST', 'auth/login', false),
            'catalog' => self::operation('catalog', 'GET', 'catalog'),
            'task.list' => self::operation('task.list', 'GET', 'tasks'),
            'task.create' => self::operation('task.create', 'POST', 'tasks', idempotent: true),
            'task.get' => self::operation('task.get', 'GET', 'tasks/{task}'),
            'task.update' => self::operation('task.update', 'PATCH', 'tasks/{task}', idempotent: true),
            'task.delete' => self::operation('task.delete', 'DELETE', 'tasks/{task}'),
            'task.start' => self::operation('task.start', 'POST', 'tasks/{task}/start', idempotent: true),
            'task.stop' => self::operation('task.stop', 'POST', 'tasks/{task}/stop', idempotent: true),
            'task.enqueue' => self::operation('task.enqueue', 'POST', 'tasks/{task}/enqueue', idempotent: true),
            'task.jobs' => self::operation('task.jobs', 'GET', 'tasks/{task}/jobs'),
            'job.get' => self::operation('job.get', 'GET', 'jobs/{job}'),
            'material.summary' => self::operation('material.summary', 'GET', 'materials'),
            'material.list' => self::operation('material.list', 'GET', 'materials/{type}'),
            'material.create' => self::operation('material.create', 'POST', 'materials/{type}', idempotent: true),
            'material.get' => self::operation('material.get', 'GET', 'materials/{type}/{id}'),
            'material.update' => self::operation('material.update', 'PATCH', 'materials/{type}/{id}', idempotent: true),
            'material.delete' => self::operation('material.delete', 'DELETE', 'materials/{type}/{id}'),
            'material.item-list' => self::operation('material.item-list', 'GET', 'materials/{type}/{id}/items'),
            'material.item-create' => self::operation('material.item-create', 'POST', 'materials/{type}/{id}/items', idempotent: true),
            'material.item-upload' => self::operation('material.item-upload', 'POST', 'materials/{type}/{id}/items', idempotent: true),
            'material.item-delete' => self::operation('material.item-delete', 'DELETE', 'materials/{type}/{id}/items'),
            'article.list' => self::operation('article.list', 'GET', 'articles'),
            'article.create' => self::operation('article.create', 'POST', 'articles', idempotent: true),
            'article.get' => self::operation('article.get', 'GET', 'articles/{article}'),
            'article.update' => self::operation('article.update', 'PATCH', 'articles/{article}', idempotent: true),
            'article.review' => self::operation('article.review', 'POST', 'articles/{article}/review', idempotent: true),
            'article.publish' => self::operation('article.publish', 'POST', 'articles/{article}/publish', idempotent: true),
            'article.trash' => self::operation('article.trash', 'POST', 'articles/{article}/trash', idempotent: true),
        ];
    }

    /** @return array{name:string,method:string,path:string,auth:bool,idempotent:bool} */
    public static function get(string $name): array
    {
        $operation = self::all()[$name] ?? null;
        if ($operation === null) {
            throw new CliException("未知 CLI API 操作: {$name}");
        }

        return $operation;
    }

    /** @return list<string> */
    public static function routeSignatures(): array
    {
        $signatures = [];
        foreach (self::all() as $operation) {
            $signatures[$operation['method'].' '.$operation['path']] = true;
        }

        $signatures = array_keys($signatures);
        sort($signatures);

        return $signatures;
    }

    /** @return array{name:string,method:string,path:string,auth:bool,idempotent:bool} */
    private static function operation(
        string $name,
        string $method,
        string $path,
        bool $auth = true,
        bool $idempotent = false,
    ): array {
        return compact('name', 'method', 'path', 'auth', 'idempotent');
    }
}
