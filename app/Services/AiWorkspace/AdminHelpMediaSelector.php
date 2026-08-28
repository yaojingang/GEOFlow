<?php

namespace App\Services\AiWorkspace;

use App\Models\Admin;
use App\Models\KnowledgeMediaAsset;
use Illuminate\Support\Str;

final class AdminHelpMediaSelector
{
    public function __construct(
        private readonly AdminHelpFeatureRegistry $features,
        private readonly SystemKnowledgeMediaManager $media,
    ) {}

    /** @param list<array<string, mixed>> $sources @return list<array<string, mixed>> */
    public function select(Admin $admin, array $sources, ?string $locale = null, int $limit = 1): array
    {
        $locale ??= app()->getLocale();
        if ($locale !== 'zh_CN') {
            return [];
        }

        $knowledgeBaseIds = collect($sources)
            ->pluck('knowledge_base_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        if ($knowledgeBaseIds === []) {
            return [];
        }

        $assets = KnowledgeMediaAsset::query()
            ->whereIn('knowledge_base_id', $knowledgeBaseIds)
            ->where('locale', $locale)
            ->where('is_active', true)
            ->where('needs_review', false)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $scored = $assets->map(function (KnowledgeMediaAsset $asset) use ($admin, $sources): array {
            if (! $this->features->canAccessRoute($admin, (string) $asset->route_name)) {
                return ['asset' => $asset, 'score' => -1];
            }

            $score = 0;
            $keywords = collect((array) $asset->keywords_json)
                ->map(static fn (mixed $keyword): string => Str::lower(trim((string) $keyword)))
                ->filter()
                ->values();
            foreach ($sources as $source) {
                $section = Str::lower((string) ($source['section_path'] ?? ''));
                $featureId = (string) ($source['feature_id'] ?? '');
                if ($section !== '' && Str::contains($section, Str::lower((string) $asset->section_key))) {
                    $score += 100;
                }
                $feature = $this->features->featureForRoute((string) $asset->route_name);
                if (is_array($feature) && $featureId !== '' && (string) $feature['id'] === $featureId) {
                    $score += 60;
                }
                foreach ($keywords as $keyword) {
                    if ($keyword !== '' && Str::contains($section, $keyword)) {
                        $score += 15;
                    }
                }
            }

            return ['asset' => $asset, 'score' => $score];
        })->filter(static fn (array $item): bool => (int) $item['score'] >= 40)
            ->sortBy([['score', 'desc'], ['asset.sort_order', 'asc']])
            ->unique(static fn (array $item): string => (string) $item['asset']->asset_key)
            ->take(max(1, min(3, $limit)))
            ->values();

        return $scored->map(fn (array $item): array => $this->media->answerPayload($item['asset']))->all();
    }
}
