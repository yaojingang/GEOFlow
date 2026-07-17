<?php

namespace App\Services\GeoFlow;

use App\Models\DistributionChannel;
use Illuminate\Http\Client\Response;
use RuntimeException;

class WordPressMediaSyncService
{
    public function __construct(private readonly WordPressRestRequestFactory $requestFactory) {}

    /**
     * @param  array<string,mixed>  $payload
     */
    public function rewriteContentImages(DistributionChannel $channel, array $payload, string $contentHtml): string
    {
        if ($channel->resolvedChannelConfig()['wordpress_image_strategy'] === 'keep_original') {
            return $contentHtml;
        }

        $assets = is_array($payload['assets'] ?? null) ? $payload['assets'] : [];
        $images = is_array($assets['images'] ?? null) ? $assets['images'] : [];
        foreach ($images as $image) {
            if (! is_array($image)) {
                continue;
            }

            $sourceUrl = (string) ($image['source_url'] ?? '');
            $contentBase64 = (string) ($image['content_base64'] ?? '');
            if ($sourceUrl === '' || $contentBase64 === '') {
                continue;
            }

            $binary = base64_decode($contentBase64, true);
            if (! is_string($binary) || $binary === '') {
                continue;
            }

            $uploadedUrl = $this->uploadImage($channel, $binary, (string) ($image['filename'] ?? ''), (string) ($image['mime_type'] ?? 'application/octet-stream'));
            if ($uploadedUrl !== '') {
                $contentHtml = str_replace($sourceUrl, $uploadedUrl, $contentHtml);
            }
        }

        return $contentHtml;
    }

    private function uploadImage(DistributionChannel $channel, string $binary, string $filename, string $mimeType): string
    {
        $filename = $this->safeFilename($filename);
        $mimeType = $mimeType !== '' ? $mimeType : 'application/octet-stream';
        $response = $this->requestFactory->request($channel)
            ->withHeaders([
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ])
            ->withBody($binary, $mimeType)
            ->post($channel->wordpressRestBaseUrl().'/wp/v2/media');
        $this->throwIfFailed($response, 'WordPress 媒体上传');
        $json = $response->json();

        return is_array($json) ? (string) ($json['source_url'] ?? '') : '';
    }

    private function safeFilename(string $filename): string
    {
        $filename = trim(basename($filename));

        return $filename !== '' ? $filename : 'geoflow-image.jpg';
    }

    private function throwIfFailed(Response $response, string $operation): void
    {
        if (! $response->failed()) {
            return;
        }

        throw new RuntimeException($operation.'失败：HTTP '.$response->status());
    }
}
