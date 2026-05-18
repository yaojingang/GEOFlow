<?php

namespace App\Services\Geo;

use App\Models\GeoCitationPageSnapshot;
use App\Models\GeoReferenceContentScore;

class GeoReferenceContentQualityScorer
{
    /**
     * @param  array{
     *     query?: string,
     *     keywords?: list<string>,
     *     brand_names?: list<string>,
     *     competitor_names?: list<string>
     * }  $context
     */
    public function scoreSnapshot(GeoCitationPageSnapshot $snapshot, array $context = []): GeoReferenceContentScore
    {
        return GeoReferenceContentScore::query()->create(array_merge(
            ['geo_citation_page_snapshot_id' => $snapshot->id],
            $this->score($snapshot, $context),
            ['scored_at' => now()],
        ));
    }

    /**
     * @param  array{
     *     query?: string,
     *     keywords?: list<string>,
     *     brand_names?: list<string>,
     *     competitor_names?: list<string>
     * }  $context
     * @return array{
     *     relevance_score: int,
     *     structure_score: int,
     *     actionability_score: int,
     *     evidence_density_score: int,
     *     brand_competitor_score: int,
     *     total_score: int,
     *     score_reason: string,
     *     suggested_usage: string,
     *     signals: array<string, mixed>
     * }
     */
    public function score(GeoCitationPageSnapshot|array $snapshot, array $context = []): array
    {
        $title = $this->field($snapshot, 'title');
        $description = $this->field($snapshot, 'description');
        $content = $this->field($snapshot, 'content_text');
        $text = trim($title.' '.$description.' '.$content);

        $keywords = $this->cleanTerms(array_merge(
            isset($context['query']) ? preg_split('/[\s,，。；;、]+/u', (string) $context['query']) ?: [] : [],
            $context['keywords'] ?? [],
        ));
        $brandNames = $this->cleanTerms($context['brand_names'] ?? []);
        $competitorNames = $this->cleanTerms($context['competitor_names'] ?? []);

        $keywordHits = $this->countTermHits($text, $keywords);
        $brandHits = $this->countTermHits($text, $brandNames);
        $competitorHits = $this->countTermHits($text, $competitorNames);
        $structureSignals = $this->structureSignals($content);
        $evidenceSignals = $this->evidenceSignals($content);
        $actionSignals = $this->actionSignals($content);

        $relevanceScore = $keywords === [] ? min(60, (int) floor(mb_strlen($text) / 40)) : min(100, $keywordHits * 25);
        $structureScore = min(100, ($structureSignals['paragraphs'] >= 3 ? 35 : $structureSignals['paragraphs'] * 10) + ($structureSignals['has_heading_words'] ? 25 : 0) + min(40, (int) floor($structureSignals['length'] / 30)));
        $actionabilityScore = min(100, $actionSignals * 25);
        $evidenceDensityScore = min(100, ($evidenceSignals['numbers'] * 15) + ($evidenceSignals['evidence_words'] * 20) + ($evidenceSignals['has_date'] ? 20 : 0));
        $brandCompetitorScore = min(100, ($brandHits * 35) + ($competitorHits * 20));

        $totalScore = (int) round(
            $relevanceScore * 0.3
            + $structureScore * 0.2
            + $actionabilityScore * 0.2
            + $evidenceDensityScore * 0.15
            + $brandCompetitorScore * 0.15
        );

        $signals = [
            'keyword_hits' => $keywordHits,
            'brand_hits' => $brandHits,
            'competitor_hits' => $competitorHits,
            'structure' => $structureSignals,
            'evidence' => $evidenceSignals,
            'action_signals' => $actionSignals,
            'text_length' => mb_strlen($text),
        ];

        return [
            'relevance_score' => $relevanceScore,
            'structure_score' => $structureScore,
            'actionability_score' => $actionabilityScore,
            'evidence_density_score' => $evidenceDensityScore,
            'brand_competitor_score' => $brandCompetitorScore,
            'total_score' => max(0, min(100, $totalScore)),
            'score_reason' => $this->reason($signals),
            'suggested_usage' => $this->suggestedUsage($totalScore, $signals),
            'signals' => $signals,
        ];
    }

    private function field(GeoCitationPageSnapshot|array $snapshot, string $field): string
    {
        $value = $snapshot instanceof GeoCitationPageSnapshot ? $snapshot->{$field} : ($snapshot[$field] ?? '');

        return trim((string) $value);
    }

    /**
     * @param  list<string>  $terms
     * @return list<string>
     */
    private function cleanTerms(array $terms): array
    {
        return collect($terms)
            ->map(static fn (mixed $term): string => trim((string) $term))
            ->filter(static fn (string $term): bool => mb_strlen($term) >= 2)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $terms
     */
    private function countTermHits(string $text, array $terms): int
    {
        $hits = 0;
        foreach ($terms as $term) {
            if (mb_stripos($text, $term) !== false) {
                $hits++;
            }
        }

        return $hits;
    }

    /**
     * @return array{paragraphs: int, has_heading_words: bool, length: int}
     */
    private function structureSignals(string $content): array
    {
        $paragraphs = max(1, preg_match_all('/[。！？.!?]\s*/u', $content));

        return [
            'paragraphs' => $paragraphs,
            'has_heading_words' => preg_match('/优势|案例|价格|流程|对比|建议|总结|方案/u', $content) === 1,
            'length' => mb_strlen($content),
        ];
    }

    /**
     * @return array{numbers: int, evidence_words: int, has_date: bool}
     */
    private function evidenceSignals(string $content): array
    {
        return [
            'numbers' => preg_match_all('/\d+(?:\.\d+)?%?|\d+年|\d+月/u', $content),
            'evidence_words' => preg_match_all('/案例|数据|实测|报价|来源|报告|排名|评价|口碑/u', $content),
            'has_date' => preg_match('/20\d{2}年|20\d{2}-\d{1,2}-\d{1,2}/u', $content) === 1,
        ];
    }

    private function actionSignals(string $content): int
    {
        return preg_match_all('/建议|可以|适合|优先|对比|参考|选择|避坑|流程|清单/u', $content);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function reason(array $signals): string
    {
        return sprintf(
            'keyword_hits=%d; brand_hits=%d; competitor_hits=%d; evidence_words=%d; action_signals=%d',
            $signals['keyword_hits'],
            $signals['brand_hits'],
            $signals['competitor_hits'],
            $signals['evidence']['evidence_words'],
            $signals['action_signals'],
        );
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function suggestedUsage(int $totalScore, array $signals): string
    {
        if ($totalScore >= 75 && $signals['evidence']['evidence_words'] >= 2) {
            return 'core_reference';
        }

        if ($totalScore >= 55) {
            return 'outline_or_angle';
        }

        if ($signals['brand_hits'] > 0 || $signals['competitor_hits'] > 0) {
            return 'brand_competitor_clue';
        }

        return 'background_reference';
    }
}
