<?php

namespace App\Services\Geo;

use App\Models\GeoArticleDraft;
use App\Models\GeoPublishRecord;
use App\Models\GeoPublishTarget;
use App\Support\Site\ArticleHtmlPresenter;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class GeoYixiaoerDistributionService
{
    private const PLATFORM_LABELS = [
        'weixingongzhonghao' => '微信公众号',
    ];

    public function __construct(private readonly GeoArticlePublishPackageExporter $packageExporter) {}

    /**
     * @param  list<string>  $platformCodes
     */
    public function submitOfficialAccountArticleDraft(GeoArticleDraft $draft, array $platformCodes): GeoPublishRecord
    {
        $draft->loadMissing(['organization', 'writingTask']);
        $platformCodes = $this->normalizePlatformCodes($platformCodes);
        if ($platformCodes === []) {
            throw new InvalidArgumentException('当前文章只允许提交微信公众号草稿，请先选择微信公众号');
        }

        $accounts = $this->activeAccounts($platformCodes);
        if ($accounts === []) {
            throw new InvalidArgumentException('微信公众号账号未登录或授权已失效，请先在蚁小二登录公众号账号后再提交');
        }

        $package = $this->ensurePublishPackage($draft);
        $manifest = $this->readManifest($package);
        $uploadedImages = $this->uploadImages((array) ($manifest['images'] ?? []));
        if ($uploadedImages === []) {
            throw new InvalidArgumentException('发布包缺少可上传图片，请先植入配图并导出发布包');
        }

        $publishPayload = $this->publishPayload($draft, $accounts, $uploadedImages);
        $response = $this->request()->post($this->apiUrl('/taskSets/v2'), $publishPayload);
        if ($response->failed()) {
            throw new RuntimeException('蚁小二分发提交失败：'.$response->body());
        }

        $responseData = $response->json();
        $taskSetId = $this->extractTaskSetId(is_array($responseData) ? $responseData : []);
        $detailsResponse = $this->fetchTaskDetails($taskSetId);
        [$recordStatus, $errorMessage] = $this->deriveRecordStatus($detailsResponse);

        return DB::transaction(function () use ($draft, $accounts, $uploadedImages, $publishPayload, $responseData, $taskSetId, $detailsResponse, $recordStatus, $errorMessage): GeoPublishRecord {
            $recordedAt = now();
            $target = GeoPublishTarget::query()->firstOrCreate(
                [
                    'organization_id' => $draft->organization_id,
                    'type' => 'yixiaoer',
                ],
                [
                    'name' => '蚁小二公众号草稿',
                    'endpoint' => $this->apiUrl('/taskSets/v2'),
                    'status' => 'active',
                ]
            );
            $target->forceFill([
                'name' => '蚁小二公众号草稿',
                'endpoint' => $this->apiUrl('/taskSets/v2'),
                'status' => 'active',
            ])->save();

            return GeoPublishRecord::query()->updateOrCreate(
                [
                    'geo_article_draft_id' => $draft->id,
                    'geo_publish_target_id' => $target->id,
                ],
                [
                    'platform_codes' => array_values(array_unique(array_column($accounts, 'code'))),
                    'handoff_payload' => [
                        'channel' => 'yixiaoer',
                        'action' => 'official_account_article_draft_submitted',
                        'publish_type' => 'article',
                        'task_set_id' => $taskSetId,
                        'accounts' => $accounts,
                        'uploaded_images' => $uploadedImages,
                        'publish_payload' => $publishPayload,
                        'response' => $responseData,
                        'details_response' => $detailsResponse,
                        'generated_at' => now()->toDateTimeString(),
                    ],
                    'status' => $recordStatus,
                    'target_url' => $taskSetId,
                    'error_message' => $errorMessage,
                    'submitted_at' => in_array($recordStatus, ['submitted', 'partial_success', 'published'], true) ? $recordedAt : null,
                    'published_at' => $recordStatus === 'published' ? $recordedAt : null,
                ]
            );
        });
    }

    /**
     * @param  list<string>  $platformCodes
     * @return list<string>
     */
    private function normalizePlatformCodes(array $platformCodes): array
    {
        return collect($platformCodes)
            ->map(static fn (mixed $code): string => trim((string) $code))
            ->filter(static fn (string $code): bool => array_key_exists($code, self::PLATFORM_LABELS))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function ensurePublishPackage(GeoArticleDraft $draft): array
    {
        $brief = (array) ($draft->writingTask?->brief ?? []);
        $package = (array) ($brief['publish_package'] ?? []);
        $manifestPath = (string) ($package['manifest_path'] ?? '');
        if ($manifestPath !== '' && Storage::disk('local')->exists($manifestPath)) {
            return $package;
        }

        $draft = $this->packageExporter->export($draft);
        $brief = (array) ($draft->writingTask?->brief ?? []);
        $package = (array) ($brief['publish_package'] ?? []);
        if ($package === []) {
            throw new InvalidArgumentException('发布包导出失败，暂时无法提交蚁小二');
        }

        return $package;
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    private function readManifest(array $package): array
    {
        $manifestPath = (string) ($package['manifest_path'] ?? '');
        if ($manifestPath === '' || ! Storage::disk('local')->exists($manifestPath)) {
            throw new InvalidArgumentException('发布包 manifest 不存在，请重新导出发布包');
        }

        $manifest = json_decode(Storage::disk('local')->get($manifestPath), true);
        if (! is_array($manifest)) {
            throw new InvalidArgumentException('发布包 manifest 无法读取，请重新导出发布包');
        }

        return $manifest;
    }

    /**
     * @param  array<int, mixed>  $images
     * @return list<array{key: string, width: int, height: int, size: int, format: string}>
     */
    private function uploadImages(array $images): array
    {
        $uploaded = [];
        foreach ($images as $image) {
            $image = (array) $image;
            $path = (string) ($image['package_path'] ?? '');
            if ($path === '' || ! Storage::disk('local')->exists($path)) {
                continue;
            }

            $contents = Storage::disk('local')->get($path);
            $filename = basename($path);
            $format = strtolower(pathinfo($filename, PATHINFO_EXTENSION) ?: 'png');
            $contentType = $this->contentType($format);
            $size = strlen($contents);
            [$width, $height] = $this->imageDimensions($contents, $filename);

            $uploadUrlResponse = $this->request()->get($this->apiUrl('/storages/cloud-publish/upload-url'), [
                'fileKey' => $filename,
                'contentType' => $contentType,
                'size' => $size,
            ]);
            if ($uploadUrlResponse->failed()) {
                throw new RuntimeException('蚁小二图片上传地址获取失败：'.$uploadUrlResponse->body());
            }

            $uploadInfo = $uploadUrlResponse->json('data') ?: $uploadUrlResponse->json();
            if (! is_array($uploadInfo) || empty($uploadInfo['serviceUrl']) || empty($uploadInfo['key'])) {
                throw new RuntimeException('蚁小二图片上传地址返回异常');
            }

            $putResponse = Http::withHeaders(['Content-Type' => $contentType])
                ->withBody($contents, $contentType)
                ->put((string) $uploadInfo['serviceUrl']);
            if ($putResponse->failed()) {
                throw new RuntimeException('图片上传到蚁小二 OSS 失败：'.$putResponse->body());
            }

            $uploaded[] = [
                'key' => (string) $uploadInfo['key'],
                'width' => $width,
                'height' => $height,
                'size' => $size,
                'format' => $format,
            ];
        }

        return $uploaded;
    }

    /**
     * @param  list<string>  $platformCodes
     * @return list<array{code: string, platform: string, account_id: string, account_name: string}>
     */
    private function activeAccounts(array $platformCodes): array
    {
        $labels = array_map(static fn (string $code): string => self::PLATFORM_LABELS[$code], $platformCodes);
        $response = $this->request()->get($this->apiUrl('/v2/platform/accounts'), [
            'platforms' => implode(',', $labels),
            'page' => 1,
            'size' => 100,
        ]);
        if ($response->failed()) {
            throw new RuntimeException('蚁小二账号查询失败：'.$response->body());
        }

        $json = $response->json();
        $rows = $json['data']['data'] ?? $json['data'] ?? [];
        if (! is_array($rows)) {
            $rows = [];
        }

        $accountsByCode = [];
        foreach ($rows as $row) {
            $row = (array) $row;
            $code = $this->platformCode((string) ($row['platformName'] ?? ''));
            if ($code === null || ! in_array($code, $platformCodes, true) || (int) ($row['status'] ?? 0) !== 1) {
                continue;
            }

            $accountsByCode[$code] ??= [
                'code' => $code,
                'platform' => self::PLATFORM_LABELS[$code],
                'account_id' => (string) ($row['id'] ?? ''),
                'account_name' => (string) ($row['platformAccountName'] ?? ''),
            ];
        }

        return collect($platformCodes)
            ->map(static fn (string $code): ?array => $accountsByCode[$code] ?? null)
            ->filter()
            ->values()
            ->all();
    }

    private function platformCode(string $platformName): ?string
    {
        return match ($platformName) {
            '微信公众号' => 'weixingongzhonghao',
            default => null,
        };
    }

    /**
     * @param  list<array{code: string, platform: string, account_id: string, account_name: string}>  $accounts
     * @param  list<array{key: string, width: int, height: int, size: int, format: string}>  $uploadedImages
     * @return array<string, mixed>
     */
    private function publishPayload(GeoArticleDraft $draft, array $accounts, array $uploadedImages): array
    {
        $cover = $uploadedImages[0];
        $content = $this->officialAccountHtml($draft, $uploadedImages);
        $account = $accounts[0];

        return [
            'publishType' => 'article',
            'platforms' => ['微信公众号'],
            'coverKey' => $cover['key'],
            'desc' => Str::limit((string) ($draft->summary ?: $draft->seo_description), 180, ''),
            'publishChannel' => 'cloud',
            'publishArgs' => [
                'content' => $content,
                'accountForms' => [
                    [
                        'platformAccountId' => $account['account_id'],
                        'coverKey' => $cover['key'],
                        'cover' => $cover,
                        'contentPublishForm' => [],
                    ],
                ],
                'platformForms' => [
                    '微信公众号' => [
                        'articles' => [[
                            'title' => Str::limit((string) $draft->title, 64, ''),
                            'content' => $content,
                            'authorName' => mb_substr((string) ($account['account_name'] ?: '副业库'), 0, 8),
                            'digest' => Str::limit((string) ($draft->summary ?: $draft->seo_description), 129, ''),
                            'type' => 0,
                            'cover' => [
                                'key' => $cover['key'],
                                'raw' => new \stdClass,
                            ],
                            'quickRepost' => 0,
                            'quickPrivateMessage' => 0,
                        ]],
                        'notifySubscribers' => 0,
                        'pubType' => 0,
                        'sex' => 0,
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  list<array{key: string, width: int, height: int, size: int, format: string}>  $uploadedImages
     */
    private function officialAccountHtml(GeoArticleDraft $draft, array $uploadedImages): string
    {
        $markdown = trim((string) $draft->content_markdown);
        $index = 0;
        $markdown = preg_replace_callback(
            '/!\[([^\]]*)\]\(([^)\s]+)(?:\s+(".*?"|\'.*?\'))?\)/u',
            function (array $matches) use (&$index, $uploadedImages): string {
                $alt = trim((string) ($matches[1] ?? ''));
                $image = $uploadedImages[$index] ?? null;
                $index++;
                if (! is_array($image)) {
                    return '';
                }

                return '!['.$alt.']('.$image['key'].')';
            },
            $markdown
        ) ?? $markdown;

        $html = ArticleHtmlPresenter::markdownToHtml($markdown);
        foreach ($uploadedImages as $image) {
            $key = (string) $image['key'];
            $html = str_replace('src="/'.$key.'"', 'src="'.$key.'"', $html);
        }
        $html = preg_replace('/\sloading="lazy"/u', '', $html) ?? $html;
        $html = preg_replace('/\sdecoding="async"/u', '', $html) ?? $html;

        return $html;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function imageDimensions(string $contents, string $filename): array
    {
        $size = @getimagesizefromstring($contents);
        if (is_array($size) && isset($size[0], $size[1])) {
            return [(int) $size[0], (int) $size[1]];
        }

        if (str_contains($filename, 'cover')) {
            return [1200, 630];
        }
        if (str_contains($filename, 'process')) {
            return [1200, 800];
        }
        if (str_contains($filename, 'checklist')) {
            return [900, 1200];
        }

        return [1080, 1440];
    }

    private function contentType(string $format): string
    {
        return match ($format) {
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    private function extractTaskSetId(array $responseData): string
    {
        if (isset($responseData['data']) && is_string($responseData['data'])) {
            return $responseData['data'];
        }

        $data = (array) ($responseData['data'] ?? []);

        return (string) ($data['taskSetId'] ?? $data['id'] ?? $responseData['taskSetId'] ?? $responseData['id'] ?? '');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchTaskDetails(string $taskSetId): ?array
    {
        if ($taskSetId === '') {
            return null;
        }

        $response = $this->request()->get($this->apiUrl('/v2/taskSets/'.$taskSetId.'/tasks'));
        if ($response->failed()) {
            return [
                'statusCode' => $response->status(),
                'errorMessage' => $response->body(),
            ];
        }

        $json = $response->json();

        return is_array($json) ? $json : null;
    }

    /**
     * @param  array<string, mixed>|null  $detailsResponse
     * @return array{0: string, 1: ?string}
     */
    private function deriveRecordStatus(?array $detailsResponse): array
    {
        if ($detailsResponse === null) {
            return ['submitted', null];
        }

        $tasks = $detailsResponse['data']['tasks'] ?? $detailsResponse['tasks'] ?? [];
        if (! is_array($tasks) || $tasks === []) {
            return ['submitted', null];
        }

        $successCount = 0;
        $publishedCount = 0;
        $failureCount = 0;
        $pendingCount = 0;
        $errors = [];

        foreach ($tasks as $task) {
            $task = (array) $task;
            $stageStatus = strtolower((string) ($task['stageStatus'] ?? $task['taskStatus'] ?? ''));
            if ($stageStatus === 'published') {
                $publishedCount++;
                $successCount++;
            } elseif (in_array($stageStatus, ['success', 'succeeded'], true)) {
                $successCount++;
            } elseif (in_array($stageStatus, ['fail', 'failed', 'error'], true)) {
                $failureCount++;
            } else {
                $pendingCount++;
            }

            $message = trim((string) ($task['errorMessage'] ?? ''));
            if ($message !== '') {
                $platform = trim((string) ($task['platformName'] ?? '平台')) ?: '平台';
                $errors[] = $platform.'：'.$message;
            }
        }

        $status = 'submitted';
        if ($failureCount > 0 && $successCount > 0) {
            $status = 'partial_success';
        } elseif ($failureCount > 0 && $successCount === 0) {
            $status = 'failed';
        } elseif ($publishedCount > 0 && $publishedCount === $successCount && $pendingCount === 0) {
            $status = 'published';
        }

        return [$status, $errors === [] ? null : implode("\n", $errors)];
    }

    private function request(): PendingRequest
    {
        $apiKey = trim((string) (config('services.yixiaoer.api_key') ?: env('YIXIAOER_API_KEY', '')));
        if ($apiKey === '') {
            throw new InvalidArgumentException('缺少 YIXIAOER_API_KEY，无法提交蚁小二分发');
        }

        return Http::withHeaders([
            'Authorization' => $apiKey,
            'Content-Type' => 'application/json',
        ])->acceptJson();
    }

    private function apiUrl(string $path): string
    {
        $baseUrl = rtrim((string) (config('services.yixiaoer.api_url') ?: env('YIXIAOER_API_URL', 'https://www.yixiaoer.cn/api')), '/');

        return $baseUrl.'/'.ltrim($path, '/');
    }
}
