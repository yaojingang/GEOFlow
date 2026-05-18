<?php

namespace App\Services\Geo;

use App\Models\BrandProfile;
use App\Models\GeoCompetitor;

class GeoAIAnswerAnalyzer
{
    /**
     * @return array{
     *     brand_mentioned: bool,
     *     competitors_mentioned: list<string>,
     *     citations: list<string>,
     *     source_urls: list<string>,
     *     visibility_score: int,
     *     analysis_json: array<string, mixed>
     * }
     */
    public function analyze(BrandProfile $brandProfile, int $organizationId, string $answer): array
    {
        $brandNames = $this->brandNames($brandProfile);
        $brandMentioned = $this->containsAny($answer, $brandNames);
        $competitors = $this->competitorsMentioned($organizationId, $answer);
        $sourceUrls = $this->sourceUrls($answer);
        $citations = $sourceUrls;
        if ($citations === [] && preg_match('/来源|引用|参考|资料显示/u', $answer) === 1) {
            $citations = ['回答中出现来源/参考表达'];
        }

        $visibilityScore = 0;
        $visibilityScore += $brandMentioned ? 40 : 0;
        $visibilityScore += $this->brandRecommended($answer, $brandNames) ? 20 : 0;
        $visibilityScore += $sourceUrls !== [] ? 15 : 0;
        $visibilityScore += preg_match('/上门量尺|报价|官网|门店|地址|电话|案例/u', $answer) === 1 ? 10 : 0;
        if (! $brandMentioned && $competitors !== []) {
            $visibilityScore -= 10;
        }

        return [
            'brand_mentioned' => $brandMentioned,
            'competitors_mentioned' => $competitors,
            'citations' => $citations,
            'source_urls' => $sourceUrls,
            'visibility_score' => max(0, min(100, $visibilityScore)),
            'analysis_json' => [
                'brand_names' => $brandNames,
                'has_citation' => $citations !== [],
                'has_source_url' => $sourceUrls !== [],
                'answer_length' => mb_strlen($answer),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public function sourceUrls(string $answer): array
    {
        preg_match_all('#https?://[^\s<>"\'）)\]}，。；、]+#iu', $answer, $matches);

        return collect($matches[0] ?? [])
            ->map(static fn (string $url): string => rtrim($url, '.,;:!?。；，、）)]}'))
            ->filter(static fn (string $url): bool => filter_var($url, FILTER_VALIDATE_URL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<string>
     */
    private function brandNames(BrandProfile $brandProfile): array
    {
        return collect([(string) $brandProfile->brand_name])
            ->merge((array) $brandProfile->aliases)
            ->map(static fn (mixed $name): string => trim((string) $name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $names
     */
    private function containsAny(string $answer, array $names): bool
    {
        foreach ($names as $name) {
            if (mb_stripos($answer, $name) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $brandNames
     */
    private function brandRecommended(string $answer, array $brandNames): bool
    {
        foreach ($brandNames as $brandName) {
            $position = mb_stripos($answer, $brandName);
            if ($position === false) {
                continue;
            }

            $context = mb_substr($answer, max(0, $position - 40), mb_strlen($brandName) + 80);
            if (preg_match('/推荐|优先|可以考虑|值得|靠谱|适合/u', $context) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function competitorsMentioned(int $organizationId, string $answer): array
    {
        $known = GeoCompetitor::query()
            ->where('organization_id', $organizationId)
            ->get()
            ->flatMap(fn (GeoCompetitor $competitor): array => array_merge([(string) $competitor->name], (array) $competitor->aliases))
            ->map(static fn (mixed $name): string => trim((string) $name))
            ->filter(static fn (string $name): bool => $name !== '')
            ->values()
            ->all();

        $mentioned = collect($known)
            ->filter(fn (string $name): bool => mb_stripos($answer, $name) !== false);

        preg_match_all('/(?:对比|比较|以及|还有|也可以对比|另外看看|同时看)([^。；，,、\s]{2,24}(?:定制|家居|装修|装饰|品牌|公司|工厂))/u', $answer, $matches);
        foreach (($matches[1] ?? []) as $candidate) {
            $mentioned->push(trim((string) $candidate));
        }

        return $mentioned
            ->filter(static fn (string $name): bool => $name !== '')
            ->unique()
            ->values()
            ->all();
    }
}
