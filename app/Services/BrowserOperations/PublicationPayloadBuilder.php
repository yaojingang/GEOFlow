<?php

namespace App\Services\BrowserOperations;

use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use App\Services\GeoFlow\Distribution\PlatformWeb\PlatformCatalog;
use Illuminate\Support\Arr;

final class PublicationPayloadBuilder
{
    /** 构建进 payload 的 extras 键，同时也是状态流转回填时从既有 payload 提取的键。 */
    public const EXTRA_KEYS = ['images', 'append_source_link', 'source_url'];

    /** @param array<string,mixed> $attributes
     * @return array<string,mixed>
     */
    public function build(array $attributes): array
    {
        $type = (string) ($attributes['type'] ?? ManualPublication::TYPE_POST);
        $platform = (string) ($attributes['platform'] ?? '');
        $source = is_array($attributes['source_snapshot'] ?? null) ? $attributes['source_snapshot'] : [];
        $assetIds = array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            (array) Arr::get($source, 'asset_ids', []),
        ), static fn (int $id): bool => $id > 0));

        $extras = is_array($attributes['publication_payload_extras'] ?? null)
            ? $attributes['publication_payload_extras']
            : [];
        $images = is_array($extras['images'] ?? null)
            ? array_values(array_filter($extras['images'], static fn (mixed $image): bool => is_array($image) && (string) ($image['url'] ?? '') !== ''))
            : [];

        return [
            'schema_version' => 1,
            'target_action' => $this->resolveTargetAction($platform, $type),
            'title' => trim((string) Arr::get($source, 'title', '')),
            'body_plain' => (string) ($attributes['content'] ?? ''),
            'body_markdown' => (string) ($attributes['content'] ?? ''),
            'tags' => [],
            'canonical_url' => $attributes['target_url'] ?? null,
            'disclosure' => $attributes['disclosure_snapshot'] ?? null,
            'asset_ids' => $assetIds,
            'images' => $images,
            'append_source_link' => (bool) ($extras['append_source_link'] ?? false),
            'source_url' => isset($extras['source_url']) && is_string($extras['source_url']) && $extras['source_url'] !== '' ? $extras['source_url'] : null,
        ];
    }

    private function resolveTargetAction(string $platform, string $type): string
    {
        if ($type === ManualPublication::TYPE_POST && $platform === ManualPublicationAccount::PLATFORM_ZHIHU) {
            return 'zhihu_answer';
        }
        if ($type === ManualPublication::TYPE_POST && PlatformCatalog::isSupported($platform)) {
            return $platform.'_post';
        }

        return 'manual_'.$type;
    }
}
