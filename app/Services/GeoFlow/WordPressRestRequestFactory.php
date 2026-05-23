<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class WordPressRestRequestFactory
{
    public function __construct(private readonly ApiKeyCrypto $apiKeyCrypto) {}

    public function request(DistributionChannel $channel, int $timeout = 30): PendingRequest
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

        return Http::timeout($timeout)
            ->acceptJson()
            ->asJson()
            ->withBasicAuth($username, $applicationPassword);
    }
}
