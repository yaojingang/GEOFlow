<?php

namespace App\Services\GeoFlow\KnowledgeFacts;

class AtomicFactComparator
{
    /** @param array<string,mixed> $claim @param array<string,mixed> $standard @return array{result:string,decision:string,reason:string} */
    public function compare(array $claim, array $standard): array
    {
        $claimValue = $this->decimal($claim['value'] ?? null);
        $standardValue = $this->decimal($standard['value'] ?? null);
        if ($claimValue !== null && $standardValue !== null) {
            $claimNormalized = $claimValue * $this->unitMultiplier((string) ($claim['unit'] ?? ''));
            $standardNormalized = $standardValue * $this->unitMultiplier((string) ($standard['unit'] ?? ''));
            $tolerance = max(0.0, (float) ($standard['tolerance'] ?? 0));
            $matches = abs($claimNormalized - $standardNormalized) <= $tolerance;

            return ['result' => $matches ? 'match' : 'mismatch', 'decision' => $matches ? 'passed' : 'blocked', 'reason' => $matches ? 'numeric_match' : 'numeric_mismatch'];
        }

        $claimText = $this->normalizeText((string) ($claim['text'] ?? ''));
        $standardText = $this->normalizeText((string) ($standard['answer'] ?? ''));
        if ($claimText === '' || $standardText === '') {
            return ['result' => 'unknown', 'decision' => 'needs_review', 'reason' => 'insufficient_comparable_value'];
        }
        $matches = $claimText === $standardText || str_contains($claimText, $standardText) || str_contains($standardText, $claimText);

        return ['result' => $matches ? 'match' : 'unknown', 'decision' => $matches ? 'passed' : 'needs_review', 'reason' => $matches ? 'text_match' : 'semantic_review_required'];
    }

    private function decimal(mixed $value): ?float
    {
        if (! is_string($value) || preg_match('/\A-?(?:0|[1-9]\d*)(?:\.\d+)?\z/', trim($value)) !== 1) {
            return null;
        }

        return (float) $value;
    }

    private function unitMultiplier(string $unit): float
    {
        return match (mb_strtolower(trim($unit))) {
            '万', '万件' => 10000.0,
            '%', 'percent', 'percentage' => 0.01,
            default => 1.0,
        };
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower((string) preg_replace('/[\s\p{P}\p{S}]+/u', '', trim($text)));
    }
}
