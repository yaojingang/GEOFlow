<?php

namespace App\Support\GeoFlow;

use InvalidArgumentException;

final class PromptTestDiagnosticResult
{
    public const VERSION = 'prompt-debug-v1';

    public const DIMENSIONS = [
        'readability' => ['clarity', 'structure', 'density', 'coherence'],
        'eeat' => ['experience', 'expertise', 'sources', 'trust'],
        'geo' => ['intent', 'answer', 'entities', 'evidence'],
    ];

    /** @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    public function validate(array $raw, string $article, string $prompt): array
    {
        if (! is_array($raw['criteria'] ?? null) || count($raw['criteria']) !== 12
            || ! is_array($raw['suggestions'] ?? null) || count($raw['suggestions']) > 8) {
            throw new InvalidArgumentException('invalid_diagnosis');
        }
        $criteria = [];
        foreach ($raw['criteria'] as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('invalid_diagnosis');
            }
            $id = $item['id'] ?? '';
            $status = $item['status'] ?? '';
            $level = $item['level'] ?? null;
            if (! is_string($id) || isset($criteria[$id])
                || ! in_array($id, array_merge(...array_values(self::DIMENSIONS)), true)
                || ! in_array($status, ['observed', 'missing', 'unverified'], true)
                || ! ($level === null || (is_int($level) && $level >= 0 && $level <= 4))
                || ($status === 'unverified' && $level !== null)
                || ($status !== 'unverified' && $level === null)) {
                throw new InvalidArgumentException('invalid_diagnosis');
            }
            $reason = $this->text($item, 'reason', 1500);
            $quote = $this->text($item, 'quote', 1500, false);
            $scope = $this->text($item, 'scope', 1000, $status !== 'observed');
            if (($quote !== '' && ! str_contains($article, $quote)) || ($status === 'observed' && $quote === '')) {
                throw new InvalidArgumentException('invalid_diagnosis_evidence');
            }
            $criteria[$id] = compact('id', 'status', 'level', 'reason', 'quote', 'scope');
        }
        $dimensions = [];
        foreach (self::DIMENSIONS as $id => $keys) {
            $items = array_map(fn (string $key): array => $criteria[$key], $keys);
            $assessed = count(array_filter($items, fn (array $item): bool => $item['level'] !== null));
            $dimensions[$id] = [
                'score' => $assessed === 4 ? (int) round(array_sum(array_column($items, 'level')) / 16 * 100) : null,
                'assessed' => $assessed, 'total' => 4, 'criteria' => $items,
            ];
        }
        $scores = array_column($dimensions, 'score');
        $overall = in_array(null, $scores, true) ? null : (int) round(
            $dimensions['readability']['score'] * .3 + $dimensions['eeat']['score'] * .3 + $dimensions['geo']['score'] * .4,
        );
        $suggestions = [];
        foreach ($raw['suggestions'] as $item) {
            try {
                if (! is_array($item) || ! in_array($item['dimension'] ?? '', array_keys(self::DIMENSIONS), true)
                    || ! in_array($item['priority'] ?? '', ['high', 'medium', 'low'], true)) {
                    throw new InvalidArgumentException('invalid_suggestion');
                }
                $quote = $this->text($item, 'article_quote', 1500, false);
                $scope = $this->text($item, 'article_scope', 1000, $quote === '');
                if ($quote !== '' && ! str_contains($article, $quote)) {
                    throw new InvalidArgumentException('invalid_suggestion_evidence');
                }
                $anchor = PromptTestAnchors::resolve(
                    $prompt, $this->text($item, 'segment_id', 30),
                    $this->text($item, 'prompt_quote', 10000), $this->text($item, 'action', 30),
                );
                if ($anchor === null) {
                    throw new InvalidArgumentException('invalid_suggestion_anchor');
                }
                $suggestions[] = [
                    'dimension' => $item['dimension'], 'priority' => $item['priority'],
                    'problem' => $this->text($item, 'problem', 1500),
                    'article_quote' => $quote, 'article_scope' => $scope, 'anchor' => $anchor,
                    'replacement' => $this->text($item, 'replacement', 6000),
                    'reason' => $this->text($item, 'reason', 1500),
                    'expected_improvement' => $this->text($item, 'expected_improvement', 1500),
                    'needed_evidence' => $this->text($item, 'needed_evidence', 1500, false),
                ];
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return [
            'version' => self::VERSION, 'overall' => $overall, 'dimensions' => $dimensions,
            'suggestions' => $suggestions,
            'unresolved_suggestions' => count($raw['suggestions']) - count($suggestions),
        ];
    }

    /** @param array<string,mixed> $item */
    private function text(array $item, string $key, int $limit, bool $required = true): string
    {
        $value = $item[$key] ?? '';
        if (! is_string($value) || mb_strlen($value) > $limit || ($required && trim($value) === '')) {
            throw new InvalidArgumentException('invalid_diagnosis');
        }

        return $value;
    }
}
