<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use RuntimeException;

class GenericHttpEndpointResolver
{
    public function resolve(DistributionChannel $channel, ArticleDistribution $distribution, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('通用 API 请求路径不能为空。');
        }

        $distribution->loadMissing('article');
        $article = $distribution->article;
        $remoteId = trim((string) ($distribution->remote_id ?? ''));
        $replacements = [
            '{article_id}' => (string) ($article?->id ?? $distribution->article_id ?? ''),
            '{slug}' => rawurlencode((string) ($article?->slug ?? '')),
            '{remote_id}' => rawurlencode($remoteId),
            '{channel_id}' => (string) $channel->id,
        ];

        foreach ($replacements as $token => $value) {
            if (str_contains($path, $token) && $value === '') {
                throw new RuntimeException('通用 API 请求路径缺少变量 '.$token.' 的可用值。');
            }
        }

        $resolvedPath = strtr($path, $replacements);
        $baseUrl = rtrim((string) $channel->endpoint_url, '/');
        if ($baseUrl === '') {
            throw new RuntimeException('通用 API 基础地址不能为空。');
        }

        return $baseUrl.(str_starts_with($resolvedPath, '/') ? $resolvedPath : '/'.$resolvedPath);
    }

    public function resolveChannelPath(DistributionChannel $channel, string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            throw new RuntimeException('通用 API 请求路径不能为空。');
        }

        $resolvedPath = strtr($path, [
            '{channel_id}' => (string) $channel->id,
        ]);
        if (preg_match('/\{[^}]+\}/', $resolvedPath) === 1) {
            throw new RuntimeException('通用 API 渠道级请求路径不能包含文章变量。');
        }

        $baseUrl = rtrim((string) $channel->endpoint_url, '/');
        if ($baseUrl === '') {
            throw new RuntimeException('通用 API 基础地址不能为空。');
        }

        return $baseUrl.(str_starts_with($resolvedPath, '/') ? $resolvedPath : '/'.$resolvedPath);
    }
}
