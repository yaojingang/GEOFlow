<?php

namespace App\Services\GeoFlow;

use App\Models\ArticleDistribution;
use App\Models\DistributionChannel;
use Illuminate\Http\Client\Response;
use RuntimeException;

class WordPressRestPublisher implements DistributionPublisherInterface
{
    public function __construct(
        private readonly WordPressRestRequestFactory $requestFactory,
        private readonly WordPressMediaSyncService $mediaSyncService,
        private readonly WordPressTaxonomySyncService $taxonomySyncService,
    ) {}

    public function health(DistributionChannel $channel): array
    {
        $indexResponse = $this->requestFactory->request($channel, 10)->get($channel->wordpressRestBaseUrl());
        $this->throwIfFailed($indexResponse, 'WordPress REST 入口检测');

        $response = $this->requestFactory->request($channel, 10)
            ->get($channel->wordpressRestBaseUrl().'/wp/v2/users/me', ['context' => 'edit']);
        $this->throwIfFailed($response, 'WordPress 健康检查');
        $user = $response->json();
        if (! is_array($user)) {
            $user = [];
        }
        $capabilities = is_array($user['capabilities'] ?? null) ? $user['capabilities'] : [];

        return [
            'ok' => true,
            'channel_type' => 'wordpress_rest',
            'rest_base_url' => $channel->wordpressRestBaseUrl(),
            'user_id' => (int) ($user['id'] ?? 0),
            'user_name' => (string) ($user['name'] ?? ''),
            'can_edit_posts' => (bool) ($capabilities['edit_posts'] ?? false),
            'can_publish_posts' => (bool) ($capabilities['publish_posts'] ?? false),
            'can_upload_files' => (bool) ($capabilities['upload_files'] ?? false),
        ];
    }

    public function publish(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $response = $this->requestFactory->request($channel)
            ->post($channel->wordpressRestBaseUrl().'/wp/v2/posts', $this->postPayload($channel, $payload));
        $this->throwIfFailed($response, 'WordPress 文章发布');

        return $this->postResult($response);
    }

    public function update(ArticleDistribution $distribution, array $payload): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $postId = $distribution->wordpressPostId();
        if (! $postId) {
            return $this->publish($distribution, $payload);
        }

        $response = $this->requestFactory->request($channel)
            ->post($channel->wordpressRestBaseUrl().'/wp/v2/posts/'.$postId, $this->postPayload($channel, $payload));
        $this->throwIfFailed($response, 'WordPress 文章更新');

        return $this->postResult($response);
    }

    public function delete(ArticleDistribution $distribution): array
    {
        $distribution->loadMissing('channel');
        $channel = $this->channel($distribution);
        $postId = $distribution->wordpressPostId();
        if (! $postId) {
            return [
                'deleted' => true,
                'remote_id' => null,
                'remote_url' => null,
                'message' => 'missing_remote_post_id',
            ];
        }

        $response = $this->requestFactory->request($channel)
            ->delete($channel->wordpressRestBaseUrl().'/wp/v2/posts/'.$postId, ['force' => false]);
        $this->throwIfFailed($response, 'WordPress 文章删除');

        return [
            'deleted' => true,
            'remote_id' => (string) $postId,
            'remote_url' => null,
        ];
    }

    public function syncSiteSettings(DistributionChannel $channel): array
    {
        $settings = $channel->resolvedSiteSettings();
        $payload = [
            'title' => $settings['site_name'],
            'description' => $settings['site_description'],
            'posts_per_page' => $settings['per_page'],
        ];

        $response = $this->requestFactory->request($channel)
            ->post($channel->wordpressRestBaseUrl().'/wp/v2/settings', $payload);
        $this->throwIfFailed($response, 'WordPress 站点设置同步');

        return [
            'ok' => true,
            'settings' => $payload,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function postPayload(DistributionChannel $channel, array $payload): array
    {
        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $config = $channel->resolvedChannelConfig();
        $contentHtml = (string) ($article['content_html'] ?? '');

        if ($config['wordpress_image_strategy'] === 'upload_to_media') {
            $contentHtml = $this->mediaSyncService->rewriteContentImages($channel, $payload, $contentHtml);
        }

        $postPayload = [
            'title' => (string) ($article['title'] ?? ''),
            'slug' => (string) ($article['slug'] ?? ''),
            'status' => (string) $config['wordpress_post_status'],
            'content' => $contentHtml,
            'excerpt' => (string) ($article['excerpt'] ?? ''),
        ];

        $categoryIds = $this->taxonomySyncService->categoryIds($channel, $payload);
        if ($categoryIds !== []) {
            $postPayload['categories'] = $categoryIds;
        }

        $tagIds = $this->taxonomySyncService->tagIds($channel, $payload);
        if ($tagIds !== []) {
            $postPayload['tags'] = $tagIds;
        }

        return $postPayload;
    }

    private function channel(ArticleDistribution $distribution): DistributionChannel
    {
        if (! $distribution->channel instanceof DistributionChannel) {
            throw new RuntimeException('分发记录缺少 WordPress 渠道。');
        }

        return $distribution->channel;
    }

    private function throwIfFailed(Response $response, string $operation): void
    {
        if (! $response->failed()) {
            return;
        }

        $body = html_entity_decode(strip_tags((string) $response->body()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $body = preg_replace('/\s+/', ' ', trim($body));
        $summary = is_string($body) && mb_strlen($body) > 300 ? mb_substr($body, 0, 300).'...' : (string) $body;

        throw new RuntimeException($operation.'失败：HTTP '.$response->status().($summary !== '' ? ' '.$summary : ''));
    }

    /**
     * @return array<string,mixed>
     */
    private function postResult(Response $response): array
    {
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('WordPress 返回内容不是有效 JSON。');
        }

        $postId = (int) ($json['id'] ?? 0);

        return [
            'remote_id' => $postId > 0 ? (string) $postId : '',
            'remote_url' => (string) ($json['link'] ?? ''),
            'remote_meta' => [
                'wordpress_post_id' => $postId,
            ],
        ];
    }
}
