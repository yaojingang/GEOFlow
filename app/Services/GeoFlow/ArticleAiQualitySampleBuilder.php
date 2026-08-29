<?php

namespace App\Services\GeoFlow;

use Illuminate\Support\Str;

/**
 * Builds a deterministic, auditable sample without calling a model.
 */
class ArticleAiQualitySampleBuilder
{
    public const ALGORITHM_VERSION = 'article-quality-sampling-1.1.0';

    public function __construct(
        private readonly ?int $maxCharacters = null,
        private readonly ?int $maxRanges = null,
    ) {}

    /**
     * @param  array<string, mixed>  $articleSnapshot
     * @param  list<array<string, mixed>>  $factCandidates
     * @param  list<array<string, mixed>>  $riskMatches
     * @return array<string, mixed>
     */
    public function build(array $articleSnapshot, array $factCandidates, array $riskMatches = []): array
    {
        $content = (string) ($articleSnapshot['content'] ?? '');
        $totalCharacters = Str::length($content);
        $maximumCharacters = max(1, $this->maxCharacters
            ?? (int) config('geoflow.ai_quality_sampled_max_characters', 6000));
        $maximumRanges = max(1, $this->maxRanges
            ?? (int) config('geoflow.ai_quality_sampled_max_ranges', 12));
        $targetWindow = max(120, min(700, (int) floor($maximumCharacters / max(3, $maximumRanges))));

        [$mandatoryClaims, $unresolvedMandatoryCount] = $this->mandatoryClaims(
            $content,
            $factCandidates,
            $riskMatches,
        );
        $candidates = [];
        foreach ($mandatoryClaims as $claim) {
            if (! (bool) $claim['requires_range']) {
                continue;
            }
            $candidates[] = $this->window(
                $content,
                (int) $claim['start_offset'],
                (int) $claim['end_offset'],
                $targetWindow,
                0,
                ['mandatory_claim'],
                [(string) $claim['key']],
            );
        }

        if ($totalCharacters > 0) {
            $candidates[] = $this->window($content, 0, min($totalCharacters, $targetWindow), $targetWindow, 10, ['opening']);
            $middle = (int) floor($totalCharacters / 2);
            $candidates[] = $this->window($content, $middle, $middle + 1, $targetWindow, 11, ['middle']);
            $candidates[] = $this->window(
                $content,
                max(0, $totalCharacters - $targetWindow),
                $totalCharacters,
                $targetWindow,
                12,
                ['conclusion'],
            );

            foreach ([1, 2, 4, 5] as $sixth) {
                $anchor = (int) floor($totalCharacters * ($sixth / 6));
                $candidates[] = $this->window($content, $anchor, $anchor + 1, $targetWindow, 20 + $sixth, ['stratified']);
            }
        }

        usort($candidates, static fn (array $left, array $right): int => [
            (int) $left['priority'],
            (int) $left['start_offset'],
            (int) $left['end_offset'],
        ] <=> [
            (int) $right['priority'],
            (int) $right['start_offset'],
            (int) $right['end_offset'],
        ]);

        $selected = [];
        foreach ($candidates as $candidate) {
            if (count($selected) >= $maximumRanges) {
                break;
            }

            $merged = $this->mergeCandidate($selected, $candidate);
            if ($this->rangeCharacters($merged) > $maximumCharacters || count($merged) > $maximumRanges) {
                continue;
            }
            $selected = $merged;
        }

        usort($selected, static fn (array $left, array $right): int => (int) $left['start_offset'] <=> (int) $right['start_offset']);
        $coveredMandatory = [];
        foreach ($mandatoryClaims as $claim) {
            if (! (bool) $claim['requires_range']) {
                $coveredMandatory[(string) $claim['key']] = true;
            }
        }
        $sampledRanges = [];
        foreach ($selected as $index => $range) {
            foreach ($mandatoryClaims as $claim) {
                if ((int) $range['start_offset'] <= (int) $claim['start_offset']
                    && (int) $range['end_offset'] >= (int) $claim['end_offset']) {
                    $coveredMandatory[(string) $claim['key']] = true;
                }
            }

            $sampledRanges[] = [
                'index' => $index,
                'field' => 'content',
                'start_offset' => (int) $range['start_offset'],
                'end_offset' => (int) $range['end_offset'],
                'characters' => (int) $range['end_offset'] - (int) $range['start_offset'],
                'region' => $this->region($range, $totalCharacters),
                'reasons' => array_values(array_unique($range['reasons'])),
                'content' => Str::substr(
                    $content,
                    (int) $range['start_offset'],
                    (int) $range['end_offset'] - (int) $range['start_offset'],
                ),
            ];
        }

        $checkedCharacters = array_sum(array_column($sampledRanges, 'characters'));
        $regions = $totalCharacters === 0 || $checkedCharacters >= $totalCharacters
            ? ['front', 'middle', 'back']
            : array_values(array_intersect(
                ['front', 'middle', 'back'],
                array_values(array_unique(array_column($sampledRanges, 'region'))),
            ));
        $mandatoryTotal = count($mandatoryClaims) + $unresolvedMandatoryCount;
        $mandatoryCovered = count($coveredMandatory);
        $riskClaims = array_values(array_filter(
            $mandatoryClaims,
            static fn (array $claim): bool => (string) $claim['kind'] === 'risk',
        ));
        $riskCovered = count(array_filter(
            $riskClaims,
            static fn (array $claim): bool => isset($coveredMandatory[(string) $claim['key']]),
        ));

        return [
            'algorithm_version' => self::ALGORITHM_VERSION,
            'checked_chars' => $checkedCharacters,
            'total_chars' => $totalCharacters,
            'coverage_ratio' => $totalCharacters > 0 ? round($checkedCharacters / $totalCharacters, 4) : 1.0,
            'range_count' => count($sampledRanges),
            'mandatory_claims_total' => $mandatoryTotal,
            'mandatory_claims_covered' => $mandatoryCovered,
            'mandatory_overflow' => $mandatoryCovered < $mandatoryTotal,
            'unresolved_mandatory_count' => $unresolvedMandatoryCount,
            'risk_matches_total' => count($riskClaims) + $unresolvedMandatoryCount,
            'risk_matches_covered' => $riskCovered,
            'regions_covered' => $regions,
            'metadata_fields_included' => ['title', 'excerpt', 'meta_description', 'keywords'],
            'sampled_ranges' => $sampledRanges,
            'sampled_content' => implode("\n\n", array_map(
                static fn (array $range): string => sprintf(
                    '[原文范围 %d-%d]\n%s',
                    $range['start_offset'],
                    $range['end_offset'],
                    $range['content'],
                ),
                $sampledRanges,
            )),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $factCandidates
     * @param  list<array<string, mixed>>  $riskMatches
     * @return array{list<array{key:string,start_offset:int,end_offset:int,requires_range:bool,kind:string}>,int}
     */
    private function mandatoryClaims(string $content, array $factCandidates, array $riskMatches): array
    {
        $length = Str::length($content);
        $claims = [];
        foreach ($factCandidates as $candidate) {
            if ((string) ($candidate['materiality'] ?? '') !== 'high') {
                continue;
            }
            $occurrences = array_values(array_filter(
                is_array($candidate['occurrences'] ?? null) ? $candidate['occurrences'] : [$candidate],
                'is_array',
            ));
            foreach ($occurrences as $occurrenceIndex => $occurrence) {
                $field = (string) ($occurrence['field'] ?? $candidate['field'] ?? '');
                $start = max(0, min($length, (int) ($occurrence['start_offset'] ?? $candidate['start_offset'] ?? 0)));
                $end = max($start, min($length, (int) ($occurrence['end_offset'] ?? $candidate['end_offset'] ?? $start)));
                $claimHash = (string) ($candidate['claim_hash'] ?? hash('sha256', json_encode($candidate)));
                $occurrenceKey = (string) ($occurrence['source_hash'] ?? $occurrenceIndex.':'.$start.':'.$end);
                $key = 'fact:'.$field.':'.$claimHash.':'.$occurrenceKey;
                if ($field !== 'content') {
                    if (in_array($field, ['title', 'excerpt', 'meta_description', 'keywords'], true)) {
                        $claims[$key] = [
                            'key' => $key,
                            'start_offset' => 0,
                            'end_offset' => 0,
                            'requires_range' => false,
                            'kind' => 'fact',
                        ];
                    }

                    continue;
                }
                if ($end <= $start) {
                    continue;
                }
                $claims[$key] = [
                    'key' => $key,
                    'start_offset' => $start,
                    'end_offset' => $end,
                    'requires_range' => true,
                    'kind' => 'fact',
                ];
            }
        }

        $unresolvedMandatoryCount = 0;
        foreach ($riskMatches as $riskIndex => $match) {
            $field = (string) ($match['field'] ?? '');
            $word = trim((string) ($match['word'] ?? ''));
            if ($word === '') {
                continue;
            }
            if ($field !== 'content') {
                if (in_array($field, ['title', 'excerpt', 'meta_description', 'keywords'], true)) {
                    $key = 'risk:'.$field.':'.hash('sha256', $word.':'.$riskIndex);
                    $claims[$key] = [
                        'key' => $key,
                        'start_offset' => 0,
                        'end_offset' => 0,
                        'requires_range' => false,
                        'kind' => 'risk',
                    ];
                }

                continue;
            }

            $offsets = $this->exactOffsets($content, $word);
            $expected = max(1, (int) ($match['count'] ?? 1));
            $unresolvedMandatoryCount += max(0, $expected - count($offsets));
            foreach ($offsets as $offset) {
                $key = 'risk:content:'.hash('sha256', $word.':'.$offset);
                $claims[$key] = [
                    'key' => $key,
                    'start_offset' => $offset,
                    'end_offset' => $offset + Str::length($word),
                    'requires_range' => true,
                    'kind' => 'risk',
                ];
            }
        }

        uasort($claims, static fn (array $left, array $right): int => [
            $left['start_offset'],
            $left['end_offset'],
            $left['key'],
        ] <=> [
            $right['start_offset'],
            $right['end_offset'],
            $right['key'],
        ]);

        return [array_values($claims), $unresolvedMandatoryCount];
    }

    /** @return list<int> */
    private function exactOffsets(string $content, string $word): array
    {
        preg_match_all('/'.preg_quote($word, '/').'/iu', $content, $matches, PREG_OFFSET_CAPTURE);

        return array_values(array_map(
            static fn (array $match): int => mb_strlen(substr($content, 0, (int) $match[1]), 'UTF-8'),
            is_array($matches[0] ?? null) ? $matches[0] : [],
        ));
    }

    /** @return array<string, mixed> */
    private function window(
        string $content,
        int $start,
        int $end,
        int $target,
        int $priority,
        array $reasons,
        array $claimKeys = [],
    ): array {
        $length = Str::length($content);
        $start = max(0, min($length, $start));
        $end = max($start, min($length, $end));
        $required = max(1, $end - $start);
        $window = max($required, $target);
        $padding = max(0, $window - $required);
        $windowStart = max(0, $start - (int) floor($padding / 2));
        $windowEnd = min($length, $windowStart + $window);
        $windowStart = max(0, $windowEnd - $window);

        return [
            'start_offset' => $windowStart,
            'end_offset' => $windowEnd,
            'priority' => $priority,
            'reasons' => $reasons,
            'claim_keys' => $claimKeys,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $selected
     * @param  array<string,mixed>  $candidate
     * @return list<array<string,mixed>>
     */
    private function mergeCandidate(array $selected, array $candidate): array
    {
        $selected[] = $candidate;
        usort($selected, static fn (array $left, array $right): int => (int) $left['start_offset'] <=> (int) $right['start_offset']);
        $merged = [];
        foreach ($selected as $range) {
            $lastIndex = count($merged) - 1;
            if ($lastIndex >= 0 && (int) $range['start_offset'] <= (int) $merged[$lastIndex]['end_offset']) {
                $merged[$lastIndex]['end_offset'] = max((int) $merged[$lastIndex]['end_offset'], (int) $range['end_offset']);
                $merged[$lastIndex]['priority'] = min((int) $merged[$lastIndex]['priority'], (int) $range['priority']);
                $merged[$lastIndex]['reasons'] = array_values(array_unique(array_merge(
                    $merged[$lastIndex]['reasons'],
                    $range['reasons'],
                )));
                $merged[$lastIndex]['claim_keys'] = array_values(array_unique(array_merge(
                    $merged[$lastIndex]['claim_keys'],
                    $range['claim_keys'],
                )));

                continue;
            }
            $merged[] = $range;
        }

        return $merged;
    }

    /** @param list<array<string,mixed>> $ranges */
    private function rangeCharacters(array $ranges): int
    {
        return array_sum(array_map(
            static fn (array $range): int => max(0, (int) $range['end_offset'] - (int) $range['start_offset']),
            $ranges,
        ));
    }

    /** @param array<string,mixed> $range */
    private function region(array $range, int $totalCharacters): string
    {
        if ($totalCharacters <= 0) {
            return 'front';
        }
        $midpoint = ((int) $range['start_offset'] + (int) $range['end_offset']) / 2;
        $ratio = $midpoint / $totalCharacters;

        return $ratio < (1 / 3) ? 'front' : ($ratio < (2 / 3) ? 'middle' : 'back');
    }
}
