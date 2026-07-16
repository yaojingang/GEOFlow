<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use Illuminate\Http\Client\Response;
use RuntimeException;

class GenericHttpApiPublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly GenericHttpRequestFactory $requestFactory,
        private readonly GenericHttpEndpointResolver $endpointResolver,
        private readonly GenericHttpResponseMapper $responseMapper,
    ) {}

    public function health(DistributionChannel $channel): array
    {
        $config = $channel->resolvedGenericHttpConfig();
        $result = $this->sendChannelRequest(
            $channel,
            (string) $config['generic_health_method'],
            (string) $config['generic_health_path'],
            [
                'version' => '1.0',
                'source' => 'geoflow',
                'event' => 'health.check',
                'channel' => [
                    'id' => (int) $channel->id,
                    'name' => (string) $channel->name,
                    'domain' => (string) $channel->domain,
                ],
            ],
            'health.check',
            'channel-'.(int) $channel->id.'-health-v1',
            '通用 API 健康检查'
        );

        return [
            'ok' => true,
            'channel_type' => 'generic_http_api',
            'endpoint' => $result['endpoint'],
            'status_code' => $result['status_code'],
            'response' => $result['json'],
        ];
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        return $this->articleRequest($distribution, $payload, 'publish', 'article.publish', '通用 API 文章发布');
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        return $this->articleRequest($distribution, $payload, 'update', 'article.update', '通用 API 文章更新');
    }

    public function delete(ArticleDistribution $distribution): array
    {
        $distribution->loadMissing(['article', 'channel']);
        $channel = $this->channel($distribution);
        $article = $distribution->article;
        $payload = [
            'version' => '1.0',
            'source' => 'geoflow',
            'event' => 'article.delete',
            'article' => [
                'id' => (int) ($article?->id ?? $distribution->article_id),
                'slug' => (string) ($article?->slug ?? ''),
                'title' => (string) ($article?->title ?? ''),
            ],
            'remote' => [
                'id' => (string) ($distribution->remote_id ?? ''),
                'url' => (string) ($distribution->remote_url ?? ''),
            ],
        ];

        $config = $channel->resolvedGenericHttpConfig();
        $result = $this->sendDistributionRequest(
            $channel,
            $distribution,
            (string) $config['generic_delete_method'],
            (string) $config['generic_delete_path'],
            $payload,
            'article.delete',
            '通用 API 文章删除'
        );
        $mapped = $this->responseMapper->map($result['json'], $config);

        return [
            'deleted' => true,
            'remote_id' => $mapped['remote_id'] !== '' ? $mapped['remote_id'] : (string) ($distribution->remote_id ?? ''),
            'remote_url' => null,
            'remote_meta' => array_replace($mapped['remote_meta'], [
                'generic_http' => $this->transportMeta($result),
            ]),
        ];
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        $config = $channel->resolvedGenericHttpConfig();
        $path = trim((string) $config['generic_settings_path']);
        if ($path === '') {
            return [
                'ok' => true,
                'skipped' => true,
                'reason' => 'generic_settings_path_empty',
            ];
        }

        $result = $this->sendChannelRequest(
            $channel,
            (string) $config['generic_settings_method'],
            $path,
            [
                'version' => '1.0',
                'source' => 'geoflow',
                'event' => 'site.settings.update',
                'settings' => $channel->targetSiteSettingsPayload(),
            ],
            'site.settings.update',
            'channel-'.(int) $channel->id.'-settings-v1',
            '通用 API 站点设置同步'
        );

        return [
            'ok' => true,
            'endpoint' => $result['endpoint'],
            'status_code' => $result['status_code'],
            'response' => $result['json'],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function articleRequest(
        ArticleDistribution $distribution,
        array $payload,
        string $operation,
        string $event,
        string $operationLabel
    ): array {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $config = $channel->resolvedGenericHttpConfig();
        $method = (string) $config['generic_'.$operation.'_method'];
        $path = (string) $config['generic_'.$operation.'_path'];
        $payload['event'] = $event;

        $result = $this->sendDistributionRequest($channel, $distribution, $method, $path, $payload, $event, $operationLabel);
        $mapped = $this->responseMapper->map($result['json'], $config);

        return [
            'remote_id' => $mapped['remote_id'] !== '' ? $mapped['remote_id'] : (string) ($distribution->remote_id ?? ''),
            'remote_url' => $mapped['remote_url'] !== '' ? $mapped['remote_url'] : (string) ($distribution->remote_url ?? ''),
            'remote_meta' => array_replace($mapped['remote_meta'], [
                'generic_http' => $this->transportMeta($result),
            ]),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{endpoint:string,status_code:int,json:array<string,mixed>,method:string}
     */
    private function sendDistributionRequest(
        DistributionChannel $channel,
        ArticleDistribution $distribution,
        string $method,
        string $path,
        array $payload,
        string $event,
        string $operationLabel
    ): array {
        return $this->sendRequest(
            $channel,
            $method,
            $this->endpointResolver->resolve($channel, $distribution, $path),
            $payload,
            $event,
            (string) ($distribution->idempotency_key ?: 'distribution-'.(int) $distribution->id.'-'.$event),
            $operationLabel
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{endpoint:string,status_code:int,json:array<string,mixed>,method:string}
     */
    private function sendChannelRequest(
        DistributionChannel $channel,
        string $method,
        string $path,
        array $payload,
        string $event,
        string $idempotencyKey,
        string $operationLabel
    ): array {
        return $this->sendRequest(
            $channel,
            $method,
            $this->endpointResolver->resolveChannelPath($channel, $path),
            $payload,
            $event,
            $idempotencyKey,
            $operationLabel
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{endpoint:string,status_code:int,json:array<string,mixed>,method:string}
     */
    private function sendRequest(
        DistributionChannel $channel,
        string $method,
        string $endpoint,
        array $payload,
        string $event,
        string $idempotencyKey,
        string $operationLabel
    ): array {
        $config = $channel->resolvedGenericHttpConfig();
        $bodyPayload = $config['generic_payload_wrapper'] === 'data' ? ['data' => $payload] : $payload;
        $body = json_encode($bodyPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $body = is_string($body) ? $body : '{}';
        $method = strtoupper($method);

        $wireBody = $method === 'GET' ? '' : $body;
        $request = $this->requestFactory->request($channel, $config, $method, $endpoint, $wireBody, $event, $idempotencyKey);
        $response = $method === 'GET'
            ? $request->send($method, $endpoint)
            : $request->withBody($wireBody, 'application/json')->send($method, $endpoint);

        $this->markSecretUsed($channel, $config);
        $this->throwIfUnexpectedStatus($response, $operationLabel, $config);

        return [
            'endpoint' => $endpoint,
            'status_code' => $response->status(),
            'json' => $this->json($response),
            'method' => $method,
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function throwIfUnexpectedStatus(Response $response, string $operationLabel, array $config): void
    {
        if (in_array($response->status(), $config['generic_success_statuses'], true)) {
            return;
        }

        throw new RuntimeException($operationLabel.'失败：HTTP '.$response->status());
    }

    /**
     * @return array<string,mixed>
     */
    private function json(Response $response): array
    {
        if ($response->status() === 204 || trim((string) $response->body()) === '') {
            return [];
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  array{endpoint:string,status_code:int,json:array<string,mixed>,method:string}  $result
     * @return array<string,mixed>
     */
    private function transportMeta(array $result): array
    {
        return [
            'endpoint' => $result['endpoint'],
            'method' => $result['method'],
            'status_code' => $result['status_code'],
        ];
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function markSecretUsed(DistributionChannel $channel, array $config): void
    {
        if (($config['generic_auth_type'] ?? 'bearer') === 'none') {
            return;
        }

        $channel->loadMissing('activeSecret');
        if ($channel->activeSecret) {
            $channel->activeSecret->forceFill(['last_used_at' => now()])->save();
        }
    }

    private function channel(ArticleDistribution $distribution): DistributionChannel
    {
        if (! $distribution->channel instanceof DistributionChannel) {
            throw new RuntimeException('分发记录缺少通用 API 渠道。');
        }

        return $distribution->channel;
    }
}
