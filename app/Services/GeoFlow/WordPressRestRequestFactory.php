<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SafeOutboundRequest;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WordPressRestRequestFactory
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly SafeOutboundHttpClient $safeHttp,
    ) {}

    public function request(DistributionChannel $channel, int $timeout = 30): SafeOutboundRequest
    {
        $channel->loadMissing('activeSecret');
        $config = $channel->resolvedChannelConfig();
        $username = (string) $config['wordpress_username'];
        $secret = $channel->activeSecret;
        if (! $secret instanceof DistributionChannelSecret || $username === '') {
            throw new RuntimeException('WordPress 渠道缺少用户名或 Application Password。');
        }

        $applicationPassword = $this->apiKeyCrypto->decrypt((string) $secret->secret_ciphertext);
        if ($applicationPassword === '') {
            throw new RuntimeException('WordPress Application Password 解密失败。');
        }

        $request = Http::timeout($timeout)
            ->connectTimeout(5)
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($username, $applicationPassword);

        return new SafeOutboundRequest(
            $this->safeHttp,
            $request,
            (int) config('geoflow.outbound_json_max_bytes', 4 * 1024 * 1024),
        );
    }
}
