<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use App\Models\GeoKeywordOpportunity;
use App\Models\Keyword;
use App\Models\KeywordLibrary;
use App\Models\Organization;
use App\Models\Title;
use App\Models\TitleLibrary;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GeoOpportunityMaterialSyncService
{
    /**
     * @param  Collection<int, GeoKeywordOpportunity>  $opportunities
     * @return array{keyword_library_id:int,title_library_id:int,keywords_added:int,titles_added:int}
     */
    public function sync(Organization $organization, BrandProfile $brandProfile, Collection $opportunities): array
    {
        $opportunities = $opportunities
            ->filter(fn (GeoKeywordOpportunity $opportunity): bool => trim((string) $opportunity->keyword) !== '')
            ->unique(fn (GeoKeywordOpportunity $opportunity): string => trim((string) $opportunity->keyword))
            ->values();

        if ($opportunities->isEmpty()) {
            return [
                'keyword_library_id' => 0,
                'title_library_id' => 0,
                'keywords_added' => 0,
                'titles_added' => 0,
            ];
        }

        return DB::transaction(function () use ($organization, $brandProfile, $opportunities): array {
            $keywordLibrary = $this->keywordLibrary($organization, $brandProfile);
            $titleLibrary = $this->titleLibrary($organization, $brandProfile, $keywordLibrary);
            $keywordsAdded = 0;
            $titlesAdded = 0;

            foreach ($opportunities as $opportunity) {
                $keyword = mb_substr(trim((string) $opportunity->keyword), 0, 200);

                $keywordModel = Keyword::query()->firstOrCreate(
                    [
                        'library_id' => (int) $keywordLibrary->id,
                        'keyword' => $keyword,
                    ],
                    [
                        'used_count' => 0,
                        'usage_count' => 0,
                    ]
                );
                if ($keywordModel->wasRecentlyCreated) {
                    $keywordsAdded++;
                }

                $titleText = $this->titleForKeyword($keyword);
                $titleExists = Title::query()
                    ->where('library_id', (int) $titleLibrary->id)
                    ->where('title', $titleText)
                    ->exists();
                if (! $titleExists) {
                    Title::query()->create([
                        'library_id' => (int) $titleLibrary->id,
                        'title' => $titleText,
                        'keyword' => $keyword,
                        'is_ai_generated' => true,
                        'used_count' => 0,
                        'usage_count' => 0,
                    ]);
                    $titlesAdded++;
                }

                $metadata = (array) ($opportunity->metadata ?? []);
                $metadata['material_sync'] = [
                    'keyword_library_id' => (int) $keywordLibrary->id,
                    'title_library_id' => (int) $titleLibrary->id,
                    'synced_at' => now()->toIso8601String(),
                ];
                $opportunity->forceFill(['metadata' => $metadata])->save();
            }

            $keywordLibrary->update([
                'keyword_count' => Keyword::query()->where('library_id', (int) $keywordLibrary->id)->count(),
            ]);
            $titleLibrary->update([
                'title_count' => Title::query()->where('library_id', (int) $titleLibrary->id)->count(),
            ]);

            return [
                'keyword_library_id' => (int) $keywordLibrary->id,
                'title_library_id' => (int) $titleLibrary->id,
                'keywords_added' => $keywordsAdded,
                'titles_added' => $titlesAdded,
            ];
        });
    }

    private function keywordLibrary(Organization $organization, BrandProfile $brandProfile): KeywordLibrary
    {
        $library = KeywordLibrary::query()->firstOrCreate(
            ['name' => $this->libraryName($brandProfile, 'GEO机会词库')],
            ['description' => '由 GEO 工作台机会搜索自动沉淀。']
        );

        $library->update([
            'description' => '由 '.$organization->name.' 的 GEO 机会搜索自动沉淀，可用于诊断、文章选题和标签。',
        ]);

        return $library;
    }

    private function titleLibrary(Organization $organization, BrandProfile $brandProfile, KeywordLibrary $keywordLibrary): TitleLibrary
    {
        $library = TitleLibrary::query()->firstOrCreate(
            ['name' => $this->libraryName($brandProfile, 'GEO机会标题库')],
            [
                'description' => '由 GEO 工作台机会词自动生成标题。',
                'generation_type' => 'geo_opportunity',
                'keyword_library_id' => (int) $keywordLibrary->id,
                'generation_rounds' => 1,
                'is_ai_generated' => 1,
            ]
        );

        $library->update([
            'description' => '由 '.$organization->name.' 的 GEO 机会词自动生成，已关联对应机会词库。',
            'generation_type' => 'geo_opportunity',
            'keyword_library_id' => (int) $keywordLibrary->id,
            'generation_rounds' => 1,
            'is_ai_generated' => 1,
        ]);

        return $library;
    }

    private function libraryName(BrandProfile $brandProfile, string $suffix): string
    {
        $brandName = trim((string) $brandProfile->brand_name) ?: 'GEO';
        $maxBrandLength = max(1, 99 - mb_strlen($suffix));

        return mb_substr($brandName, 0, $maxBrandLength).' '.$suffix;
    }

    private function titleForKeyword(string $keyword): string
    {
        return mb_substr($keyword.'：本地选择标准与避坑建议', 0, 500);
    }
}
