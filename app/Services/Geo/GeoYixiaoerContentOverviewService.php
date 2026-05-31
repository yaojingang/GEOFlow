<?php

namespace App\Services\Geo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class GeoYixiaoerContentOverviewService
{
    /**
     * @return array{
     *     configured: bool,
     *     error: string|null,
     *     updated_at: string|null,
     *     total_size: int,
     *     filters: array{keyword:string,platform:string},
     *     stats: array{works:int,play:int,read:int,recommend:int,likes:int,comments:int,collects:int,shares:int,engagement:int},
     *     items: list<array<string,mixed>>,
     *     platform_filters: list<array{platform:string,works:int}>,
     *     platform_groups: list<array<string,mixed>>
     * }
     */
    public function summary(int $size = 10, string $keyword = '', string $platform = ''): array
    {
        if ($this->apiKey() === '') {
            return $this->emptySummary(false, '未配置 YIXIAOER_API_KEY', $keyword, $platform);
        }

        try {
            $response = $this->request()->get($this->apiUrl('/contents/overviews'), [
                'page' => 1,
                'size' => max(1, min($size, 50)),
            ]);

            if ($response->failed()) {
                return $this->emptySummary(true, '蚁小二作品数据获取失败：'.$response->body(), $keyword, $platform);
            }

            return $this->normalizeResponse((array) $response->json(), $keyword, $platform);
        } catch (Throwable $exception) {
            return $this->emptySummary(true, '蚁小二作品数据获取失败：'.$exception->getMessage(), $keyword, $platform);
        }
    }

    /**
     * @return array{
     *     configured: bool,
     *     error: string|null,
     *     updated_at: string|null,
     *     total_size: int,
     *     filters: array{keyword:string,platform:string},
     *     stats: array{works:int,play:int,read:int,recommend:int,likes:int,comments:int,collects:int,shares:int,engagement:int},
     *     items: list<array<string,mixed>>,
     *     platform_filters: list<array{platform:string,works:int}>,
     *     platform_groups: list<array<string,mixed>>
     * }
     */
    public function deferredSummary(string $keyword = '', string $platform = ''): array
    {
        if ($this->apiKey() === '') {
            return $this->emptySummary(false, '未配置 YIXIAOER_API_KEY', $keyword, $platform);
        }

        return $this->emptySummary(true, '点击加载后同步蚁小二作品数据', $keyword, $platform);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{
     *     configured: bool,
     *     error: string|null,
     *     updated_at: string|null,
     *     total_size: int,
     *     filters: array{keyword:string,platform:string},
     *     stats: array{works:int,play:int,read:int,recommend:int,likes:int,comments:int,collects:int,shares:int,engagement:int},
     *     items: list<array<string,mixed>>,
     *     platform_filters: list<array{platform:string,works:int}>,
     *     platform_groups: list<array<string,mixed>>
     * }
     */
    private function normalizeResponse(array $payload, string $keyword, string $platform): array
    {
        $data = data_get($payload, 'data', $payload);
        $rows = data_get($data, 'data');
        if (! is_array($rows) && is_array($data) && array_is_list($data)) {
            $rows = $data;
        }
        if (! is_array($rows)) {
            $rows = [];
        }

        $items = collect($rows)
            ->map(fn (mixed $row): array => $this->normalizeItem((array) $row))
            ->filter(fn (array $item): bool => $item['title'] !== '')
            ->when(trim($keyword) !== '', fn ($collection) => $collection->filter(fn (array $item): bool => $this->matchesKeyword($item, $keyword)))
            ->values();

        $platformFilters = $this->platformFilters($items->all());
        $itemList = $items
            ->when(trim($platform) !== '', fn ($collection) => $collection->filter(fn (array $item): bool => $item['platform'] === trim($platform)))
            ->values()
            ->all();
        $stats = $this->statsFor($itemList);

        $latestUpdatedAt = collect($itemList)->max('updated_at_ms');

        return [
            'configured' => true,
            'error' => null,
            'updated_at' => $latestUpdatedAt ? $this->formatTimestamp((int) $latestUpdatedAt) : null,
            'total_size' => (int) (data_get($data, 'totalSize') ?? count($items)),
            'filters' => [
                'keyword' => trim($keyword),
                'platform' => trim($platform),
            ],
            'stats' => $stats,
            'items' => $itemList,
            'platform_filters' => $platformFilters,
            'platform_groups' => $this->platformGroups($itemList),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function normalizeItem(array $row): array
    {
        $content = (array) ($row['contentData'] ?? []);
        $title = trim((string) ($content['title'] ?? $content['desc'] ?? ''));
        $type = trim((string) ($content['type'] ?? $row['type'] ?? ''));

        return [
            'id' => trim((string) ($content['id'] ?? '')),
            'account_name' => trim((string) ($row['accountName'] ?? '')) ?: '未命名账号',
            'platform' => $this->platformLabel($row, $content),
            'type' => $type,
            'type_label' => $this->typeLabel($type),
            'title' => $title,
            'date' => trim((string) ($content['date'] ?? '')),
            'url' => trim((string) ($content['pageUrl'] ?? '')),
            'play' => trim((string) ($content['play'] ?? '0')),
            'read' => trim((string) ($content['read'] ?? '0')),
            'recommend' => trim((string) ($content['reCommand'] ?? '0')),
            'like' => trim((string) ($content['great'] ?? $content['like'] ?? '0')),
            'comment' => trim((string) ($content['comment'] ?? '0')),
            'collect' => trim((string) ($content['collect'] ?? '0')),
            'share' => trim((string) ($content['share'] ?? '0')),
            'play_value' => $this->metricValue($content['play'] ?? 0),
            'read_value' => $this->metricValue($content['read'] ?? 0),
            'recommend_value' => $this->metricValue($content['reCommand'] ?? 0),
            'like_value' => $this->metricValue($content['great'] ?? $content['like'] ?? 0),
            'comment_value' => $this->metricValue($content['comment'] ?? 0),
            'collect_value' => $this->metricValue($content['collect'] ?? 0),
            'share_value' => $this->metricValue($content['share'] ?? 0),
            'updated_at_ms' => (int) ($row['updatedAt'] ?? 0),
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @param  array<string,mixed>  $content
     */
    private function platformLabel(array $row, array $content): string
    {
        $explicit = trim((string) ($row['platformName'] ?? $row['platform'] ?? ''));
        if ($explicit !== '') {
            return $explicit;
        }

        $source = mb_strtolower(implode(' ', [
            (string) ($content['pageUrl'] ?? ''),
            (string) ($content['cover'] ?? ''),
            (string) ($row['accountAvatar'] ?? ''),
        ]));

        return match (true) {
            str_contains($source, 'douyin') || str_contains($source, 'aweme') => '抖音',
            str_contains($source, 'toutiao') => '今日头条',
            str_contains($source, 'zhihu') => '知乎',
            str_contains($source, 'xiaohongshu') || str_contains($source, 'xhscdn') => '小红书',
            str_contains($source, 'bilibili') || str_contains($source, 'b23.tv') => 'B站',
            str_contains($source, 'kuaishou') => '快手',
            str_contains($source, 'weixin.qq.com') || str_contains($source, 'mp.weixin.qq.com') => '微信公众号',
            str_contains($source, 'baidu') || str_contains($source, 'bdstatic') => '百家号',
            str_contains($source, 'sohu') || str_contains($source, 'itc.cn') => '搜狐号',
            default => '未标注平台',
        };
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array{platform:string,works:int}>
     */
    private function platformFilters(array $items): array
    {
        return collect($items)
            ->groupBy('platform')
            ->map(fn ($platformItems, string $platform): array => [
                'platform' => $platform,
                'works' => $platformItems->count(),
            ])
            ->sortByDesc('works')
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array{works:int,play:int,read:int,recommend:int,likes:int,comments:int,collects:int,shares:int,engagement:int}
     */
    private function statsFor(array $items): array
    {
        $stats = [
            'works' => count($items),
            'play' => array_sum(array_column($items, 'play_value')),
            'read' => array_sum(array_column($items, 'read_value')),
            'recommend' => array_sum(array_column($items, 'recommend_value')),
            'likes' => array_sum(array_column($items, 'like_value')),
            'comments' => array_sum(array_column($items, 'comment_value')),
            'collects' => array_sum(array_column($items, 'collect_value')),
            'shares' => array_sum(array_column($items, 'share_value')),
            'engagement' => 0,
        ];
        $stats['engagement'] = $stats['likes'] + $stats['comments'] + $stats['collects'] + $stats['shares'];

        return $stats;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function platformGroups(array $items): array
    {
        return collect($items)
            ->groupBy('platform')
            ->map(function ($platformItems, string $platform): array {
                $platformItemList = $platformItems->values()->all();

                return [
                    'platform' => $platform,
                    'stats' => $this->statsFor($platformItemList),
                    'accounts' => $platformItems
                        ->groupBy('account_name')
                        ->map(function ($accountItems, string $accountName): array {
                            $accountItemList = $accountItems->values()->all();

                            return [
                                'account_name' => $accountName,
                                'stats' => $this->statsFor($accountItemList),
                                'items' => $accountItemList,
                            ];
                        })
                        ->sortByDesc(fn (array $account): int => (int) ($account['stats']['play'] ?? 0) + (int) ($account['stats']['read'] ?? 0))
                        ->values()
                        ->all(),
                ];
            })
            ->sortByDesc(fn (array $platform): int => (int) ($platform['stats']['play'] ?? 0) + (int) ($platform['stats']['read'] ?? 0))
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function matchesKeyword(array $item, string $keyword): bool
    {
        $needle = mb_strtolower(trim($keyword));
        if ($needle === '') {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', [
            (string) ($item['title'] ?? ''),
            (string) ($item['account_name'] ?? ''),
            (string) ($item['platform'] ?? ''),
            (string) ($item['type_label'] ?? ''),
            (string) ($item['date'] ?? ''),
        ]));

        return str_contains($haystack, $needle);
    }

    private function metricValue(mixed $value): int
    {
        $normalized = preg_replace('/[^\d-]/', '', (string) $value);
        if ($normalized === '' || $normalized === '-') {
            return 0;
        }

        return (int) $normalized;
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'article' => '文章',
            'video', 'miniVideo' => '视频',
            'dynamic' => '动态',
            default => $type !== '' ? $type : '作品',
        };
    }

    /**
     * @return array{
     *     configured: bool,
     *     error: string|null,
     *     updated_at: string|null,
     *     total_size: int,
     *     filters: array{keyword:string,platform:string},
     *     stats: array{works:int,play:int,read:int,recommend:int,likes:int,comments:int,collects:int,shares:int,engagement:int},
     *     items: list<array<string,mixed>>,
     *     platform_filters: list<array{platform:string,works:int}>,
     *     platform_groups: list<array<string,mixed>>
     * }
     */
    private function emptySummary(bool $configured, ?string $error, string $keyword = '', string $platform = ''): array
    {
        return [
            'configured' => $configured,
            'error' => $error,
            'updated_at' => null,
            'total_size' => 0,
            'filters' => [
                'keyword' => trim($keyword),
                'platform' => trim($platform),
            ],
            'stats' => [
                'works' => 0,
                'play' => 0,
                'read' => 0,
                'recommend' => 0,
                'likes' => 0,
                'comments' => 0,
                'collects' => 0,
                'shares' => 0,
                'engagement' => 0,
            ],
            'items' => [],
            'platform_filters' => [],
            'platform_groups' => [],
        ];
    }

    private function formatTimestamp(int $timestampMs): string
    {
        return Carbon::createFromTimestampMs($timestampMs)
            ->timezone(config('app.timezone', 'Asia/Shanghai'))
            ->format('Y-m-d H:i');
    }

    private function request(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => $this->apiKey(),
            'Content-Type' => 'application/json',
        ])
            ->acceptJson()
            ->timeout(8);
    }

    private function apiKey(): string
    {
        return trim((string) (config('services.yixiaoer.api_key') ?: env('YIXIAOER_API_KEY', '')));
    }

    private function apiUrl(string $path): string
    {
        $baseUrl = rtrim((string) (config('services.yixiaoer.api_url') ?: env('YIXIAOER_API_URL', 'https://www.yixiaoer.cn/api')), '/');

        return $baseUrl.'/'.ltrim($path, '/');
    }
}
