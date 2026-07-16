<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use App\Models\DistributionChannelSecret;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SafeOutboundRequest;
use App\Support\GeoFlow\ApiKeyCrypto;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GenericHttpRequestFactory
{
    public function __construct(
        private readonly ApiKeyCrypto $apiKeyCrypto,
        private readonly SafeOutboundHttpClient $safeHttp,
    ) {}

    /**
     * @param  array<string,mixed>  $config
     */
    public function request(
        DistributionChannel $channel,
        array $config,
        string $method,
        string $endpoint,
        string $body,
        string $event,
        string $idempotencyKey
    ): SafeOutboundRequest {
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'GEOFlow/2.0 Generic API Publisher',
            'X-GEOFlow-Event' => $event,
            'X-GEOFlow-Idempotency-Key' => $idempotencyKey,
            'X-GEOFlow-Payload-SHA256' => hash('sha256', $body),
        ];

        $request = Http::timeout((int) $config['generic_timeout_seconds'])
            ->withHeaders($headers);

        $authType = (string) $config['generic_auth_type'];
        if ($authType === 'none') {
            return $this->safeRequest($request);
        }

        [$keyId, $secret] = $this->activeSecret($channel);

        $request = match ($authType) {
            'bearer' => $request->withToken($secret),
            'basic' => $this->withBasicAuth($request, (string) $config['generic_basic_username'], $secret),
            'header_key' => $request->withHeaders([(string) $config['generic_header_name'] => $secret]),
            'hmac' => $request->withHeaders($this->hmacHeaders($config, $keyId, $secret, $method, $endpoint, $body)),
            default => throw new RuntimeException('不支持的通用 API 鉴权方式：'.$authType),
        };

        return $this->safeRequest($request);
    }

    private function safeRequest(PendingRequest $request): SafeOutboundRequest
    {
        return new SafeOutboundRequest(
            $this->safeHttp,
            $request->connectTimeout(5),
            (int) config('geoflow.outbound_json_max_bytes', 4 * 1024 * 1024),
        );
    }

    /**
     * @return array{0:string,1:string}
     */
    private function activeSecret(DistributionChannel $channel): array
    {
        $channel->loadMissing('activeSecret');
        $secret = $channel->activeSecret;
        if (! $secret instanceof DistributionChannelSecret) {
            throw new RuntimeException('通用 API 渠道缺少有效密钥。');
        }

        $plainSecret = $this->apiKeyCrypto->decrypt((string) $secret->secret_ciphertext);
        if ($plainSecret === '') {
            throw new RuntimeException('通用 API 密钥解密失败。');
        }

        return [(string) $secret->key_id, $plainSecret];
    }

    private function withBasicAuth(PendingRequest $request, string $username, string $secret): PendingRequest
    {
        if (trim($username) === '') {
            throw new RuntimeException('通用 API Basic 鉴权缺少用户名。');
        }

        return $request->withBasicAuth($username, $secret);
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,string>
     */
    private function hmacHeaders(
        array $config,
        string $keyId,
        string $secret,
        string $method,
        string $endpoint,
        string $body
    ): array {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $bodyHash = hash('sha256', $body);
        $path = $this->requestTarget($endpoint);
        $canonical = strtoupper($method)."\n".$path."\n".$timestamp."\n".$nonce."\n".$bodyHash;

        return [
            (string) $config['generic_hmac_key_id_header'] => $keyId,
            (string) $config['generic_hmac_signature_header'] => hash_hmac('sha256', $canonical, $secret),
            (string) $config['generic_hmac_timestamp_header'] => $timestamp,
            (string) $config['generic_hmac_nonce_header'] => $nonce,
            (string) $config['generic_hmac_body_hash_header'] => $bodyHash,
        ];
    }

    private function requestTarget(string $endpoint): string
    {
        $path = parse_url($endpoint, PHP_URL_PATH);
        $query = parse_url($endpoint, PHP_URL_QUERY);
        $target = is_string($path) && $path !== '' ? $path : '/';

        return is_string($query) && $query !== '' ? $target.'?'.$query : $target;
    }
}
