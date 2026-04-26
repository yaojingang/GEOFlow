<?php

/**
 * 反向代理信任配置。
 *
 * 当系统部署在 Nginx、CDN、负载均衡或一级目录反向代理之后时，
 * 可通过 TRUSTED_PROXIES 配置信任代理来源，让 Laravel 识别
 * X-Forwarded-Proto、X-Forwarded-Host 和 X-Forwarded-Prefix。
 */
return [
    'proxies' => env('TRUSTED_PROXIES'),
];
