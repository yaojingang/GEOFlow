<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

class WordPressTaxonomySyncService
{
    public function __construct(private readonly WordPressRestRequestFactory $requestFactory) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return list<int>
     */
    public function categoryIds(DistributionChannel $channel, array $payload): array
    {
        $config = $channel->resolvedChannelConfig();
        $strategy = (string) $config['wordpress_category_strategy'];
        if ($strategy === 'fixed') {
            $fixedCategory = trim((string) $config['wordpress_fixed_category']);
            if ($fixedCategory === '') {
                return [];
            }
            if (ctype_digit($fixedCategory)) {
                return [(int) $fixedCategory];
            }

            $slug = $this->slug($fixedCategory);
            $matchedId = $this->findTermId($channel, 'categories', $slug);

            return $matchedId !== null ? [$matchedId] : array_values(array_filter([
                $this->createTermId($channel, 'categories', $fixedCategory, $slug),
            ]));
        }

        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $category = is_array($article['category'] ?? null) ? $article['category'] : [];
        $name = trim((string) ($category['name'] ?? ''));
        if ($name === '') {
            return [];
        }

        $slug = trim((string) ($category['slug'] ?? ''));
        $slug = $slug !== '' ? $this->slug($slug) : $this->slug($name);
        $matchedId = $this->findTermId($channel, 'categories', $slug);
        if ($matchedId !== null) {
            return [$matchedId];
        }

        if ($strategy === 'match_only') {
            return [];
        }

        $createdId = $this->createTermId($channel, 'categories', $name, $slug);

        return $createdId !== null ? [$createdId] : [];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<int>
     */
    public function tagIds(DistributionChannel $channel, array $payload): array
    {
        $config = $channel->resolvedChannelConfig();
        if ($config['wordpress_tag_strategy'] === 'disabled') {
            return [];
        }

        $article = is_array($payload['article'] ?? null) ? $payload['article'] : [];
        $keywords = (string) ($article['keywords'] ?? '');
        $names = preg_split('/[,，;；\n]+/u', $keywords) ?: [];
        $ids = [];
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }

            $slug = $this->slug($name);
            $matchedId = $this->findTermId($channel, 'tags', $slug);
            $ids[] = $matchedId ?? $this->createTermId($channel, 'tags', $name, $slug);
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($id): ?int => is_numeric($id) ? (int) $id : null,
            $ids
        ))));
    }

    private function findTermId(DistributionChannel $channel, string $taxonomy, string $slug): ?int
    {
        $response = $this->requestFactory->request($channel)
            ->get($channel->wordpressRestBaseUrl().'/wp/v2/'.$taxonomy, ['slug' => $slug]);
        $this->throwIfFailed($response, 'WordPress 分类/标签查询');
        $json = $response->json();
        if (! is_array($json)) {
            return null;
        }

        $first = $json[0] ?? null;

        return is_array($first) && is_numeric($first['id'] ?? null) ? (int) $first['id'] : null;
    }

    private function createTermId(DistributionChannel $channel, string $taxonomy, string $name, string $slug): ?int
    {
        $response = $this->requestFactory->request($channel)
            ->post($channel->wordpressRestBaseUrl().'/wp/v2/'.$taxonomy, [
                'name' => $name,
                'slug' => $slug,
            ]);
        $this->throwIfFailed($response, 'WordPress 分类/标签创建');
        $json = $response->json();

        return is_array($json) && is_numeric($json['id'] ?? null) ? (int) $json['id'] : null;
    }

    private function slug(string $value): string
    {
        $slug = Str::slug($value);

        return $slug !== '' ? $slug : substr(hash('sha256', $value), 0, 12);
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
}
