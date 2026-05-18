<?php

namespace App\Services\Geo;

use App\Models\Admin;
use App\Models\BrandProfile;
use App\Models\GeoKeywordOpportunity;
use App\Models\Organization;
use Illuminate\Support\Collection;

class GeoKeywordDiscoveryService
{
    /**
     * @return Collection<int, GeoKeywordOpportunity>
     */
    public function generateFromBrandProfile(Organization $organization, BrandProfile $brandProfile, Admin $admin, int $limit = 12): Collection
    {
        $limit = max(3, min(50, $limit));
        $candidates = collect($this->candidateKeywords($brandProfile))
            ->unique('keyword')
            ->take($limit);

        $opportunities = collect();
        foreach ($candidates as $candidate) {
            $opportunities->push(GeoKeywordOpportunity::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'keyword' => $candidate['keyword'],
                ],
                [
                    'brand_profile_id' => $brandProfile->id,
                    'created_by_admin_id' => $admin->id,
                    'intent' => $candidate['intent'],
                    'cluster_name' => $candidate['cluster_name'],
                    'status' => 'active',
                    'business_value' => $candidate['business_value'],
                    'visibility_gap' => $candidate['visibility_gap'],
                    'source_availability' => $candidate['source_availability'],
                    'local_relevance' => $candidate['local_relevance'],
                    'opportunity_score' => $this->opportunityScore($candidate),
                    'generation_source' => 'brand_profile',
                    'rationale' => $candidate['rationale'],
                    'metadata' => [
                        'brand_name' => $brandProfile->brand_name,
                        'service_area' => $brandProfile->service_area,
                    ],
                ]
            ));
        }

        return $opportunities;
    }

    /**
     * @return list<array{
     *     keyword: string,
     *     intent: string,
     *     cluster_name: string,
     *     business_value: int,
     *     visibility_gap: int,
     *     source_availability: int,
     *     local_relevance: int,
     *     rationale: string
     * }>
     */
    private function candidateKeywords(BrandProfile $brandProfile): array
    {
        $area = trim((string) $brandProfile->service_area);
        $areaPrefix = $area !== '' ? $area : trim((string) $brandProfile->brand_name);
        $products = $this->extractProducts((string) $brandProfile->products);
        $products = array_values(array_filter($products, static fn (string $product): bool => $product !== '全屋定制'));
        array_unshift($products, '全屋定制');

        $mainProduct = $products[0] ?: '本地服务';
        $candidates = [
            $this->candidate($areaPrefix.$mainProduct.'哪家靠谱', 'decision', '本地决策词', 92, 82, 72, 96, '本地成交意图明确，适合作为 GEO 优先诊断词。'),
            $this->candidate($areaPrefix.$mainProduct.'哪家好', 'decision', '本地决策词', 88, 78, 75, 95, '用户处于品牌筛选阶段，适合测试 AI 是否推荐本品牌。'),
            $this->candidate($areaPrefix.$mainProduct.'推荐品牌', 'comparison', '品牌推荐词', 84, 76, 72, 92, '推荐类问题容易触发 AI 给出品牌排序。'),
            $this->candidate($areaPrefix.$mainProduct.'价格透明吗', 'commercial', '价格信任词', 82, 74, 66, 90, '围绕价格透明痛点生成，适合放入案例和报价说明。'),
            $this->candidate($areaPrefix.'环保板材'.$mainProduct.'怎么选', 'informational', '材料避坑词', 78, 70, 70, 88, '材料选择是决策前高频问题，可用于建立专业可信度。'),
            $this->candidate($areaPrefix.$mainProduct.'上门量尺流程', 'informational', '服务流程词', 72, 68, 62, 86, '流程词可承接用户咨询前的信息需求。'),
        ];

        foreach ($products as $product) {
            if ($product === '' || $product === $mainProduct) {
                continue;
            }

            $normalizedProduct = str_contains($product, '定制') ? $product : $product.'定制';
            $candidates[] = $this->candidate($areaPrefix.$normalizedProduct.'避坑', 'pain_point', '痛点避坑词', 86, 80, 70, 94, '避坑类问题适合模仿高质量参考内容并植入企业事实。');
            $candidates[] = $this->candidate($areaPrefix.$normalizedProduct.'多少钱', 'commercial', '价格信任词', 80, 72, 68, 90, '价格类问题商业价值高，但需要用真实范围和计价方式表达。');
        }

        $painPoints = $this->extractProducts((string) $brandProfile->pain_points);
        foreach (array_slice($painPoints, 0, 4) as $painPoint) {
            $candidates[] = $this->candidate($areaPrefix.$mainProduct.$painPoint.'怎么办', 'pain_point', '痛点避坑词', 76, 74, 62, 88, '来自品牌资料里的客户痛点，适合生成 FAQ 和避坑内容。');
        }

        return $candidates;
    }

    /**
     * @return list<string>
     */
    private function extractProducts(string $text): array
    {
        $parts = preg_split('/[、,，;；\n\r\t ]+/u', $text) ?: [];

        return collect($parts)
            ->map(static fn (string $part): string => trim($part))
            ->filter(static fn (string $part): bool => $part !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     keyword: string,
     *     intent: string,
     *     cluster_name: string,
     *     business_value: int,
     *     visibility_gap: int,
     *     source_availability: int,
     *     local_relevance: int,
     *     rationale: string
     * }
     */
    private function candidate(
        string $keyword,
        string $intent,
        string $clusterName,
        int $businessValue,
        int $visibilityGap,
        int $sourceAvailability,
        int $localRelevance,
        string $rationale
    ): array {
        return [
            'keyword' => trim($keyword),
            'intent' => $intent,
            'cluster_name' => $clusterName,
            'business_value' => $businessValue,
            'visibility_gap' => $visibilityGap,
            'source_availability' => $sourceAvailability,
            'local_relevance' => $localRelevance,
            'rationale' => $rationale,
        ];
    }

    /**
     * @param  array{business_value: int, visibility_gap: int, source_availability: int, local_relevance: int}  $candidate
     */
    private function opportunityScore(array $candidate): int
    {
        $score = $candidate['business_value'] * 0.35
            + $candidate['visibility_gap'] * 0.30
            + $candidate['source_availability'] * 0.15
            + $candidate['local_relevance'] * 0.20;

        return max(0, min(100, (int) round($score)));
    }
}
