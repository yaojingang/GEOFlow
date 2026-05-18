<?php

namespace App\Services\Geo;

use App\Models\Admin;
use App\Models\BrandProfile;
use App\Models\GeoKeywordOpportunity;
use App\Models\Organization;
use Illuminate\Support\Collection;

class GeoKeywordCombinationService
{
    /**
     * @return list<string>
     */
    public static function allowedPatterns(): array
    {
        return [
            'C+D',
            'A+C+D',
            'B+C+D',
            'A+B+C+D',
            'C+D+E',
            'C+D+F',
            'A+C+D+E',
            'B+C+D+E',
            'A+B+C+D+E',
            'C+D+E+F',
            'A+C+D+E+F',
            'B+C+D+E+F',
            'A+B+C+D+F',
        ];
    }

    /**
     * @param  array{
     *     area_prefixes?: string,
     *     modifiers?: string,
     *     core_terms: string,
     *     entity_terms: string,
     *     recommend_terms?: string,
     *     question_terms?: string,
     *     combination_patterns: list<string>,
     *     limit?: int
     * }  $payload
     * @return Collection<int, GeoKeywordOpportunity>
     */
    public function generateFromManualParts(
        Organization $organization,
        BrandProfile $brandProfile,
        Admin $admin,
        array $payload
    ): Collection {
        $limit = max(1, min(200, (int) ($payload['limit'] ?? 80)));
        $parts = [
            'A' => $this->parseTerms((string) ($payload['area_prefixes'] ?? '')),
            'B' => $this->parseTerms((string) ($payload['modifiers'] ?? '')),
            'C' => $this->parseTerms((string) $payload['core_terms']),
            'D' => $this->parseTerms((string) $payload['entity_terms']),
            'E' => $this->parseTerms((string) ($payload['recommend_terms'] ?? '')),
            'F' => $this->parseTerms((string) ($payload['question_terms'] ?? '')),
        ];

        $patterns = collect($payload['combination_patterns'])
            ->intersect(self::allowedPatterns())
            ->values()
            ->all();

        $candidates = $this->buildCandidates($parts, $patterns, $limit);
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
                    'intent' => 'manual_expansion',
                    'cluster_name' => '手工拓词',
                    'status' => 'active',
                    'business_value' => $candidate['business_value'],
                    'visibility_gap' => $candidate['visibility_gap'],
                    'source_availability' => $candidate['source_availability'],
                    'local_relevance' => $candidate['local_relevance'],
                    'opportunity_score' => $this->opportunityScore($candidate),
                    'generation_source' => 'manual_abcdef',
                    'rationale' => '由 ABCDEF 手工拓词组合生成，适合纳入 GEO 可见度诊断。',
                    'metadata' => [
                        'pattern' => $candidate['pattern'],
                        'parts' => $candidate['parts'],
                        'brand_name' => $brandProfile->brand_name,
                        'service_area' => $brandProfile->service_area,
                    ],
                ]
            ));
        }

        return $opportunities;
    }

    /**
     * @return list<string>
     */
    private function parseTerms(string $text): array
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
     * @param  array<string, list<string>>  $parts
     * @param  list<string>  $patterns
     * @return list<array{
     *     keyword: string,
     *     pattern: string,
     *     parts: array<string, string>,
     *     business_value: int,
     *     visibility_gap: int,
     *     source_availability: int,
     *     local_relevance: int
     * }>
     */
    private function buildCandidates(array $parts, array $patterns, int $limit): array
    {
        $seen = [];
        $candidates = [];

        foreach ($patterns as $pattern) {
            $letters = explode('+', $pattern);
            $letterTerms = [];

            foreach ($letters as $letter) {
                if (($parts[$letter] ?? []) === []) {
                    continue 2;
                }

                $letterTerms[$letter] = $parts[$letter];
            }

            foreach ($this->cartesian($letterTerms) as $combination) {
                $keyword = implode('', array_values($combination));
                if ($keyword === '' || isset($seen[$keyword])) {
                    continue;
                }

                $seen[$keyword] = true;
                $candidates[] = [
                    'keyword' => $keyword,
                    'pattern' => $pattern,
                    'parts' => $combination,
                    'business_value' => $this->scoreBusinessValue($pattern),
                    'visibility_gap' => $this->scoreVisibilityGap($pattern),
                    'source_availability' => $this->scoreSourceAvailability($pattern),
                    'local_relevance' => $this->scoreLocalRelevance($pattern),
                ];

                if (count($candidates) >= $limit) {
                    return $candidates;
                }
            }
        }

        return $candidates;
    }

    /**
     * @param  array<string, list<string>>  $sets
     * @return list<array<string, string>>
     */
    private function cartesian(array $sets): array
    {
        $result = [[]];

        foreach ($sets as $letter => $terms) {
            $next = [];
            foreach ($result as $partial) {
                foreach ($terms as $term) {
                    $next[] = $partial + [$letter => $term];
                }
            }
            $result = $next;
        }

        return $result;
    }

    private function scoreBusinessValue(string $pattern): int
    {
        $score = 72;
        $score += str_contains($pattern, 'D') ? 6 : 0;
        $score += str_contains($pattern, 'E') ? 5 : 0;
        $score += str_contains($pattern, 'F') ? 5 : 0;

        return min(92, $score);
    }

    private function scoreVisibilityGap(string $pattern): int
    {
        $score = 68;
        $score += str_contains($pattern, 'B') ? 5 : 0;
        $score += str_contains($pattern, 'E') ? 4 : 0;
        $score += str_contains($pattern, 'F') ? 4 : 0;

        return min(90, $score);
    }

    private function scoreSourceAvailability(string $pattern): int
    {
        $score = 66;
        $score += str_contains($pattern, 'D') ? 4 : 0;
        $score += str_contains($pattern, 'E') ? 3 : 0;
        $score += str_contains($pattern, 'F') ? 2 : 0;

        return min(84, $score);
    }

    private function scoreLocalRelevance(string $pattern): int
    {
        $score = 74;
        $score += str_contains($pattern, 'A') ? 14 : 0;
        $score += str_contains($pattern, 'B') ? 3 : 0;

        return min(96, $score);
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
