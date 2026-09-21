<?php

/**
 * 磁盘与云存储挂载（local/s3 等），上传目录可在此扩展。
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            // SAE 首期可以把该目录放在 NAS，保留现有代码依赖的 POSIX path 语义。
            'root' => env('FILESYSTEM_LOCAL_ROOT', storage_path('app/private')),
            'serve' => true,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            // 与 local 分开配置，避免把 OSS 当成本地 rename/ZipArchive 盘。
            'root' => env('FILESYSTEM_PUBLIC_ROOT', storage_path('app/public')),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        'nas' => [
            'driver' => 'local',
            'root' => env('NAS_STORAGE_ROOT', storage_path('app')),
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

        // 阿里云 OSS 使用 S3-compatible driver；保留 s3 作为 Laravel 兼容别名。
        // 只有完成对应业务路径的对象化适配后，才把某个业务切换到该 disk。
        'oss' => [
            'driver' => 's3',
            'key' => env('OSS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('OSS_ACCESS_KEY_SECRET', env('AWS_SECRET_ACCESS_KEY')),
            'region' => env('OSS_REGION', env('AWS_DEFAULT_REGION')),
            'bucket' => env('OSS_BUCKET', env('AWS_BUCKET')),
            'url' => env('OSS_URL', env('AWS_URL')),
            'endpoint' => env('OSS_ENDPOINT', env('AWS_ENDPOINT')),
            'use_path_style_endpoint' => env('OSS_USE_PATH_STYLE_ENDPOINT', env('AWS_USE_PATH_STYLE_ENDPOINT', false)),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
