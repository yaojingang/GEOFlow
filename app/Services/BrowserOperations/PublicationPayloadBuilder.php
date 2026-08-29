<?php

namespace App\Services\BrowserOperations;

use App\Models\ManualPublication;
use App\Models\ManualPublicationAccount;
use Illuminate\Support\Arr;

final class PublicationPayloadBuilder
{
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

        return [
            'schema_version' => 1,
            'target_action' => $platform === ManualPublicationAccount::PLATFORM_ZHIHU && $type === ManualPublication::TYPE_POST
                ? 'zhihu_answer'
                : 'manual_'.$type,
            'title' => trim((string) Arr::get($source, 'title', '')),
            'body_plain' => (string) ($attributes['content'] ?? ''),
            'body_markdown' => (string) ($attributes['content'] ?? ''),
            'tags' => [],
            'canonical_url' => $attributes['target_url'] ?? null,
            'disclosure' => $attributes['disclosure_snapshot'] ?? null,
            'asset_ids' => $assetIds,
        ];
    }
}
